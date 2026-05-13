<?php
/**
 * Unit tests for Haydi_Fetch_Url_Tool::is_private_ip().
 *
 * The method is private, so it is accessed via ReflectionMethod.
 * The fetcher uses only PHP built-ins so no live WordPress is required.
 */

use PHPUnit\Framework\TestCase;

class IsPrivateIpTest extends TestCase {

    private static \ReflectionMethod $method;
    private static \Haydi_Fetch_Url_Tool $handler;

    public static function setUpBeforeClass(): void {
        $ref           = new \ReflectionClass( Haydi_Fetch_Url_Tool::class );
        self::$handler = $ref->newInstanceWithoutConstructor();
        self::$method  = $ref->getMethod( 'is_private_ip' );
    }

    private function isPrivate( string $ip ): bool {
        return self::$method->invoke( self::$handler, $ip );
    }

    // -----------------------------------------------------------------------
    // IPv4 loopback
    // -----------------------------------------------------------------------

    public function test_ipv4_loopback(): void {
        $this->assertTrue( $this->isPrivate( '127.0.0.1' ) );
        $this->assertTrue( $this->isPrivate( '127.255.255.255' ) );
    }

    // -----------------------------------------------------------------------
    // RFC-1918 private ranges
    // -----------------------------------------------------------------------

    public function test_ipv4_rfc1918_class_a(): void {
        $this->assertTrue( $this->isPrivate( '10.0.0.1' ) );
        $this->assertTrue( $this->isPrivate( '10.255.255.255' ) );
    }

    public function test_ipv4_rfc1918_class_b(): void {
        $this->assertTrue( $this->isPrivate( '172.16.0.1' ) );
        $this->assertTrue( $this->isPrivate( '172.31.255.255' ) );
    }

    public function test_ipv4_rfc1918_class_c(): void {
        $this->assertTrue( $this->isPrivate( '192.168.0.1' ) );
        $this->assertTrue( $this->isPrivate( '192.168.255.255' ) );
    }

    // -----------------------------------------------------------------------
    // IPv4 link-local (APIPA) — includes AWS instance-metadata address
    // -----------------------------------------------------------------------

    public function test_ipv4_link_local(): void {
        $this->assertTrue( $this->isPrivate( '169.254.0.1' ) );
        $this->assertTrue( $this->isPrivate( '169.254.169.254' ) ); // AWS metadata endpoint.
    }

    // -----------------------------------------------------------------------
    // Public IPv4 addresses — must NOT be blocked
    // -----------------------------------------------------------------------

    public function test_ipv4_public_allowed(): void {
        $this->assertFalse( $this->isPrivate( '8.8.8.8' ) );   // Google DNS
        $this->assertFalse( $this->isPrivate( '1.1.1.1' ) );   // Cloudflare DNS
        $this->assertFalse( $this->isPrivate( '93.184.216.34' ) ); // example.com
    }

    // -----------------------------------------------------------------------
    // IPv6 loopback
    // -----------------------------------------------------------------------

    public function test_ipv6_loopback(): void {
        $this->assertTrue( $this->isPrivate( '::1' ) );
    }

    // -----------------------------------------------------------------------
    // IPv6 ULA (fc00::/7 — analogous to RFC-1918)
    // -----------------------------------------------------------------------

    public function test_ipv6_ula_fc_prefix(): void {
        $this->assertTrue( $this->isPrivate( 'fc00::1' ) );
    }

    public function test_ipv6_ula_fd_prefix(): void {
        $this->assertTrue( $this->isPrivate( 'fd00::1' ) );
    }

    // -----------------------------------------------------------------------
    // IPv4-mapped IPv6 addresses (::ffff:0:0/96) — could alias private IPv4
    // -----------------------------------------------------------------------

    public function test_ipv4_mapped_private(): void {
        $this->assertTrue( $this->isPrivate( '::ffff:192.168.1.1' ) );
        $this->assertTrue( $this->isPrivate( '::ffff:127.0.0.1' ) );
    }

    // -----------------------------------------------------------------------
    // IPv6 link-local (fe80::/10)
    // -----------------------------------------------------------------------

    public function test_ipv6_link_local(): void {
        $this->assertTrue( $this->isPrivate( 'fe80::1' ) );
    }

    // -----------------------------------------------------------------------
    // Public IPv6 addresses — must NOT be blocked
    // -----------------------------------------------------------------------

    public function test_ipv6_public_allowed(): void {
        $this->assertFalse( $this->isPrivate( '2606:4700:4700::1111' ) ); // Cloudflare DNS
        $this->assertFalse( $this->isPrivate( '2001:4860:4860::8888' ) ); // Google DNS
    }
}
