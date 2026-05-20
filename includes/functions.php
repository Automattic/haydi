<?php
/**
 * Global helper functions used by both core and extensions.
 *
 * Kept in a separate file so haydi.php can contain only the plugin bootstrap
 * class (phpcs Universal.Files.SeparateFunctionsFromOO requires it).
 */

defined( 'ABSPATH' ) || exit;

// Global proposal registry for extension tools.
$GLOBALS['haydi_proposals'] = array();

/**
 * Register an extension-provided proposal tool.
 *
 * @param string $tool_name AI tool name (e.g. 'write_file').
 * @param array  $config    Label, fields, ajax_action, log_action, log_path_field, tool_description.
 */
function haydi_register_proposal( string $tool_name, array $config ): void {
	$GLOBALS['haydi_proposals'][ $tool_name ] = $config;
}

/**
 * Return all registered extension proposals.
 *
 * @return array<string, array>
 */
function haydi_get_proposals(): array {
	return $GLOBALS['haydi_proposals'] ?? array();
}

/**
 * Check whether the current REST request is authorized via Bearer token or manage_options.
 *
 * @param WP_REST_Request $request Incoming REST request.
 * @return bool
 */
function haydi_is_authorized_api_request( WP_REST_Request $request ): bool {
	static $token_manager = null;
	if ( null === $token_manager ) {
		$token_manager = new Haydi_Api_Token_Manager();
	}
	$auth = $request->get_header( 'Authorization' );
	if ( ! $auth ) {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth = $_SERVER['HTTP_AUTHORIZATION']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		} elseif ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			$auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
		}
	}
	if ( $auth && str_starts_with( $auth, 'Bearer ' ) ) {
		$token = trim( substr( $auth, 7 ) );
		if ( $token_manager->validate_token( $token ) ) {
			return true;
		}
	}
	return current_user_can( 'manage_options' );
}
