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
}
