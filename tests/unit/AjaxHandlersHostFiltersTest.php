<?php
/**
 * Unit tests for hooks used by host-specific Haydi integrations.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class AjaxHandlersHostFiltersTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		unset( $GLOBALS['haydi_tool_catalog'], $GLOBALS['haydi_tool_catalog_building'] );

		global $wpdb;
		$wpdb = (object) array( 'prefix' => 'wp_' );

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) {
				return Haydi_Jetpack_Context::PROMPT_CACHE_KEY === $key ? '' : false;
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$args ) {
				unset( $hook );
				unset( $args );
				return $value;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['haydi_tool_catalog'], $GLOBALS['haydi_tool_catalog_building'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_system_prompt_can_be_filtered_with_context(): void {
		$seen_context = null;

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$args ) use ( &$seen_context ) {
				if ( 'haydi_system_prompt' === $hook ) {
					$seen_context = $args[0];
					return $value . "\n\n## Custom Host Context";
				}

				return $value;
			}
		);

		$prompt = $this->build_system_prompt();

		$this->assertStringContainsString( '## Custom Host Context', $prompt );
		$this->assertIsArray( $seen_context );
		$this->assertArrayHasKey( 'allowed_roots', $seen_context );
		$this->assertArrayHasKey( 'action_proposals', $seen_context );
		$this->assertTrue( $seen_context['can_edit_plugins'] );
		$this->assertTrue( $seen_context['can_edit_themes'] );
		$this->assertSame( 'wp_', $seen_context['db_prefix'] );
	}

	public function test_read_tool_execution_can_be_filtered_with_string_result(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$args ) {
				if ( 'haydi_execute_read_tool' === $hook && 'wccom_get_migration_url' === $args[0] ) {
					return 'https://wordpress.com/setup/hosted-site-migration';
				}

				return $value;
			}
		);

		$result = $this->dispatch_chat_tool( 'wccom_get_migration_url', array() );

		$this->assertSame( 'https://wordpress.com/setup/hosted-site-migration', $result );
	}

	public function test_read_tool_execution_preserves_filtered_array_result(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$args ) {
				if ( 'haydi_execute_read_tool' === $hook && 'wccom_get_migration_url' === $args[0] ) {
					return array( 'url' => 'https://wordpress.com/setup/hosted-site-migration' );
				}

				return $value;
			}
		);

		$result = $this->dispatch_chat_tool( 'wccom_get_migration_url', array() );

		$this->assertSame(
			array( 'url' => 'https://wordpress.com/setup/hosted-site-migration' ),
			$result
		);
	}

	public function test_read_tool_execution_returns_wp_error_message(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$args ) {
				if ( 'haydi_execute_read_tool' === $hook && 'broken_tool' === $args[0] ) {
					return new WP_Error( 'broken_tool', 'Tool failed.' );
				}

				return $value;
			}
		);

		$result = $this->dispatch_chat_tool( 'broken_tool', array() );

		$this->assertSame( 'Error: Tool failed.', $result );
	}

	private function build_system_prompt(): string {
		$ref     = new \ReflectionClass( Haydi_Ajax_Handlers::class );
		$handler = $ref->newInstanceWithoutConstructor();
		$guard   = $ref->getProperty( 'guard' );
		$method  = $ref->getMethod( 'build_system_prompt' );

		$guard->setAccessible( true );
		$method->setAccessible( true );
		$guard->setValue( $handler, new Haydi_Filesystem_Guard() );

		return $method->invoke( $handler );
	}

	private function dispatch_chat_tool( string $name, array $input ): mixed {
		$catalog = new Haydi_Tool_Catalog();
		$outcome = $catalog->dispatch( Haydi_Tool_Catalog::CHAT, $name, $input );

		return is_wp_error( $outcome )
			? 'Error: ' . $outcome->get_error_message()
			: $outcome['result'];
	}
}
