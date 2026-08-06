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
