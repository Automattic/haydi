<?php
/**
 * Unit tests for Haydi_Fetch_Url_Tool::resolve_and_validate_host().
 *
 * Tests the host-resolution + IP-validation layer that guards fetch().
 * Uses ReflectionMethod to access the private method.
 */

use PHPUnit\Framework\TestCase;

class CheckHostForSsrfTest extends TestCase {

    private static \ReflectionMethod $method;
    private static \Haydi_Fetch_Url_Tool $handler;

    public static function setUpBeforeClass(): void {
        $ref           = new \ReflectionClass( Haydi_Fetch_Url_Tool::class );
        self::$handler = $ref->newInstanceWithoutConstructor();
        self::$method  = $ref->getMethod( 'resolve_and_validate_host' );
    }

    private function check( string $host ) {
        return self::$method->invoke( self::$handler, $host );
    }

    // -----------------------------------------------------------------------
    // Bare IP address — DNS resolution is skipped
    // -----------------------------------------------------------------------

    public function test_bare_loopback_ip_is_blocked(): void {
        $result = $this->check( '127.0.0.1' );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'ssrf_blocked', $result->get_error_code() );
    }

    public function test_bare_rfc1918_ip_is_blocked(): void {
        $result = $this->check( '192.168.1.1' );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'ssrf_blocked', $result->get_error_code() );
    }

    public function test_bare_public_ip_is_allowed(): void {
        $result = $this->check( '8.8.8.8' );
        $this->assertSame( array( '8.8.8.8' ), $result );
    }

    // -----------------------------------------------------------------------
    // Bracketed IPv6 (as it appears in a URL host component)
    // -----------------------------------------------------------------------

    public function test_bracketed_ipv6_loopback_is_blocked(): void {
        $result = $this->check( '[::1]' );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'ssrf_blocked', $result->get_error_code() );
    }

    // -----------------------------------------------------------------------
    // DNS resolution failure
    // -----------------------------------------------------------------------

    public function test_unresolvable_hostname_returns_dns_failure(): void {
        // This domain is guaranteed not to exist (.invalid TLD is reserved by RFC 2606).
        $result = $this->check( 'haydi-test.invalid' );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertEquals( 'dns_failure', $result->get_error_code() );
    }
}
