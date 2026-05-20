<?php
/**
 * Unit tests for plugin-management and PHP-execution tools.
 *   Haydi_Plugin_Tool::list_plugins_for_ai()
 *   Haydi_Plugin_Tool::handle_install_plugin
 *   Haydi_Plugin_Tool::handle_activate_plugin
 *   Haydi_Plugin_Tool::handle_deactivate_plugin
 *   haydi_php_ext_execute() (extensions/haydi-php.php)
 *
 * WordPress functions are stubbed via Brain\Monkey so no live WordPress is needed.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class AjaxHandlersPluginActionsTest extends TestCase {

	private \ReflectionClass $pluginRef;
	private \Haydi_Plugin_Tool $pluginHandler;
	private \Haydi_Health_Check $health;
	private \Haydi_Audit_Logger $mockLogger;

	/** Captured result of the last wp_send_json_success / wp_send_json_error call. */
	private ?bool $lastSuccess = null;
	private mixed $lastData    = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Stub verify() dependencies so every handler can pass the auth check.
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );

		// Common WP helpers used by post_param() and the handlers.
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// Capture wp_send_json_success / wp_send_json_error and halt handler
		// execution — production wp_die() does the same. Throwing prevents a
		// later code path inside the handler from masking the first error.
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

		// Default healthy loopback response — the post-mutation Health_Check
		// uses these globals on every successful path. Per-test stubs may
		// override to simulate a broken site.
		Functions\when( 'admin_url' )->justReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		Functions\when( 'get_transient' )->justReturn( 'http://127.0.0.1/wp-admin/admin-ajax.php?action=haydi_health' );
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '{"success":true,"data":"ok"}' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":true,"data":"ok"}' );

		// Build plugin tool without invoking constructor (add_action) side-effects.
		$this->pluginRef     = new \ReflectionClass( Haydi_Plugin_Tool::class );
		$this->pluginHandler = $this->pluginRef->newInstanceWithoutConstructor();
		$this->mockLogger    = $this->createMock( Haydi_Audit_Logger::class );
		$this->health        = new Haydi_Health_Check();

		$this->pluginRef->getParentClass()->getProperty( 'logger' )->setValue( $this->pluginHandler, $this->mockLogger );
		$this->pluginRef->getProperty( 'health' )->setValue( $this->pluginHandler, $this->health );

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

	/** Invoke a public handler on a tool instance after injecting $_POST values. */
	private function callHandler( object $handler, string $method, array $post ): void {
		$this->lastSuccess = null;
		$this->lastData    = null;
		$_POST             = array_merge( $post, array( 'nonce' => 'test_nonce' ) );
		try {
			$handler->{$method}();
		} catch ( \HaydiTestHaltException $e ) {
			unset( $e );
		}
	}

	/** Call haydi_php_ext_execute() directly and capture result. */
	private function callPhp( string $code, string $reason = 'test' ): void {
		$this->lastSuccess = null;
		$this->lastData    = null;
		$result            = haydi_php_ext_execute( $code, $reason, $this->mockLogger, $this->health );
		if ( is_wp_error( $result ) ) {
			$data              = $result->get_error_data();
			$this->lastSuccess = false;
			$this->lastData    = array(
				'message' => $result->get_error_message(),
				'output'  => is_array( $data ) ? ( $data['output'] ?? '' ) : '',
			);
		} else {
			$this->lastSuccess = true;
			$this->lastData    = $result;
		}
	}

	// =======================================================================
	// Haydi_Plugin_Tool::list_plugins_for_ai()
	// =======================================================================

	public function test_list_plugins_returns_json_array(): void {
		Functions\when( 'get_plugins' )->justReturn( array(
			'akismet/akismet.php'         => array( 'Name' => 'Akismet',     'Version' => '5.0' ),
			'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce', 'Version' => '8.0' ),
		) );
		Functions\when( 'get_option' )->justReturn( array() );

		$result  = $this->pluginHandler->list_plugins_for_ai();
		$decoded = json_decode( $result, true );

		$this->assertIsArray( $decoded );
		$this->assertCount( 2, $decoded );
	}

	public function test_list_plugins_marks_active_plugin_correctly(): void {
		Functions\when( 'get_plugins' )->justReturn( array(
			'akismet/akismet.php'         => array( 'Name' => 'Akismet',     'Version' => '5.0' ),
			'woocommerce/woocommerce.php' => array( 'Name' => 'WooCommerce', 'Version' => '8.0' ),
		) );
		Functions\when( 'get_option' )->justReturn( array( 'woocommerce/woocommerce.php' ) );

		$result  = $this->pluginHandler->list_plugins_for_ai();
		$decoded = json_decode( $result, true );
		$byFile  = array_column( $decoded, null, 'file' );

		$this->assertFalse( $byFile['akismet/akismet.php']['active'] );
		$this->assertTrue( $byFile['woocommerce/woocommerce.php']['active'] );
	}

	public function test_list_plugins_returns_empty_array_when_no_plugins(): void {
		Functions\when( 'get_plugins' )->justReturn( array() );
		Functions\when( 'get_option' )->justReturn( array() );

		$result  = $this->pluginHandler->list_plugins_for_ai();
		$decoded = json_decode( $result, true );

		$this->assertSame( array(), $decoded );
	}

	public function test_list_plugins_includes_file_name_and_version(): void {
		Functions\when( 'get_plugins' )->justReturn( array(
			'hello-dolly/hello.php' => array( 'Name' => 'Hello Dolly', 'Version' => '1.7.2' ),
		) );
		Functions\when( 'get_option' )->justReturn( array() );

		$result  = $this->pluginHandler->list_plugins_for_ai();
		$decoded = json_decode( $result, true );

		$this->assertSame( 'hello-dolly/hello.php', $decoded[0]['file'] );
		$this->assertSame( 'Hello Dolly',           $decoded[0]['name'] );
		$this->assertSame( '1.7.2',                 $decoded[0]['version'] );
		$this->assertFalse( $decoded[0]['active'] );
	}

	public function test_list_plugins_all_active_when_all_in_active_option(): void {
		Functions\when( 'get_plugins' )->justReturn( array(
			'plugin-a/plugin-a.php' => array( 'Name' => 'Plugin A', 'Version' => '1.0' ),
			'plugin-b/plugin-b.php' => array( 'Name' => 'Plugin B', 'Version' => '2.0' ),
		) );
		Functions\when( 'get_option' )->justReturn( array( 'plugin-a/plugin-a.php', 'plugin-b/plugin-b.php' ) );

		$result  = $this->pluginHandler->list_plugins_for_ai();
		$decoded = json_decode( $result, true );

		foreach ( $decoded as $plugin ) {
			$this->assertTrue( $plugin['active'], "Plugin '{$plugin['file']}' should be active" );
		}
	}

	// =======================================================================
	// haydi_php_ext_execute()
	// =======================================================================

	public function test_run_php_rejects_empty_code(): void {
		$this->callPhp( '' );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'required', $this->lastData['message'] );
	}

	public function test_run_php_rejects_whitespace_only_code(): void {
		$this->callPhp( "   \n\t  " );

		$this->assertFalse( $this->lastSuccess );
	}

	public function test_run_php_captures_echo_output(): void {
		$this->callPhp( "echo 'hello world';", 'test echo' );

		$this->assertTrue( $this->lastSuccess );
		$this->assertSame( 'hello world', $this->lastData['output'] );
	}

	public function test_run_php_captures_multi_line_output(): void {
		$this->callPhp( "echo 'line1' . PHP_EOL . 'line2';", 'multi-line output' );

		$this->assertTrue( $this->lastSuccess );
		$this->assertStringContainsString( 'line1', $this->lastData['output'] );
		$this->assertStringContainsString( 'line2', $this->lastData['output'] );
	}

	public function test_run_php_returns_no_output_placeholder_for_silent_code(): void {
		$this->callPhp( '$x = 1 + 1;', 'silent computation' );

		$this->assertTrue( $this->lastSuccess );
		$this->assertSame( '(no output)', $this->lastData['output'] );
	}

	public function test_run_php_catches_thrown_exception_and_reports_error(): void {
		$this->callPhp( "throw new \\RuntimeException('boom');", 'test exception' );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'boom', $this->lastData['message'] );
	}

	public function test_run_php_catches_error_class_and_reports_it(): void {
		$this->callPhp( "throw new \\Error('fatal error');", 'test error class' );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'fatal error', $this->lastData['message'] );
	}

	public function test_run_php_captures_output_produced_before_exception(): void {
		$this->callPhp( "echo 'before'; throw new \\RuntimeException('oops');", 'output before throw' );

		$this->assertFalse( $this->lastSuccess );
		$this->assertSame( 'before', $this->lastData['output'] );
		$this->assertStringContainsString( 'oops', $this->lastData['message'] );
	}

	// =======================================================================
	// handle_install_plugin — input validation
	// =======================================================================

	public function test_install_plugin_rejects_empty_slug(): void {
		$this->callHandler( $this->pluginHandler, 'handle_install_plugin', array( 'slug' => '', 'reason' => 'test' ) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'required', $this->lastData['message'] );
	}

	public function test_install_plugin_rejects_slug_with_uppercase(): void {
		$this->callHandler( $this->pluginHandler, 'handle_install_plugin', array( 'slug' => 'WooCommerce', 'reason' => 'test' ) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'slug', $this->lastData['message'] );
	}

	public function test_install_plugin_rejects_slug_with_path_traversal(): void {
		$this->callHandler( $this->pluginHandler, 'handle_install_plugin', array( 'slug' => '../evil', 'reason' => 'test' ) );

		$this->assertFalse( $this->lastSuccess );
	}

	public function test_install_plugin_rejects_slug_with_slash(): void {
		$this->callHandler( $this->pluginHandler, 'handle_install_plugin', array( 'slug' => 'some/thing', 'reason' => 'test' ) );

		$this->assertFalse( $this->lastSuccess );
	}

	public function test_install_plugin_rejects_slug_with_spaces(): void {
		$this->callHandler( $this->pluginHandler, 'handle_install_plugin', array( 'slug' => 'my plugin', 'reason' => 'test' ) );

		$this->assertFalse( $this->lastSuccess );
	}

	/**
	 * The regex guard in handle_install_plugin must accept well-formed slugs.
	 * We test the pattern directly because the handler can't run past the
	 * require_once lines without a live WordPress environment.
	 */
	public function test_install_plugin_slug_regex_accepts_valid_slugs(): void {
		$valid = array( 'woocommerce', 'hello-dolly', 'akismet', 'wp-seo', 'plugin123', 'a', '0plugin' );
		foreach ( $valid as $slug ) {
			$this->assertSame(
				1,
				preg_match( '/^[a-z0-9][a-z0-9\-]*$/', $slug ),
				"Slug '{$slug}' should pass the validation regex"
			);
		}
	}

	public function test_install_plugin_slug_regex_rejects_invalid_slugs(): void {
		$invalid = array( '', 'WooCommerce', '../evil', 'some/thing', 'my plugin', '-leading-hyphen' );
		foreach ( $invalid as $slug ) {
			$this->assertSame(
				0,
				preg_match( '/^[a-z0-9][a-z0-9\-]*$/', $slug ),
				"Slug '{$slug}' should fail the validation regex"
			);
		}
	}

	// =======================================================================
	// handle_activate_plugin — input validation
	// =======================================================================

	public function test_activate_plugin_rejects_empty_plugin(): void {
		$this->callHandler( $this->pluginHandler, 'handle_activate_plugin', array( 'plugin' => '', 'reason' => 'test' ) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'required', $this->lastData['message'] );
	}

	public function test_activate_plugin_rejects_path_traversal(): void {
		$this->callHandler( $this->pluginHandler, 'handle_activate_plugin', array(
			'plugin' => '../../../wp-config.php',
			'reason' => 'test',
		) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'Invalid', $this->lastData['message'] );
	}

	public function test_activate_plugin_rejects_non_php_extension(): void {
		$this->callHandler( $this->pluginHandler, 'handle_activate_plugin', array(
			'plugin' => 'woocommerce/woocommerce.js',
			'reason' => 'test',
		) );

		$this->assertFalse( $this->lastSuccess );
	}

	public function test_activate_plugin_rejects_double_dot_in_name(): void {
		$this->callHandler( $this->pluginHandler, 'handle_activate_plugin', array(
			'plugin' => 'plugin..name/file.php',
			'reason' => 'test',
		) );

		$this->assertFalse( $this->lastSuccess );
	}

	public function test_activate_plugin_rejects_nonexistent_file(): void {
		// Stub activate_plugin so function_exists() returns true and the
		// require_once for plugin.php is skipped.
		Functions\when( 'activate_plugin' )->justReturn( null );

		$this->callHandler( $this->pluginHandler, 'handle_activate_plugin', array(
			'plugin' => 'nonexistent/plugin.php',
			'reason' => 'test',
		) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'not found', $this->lastData['message'] );
	}

	public function test_activate_plugin_succeeds_when_file_exists(): void {
		// Create a real plugin file in the test WP_PLUGIN_DIR.
		$plugin_dir  = WP_PLUGIN_DIR . '/fake-plugin';
		$plugin_file = $plugin_dir . '/fake-plugin.php';
		if ( ! is_dir( $plugin_dir ) ) {
			mkdir( $plugin_dir, 0755, true );
		}
		file_put_contents( $plugin_file, '<?php // fake plugin' );

		Functions\when( 'activate_plugin' )->justReturn( null ); // null = success in WP

		$this->callHandler( $this->pluginHandler, 'handle_activate_plugin', array(
			'plugin' => 'fake-plugin/fake-plugin.php',
			'reason' => 'test activation',
		) );

		// Cleanup.
		unlink( $plugin_file );
		rmdir( $plugin_dir );

		$this->assertTrue( $this->lastSuccess );
		$this->assertStringContainsString( 'activated', $this->lastData['message'] );
	}

	public function test_activate_plugin_auto_deactivates_on_unhealthy_post_check(): void {
		// Real plugin file required so handle_activate_plugin reaches the
		// activate_plugin() call before the file_exists guard.
		$plugin_dir  = WP_PLUGIN_DIR . '/break-plugin';
		$plugin_file = $plugin_dir . '/break-plugin.php';
		if ( ! is_dir( $plugin_dir ) ) {
			mkdir( $plugin_dir, 0755, true );
		}
		file_put_contents( $plugin_file, '<?php // simulates a plugin that fatals on init' );

		Functions\when( 'activate_plugin' )->justReturn( null );

		// Simulate a fatal on the next request — this is the scenario where
		// WP's own plugin_sandbox_scrape passes but later hooks crash the site.
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$deactivateCalledWith = null;
		Functions\when( 'deactivate_plugins' )->alias( function ( $plugin ) use ( &$deactivateCalledWith ) {
			$deactivateCalledWith = $plugin;
		} );

		$this->callHandler( $this->pluginHandler, 'handle_activate_plugin', array(
			'plugin' => 'break-plugin/break-plugin.php',
			'reason' => 'test auto-revert',
		) );

		unlink( $plugin_file );
		rmdir( $plugin_dir );

		$this->assertSame(
			'break-plugin/break-plugin.php',
			$deactivateCalledWith,
			'deactivate_plugins() must be called with the plugin we just activated'
		);
		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString(
			'Plugin activation (break-plugin/break-plugin.php) caused a site error',
			$this->lastData['message']
		);
	}

	// =======================================================================
	// handle_deactivate_plugin — input validation and success path
	// =======================================================================

	public function test_deactivate_plugin_rejects_empty_plugin(): void {
		$this->callHandler( $this->pluginHandler, 'handle_deactivate_plugin', array( 'plugin' => '', 'reason' => 'test' ) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( 'required', $this->lastData['message'] );
	}

	public function test_deactivate_plugin_calls_deactivate_plugins(): void {
		$called = false;
		Functions\when( 'deactivate_plugins' )->alias( function () use ( &$called ) {
			$called = true;
		} );

		$this->callHandler( $this->pluginHandler, 'handle_deactivate_plugin', array(
			'plugin' => 'woocommerce/woocommerce.php',
			'reason' => 'no longer needed',
		) );

		$this->assertTrue( $called, 'deactivate_plugins() should have been called' );
		$this->assertTrue( $this->lastSuccess );
		$this->assertStringContainsString( 'deactivated', $this->lastData['message'] );
	}

	public function test_deactivate_plugin_returns_plugin_name_in_response(): void {
		Functions\when( 'deactivate_plugins' )->justReturn( null );

		$this->callHandler( $this->pluginHandler, 'handle_deactivate_plugin', array(
			'plugin' => 'hello-dolly/hello.php',
			'reason' => 'test',
		) );

		$this->assertTrue( $this->lastSuccess );
		$this->assertSame( 'hello-dolly/hello.php', $this->lastData['plugin'] );
	}
}
