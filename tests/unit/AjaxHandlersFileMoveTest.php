<?php
/**
 * Unit tests for Haydi_File_Tool::handle_move_file() — specifically
 * the two-step undo (delete dest + restore src) when the post-move health
 * check fails. This is the only file mutator with non-trivial revert logic;
 * delete/copy reuse the same restore_latest_backup pathway already covered
 * by AjaxHandlersApplyWriteTest.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class AjaxHandlersFileMoveTest extends TestCase {

	private \ReflectionClass $ref;
	private \Haydi_File_Tool $handler;
	private \Haydi_Filesystem_Guard $mockGuard;

	private ?bool $lastSuccess = null;
	private mixed $lastData    = null;

	/** Real on-disk destination so `is_file()` and `unlink()` in the undo work. */
	private string $destPath;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

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

		$this->ref     = new \ReflectionClass( Haydi_File_Tool::class );
		$this->handler = $this->ref->newInstanceWithoutConstructor();

		$this->mockGuard = $this->createMock( Haydi_Filesystem_Guard::class );
		$this->ref->getProperty( 'guard' )->setValue( $this->handler, $this->mockGuard );
		$this->ref->getProperty( 'health' )->setValue(
			$this->handler,
			new Haydi_Health_Check()
		);
		$this->ref->getParentClass()->getProperty( 'logger' )->setValue(
			$this->handler,
			$this->createMock( Haydi_Audit_Logger::class )
		);

		// Real destination file for unlink() in the undo path.
		$this->destPath = tempnam( sys_get_temp_dir(), 'haydi_move_dest_' );
		file_put_contents( $this->destPath, '<?php // moved' );

		$_POST = array();
	}

	protected function tearDown(): void {
		if ( is_file( $this->destPath ) ) {
			unlink( $this->destPath );
		}
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function callMove( array $post ): void {
		$this->lastSuccess = null;
		$this->lastData    = null;
		$_POST             = array_merge( $post, array( 'nonce' => 'test' ) );
		try {
			$this->handler->handle_move_file();
		} catch ( \HaydiTestHaltException $e ) {
			unset( $e );
		}
	}

	public function test_unhealthy_post_move_deletes_dest_and_restores_src(): void {
		$srcPath = '/var/www/html/wp-content/plugins/x/orig.php';

		$this->mockGuard->method( 'move_file' )->willReturn(
			array( 'src' => $srcPath, 'dest' => $this->destPath )
		);

		// The undo must call restore_latest_backup with the SOURCE path so the
		// pre-move backup is copied back to its original location.
		$this->mockGuard->expects( $this->once() )
			->method( 'restore_latest_backup' )
			->with( $srcPath )
			->willReturn( true );

		// Unhealthy loopback response → triggers the undo.
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'get_transient' )->justReturn( 'http://127.0.0.1/wp-admin/admin-ajax.php?action=haydi_health' );
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$this->callMove( array(
			'src'  => $srcPath,
			'dest' => $this->destPath,
		) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'File move caused a site error', $this->lastData['message'] );
		$this->assertFileDoesNotExist(
			$this->destPath,
			'Destination file must be removed by the move undo'
		);
	}

	public function test_healthy_post_move_keeps_dest_and_skips_restore(): void {
		$srcPath = '/var/www/html/wp-content/plugins/x/orig.php';

		$this->mockGuard->method( 'move_file' )->willReturn(
			array( 'src' => $srcPath, 'dest' => $this->destPath )
		);

		// On a healthy site the undo callback must never run, so
		// restore_latest_backup must not be called at all.
		$this->mockGuard->expects( $this->never() )->method( 'restore_latest_backup' );

		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'get_transient' )->justReturn( 'http://127.0.0.1/wp-admin/admin-ajax.php?action=haydi_health' );
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '{"success":true}' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":true}' );

		$this->callMove( array(
			'src'  => $srcPath,
			'dest' => $this->destPath,
		) );

		$this->assertTrue( $this->lastSuccess );
		$this->assertFileExists( $this->destPath, 'Destination file must remain on a healthy move' );
	}
}
