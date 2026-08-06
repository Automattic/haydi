<?php
/**
 * Unit tests for Haydi_Rest_Api::handle_mcp() and check_permission().
 *
 * Strategy
 * --------
 * - newInstanceWithoutConstructor() builds the class without calling __construct
 *   (which would register WordPress hooks and instantiate dependencies).
 * - The Tool Catalog used by handle_mcp() is injected via reflection.
 * - WP_REST_Request / WP_REST_Response are lightweight stubs defined in bootstrap.
 * - check_permission() is exercised via reflection (the method is public, but we
 *   inject a real Haydi_Api_Token_Manager backed by an in-memory option store).
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class RestApiMcpTest extends TestCase {

	/** @var \ReflectionClass<Haydi_Rest_Api> */
	private \ReflectionClass $ref;

	/** @var Haydi_Rest_Api */
	private Haydi_Rest_Api $api;

	/** In-memory options store shared with Haydi_Api_Token_Manager. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();
		$store         = &$this->options;

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$store ) {
				return $store[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( '__' )->alias( static function ( string $text ) { return $text; } );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_is_file_mod_allowed' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value, ...$args ) {
				unset( $hook, $args );
				return $value;
			}
		);

		// Build the REST API object without invoking __construct.
		$this->ref = new \ReflectionClass( Haydi_Rest_Api::class );
		$this->api = $this->ref->newInstanceWithoutConstructor();

		// handle_mcp() now delegates declarations and execution to this catalog.
		$this->injectProperty( 'tool_catalog', Haydi_Tool_Catalog::create_default() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function injectProperty( string $name, mixed $value ): void {
		$prop = $this->ref->getProperty( $name );
		$prop->setValue( $this->api, $value );
	}

	/** Build a WP_REST_Request pre-populated with JSON-RPC body. */
	private function buildRequest( array $body ): WP_REST_Request {
		$req = new WP_REST_Request();
		$req->set_json_params( $body );
		return $req;
	}

	/** Invoke handle_mcp() and return the WP_REST_Response. */
	private function callMcp( array $body ): WP_REST_Response {
		return $this->api->handle_mcp( $this->buildRequest( $body ) );
	}

	// -------------------------------------------------------------------------
	// handle_mcp() — protocol-level error cases
	// -------------------------------------------------------------------------

	public function test_non_array_body_returns_parse_error(): void {
		// The bootstrap stub declares get_json_params(): mixed so we can extend
		// it and return null, which simulates a failed JSON parse (real WP would
		// return null from json_decode on invalid input).
		$fakeReq = new class extends WP_REST_Request { // phpcs:ignore
			public function get_json_params(): mixed { return null; } // phpcs:ignore
		};

		/** @var WP_REST_Response $resp */
		$resp = $this->api->handle_mcp( $fakeReq );
		$data = $resp->get_data();

		$this->assertSame( -32700, $data['error']['code'] );
	}

	public function test_wrong_jsonrpc_version_returns_invalid_request(): void {
		$resp = $this->callMcp( array( 'jsonrpc' => '1.0', 'method' => 'ping', 'id' => 1 ) );
		$data = $resp->get_data();

		$this->assertSame( -32600, $data['error']['code'] );
	}

	// -------------------------------------------------------------------------
	// handle_mcp() — method dispatch
	// -------------------------------------------------------------------------

	public function test_initialize_returns_protocol_version_and_capabilities(): void {
		$resp = $this->callMcp( array( 'jsonrpc' => '2.0', 'method' => 'initialize', 'id' => 1 ) );
		$data = $resp->get_data();

		$this->assertSame( '2024-11-05', $data['result']['protocolVersion'] );
		$this->assertArrayHasKey( 'tools', $data['result']['capabilities'] );
		$this->assertSame( 'haydi', $data['result']['serverInfo']['name'] );
	}

	public function test_ping_returns_result(): void {
		$resp = $this->callMcp( array( 'jsonrpc' => '2.0', 'method' => 'ping', 'id' => 1 ) );
		$data = $resp->get_data();

		$this->assertArrayHasKey( 'result', $data );
	}

	public function test_tools_list_returns_non_empty_array_with_required_tools(): void {
		$resp  = $this->callMcp( array( 'jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 1 ) );
		$data  = $resp->get_data();
		$tools = $data['result']['tools'];

		$this->assertIsArray( $tools );
		$this->assertNotEmpty( $tools );

		$names = array_column( $tools, 'name' );
		foreach ( array( 'haydi_list_files', 'haydi_read_file', 'haydi_list_plugins', 'haydi_list_posts', 'haydi_fetch_url' ) as $expected ) {
			$this->assertContains( $expected, $names, "Tool '{$expected}' should be in tools/list response" );
		}

		// Each tool must have name, description, and inputSchema.
		foreach ( $tools as $tool ) {
			$this->assertArrayHasKey( 'name',        $tool );
			$this->assertArrayHasKey( 'description', $tool );
			$this->assertArrayHasKey( 'inputSchema', $tool );
		}
	}

	public function test_notification_returns_http_204(): void {
		// Notifications have no 'id' key.
		$resp = $this->callMcp( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) );

		$this->assertSame( 204, $resp->get_status() );
	}

	public function test_unknown_method_returns_method_not_found(): void {
		$resp = $this->callMcp( array( 'jsonrpc' => '2.0', 'method' => 'nonexistent/method', 'id' => 1 ) );
		$data = $resp->get_data();

		$this->assertSame( -32601, $data['error']['code'] );
	}

	public function test_tools_call_unknown_tool_returns_is_error(): void {
		$resp = $this->callMcp( array(
			'jsonrpc' => '2.0',
			'method'  => 'tools/call',
			'id'      => 1,
			'params'  => array( 'name' => 'nonexistent_tool', 'arguments' => array() ),
		) );
		$data = $resp->get_data();

		$this->assertTrue( $data['result']['isError'] );
	}

	// -------------------------------------------------------------------------
	// check_permission()
	// -------------------------------------------------------------------------

	public function test_check_permission_with_valid_bearer_token_returns_true(): void {
		// Generate a real token using the in-memory manager.
		$mgr   = new Haydi_Api_Token_Manager();
		$token = $mgr->generate_token( 'perm-test' );

		$req = new WP_REST_Request();
		$req->set_header( 'Authorization', "Bearer {$token}" );

		$result = $this->api->check_permission( $req );

		$this->assertTrue( $result );
	}

	public function test_check_permission_with_invalid_token_falls_back_to_current_user_can(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$req = new WP_REST_Request();
		$req->set_header( 'Authorization', 'Bearer ' . str_repeat( 'a', 64 ) );

		$result = $this->api->check_permission( $req );

		$this->assertFalse( $result );
	}

	public function test_check_permission_with_no_auth_header_uses_current_user_can(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$req    = new WP_REST_Request();
		$result = $this->api->check_permission( $req );

		$this->assertTrue( $result );
	}

	public function test_check_permission_falls_back_to_server_http_authorization(): void {
		$mgr   = new Haydi_Api_Token_Manager();
		$token = $mgr->generate_token( 'server-fallback' );

		// Simulate Apache+FastCGI stripping the header from getallheaders() but
		// still setting $_SERVER['HTTP_AUTHORIZATION'].
		$_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$token}";

		$req    = new WP_REST_Request(); // no set_header() — header only in $_SERVER
		$result = $this->api->check_permission( $req );

		unset( $_SERVER['HTTP_AUTHORIZATION'] );

		$this->assertTrue( $result );
	}

	public function test_check_permission_falls_back_to_redirect_http_authorization(): void {
		$mgr   = new Haydi_Api_Token_Manager();
		$token = $mgr->generate_token( 'redirect-fallback' );

		// Simulate Apache mod_rewrite renaming the header after a URL rewrite.
		$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = "Bearer {$token}";

		$req    = new WP_REST_Request();
		$result = $this->api->check_permission( $req );

		unset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );

		$this->assertTrue( $result );
	}
}
