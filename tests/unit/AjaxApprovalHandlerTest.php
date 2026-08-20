<?php
/**
 * Tests for the one catalog-native browser approval Adapter.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AjaxApprovalHandlerTest extends TestCase {

	private ?bool $success = null;
	private mixed $data    = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);
		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ): never {
				$this->success = true;
				$this->data    = $data;
				throw new HaydiTestHaltException();
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null ): never {
				$this->success = false;
				$this->data    = $data;
				throw new HaydiTestHaltException();
			}
		);

		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_approved_tool_returns_one_structured_result(): void {
		$catalog = $this->approval_catalog(
			static fn( array $arguments ): array => array(
				'message' => 'Cache cleared.',
				'reason'  => $arguments['reason'],
				'details' => array(
					'status' => 'complete',
					'keys'   => array( 'pages', 'fragments' ),
				),
			)
		);
		$handler = new Haydi_Ajax_Handlers( $catalog );

		$this->invoke(
			$handler,
			array(
				'tool_name' => 'clear_cache',
				'arguments' => wp_json_encode( array( 'reason' => 'Refresh pages.' ) ),
			)
		);

		$this->assertTrue( $this->success );
		$this->assertSame( 'clear_cache', $this->data['tool_name'] );
		$this->assertSame(
			array(
				'message' => 'Cache cleared.',
				'reason'  => 'Refresh pages.',
				'details' => array(
					'status' => 'complete',
					'keys'   => array( 'pages', 'fragments' ),
				),
			),
			$this->data['result']
		);
		$this->assertArrayNotHasKey( 'tool_result', $this->data );
	}

	public function test_approved_tool_preserves_safe_error_output(): void {
		$catalog = $this->approval_catalog(
			static fn(): WP_Error => new WP_Error(
				'php_error',
				'PHP failed.',
				array( 'output' => 'before failure' )
			)
		);
		$handler = new Haydi_Ajax_Handlers( $catalog );

		$this->invoke(
			$handler,
			array(
				'tool_name' => 'clear_cache',
				'arguments' => '{"reason":"Test error."}',
			)
		);

		$this->assertFalse( $this->success );
		$this->assertSame(
			array( 'message' => 'PHP failed.', 'output' => 'before failure' ),
			$this->data
		);
	}

	public function test_approved_tool_preserves_an_explicit_empty_output(): void {
		$catalog = $this->approval_catalog(
			static fn(): WP_Error => new WP_Error( 'php_error', 'PHP failed.', array( 'output' => '' ) )
		);
		$handler = new Haydi_Ajax_Handlers( $catalog );

		$this->invoke(
			$handler,
			array(
				'tool_name' => 'clear_cache',
				'arguments' => '{"reason":"Test error."}',
			)
		);

		$this->assertFalse( $this->success );
		$this->assertSame( array( 'message' => 'PHP failed.', 'output' => '' ), $this->data );
	}

	public function test_approved_tool_rejects_malformed_arguments(): void {
		$handler = new Haydi_Ajax_Handlers( $this->approval_catalog( static fn(): string => 'unused' ) );

		$this->invoke(
			$handler,
			array(
				'tool_name' => 'clear_cache',
				'arguments' => 'not-json',
			)
		);

		$this->assertFalse( $this->success );
		$this->assertSame( array( 'message' => 'arguments must be a JSON object.' ), $this->data );
	}

	public function test_approved_tool_rejects_a_json_array(): void {
		$handler = new Haydi_Ajax_Handlers( $this->approval_catalog( static fn(): string => 'unused' ) );

		$this->invoke(
			$handler,
			array(
				'tool_name' => 'clear_cache',
				'arguments' => '[{"reason":"not an object"}]',
			)
		);

		$this->assertFalse( $this->success );
		$this->assertSame( array( 'message' => 'arguments must be a JSON object.' ), $this->data );
	}

	public function test_multisite_site_administrator_cannot_execute_run_php_directly(): void {
		$executed = false;
		Functions\when( 'current_user_can' )->alias(
			static fn( string $capability ): bool => 'manage_options' === $capability
		);
		$handler = new Haydi_Ajax_Handlers(
			$this->approval_catalog(
				static function () use ( &$executed ): array {
					$executed = true;
					return array( 'message' => 'PHP executed.' );
				},
				'run_php'
			)
		);

		$this->invoke(
			$handler,
			array(
				'tool_name' => 'run_php',
				'arguments' => '{"code":"grant_super_admin(1);","reason":"test"}',
			)
		);

		$this->assertFalse( $this->success );
		$this->assertSame( array( 'message' => 'Permission denied.' ), $this->data );
		$this->assertFalse( $executed );
	}

	public function test_multisite_site_administrator_cannot_generate_an_api_token(): void {
		$option_updated = false;
		Functions\when( 'current_user_can' )->alias(
			static fn( string $capability ): bool => 'manage_options' === $capability
		);
		Functions\when( 'update_option' )->alias(
			static function () use ( &$option_updated ): bool {
				$option_updated = true;
				return true;
			}
		);
		$handler         = new Haydi_Ajax_Handlers( $this->approval_catalog( static fn(): string => 'unused' ) );
		$this->success   = null;
		$this->data      = null;
		$_POST           = array( 'nonce' => 'test', 'label' => 'attacker token' );

		try {
			$handler->handle_generate_token();
		} catch ( HaydiTestHaltException $exception ) {
			unset( $exception );
		}

		$this->assertFalse( $this->success );
		$this->assertSame( array( 'message' => 'Permission denied.' ), $this->data );
		$this->assertFalse( $option_updated );
	}

	private function invoke( Haydi_Ajax_Handlers $handler, array $post ): void {
		$this->success = null;
		$this->data    = null;
		$_POST         = array_merge( array( 'nonce' => 'test' ), $post );

		try {
			$handler->handle_execute_approved_tool();
		} catch ( HaydiTestHaltException $exception ) {
			unset( $exception );
		}
	}

	private function approval_catalog( callable $implementation, string $name = 'clear_cache' ): Haydi_Tool_Catalog {
		$catalog = new Haydi_Tool_Catalog();
		$catalog->register(
			array(
				'name'         => $name,
				'description'  => 'Clear the cache after approval.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'reason' => array( 'type' => 'string', 'description' => 'Reason.' ),
					),
					'required'   => array( 'reason' ),
				),
				'effect'       => 'approval',
				'proposal'     => array(
					'label'      => 'Clear Cache',
					'log_action' => 'cache_clear_proposed',
				),
				'projections'  => array( 'chat' => true ),
			),
			$implementation
		);
		return $catalog;
	}
}
