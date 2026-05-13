<?php
/**
 * Unit tests for Haydi_Jetpack_Context — Jetpack present.
 *
 * Jetpack stub classes are loaded at the top of this file so all tests run
 * in an environment where Jetpack appears to be installed. Each test resets
 * the stubs' static state in setUp() to ensure isolation.
 */

require_once __DIR__ . '/stubs/JetpackStubs.php';

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class JetpackContextTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Reset all stub state so tests start from a clean slate.
        \Jetpack_Options::reset();
        \Jetpack::reset();
        \Automattic\Jetpack\Current_Plan::reset();
        \Automattic\Jetpack\Stats\WPCOM_Stats::reset();
        \Automattic\Jetpack\Boost_Speed_Score\Speed_Score_History::reset();

        // Default WP function stubs used across multiple tests.
        Functions\when( 'get_option' )->justReturn( false );
        Functions\when( 'get_user_meta' )->justReturn( '' );
        Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => number_format( (float) $n ) );
        Functions\when( 'get_site_url' )->justReturn( 'https://example.com' );
        // Bypass the prompt-section transient cache so each test sees the
        // freshly-rendered output rather than a previous test's result.
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'set_transient' )->justReturn( true );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // is_available() — stubs are loaded so this must return true
    // -------------------------------------------------------------------------

    public function test_is_available_returns_true_when_jetpack_classes_exist(): void {
        $this->assertTrue( Haydi_Jetpack_Context::is_available() );
    }

    // -------------------------------------------------------------------------
    // is_connected()
    // -------------------------------------------------------------------------

    public function test_is_connected_returns_false_when_no_blog_id(): void {
        \Jetpack_Options::$options = [];
        $this->assertFalse( Haydi_Jetpack_Context::is_connected() );
    }

    public function test_is_connected_returns_true_when_blog_id_set(): void {
        \Jetpack_Options::$options = [ 'id' => 42 ];
        $this->assertTrue( Haydi_Jetpack_Context::is_connected() );
    }

    // -------------------------------------------------------------------------
    // get_wpcom_blog_id()
    // -------------------------------------------------------------------------

    public function test_get_wpcom_blog_id_returns_null_when_no_option(): void {
        \Jetpack_Options::$options = [];
        $ctx = new Haydi_Jetpack_Context();
        $this->assertNull( $ctx->get_wpcom_blog_id() );
    }

    public function test_get_wpcom_blog_id_returns_integer_when_option_set(): void {
        \Jetpack_Options::$options = [ 'id' => '99' ];
        $ctx = new Haydi_Jetpack_Context();
        $this->assertSame( 99, $ctx->get_wpcom_blog_id() );
    }

    // -------------------------------------------------------------------------
    // get_wpcom_user_id()
    // -------------------------------------------------------------------------

    public function test_get_wpcom_user_id_returns_null_when_meta_absent(): void {
        Functions\when( 'get_user_meta' )->justReturn( '' );
        $this->assertNull( Haydi_Jetpack_Context::get_wpcom_user_id( 1 ) );
    }

    public function test_get_wpcom_user_id_returns_integer_when_meta_set(): void {
        Functions\when( 'get_user_meta' )->justReturn( '77' );
        $this->assertSame( 77, Haydi_Jetpack_Context::get_wpcom_user_id( 1 ) );
    }

    // -------------------------------------------------------------------------
    // collect() — happy path
    // -------------------------------------------------------------------------

    public function test_collect_returns_site_id_when_option_set(): void {
        \Jetpack_Options::$options = [ 'id' => 123 ];
        $ctx = $this->make_context()->collect();
        $this->assertSame( 123, $ctx['site_id'] );
    }

    public function test_collect_includes_plan_from_current_plan_class(): void {
        \Automattic\Jetpack\Current_Plan::$data = [
            'class'        => 'business',
            'product_slug' => 'jetpack_business',
        ];
        $ctx = $this->make_context()->collect();
        $this->assertSame( 'business',         $ctx['plan']['class'] );
        $this->assertSame( 'jetpack_business', $ctx['plan']['product_slug'] );
    }

    public function test_collect_falls_back_to_jetpack_active_plan_option(): void {
        // Current_Plan returns empty; wp option has the plan.
        \Automattic\Jetpack\Current_Plan::$data = [];
        Functions\when( 'get_option' )->alias( static function ( $key ) {
            if ( 'jetpack_active_plan' === $key ) {
                return [ 'class' => 'free', 'product_slug' => 'jetpack_free' ];
            }
            return false;
        } );
        $ctx = $this->make_context()->collect();
        $this->assertSame( 'free',         $ctx['plan']['class'] );
        $this->assertSame( 'jetpack_free', $ctx['plan']['product_slug'] );
    }

    public function test_collect_includes_active_modules_from_jetpack_options(): void {
        \Jetpack_Options::$options = [ 'active_modules' => [ 'stats', 'protect', 'sso' ] ];
        $ctx = $this->make_context()->collect();
        $this->assertSame( [ 'stats', 'protect', 'sso' ], $ctx['active_modules'] );
    }

    public function test_collect_includes_active_modules_from_jetpack_class_when_options_absent(): void {
        \Jetpack::$modules = [ 'publicize', 'related-posts' ];
        $ctx = $this->make_context()->collect();
        $this->assertSame( [ 'publicize', 'related-posts' ], $ctx['active_modules'] );
    }

    public function test_collect_includes_stats_summary(): void {
        \Automattic\Jetpack\Stats\WPCOM_Stats::$stats_summary = [
            'views'    => 1000,
            'visitors' => 500,
            'likes'    => 20,
            'comments' => 10,
        ];
        $ctx = $this->make_context()->collect();
        $this->assertSame( 1000, $ctx['stats']['views'] );
        $this->assertSame( 500,  $ctx['stats']['visitors'] );
        $this->assertSame( 20,   $ctx['stats']['likes'] );
        $this->assertSame( 10,   $ctx['stats']['comments'] );
    }

    public function test_collect_includes_top_posts(): void {
        \Automattic\Jetpack\Stats\WPCOM_Stats::$top_posts = [
            'days' => [
                '2025-01-01' => [
                    'postviews' => [
                        [ 'title' => 'Hello World', 'views' => 300 ],
                        [ 'title' => 'About',       'views' => 150 ],
                    ],
                ],
            ],
        ];
        $ctx = $this->make_context()->collect();
        $this->assertCount( 2, $ctx['top_posts'] );
        $this->assertSame( 'Hello World', $ctx['top_posts'][0]['title'] );
        $this->assertSame( 300,           $ctx['top_posts'][0]['views'] );
    }

    public function test_collect_limits_top_posts_to_five(): void {
        $postviews = [];
        for ( $i = 1; $i <= 10; $i++ ) {
            $postviews[] = [ 'title' => "Post {$i}", 'views' => $i * 10 ];
        }
        \Automattic\Jetpack\Stats\WPCOM_Stats::$top_posts = [
            'days' => [ '2025-01-01' => [ 'postviews' => $postviews ] ],
        ];
        $ctx = $this->make_context()->collect();
        $this->assertCount( 5, $ctx['top_posts'] );
    }

    public function test_collect_includes_top_referrers(): void {
        \Automattic\Jetpack\Stats\WPCOM_Stats::$referrers = [
            'days' => [
                '2025-01-01' => [
                    'groups' => [
                        [ 'name' => 'Search Engines', 'total' => 800 ],
                        [ 'name' => 'WordPress.com',  'total' => 120 ],
                    ],
                ],
            ],
        ];
        $ctx = $this->make_context()->collect();
        $this->assertCount( 2, $ctx['top_referrers'] );
        $this->assertSame( 'Search Engines', $ctx['top_referrers'][0]['name'] );
    }

    public function test_collect_includes_search_terms(): void {
        \Automattic\Jetpack\Stats\WPCOM_Stats::$search_terms = [
            'days' => [
                '2025-01-01' => [
                    'search_terms' => [
                        [ 'term' => 'wordpress tutorial', 'views' => 55 ],
                        [ 'term' => 'php hooks',          'views' => 30 ],
                    ],
                ],
            ],
        ];
        $ctx = $this->make_context()->collect();
        $this->assertSame( 'wordpress tutorial', $ctx['search_terms'][0]['term'] );
        $this->assertSame( 30,                   $ctx['search_terms'][1]['views'] );
    }

    public function test_collect_includes_social_connections(): void {
        \Jetpack_Options::$options = [
            'publicize_connections' => [
                'twitter'  => [ 'token' => 'x' ],
                'linkedin' => [ 'token' => 'y' ],
            ],
        ];
        $ctx = $this->make_context()->collect();
        $this->assertContains( 'twitter',  $ctx['social_connections'] );
        $this->assertContains( 'linkedin', $ctx['social_connections'] );
    }

    public function test_collect_includes_blocked_attempts(): void {
        Functions\when( 'get_option' )->alias( static function ( $key ) {
            return 'jetpack_protect_blocked_attempts' === $key ? 5000 : false;
        } );
        $ctx = $this->make_context()->collect();
        $this->assertSame( 5000, $ctx['blocked_login_attempts'] );
    }

    public function test_collect_includes_zero_blocked_attempts(): void {
        Functions\when( 'get_option' )->alias( static function ( $key ) {
            return 'jetpack_protect_blocked_attempts' === $key ? 0 : false;
        } );
        $ctx = $this->make_context()->collect();
        $this->assertSame( 0, $ctx['blocked_login_attempts'] );
    }

    public function test_collect_includes_speed_scores(): void {
        \Automattic\Jetpack\Boost_Speed_Score\Speed_Score_History::$scores = [
            'mobile'  => 72,
            'desktop' => 91,
        ];
        $ctx = $this->make_context()->collect();
        $this->assertSame( 72, $ctx['boost_speed_scores']['mobile'] );
        $this->assertSame( 91, $ctx['boost_speed_scores']['desktop'] );
    }

    public function test_collect_includes_sync_health(): void {
        \Jetpack_Options::$options = [ 'sync_health_status' => 'healthy' ];
        $ctx = $this->make_context()->collect();
        $this->assertSame( 'healthy', $ctx['sync_health'] );
    }

    // -------------------------------------------------------------------------
    // collect() — graceful degradation on API errors
    // -------------------------------------------------------------------------

    public function test_collect_omits_stats_when_wpcom_stats_returns_error(): void {
        \Automattic\Jetpack\Stats\WPCOM_Stats::$throw_error = true;
        $ctx = $this->make_context()->collect();
        $this->assertArrayNotHasKey( 'stats',        $ctx );
        $this->assertArrayNotHasKey( 'top_posts',    $ctx );
        $this->assertArrayNotHasKey( 'top_referrers', $ctx );
        $this->assertArrayNotHasKey( 'search_terms', $ctx );
    }

    public function test_collect_omits_stats_when_wpcom_stats_returns_null(): void {
        // All WPCOM_Stats properties stay null (reset default).
        $ctx = $this->make_context()->collect();
        $this->assertArrayNotHasKey( 'stats',     $ctx );
        $this->assertArrayNotHasKey( 'top_posts', $ctx );
    }

    public function test_collect_omits_top_posts_when_days_array_is_empty(): void {
        \Automattic\Jetpack\Stats\WPCOM_Stats::$top_posts = [ 'days' => [] ];
        $ctx = $this->make_context()->collect();
        $this->assertArrayNotHasKey( 'top_posts', $ctx );
    }

    public function test_collect_omits_top_posts_when_postviews_is_empty(): void {
        \Automattic\Jetpack\Stats\WPCOM_Stats::$top_posts = [
            'days' => [ '2025-01-01' => [ 'postviews' => [] ] ],
        ];
        $ctx = $this->make_context()->collect();
        $this->assertArrayNotHasKey( 'top_posts', $ctx );
    }

    public function test_collect_omits_speed_scores_when_latest_scores_returns_null(): void {
        // Speed_Score_History::$scores stays null.
        $ctx = $this->make_context()->collect();
        $this->assertArrayNotHasKey( 'boost_speed_scores', $ctx );
    }

    public function test_collect_omits_plan_when_current_plan_throws(): void {
        // Simulate Current_Plan::get() throwing.
        \Automattic\Jetpack\Current_Plan::$data = null; // returns [], empty → no plan
        \Automattic\Jetpack\Current_Plan::$data = [];
        $ctx = $this->make_context()->collect();
        $this->assertArrayNotHasKey( 'plan', $ctx );
    }

    public function test_collect_omits_social_when_connections_not_array(): void {
        \Jetpack_Options::$options = [ 'publicize_connections' => 'not-an-array' ];
        $ctx = $this->make_context()->collect();
        $this->assertArrayNotHasKey( 'social_connections', $ctx );
    }

    // -------------------------------------------------------------------------
    // to_prompt_section() — formatting
    // -------------------------------------------------------------------------

    public function test_to_prompt_section_returns_empty_string_when_collect_empty(): void {
        // Nothing is set → collect() returns [].
        $this->assertSame( '', ( new Haydi_Jetpack_Context() )->to_prompt_section() );
    }

    public function test_to_prompt_section_starts_with_heading_when_data_present(): void {
        \Jetpack_Options::$options = [ 'id' => 1 ];
        $section = $this->make_context()->to_prompt_section();
        $this->assertStringStartsWith( '## Jetpack Site Intelligence', $section );
    }

    public function test_to_prompt_section_includes_plan(): void {
        \Automattic\Jetpack\Current_Plan::$data = [
            'class'        => 'business',
            'product_slug' => 'jetpack_business',
        ];
        \Jetpack_Options::$options = [ 'id' => 1 ];
        $section = $this->make_context()->to_prompt_section();
        $this->assertStringContainsString( 'business', $section );
        $this->assertStringContainsString( 'jetpack_business', $section );
    }

    public function test_to_prompt_section_includes_active_modules(): void {
        \Jetpack_Options::$options = [
            'id'             => 1,
            'active_modules' => [ 'stats', 'protect' ],
        ];
        $section = $this->make_context()->to_prompt_section();
        $this->assertStringContainsString( 'stats, protect', $section );
    }

    public function test_to_prompt_section_includes_traffic_stats(): void {
        \Jetpack_Options::$options = [ 'id' => 1 ];
        \Automattic\Jetpack\Stats\WPCOM_Stats::$stats_summary = [
            'views' => 1200, 'visitors' => 600, 'likes' => 30, 'comments' => 5,
        ];
        $section = $this->make_context()->to_prompt_section();
        $this->assertStringContainsString( '1,200', $section );
        $this->assertStringContainsString( '600',   $section );
    }

    public function test_to_prompt_section_lists_top_posts(): void {
        \Jetpack_Options::$options = [ 'id' => 1 ];
        \Automattic\Jetpack\Stats\WPCOM_Stats::$top_posts = [
            'days' => [
                '2025-01-01' => [
                    'postviews' => [
                        [ 'title' => 'My Best Post', 'views' => 500 ],
                    ],
                ],
            ],
        ];
        $section = $this->make_context()->to_prompt_section();
        $this->assertStringContainsString( 'My Best Post', $section );
        $this->assertStringContainsString( '500',          $section );
    }

    public function test_to_prompt_section_lists_referrers(): void {
        \Jetpack_Options::$options = [ 'id' => 1 ];
        \Automattic\Jetpack\Stats\WPCOM_Stats::$referrers = [
            'days' => [
                '2025-01-01' => [
                    'groups' => [
                        [ 'name' => 'Google', 'total' => 900 ],
                    ],
                ],
            ],
        ];
        $section = $this->make_context()->to_prompt_section();
        $this->assertStringContainsString( 'Google', $section );
    }

    public function test_to_prompt_section_lists_search_terms(): void {
        \Jetpack_Options::$options = [ 'id' => 1 ];
        \Automattic\Jetpack\Stats\WPCOM_Stats::$search_terms = [
            'days' => [
                '2025-01-01' => [
                    'search_terms' => [
                        [ 'term' => 'woocommerce tutorial', 'views' => 40 ],
                    ],
                ],
            ],
        ];
        $section = $this->make_context()->to_prompt_section();
        $this->assertStringContainsString( '"woocommerce tutorial"', $section );
    }

    public function test_to_prompt_section_includes_blocked_attempts(): void {
        \Jetpack_Options::$options = [ 'id' => 1 ];
        Functions\when( 'get_option' )->alias( static function ( $key ) {
            return 'jetpack_protect_blocked_attempts' === $key ? 3000 : false;
        } );
        $section = $this->make_context()->to_prompt_section();
        $this->assertStringContainsString( '3,000', $section );
        $this->assertStringContainsString( 'blocked login attempts', $section );
    }

    public function test_to_prompt_section_omits_blocked_attempts_when_zero(): void {
        \Jetpack_Options::$options = [ 'id' => 1 ];
        Functions\when( 'get_option' )->alias( static function ( $key ) {
            return 'jetpack_protect_blocked_attempts' === $key ? 0 : false;
        } );
        $section = $this->make_context()->to_prompt_section();
        $this->assertStringNotContainsString( 'blocked login', $section );
    }

    public function test_to_prompt_section_includes_speed_scores(): void {
        \Jetpack_Options::$options = [ 'id' => 1 ];
        \Automattic\Jetpack\Boost_Speed_Score\Speed_Score_History::$scores = [
            'mobile' => 65, 'desktop' => 88,
        ];
        $section = $this->make_context()->to_prompt_section();
        $this->assertStringContainsString( 'mobile 65/100',  $section );
        $this->assertStringContainsString( 'desktop 88/100', $section );
    }

    public function test_to_prompt_section_includes_sync_health(): void {
        \Jetpack_Options::$options = [ 'id' => 1, 'sync_health_status' => 'healthy' ];
        $section = $this->make_context()->to_prompt_section();
        $this->assertStringContainsString( 'healthy', $section );
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function make_context(): Haydi_Jetpack_Context {
        return new Haydi_Jetpack_Context();
    }
}
