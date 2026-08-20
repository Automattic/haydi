<?php
/**
 * Unit tests for Haydi_Fetch_Url_Tool::fetch() — the validation paths
 * that fire before any outbound HTTP request is made.
 *
 * Brain Monkey stubs wp_parse_url (the only WP function called in the tested
 * paths); resolve_and_validate_host() is a real call on the same instance so
 * the full validation chain (scheme → SSRF) is exercised together.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class FetchUrlTest extends TestCase {

    private static \ReflectionMethod $method;
    private static \Haydi_Fetch_Url_Tool $handler;

    public static function setUpBeforeClass(): void {
        $ref           = new \ReflectionClass( Haydi_Fetch_Url_Tool::class );
        self::$handler = $ref->newInstanceWithoutConstructor();
        self::$method  = $ref->getMethod( 'fetch' );
    }

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function fetch( string $url ) {
        return self::$method->invoke( self::$handler, $url );
    }

    /**
     * Capture the request-scoped transport hooks installed by fetch().
     *
     * @param array<string,array{callback:callable,priority:int,accepted_args:int}> $actions
     * @param array<int,array{hook:string,callback:callable,priority:int}>           $removed
     */
    private function captureTransportHooks( array &$actions, array &$removed ): void {
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
        Functions\when( 'add_action' )->alias(
            static function ( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ) use ( &$actions ): bool {
                $actions[ $hook ] = array(
                    'callback'      => $callback,
                    'priority'      => $priority,
                    'accepted_args' => $accepted_args,
                );
                return true;
            }
        );
        Functions\when( 'remove_action' )->alias(
            static function ( string $hook, callable $callback, int $priority = 10 ) use ( &$removed ): bool {
                $removed[] = array(
                    'hook'     => $hook,
                    'callback' => $callback,
                    'priority' => $priority,
                );
                return true;
            }
        );
    }

    /**
     * Assert every temporary transport hook was removed with the same callback.
     *
     * @param array<string,array{callback:callable,priority:int,accepted_args:int}> $actions
     * @param array<int,array{hook:string,callback:callable,priority:int}>           $removed
     */
    private function assertTransportHooksRemoved( array $actions, array $removed ): void {
        $this->assertSame(
            array(
                'http_api_curl',
                'requests-fsockopen.before_request',
                'requests-requests.before_request',
            ),
            array_column( $removed, 'hook' )
        );

        foreach ( $removed as $removal ) {
            $this->assertSame( $actions[ $removal['hook'] ]['callback'], $removal['callback'] );
            $this->assertSame( $actions[ $removal['hook'] ]['priority'], $removal['priority'] );
        }
    }

    // -----------------------------------------------------------------------
    // filter_var layer — malformed URLs are rejected before parse
    // -----------------------------------------------------------------------

    public function test_rejects_plaintext_string_as_invalid_url(): void {
        // No Brain Monkey stub needed — filter_var fires before wp_parse_url.
        $result = $this->fetch( 'not-a-url' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'invalid_url', $result->get_error_code() );
    }

    // -----------------------------------------------------------------------
    // Scheme allow-list — only http and https are permitted
    // -----------------------------------------------------------------------

    public function test_rejects_ftp_scheme(): void {
        // ftp://... passes filter_var (valid URL syntax) but must be rejected
        // by the http/https allow-list before any connection is attempted.
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

        $result = $this->fetch( 'ftp://files.example.com/file.txt' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'invalid_scheme', $result->get_error_code() );
        $this->assertStringContainsString( 'ftp', $result->get_error_message() );
    }

    // -----------------------------------------------------------------------
    // Full-chain SSRF check through fetch_url
    // -----------------------------------------------------------------------

    public function test_blocks_request_to_rfc1918_ip(): void {
        // http://... passes filter_var and the scheme check; the SSRF guard
        // (check_host_for_ssrf → is_private_ip) must block it before wp_remote_get.
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

        $result = $this->fetch( 'http://192.168.1.1/' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'ssrf_blocked', $result->get_error_code() );
    }

    public function test_blocks_request_to_loopback_ip(): void {
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

        $result = $this->fetch( 'http://127.0.0.1/wp-config.php' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'ssrf_blocked', $result->get_error_code() );
    }

    public function test_blocks_request_to_aws_metadata_endpoint(): void {
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

        $result = $this->fetch( 'http://169.254.169.254/latest/meta-data/' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'ssrf_blocked', $result->get_error_code() );
    }

    // -----------------------------------------------------------------------
    // Requests transport enforcement — DNS pinning must never fall back
    // -----------------------------------------------------------------------

    public function test_forces_requests_to_curl_and_removes_scoped_hooks(): void {
        $actions          = array();
        $removed          = array();
        $forced_transport = null;
        $this->captureTransportHooks( $actions, $removed );

        Functions\when( 'wp_remote_get' )->alias(
            static function ( string $url ) use ( &$actions, &$forced_transport ): array {
                $request_url = $url;
                $headers     = array();
                $data        = null;
                $type        = 'GET';
                $options     = array();
                $actions['requests-requests.before_request']['callback']( $request_url, $headers, $data, $type, $options );
                $forced_transport = $options['transport'] ?? null;

                $handle = curl_init();
                $actions['http_api_curl']['callback']( $handle, array(), $url );

                return array( 'body' => '<p>Public response</p>' );
            }
        );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->alias(
            static fn( array $response ): string => (string) ( $response['body'] ?? '' )
        );
        Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );

        $result = $this->fetch( 'http://93.184.216.34/reference' );

        $this->assertSame( \WpOrg\Requests\Transport\Curl::class, $forced_transport );
        $this->assertSame( 'Public response', $result );
        $this->assertTransportHooksRemoved( $actions, $removed );
    }

    public function test_fails_closed_on_fsockopen_and_removes_scoped_hooks(): void {
        $actions = array();
        $removed = array();
        $this->captureTransportHooks( $actions, $removed );

        Functions\when( 'wp_remote_get' )->alias(
            static function () use ( &$actions ) {
                $actions['requests-fsockopen.before_request']['callback']();
                return array();
            }
        );

        $result = $this->fetch( 'http://93.184.216.34/reference' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'unpinned_http_transport', $result->get_error_code() );
        $this->assertTransportHooksRemoved( $actions, $removed );
    }

    public function test_fails_closed_when_proxy_would_resolve_origin_and_removes_scoped_hooks(): void {
        $actions = array();
        $removed = array();
        $this->captureTransportHooks( $actions, $removed );

        Functions\when( 'wp_remote_get' )->alias(
            static function ( string $url ) use ( &$actions ): array {
                $request_url = $url;
                $headers     = array();
                $data        = null;
                $type        = 'GET';
                $options     = array( 'proxy' => new stdClass() );
                $actions['requests-requests.before_request']['callback']( $request_url, $headers, $data, $type, $options );
                return array();
            }
        );

        $result = $this->fetch( 'http://93.184.216.34/reference' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'unpinned_http_transport', $result->get_error_code() );
        $this->assertTransportHooksRemoved( $actions, $removed );
    }
}
