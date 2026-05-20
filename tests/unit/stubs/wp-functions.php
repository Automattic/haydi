<?php
/**
 * Minimal WordPress function stubs for unit tests.
 *
 * This file is required AFTER Patchwork so that Brain\Monkey can override
 * these stubs per-test. Defining them in bootstrap.php itself (before Patchwork
 * initialises its stream wrapper) causes a DefinedTooEarly error.
 */

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, $options = 0, $depth = 512 ) { // phpcs:ignore
        return json_encode( $data, $options, $depth );
    }
}

// Stubs so extension files can be loaded without a running WP environment.
// Brain\Monkey overrides these per-test as needed.
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool { // phpcs:ignore
        return true;
    }
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool { // phpcs:ignore
        return true;
    }
}
if ( ! function_exists( 'wp_is_file_mod_allowed' ) ) {
    function wp_is_file_mod_allowed( string $context ): bool { // phpcs:ignore
        return true;
    }
}

// apply_filters returns the unmodified value (no callbacks registered in unit tests).
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( string $hook, $value, ...$args ) { // phpcs:ignore
        return $value;
    }
}

if ( ! function_exists( 'get_theme_root' ) ) {
    function get_theme_root(): string { // phpcs:ignore
        return WP_CONTENT_DIR . '/themes';
    }
}
