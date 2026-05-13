<?php
/**
 * Unit tests for Haydi_Health_Check.
 *
 * Brain\Monkey stubs admin_url / wp_remote_get / wp_remote_retrieve_body so the
 * loopback HTTP behaviour can be simulated without a live WordPress.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class HealthCheckTest extends TestCase {

	private \Haydi_Health_Check $health;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// admin_url is constant across tests; only the response varies.
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		// Bypass URL discovery — return a pre-cached URL so check() tests only the response.
		Functions\when( 'get_transient' )->justReturn( 'http://127.0.0.1/wp-admin/admin-ajax.php?action=haydi_health' );

		$this->health = new \Haydi_Health_Check();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// verify()
	// -------------------------------------------------------------------------

	public function test_verify_returns_true_when_body_contains_success_token(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '{"success":true,"data":"ok"}' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":true,"data":"ok"}' );

		$this->assertTrue( $this->health->verify() );
	}

	public function test_verify_returns_false_for_empty_body(): void {
		// Mirrors the case where exit()/die() ran before json output.
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$this->assertFalse( $this->health->verify() );
	}

	public function test_verify_returns_false_when_body_lacks_token(): void {
		// Mirrors the WordPress critical-error HTML page.
		$body = '<html><body>There has been a critical error.</body></html>';
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => $body ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		$this->assertFalse( $this->health->verify() );
	}

	public function test_verify_returns_false_when_wp_remote_get_returns_wp_error(): void {
		// Mirrors a connection timeout or loopback-blocked response.
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http_request_failed', 'cURL error 7' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$this->assertFalse( $this->health->verify() );
	}

	// -------------------------------------------------------------------------
	// verify_or_revert()
	// -------------------------------------------------------------------------

	public function test_verify_or_revert_returns_null_and_skips_undo_when_healthy(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '{"success":true}' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":true}' );

		$undoCalled = false;
		$result     = $this->health->verify_or_revert(
			function () use ( &$undoCalled ) {
				$undoCalled = true;
				return true;
			},
			'File write'
		);

		$this->assertNull( $result );
		$this->assertFalse( $undoCalled, 'undo must not run when site is healthy' );
	}

	public function test_verify_or_revert_invokes_undo_and_formats_revert_success_message(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$undoCalled = false;
		$result     = $this->health->verify_or_revert(
			function () use ( &$undoCalled ) {
				$undoCalled = true;
				return true;
			},
			'File write'
		);

		$this->assertTrue( $undoCalled );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'File write caused a site error', $result->get_error_message() );
		$this->assertStringContainsString( 'Change reverted from backup.', $result->get_error_message() );
	}

	public function test_verify_or_revert_skips_undo_when_loopback_unreachable(): void {
		// Connection refused / timeout — loopback networking issue, not a site error.
		// The operation should NOT be reverted; the site is presumed healthy.
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http_request_failed', 'cURL error 7' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$undoCalled = false;
		$result     = $this->health->verify_or_revert(
			function () use ( &$undoCalled ) {
				$undoCalled = true;
				return true;
			},
			'File write'
		);

		$this->assertNull( $result, 'unreachable loopback must not trigger a revert' );
		$this->assertFalse( $undoCalled, 'undo must not run when loopback is unreachable' );
	}

	public function test_verify_or_revert_formats_restore_failed_detail_when_undo_returns_wp_error(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$result = $this->health->verify_or_revert(
			fn() => new WP_Error( 'restore_failed', 'Failed to copy backup.' ),
			'File deletion'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'File deletion caused a site error', $result->get_error_message() );
		$this->assertStringContainsString( 'Restore also failed: Failed to copy backup.', $result->get_error_message() );
	}

	// -------------------------------------------------------------------------
	// verify_or_warn() — detect-only path for ops with no automatic undo
	// -------------------------------------------------------------------------

	public function test_verify_or_warn_returns_null_when_healthy(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '{"success":true}' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":true}' );

		$this->assertNull( $this->health->verify_or_warn( 'PHP execution' ) );
	}

	public function test_verify_or_warn_returns_recovery_mode_message_when_unhealthy(): void {
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$result = $this->health->verify_or_warn( 'SQL query' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'SQL query caused a site error', $result->get_error_message() );
		$this->assertStringContainsString( 'no automatic revert', $result->get_error_message() );
		$this->assertStringContainsString( 'recovery-mode', $result->get_error_message() );
	}

	public function test_verify_or_warn_returns_null_when_loopback_unreachable(): void {
		// Connection refused — loopback can't reach itself, not a site error.
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error( 'http_request_failed', 'cURL error 7' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$this->assertNull( $this->health->verify_or_warn( 'PHP execution' ) );
	}
}
