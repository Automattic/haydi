<?php
/**
 * PHPUnit bootstrap for Haydi unit tests.
 *
 * Provides just enough of the WordPress environment for the classes under
 * test to load and their pure-PHP logic to run without a live WordPress.
 */

// ---------------------------------------------------------------------------
// WordPress-like constants
// ---------------------------------------------------------------------------

$test_base = sys_get_temp_dir() . '/haydi-unit-tests';

define( 'ABSPATH',            $test_base . '/' );
define( 'WP_CONTENT_DIR',     $test_base . '/wp-content' );
define( 'WP_PLUGIN_DIR',      $test_base . '/wp-content/plugins' );
define( 'FS_CHMOD_FILE',      0644 );
define( 'MINUTE_IN_SECONDS',  60 );
define( 'ARRAY_A',            'ARRAY_A' );

// Ensure stub directories exist so build_allowed_roots() resolves them.
foreach ( [ WP_CONTENT_DIR . '/plugins', WP_CONTENT_DIR . '/themes' ] as $dir ) {
    if ( ! is_dir( $dir ) ) {
        mkdir( $dir, 0755, true );
    }
}

// ---------------------------------------------------------------------------
// Minimal WordPress stubs (only what the tested classes actually need)
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error { // phpcs:ignore
        private string $code;
        private string $message;

        public function __construct( string $code = '', string $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }

        public function get_error_code(): string    { return $this->code; }
        public function get_error_message(): string { return $this->message; }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ): bool { // phpcs:ignore
        return $thing instanceof WP_Error;
    }
}

// Sentinel exception used by tests to halt handler execution at the point
// where production would call wp_die() inside wp_send_json_error/success.
// Tests stub those WP functions to throw this so handlers don't keep running
// after an error is sent — which would mask the first error with a later one.
if ( ! class_exists( 'HaydiTestHaltException' ) ) {
    class HaydiTestHaltException extends \RuntimeException {} // phpcs:ignore
}

if ( ! class_exists( 'WordPress\AiClient\Providers\ProviderRegistry' ) ) {
    eval( 'namespace WordPress\AiClient\Providers { class ProviderRegistry {} }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
}

// ---------------------------------------------------------------------------
// Load plugin classes (dependency order matters)
// ---------------------------------------------------------------------------

$plugin_root = dirname( __DIR__, 2 );
require_once $plugin_root . '/includes/class-filesystem-guard.php';
require_once $plugin_root . '/includes/class-health-check.php';
require_once $plugin_root . '/includes/class-audit-logger.php';
require_once $plugin_root . '/includes/class-jetpack-context.php';
require_once $plugin_root . '/includes/class-model-limits.php';
require_once $plugin_root . '/includes/class-ai-client.php';
require_once $plugin_root . '/includes/tools/class-ajax-tool-base.php';
require_once $plugin_root . '/includes/tools/class-file-tool.php';
require_once $plugin_root . '/includes/tools/class-plugin-tool.php';
require_once $plugin_root . '/includes/tools/class-query-tool.php';
require_once $plugin_root . '/includes/tools/class-php-tool.php';
require_once $plugin_root . '/includes/tools/class-fetch-url-tool.php';
require_once $plugin_root . '/includes/class-chat-store.php';
require_once $plugin_root . '/includes/class-ajax-handlers.php';
