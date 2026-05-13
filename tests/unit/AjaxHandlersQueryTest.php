<?php
/**
 * Unit tests for Haydi_Query_Tool::handle_execute_query() — focused
 * on the post-mutation health check: SELECT-style queries must skip it (no
 * mutation, no need to pay the loopback cost or risk a false-positive on
 * loopback-blocked hosts), UPDATE-style queries must run it.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class AjaxHandlersQueryTest extends TestCase {

	private \ReflectionClass $ref;
	private \Haydi_Query_Tool $handler;

	private ?bool $lastSuccess = null;
	private mixed $lastData    = null;

	/** True after wp_remote_get is called — the loopback health probe's signature. */
	private bool $healthChecked = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

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

		// Track whether the loopback health probe ran.
		$this->healthChecked = false;
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'get_transient' )->justReturn( 'http://127.0.0.1/wp-admin/admin-ajax.php?action=haydi_health' );
		Functions\when( 'wp_remote_get' )->alias( function () {
			$this->healthChecked = true;
			return array( 'body' => '{"success":true}' );
		} );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":true}' );

		// Minimal $wpdb stub. SELECT goes through get_results; everything else
		// through query. show_errors() is a no-op.
		$GLOBALS['wpdb'] = new class() {
			public string $last_error = '';
			public function show_errors(): void {}
			public function get_results( string $sql, string $type ) {
				return array( array( 'id' => 1 ) );
			}
			public function query( string $sql ) {
				return 1; // 1 row affected
			}
		};

		$this->ref     = new \ReflectionClass( Haydi_Query_Tool::class );
		$this->handler = $this->ref->newInstanceWithoutConstructor();

		$this->ref->getProperty( 'health' )->setValue(
			$this->handler,
			new Haydi_Health_Check()
		);
		$this->ref->getParentClass()->getProperty( 'logger' )->setValue(
			$this->handler,
			$this->createMock( Haydi_Audit_Logger::class )
		);

		$_POST = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function callQuery( string $sql ): void {
		$this->lastSuccess = null;
		$this->lastData    = null;
		$_POST             = array(
			'sql'    => $sql,
			'reason' => 'test',
			'nonce'  => 'test',
		);
		try {
			$this->handler->handle_execute_query();
		} catch ( \HaydiTestHaltException $e ) {
			unset( $e );
		}
	}

	public function test_select_query_skips_health_check(): void {
		$this->callQuery( 'SELECT * FROM wp_options LIMIT 1' );

		$this->assertTrue( $this->lastSuccess );
		$this->assertSame( 'select', $this->lastData['type'] );
		$this->assertFalse(
			$this->healthChecked,
			'SELECT must not pay the loopback health-check cost — no mutation, nothing to verify'
		);
	}

	public function test_show_query_skips_health_check(): void {
		$this->callQuery( 'SHOW TABLES' );

		$this->assertTrue( $this->lastSuccess );
		$this->assertFalse( $this->healthChecked );
	}

	public function test_update_query_runs_health_check(): void {
		$this->callQuery( "UPDATE wp_options SET option_value = 'x' WHERE option_name = 'blogname'" );

		$this->assertTrue( $this->lastSuccess );
		$this->assertSame( 'write', $this->lastData['type'] );
		$this->assertTrue(
			$this->healthChecked,
			'Mutating queries must run the post-execution health check'
		);
	}

	public function test_update_query_returns_recovery_warning_when_unhealthy(): void {
		// Override the default healthy stub to simulate a broken site.
		Functions\when( 'wp_remote_get' )->alias( function () {
			$this->healthChecked = true;
			return array( 'body' => '' );
		} );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$this->callQuery( "UPDATE wp_options SET option_value = '' WHERE option_name = 'siteurl'" );

		$this->assertTrue( $this->healthChecked );
		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'SQL query caused a site error', $this->lastData['message'] );
		$this->assertStringContainsString( 'no automatic revert', $this->lastData['message'] );
	}
}
