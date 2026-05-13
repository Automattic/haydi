<?php
/**
 * Minimal Jetpack class stubs for unit tests.
 *
 * Loaded by JetpackContextTest. Each stub exposes a static $data property so
 * individual tests can control what values are returned without Mockery.
 */

// phpcs:disable

namespace Automattic\Jetpack {
    if ( ! class_exists( 'Automattic\Jetpack\Current_Plan' ) ) {
        class Current_Plan {
            /** @var array|null Return value for get(). Null → returns []. */
            public static ?array $data = null;

            public static function get(): array {
                return static::$data ?? [];
            }

            public static function reset(): void {
                static::$data = null;
            }
        }
    }
}

namespace Automattic\Jetpack\Stats {
    if ( ! class_exists( 'Automattic\Jetpack\Stats\WPCOM_Stats' ) ) {
        class WPCOM_Stats {
            /** @var array|null Returned by get_stats_summary(). */
            public static ?array $stats_summary = null;
            /** @var array|null Returned by get_top_posts(). */
            public static ?array $top_posts = null;
            /** @var array|null Returned by get_referrers(). */
            public static ?array $referrers = null;
            /** @var array|null Returned by get_search_terms(). */
            public static ?array $search_terms = null;
            /** @var bool When true, all methods return a WP_Error instead. */
            public static bool $throw_error = false;

            public function get_stats_summary( $args = [] ) {
                if ( static::$throw_error ) return new \WP_Error( 'stats_error', 'Remote failed.' );
                return static::$stats_summary;
            }

            public function get_top_posts( $args = [], $override = false ) {
                if ( static::$throw_error ) return new \WP_Error( 'stats_error', 'Remote failed.' );
                return static::$top_posts;
            }

            public function get_referrers( $args = [] ) {
                if ( static::$throw_error ) return new \WP_Error( 'stats_error', 'Remote failed.' );
                return static::$referrers;
            }

            public function get_search_terms( $args = [] ) {
                if ( static::$throw_error ) return new \WP_Error( 'stats_error', 'Remote failed.' );
                return static::$search_terms;
            }

            public static function reset(): void {
                static::$stats_summary = null;
                static::$top_posts     = null;
                static::$referrers     = null;
                static::$search_terms  = null;
                static::$throw_error   = false;
            }
        }
    }
}

namespace Automattic\Jetpack\Boost_Speed_Score {
    if ( ! class_exists( 'Automattic\Jetpack\Boost_Speed_Score\Speed_Score_History' ) ) {
        class Speed_Score_History {
            /** @var array|null Returned by latest_scores(). */
            public static ?array $scores = null;

            public function __construct( string $url ) {}

            public function latest_scores( int $offset = 0 ): ?array {
                return static::$scores;
            }

            public static function reset(): void {
                static::$scores = null;
            }
        }
    }
}

namespace {
    if ( ! class_exists( 'Jetpack_Options' ) ) {
        class Jetpack_Options {
            /** @var array Option values returned by get_option(). */
            public static array $options = [];

            public static function get_option( string $name ) {
                return static::$options[ $name ] ?? false;
            }

            public static function reset(): void {
                static::$options = [];
            }
        }
    }

    if ( ! class_exists( 'Jetpack' ) ) {
        class Jetpack {
            /** @var array Returned by get_active_modules(). */
            public static array $modules = [];

            public static function get_active_modules(): array {
                return static::$modules;
            }

            public static function reset(): void {
                static::$modules = [];
            }
        }
    }
}
