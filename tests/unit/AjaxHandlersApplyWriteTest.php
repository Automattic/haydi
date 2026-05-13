<?php
/**
 * Unit tests for Haydi_File_Tool::handle_apply_write() — specifically
 * the post-write health check and auto-rollback logic added to guard against
 * fatal PHP errors.
 *
 * Strategy
 * --------
 * - Use newInstanceWithoutConstructor() + reflection to inject a mock
 *   FilesystemGuard so write_file() / restore_latest_backup() are fully
 *   controlled without touching the real filesystem.
 * - Brain\Monkey stubs all WordPress functions (wp_remote_get, admin_url, …).
 * - The real php -l check still runs for PHP files: valid PHP content is used
 *   in tests that need to reach the health-check code path.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class AjaxHandlersApplyWriteTest extends TestCase {

	private \ReflectionClass $ref;
	private \Haydi_File_Tool $handler;
	private \Haydi_Filesystem_Guard $mockGuard;

	/** Captured outcome of the last wp_send_json_success / wp_send_json_error call. */
	private ?bool $lastSuccess = null;
	private mixed $lastData    = null;

	/** Minimal valid PHP that passes php -l without side-effects. */
	const VALID_PHP = '<?php // valid';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Auth
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );

		// Input helpers
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		// php -l support: wp_tempnam creates a real temp file; wp_delete_file removes it.
		Functions\when( 'wp_tempnam' )->alias( function ( $prefix ) {
			return tempnam( sys_get_temp_dir(), $prefix );
		} );
		// wp_delete_file must be stubbed via Patchwork (not in bootstrap) so it is
		// patchable; defining it as a plain function before Patchwork loads breaks mocking.
		Functions\when( 'wp_delete_file' )->alias( 'unlink' );

		// Capture JSON responses and halt — production wp_die()s after sending.
		Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) {
			$this->lastSuccess = true;
			$this->lastData    = $data;
			throw new \HaydiTestHaltException();
		} );
		Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) {
			$this->lastSuccess = false;
			$this->lastData    = $data;
			throw new \HaydiTestHaltException();
		} );

		// Build handler without triggering constructor add_action() calls.
		$this->ref     = new \ReflectionClass( Haydi_File_Tool::class );
		$this->handler = $this->ref->newInstanceWithoutConstructor();

		// Inject mock guard.
		$this->mockGuard = $this->createMock( Haydi_Filesystem_Guard::class );
		$this->ref->getProperty( 'guard' )->setValue( $this->handler, $this->mockGuard );

		// Inject a real Health_Check — its loopback HTTP call goes through
		// wp_remote_get / admin_url, which Brain\Monkey stubs per-test.
		$this->ref->getProperty( 'health' )->setValue(
			$this->handler,
			new Haydi_Health_Check()
		);

		// Inject mock logger (log() calls are no-ops). `logger` is declared on
		// the abstract base, so look it up via the parent reflection.
		$this->ref->getParentClass()->getProperty( 'logger' )->setValue(
			$this->handler,
			$this->createMock( Haydi_Audit_Logger::class )
		);

		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// Helper
	// -----------------------------------------------------------------------

	private function callApplyWrite( array $post ): void {
		$this->lastSuccess = null;
		$this->lastData    = null;
		$_POST             = array_merge( $post, array( 'nonce' => 'test' ) );
		try {
			$this->handler->handle_apply_write();
		} catch ( \HaydiTestHaltException $e ) {
			unset( $e );
		}
	}

	/** Returns a Brain\Monkey stub for a healthy health-check response. */
	private function stubHealthyResponse(): void {
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'get_transient' )->justReturn( 'http://127.0.0.1/wp-admin/admin-ajax.php?action=haydi_health' );
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '{"success":true,"data":"ok"}' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":true,"data":"ok"}' );
	}

	/** Stubs health-check to simulate a broken site. */
	private function stubUnhealthyResponse( string $body = '' ): void {
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'get_transient' )->justReturn( 'http://127.0.0.1/wp-admin/admin-ajax.php?action=haydi_health' );
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => $body ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
	}

	// -----------------------------------------------------------------------
	// Guard against missing path
	// -----------------------------------------------------------------------

	public function test_errors_when_path_is_empty(): void {
		$this->callApplyWrite( array( 'path' => '', 'content' => '' ) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'required', $this->lastData['message'] );
	}

	// -----------------------------------------------------------------------
	// PHP syntax check (pre-write, existing behaviour)
	// -----------------------------------------------------------------------

	public function test_syntax_error_prevents_write_and_health_check(): void {
		$wpRemoteGetCalled = false;
		Functions\when( 'wp_remote_get' )->alias( function () use ( &$wpRemoteGetCalled ) {
			$wpRemoteGetCalled = true;
		} );

		$this->mockGuard->expects( $this->never() )->method( 'write_file' );

		$this->callApplyWrite( array(
			'path'    => '/theme/functions.php',
			'content' => '<?php function broken( {',  // invalid syntax
		) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'syntax error', $this->lastData['message'] );
		$this->assertFalse( $wpRemoteGetCalled, 'Health check must not run when syntax is invalid' );
	}

	// -----------------------------------------------------------------------
	// Health check skipped for non-PHP files
	// -----------------------------------------------------------------------

	public function test_health_check_not_run_for_non_php_file(): void {
		$this->mockGuard->method( 'write_file' )->willReturn( '/theme/style.css' );

		$wpRemoteGetCalled = false;
		Functions\when( 'wp_remote_get' )->alias( function () use ( &$wpRemoteGetCalled ) {
			$wpRemoteGetCalled = true;
			return array();
		} );

		$this->callApplyWrite( array( 'path' => '/theme/style.css', 'content' => 'body {}' ) );

		$this->assertTrue( $this->lastSuccess );
		$this->assertFalse( $wpRemoteGetCalled, 'Health check must not run for non-PHP files' );
	}

	// -----------------------------------------------------------------------
	// Healthy response — success path
	// -----------------------------------------------------------------------

	public function test_succeeds_when_health_check_returns_success_token(): void {
		$this->mockGuard->method( 'write_file' )->willReturn( '/plugin/my-plugin.php' );
		$this->stubHealthyResponse();

		$this->callApplyWrite( array(
			'path'    => '/plugin/my-plugin.php',
			'content' => self::VALID_PHP,
		) );

		$this->assertTrue( $this->lastSuccess );
		$this->assertStringContainsString( 'written', $this->lastData['message'] );
	}

	// -----------------------------------------------------------------------
	// Unhealthy responses — rollback triggered
	// -----------------------------------------------------------------------

	public function test_rolls_back_and_errors_when_response_body_is_empty(): void {
		// Simulates exit() / die() cutting output before wp_send_json_success fires.
		$this->mockGuard->method( 'write_file' )->willReturn( '/plugin/my-plugin.php' );
		$this->mockGuard->expects( $this->once() )->method( 'restore_latest_backup' )->willReturn( true );
		$this->stubUnhealthyResponse( '' );

		$this->callApplyWrite( array(
			'path'    => '/plugin/my-plugin.php',
			'content' => self::VALID_PHP,
		) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'site error', $this->lastData['message'] );
	}

	public function test_rolls_back_and_errors_when_response_body_lacks_success_token(): void {
		// Simulates a WordPress 500 error page (has body, but not our token).
		$this->mockGuard->method( 'write_file' )->willReturn( '/plugin/my-plugin.php' );
		$this->mockGuard->expects( $this->once() )->method( 'restore_latest_backup' )->willReturn( true );
		$this->stubUnhealthyResponse( '<html><body>There has been a critical error.</body></html>' );

		$this->callApplyWrite( array(
			'path'    => '/plugin/my-plugin.php',
			'content' => self::VALID_PHP,
		) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'site error', $this->lastData['message'] );
	}

	public function test_skips_rollback_when_wp_remote_get_itself_fails(): void {
		// Simulates a connection timeout or loopback blocked.
		// Unreachable loopback is treated as "can't tell" — the write is kept.
		$this->mockGuard->method( 'write_file' )->willReturn( '/plugin/my-plugin.php' );
		$this->mockGuard->expects( $this->never() )->method( 'restore_latest_backup' );

		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'get_transient' )->justReturn( 'http://127.0.0.1/wp-admin/admin-ajax.php?action=haydi_health' );
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http_request_failed', 'cURL error' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$this->callApplyWrite( array(
			'path'    => '/plugin/my-plugin.php',
			'content' => self::VALID_PHP,
		) );

		$this->assertTrue( $this->lastSuccess );
	}

	// -----------------------------------------------------------------------
	// Restore failure detail surfaced to user
	// -----------------------------------------------------------------------

	public function test_error_message_includes_restore_failure_detail(): void {
		$this->mockGuard->method( 'write_file' )->willReturn( '/plugin/my-plugin.php' );
		$this->mockGuard->method( 'restore_latest_backup' )->willReturn(
			new WP_Error( 'restore_failed', 'Failed to copy backup.' )
		);
		$this->stubUnhealthyResponse( '' );

		$this->callApplyWrite( array(
			'path'    => '/plugin/my-plugin.php',
			'content' => self::VALID_PHP,
		) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'Restore also failed', $this->lastData['message'] );
		$this->assertStringContainsString( 'Failed to copy backup.', $this->lastData['message'] );
	}

	// -----------------------------------------------------------------------
	// write_file failure — health check must not run
	// -----------------------------------------------------------------------

	public function test_write_failure_short_circuits_before_health_check(): void {
		$this->mockGuard->method( 'write_file' )->willReturn(
			new WP_Error( 'write_error', 'WP_Filesystem failed.' )
		);

		$wpRemoteGetCalled = false;
		Functions\when( 'wp_remote_get' )->alias( function () use ( &$wpRemoteGetCalled ) {
			$wpRemoteGetCalled = true;
			return array();
		} );

		$this->callApplyWrite( array(
			'path'    => '/plugin/my-plugin.php',
			'content' => self::VALID_PHP,
		) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'WP_Filesystem failed.', $this->lastData['message'] );
		$this->assertFalse( $wpRemoteGetCalled, 'Health check must not run when write itself failed' );
	}

	// -----------------------------------------------------------------------
	// Restore is called with the resolved path returned by write_file
	// -----------------------------------------------------------------------

	public function test_restore_receives_resolved_path_from_write_file(): void {
		$resolvedPath = '/var/www/html/wp-content/plugins/my-plugin/my-plugin.php';
		$this->mockGuard->method( 'write_file' )->willReturn( $resolvedPath );
		$this->mockGuard->expects( $this->once() )
			->method( 'restore_latest_backup' )
			->with( $resolvedPath )
			->willReturn( true );

		$this->stubUnhealthyResponse( '' );

		$this->callApplyWrite( array(
			'path'    => '/plugin/my-plugin.php',
			'content' => self::VALID_PHP,
		) );

		$this->assertFalse( $this->lastSuccess );
	}
}
