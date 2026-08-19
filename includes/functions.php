<?php
/**
 * Global helper functions shared by Haydi tool modules.
 *
 * Kept in a separate file so haydi.php can contain only the plugin bootstrap
 * class (phpcs Universal.Files.SeparateFunctionsFromOO requires it).
 */

defined( 'ABSPATH' ) || exit;

// Compatibility registry for integrations using the historical proposal helper.
$GLOBALS['haydi_action_proposals'] = array();

/**
 * Return the WordPress capability required to use Haydi.
 *
 * Administrators have manage_options by default. Haydi's tools (run_php,
 * SQL, arbitrary plugin/theme file read) are equivalent to code execution,
 * so the floor is deliberately Administrator rather than Editor — an Editor
 * granted access could use run_php to grant themselves manage_options
 * anyway, so a lower floor is not a meaningful boundary. Sites may still
 * replace it with a custom capability when they need a different policy.
 *
 * @return string
 */
function haydi_get_access_capability(): string {
	$default    = 'manage_options';
	$capability = apply_filters( 'haydi_access_capability', $default );

	return is_string( $capability ) && '' !== trim( $capability )
		? $capability
		: $default;
}

/**
 * Check whether the current WordPress user may use Haydi.
 */
function haydi_current_user_can_access(): bool {
	return current_user_can( haydi_get_access_capability() );
}

/**
 * Register an approval-gated action tool.
 *
 * @param string $tool_name AI tool name (e.g. 'write_file').
 * @param array  $config    Label, fields, ajax_action, log_action, log_path_field, tool_description.
 */
function haydi_register_action_proposal( string $tool_name, array $config ): void {
	$GLOBALS['haydi_action_proposals'][ $tool_name ] = $config;
}

/**
 * Return approval-gated actions registered through the historical helper.
 *
 * @return array<string, array>
 */
function haydi_get_legacy_action_proposals(): array {
	return $GLOBALS['haydi_action_proposals'] ?? array();
}

/**
 * Return the one request-scoped Tool Catalog shared by chat and MCP.
 *
 * @throws LogicException If called reentrantly during Tool registration.
 */
function haydi_get_tool_catalog(): Haydi_Tool_Catalog {
	if ( isset( $GLOBALS['haydi_tool_catalog'] ) && $GLOBALS['haydi_tool_catalog'] instanceof Haydi_Tool_Catalog ) {
		return $GLOBALS['haydi_tool_catalog'];
	}
	if ( ! empty( $GLOBALS['haydi_tool_catalog_building'] ) ) {
		throw new LogicException( 'The Haydi Tool Catalog cannot be resolved while Tool registration is in progress.' );
	}

	$GLOBALS['haydi_tool_catalog_building'] = true;
	try {
		$catalog                       = Haydi_Tool_Catalog::create_default();
		$GLOBALS['haydi_tool_catalog'] = $catalog;
	} finally {
		$GLOBALS['haydi_tool_catalog_building'] = false;
	}

	return $catalog;
}

/**
 * Return all approval-gated actions in the historical registry shape.
 *
 * Built-ins come from the Tool Catalog; integrations using
 * haydi_register_action_proposal() remain supported as a compatibility Adapter.
 *
 * @return array<string, array>
 */
function haydi_get_action_proposals(): array {
	$legacy = haydi_get_legacy_action_proposals();
	if (
		! class_exists( Haydi_Tool_Catalog::class )
		|| ! empty( $GLOBALS['haydi_tool_catalog_building'] )
	) {
		return $legacy;
	}

	// Catalog-native approval Tools win their name collisions. A legacy
	// proposal may still gate an automatic Tool, preserving historical policy.
	return array_merge( $legacy, haydi_get_tool_catalog()->action_proposals() );
}

/**
 * Check whether the current REST request is authorized via Bearer token.
 *
 * REST/MCP access is deliberately gated on possession of a token (minted
 * from the WP-Admin sidebar) rather than the caller's WordPress capability:
 * a token is the explicit, auditable act that stands in for the human
 * approval click the chat UI requires for the same operations. Falling back
 * to current_user_can() here would let any session holding the Haydi access
 * capability reach approval-gated tools (e.g. run_php's eval()) over the API
 * with no approval step at all.
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
		return $token_manager->validate_token( $token );
	}
	return false;
}
