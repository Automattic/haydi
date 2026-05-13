<?php
/**
 * Unit tests for Haydi_Jetpack_Context — Jetpack NOT installed.
 *
 * Each test runs in a separate PHP process (via the attribute below) so that
 * the Jetpack stub classes defined in JetpackContextTest are NOT present.
 * This lets us verify that is_available() returns false and that every
 * public method degrades gracefully to null / empty string / empty array.
 */

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

#[RunTestsInSeparateProcesses]
class JetpackContextUnavailableTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'set_transient' )->justReturn( true );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_is_available_returns_false_without_jetpack(): void {
        $this->assertFalse( Haydi_Jetpack_Context::is_available() );
    }

    public function test_is_connected_returns_false_without_jetpack(): void {
        $this->assertFalse( Haydi_Jetpack_Context::is_connected() );
    }

    public function test_collect_returns_empty_array_without_jetpack(): void {
        $this->assertSame( [], ( new Haydi_Jetpack_Context() )->collect() );
    }

    public function test_to_prompt_section_returns_empty_string_without_jetpack(): void {
        $this->assertSame( '', ( new Haydi_Jetpack_Context() )->to_prompt_section() );
    }

    public function test_get_wpcom_blog_id_returns_null_without_jetpack(): void {
        $this->assertNull( ( new Haydi_Jetpack_Context() )->get_wpcom_blog_id() );
    }

    public function test_get_wpcom_user_id_returns_null_without_jetpack(): void {
        $this->assertNull( Haydi_Jetpack_Context::get_wpcom_user_id( 1 ) );
    }
}
