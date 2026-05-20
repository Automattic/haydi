<?php
/**
 * Unit tests for haydi_db_ext_execute_query() — focused on the post-mutation
 * health check: SELECT-style queries must skip it (no mutation, nothing to
 * verify), UPDATE-style queries must run it.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class AjaxHandlersQueryTest extends TestCase {

	private \Haydi_Health_Check $health;
	private \Haydi_Audit_Logger $mockLogger;

	private ?bool $lastSuccess   = null;
	private mixed $lastData      = null;
	private bool  $healthChecked = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->healthChecked = false;
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'get_transient' )->justReturn( 'http://127.0.0.1/wp-admin/admin-ajax.php?action=haydi_health' );
		Functions\when( 'wp_remote_get' )->alias( function () {
			$this->healthChecked = true;
			return array( 'body' => '{"success":true}' );
		} );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":true}' );

		$GLOBALS['wpdb'] = new class() {
			public string $last_error = '';
			public function show_errors(): void {}
			public function get_results( string $sql, string $type ) {
				return array( array( 'id' => 1 ) );
			}
			public function query( string $sql ) {
				return 1;
			}
		};

		$this->mockLogger = $this->createMock( Haydi_Audit_Logger::class );
		$this->health     = new Haydi_Health_Check();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function callQuery( string $sql ): void {
		$this->lastSuccess = null;
		$this->lastData    = null;
		$result            = haydi_db_ext_execute_query( $sql, 'test', $this->mockLogger, $this->health );
		if ( is_wp_error( $result ) ) {
			$this->lastSuccess = false;
			$this->lastData    = array( 'message' => $result->get_error_message() );
		} else {
			$this->lastSuccess = true;
			$this->lastData    = $result;
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
