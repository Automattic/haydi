<?php
/**
 * Plugin Name:       Haydi
 * Plugin URI:        https://github.com/Automattic/haydi
 * Description:       Your AI Autopilot — manage your site directly from WP-Admin using any AI provider.
 * Version:           1.0.0
 * Author:            Automattic
 * License:           GPL-2.0-or-later
 * Text Domain:       haydi
 * Requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'HAYDI_DIR', plugin_dir_path( __FILE__ ) );
define( 'HAYDI_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'HAYDI_SHOW_TOOL_ACTIVITY' ) ) {
	define( 'HAYDI_SHOW_TOOL_ACTIVITY', true );
}

// Global helpers (proposal registry, API auth) and per-tool classes.
require_once HAYDI_DIR . 'includes/functions.php';
require_once HAYDI_DIR . 'includes/class-filesystem-guard.php';
require_once HAYDI_DIR . 'includes/class-health-check.php';
require_once HAYDI_DIR . 'includes/class-audit-logger.php';
require_once HAYDI_DIR . 'includes/class-jetpack-context.php';
require_once HAYDI_DIR . 'includes/class-model-limits.php';
require_once HAYDI_DIR . 'includes/class-ai-client.php';
require_once HAYDI_DIR . 'includes/tools/class-ajax-tool-base.php';
require_once HAYDI_DIR . 'includes/tools/class-file-tool.php';
require_once HAYDI_DIR . 'includes/tools/class-plugin-tool.php';
require_once HAYDI_DIR . 'includes/tools/class-fetch-url-tool.php';
require_once HAYDI_DIR . 'includes/class-chat-store.php';
require_once HAYDI_DIR . 'includes/class-ajax-handlers.php';
require_once HAYDI_DIR . 'includes/class-api-token-manager.php';
require_once HAYDI_DIR . 'includes/class-rest-api.php';

// Auto-load PHP files dropped into the extensions/ directory.
$_haydi_exts = glob( HAYDI_DIR . 'extensions/*.php' );
foreach ( ( $_haydi_exts ? $_haydi_exts : array() ) as $_haydi_ext ) {
	require_once $_haydi_ext;
}
unset( $_haydi_exts, $_haydi_ext );

/**
 * Main plugin bootstrap class.
 */
final class Haydi_Plugin {

	/**
	 * Hook suffixes for the plugin's own admin pages, populated by add_admin_pages().
	 *
	 * @var string[]
	 */
	private array $plugin_screens = array();

	/**
	 * Suggestion prompts grouped by capability category.
	 *
	 * Localised on every page load so the JS picks one chip per category to
	 * surface beneath the greeting on a fresh chat. Each category is
	 * picked from independently, so adding entries to one bucket only affects
	 * that bucket's rotation.
	 */
	private static function get_suggestion_pool(): array {
		$base = array(
			'file'       => array(
				__( 'Show me what plugins and themes are installed', 'haydi' ),
				__( 'Search my theme files for any custom CSS', 'haydi' ),
				__( 'Check my theme for risky code and explain what you find', 'haydi' ),
			),
			'db'         => array(
				__( 'Show me which authors have published the most posts', 'haydi' ),
				__( 'Show posts and pages updated in the last 7 days', 'haydi' ),
			),
			'plugin'     => array(
				__( 'Add a contact form to my site', 'haydi' ),
				__( 'Add an events calendar to my site', 'haydi' ),
				__( 'Turn on spam protection if it is available', 'haydi' ),
			),
			'extensions' => array(
				__( 'What tools do you have? Show me which extensions are installed.', 'haydi' ),
			),
		);

		return apply_filters( 'haydi_suggestion_pool', $base );
	}

	/**
	 * Returns true if at least one AI provider connector is active.
	 *
	 * Pass `$require_configured = false` to accept a connector that is registered
	 * but not yet wired up with credentials (used by the activation hook to gate
	 * on plugin presence). The default (`true`) requires a fully-configured
	 * provider, which is what the runtime needs.
	 *
	 * Memoised per-request because admin_notices can fire repeatedly on the same
	 * page load and `defaultRegistry()` walks every registered connector.
	 */
	public static function has_provider( bool $require_configured = true ): bool {
		static $cache = array();
		$key          = $require_configured ? 'configured' : 'any';
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$result = false;
		if ( function_exists( 'wp_get_connectors' ) ) {
			try {
				$registry = WordPress\AiClient\AiClient::defaultRegistry();
				foreach ( wp_get_connectors() as $id => $connector ) {
					if ( 'ai_provider' !== $connector['type'] || ! $registry->hasProvider( $id ) ) {
						continue;
					}
					if ( ! $require_configured || $registry->isProviderConfigured( $id ) ) {
						$result = true;
						break;
					}
				}
			} catch ( \Exception $e ) {
				$result = false;
			}
		}

		$cache[ $key ] = $result;
		return $result;
	}

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_pages' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_commands' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_provider_notice' ) );
	}

	public function maybe_show_provider_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, $this->plugin_screens, true ) ) {
			return;
		}
		if ( self::has_provider() ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. '<strong>Haydi</strong> requires at least one AI provider connector to be active. Please configure one under Settings → Connectors.'
			. '</p></div>';
	}

	public function add_admin_pages(): void {
		$this->plugin_screens[] = add_submenu_page(
			'tools.php',
			'Haydi',
			'Haydi',
			'manage_options',
			'haydi',
			array( $this, 'render_main_page' )
		);
		$this->plugin_screens[] = add_submenu_page(
			null,
			'Haydi Audit Log',
			'Haydi Audit Log',
			'manage_options',
			'haydi-audit-log',
			array( $this, 'render_audit_log_page' )
		);
	}

	public function enqueue_commands(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Registers a "Interact with AI" entry in the WordPress command palette
		// (Cmd/Ctrl+K). The handler is small enough to inline against wp-commands
		// directly — no extra HTTP request, no separate asset version to manage.
		if ( ! wp_script_is( 'wp-commands', 'registered' ) ) {
			return;
		}

		wp_enqueue_script( 'wp-commands' );

		$page_url = wp_json_encode( admin_url( 'tools.php?page=haydi' ) );
		$inline   = "( function () {\n"
			. "\t'use strict';\n"
			. "\tif ( typeof wp === 'undefined' || ! wp.data ) {\n"
			. "\t\treturn;\n"
			. "\t}\n"
			. "\ttry {\n"
			. "\t\twp.data.dispatch( 'core/commands' ).registerCommand( {\n"
			. "\t\t\tname: 'haydi/open',\n"
			. "\t\t\tlabel: 'Interact with AI',\n"
			. "\t\t\tcallback: function ( { close } ) {\n"
			. "\t\t\t\tclose();\n"
			. "\t\t\t\twindow.location.href = " . $page_url . ";\n"
			. "\t\t\t},\n"
			. "\t\t} );\n"
			. "\t} catch ( _e ) {\n"
			. "\t\t// wp-commands store not available on this page.\n"
			. "\t}\n"
			. '} )();';

		wp_add_inline_script( 'wp-commands', $inline );
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'haydi' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'haydi-admin',
			HAYDI_URL . 'assets/admin.css',
			array(),
			filemtime( HAYDI_DIR . 'assets/admin.css' )
		);

		wp_enqueue_script(
			'haydi-marked',
			HAYDI_URL . 'assets/marked.min.js',
			array(),
			filemtime( HAYDI_DIR . 'assets/marked.min.js' ),
			true
		);

		wp_enqueue_script(
			'haydi-admin',
			HAYDI_URL . 'assets/admin.js',
			array( 'jquery', 'haydi-marked' ),
			filemtime( HAYDI_DIR . 'assets/admin.js' ),
			true
		);

		$current_user  = wp_get_current_user();
		$wpcom_blog_id = ( new Haydi_Jetpack_Context() )->get_wpcom_blog_id();
		$wpcom_user_id = Haydi_Jetpack_Context::get_wpcom_user_id( $current_user->ID );
		$is_chat_page  = false === strpos( $hook, 'audit-log' );
		$model_service = $is_chat_page ? new Haydi_Model_Limits() : null;
		$model_limits  = $model_service ? $model_service->get_limits_for_configured_providers() : array();
		$model_choices = $model_service ? $model_service->get_model_choices_for_configured_providers() : array();

		wp_localize_script(
			'haydi-admin',
			'haydi',
			array(
				'ajaxUrl'                    => admin_url( 'admin-ajax.php' ),
				'nonce'                      => wp_create_nonce( 'haydi_nonce' ),
				'auditLogUrl'                => admin_url( 'tools.php?page=haydi-audit-log' ),
				'maxTokens'                  => (int) get_option( 'haydi_max_tokens', Haydi_AI_Client::DEFAULT_MAX_TOKENS ),
				'playgroundPreflightEnabled' => (bool) get_option( 'haydi_playground_preflight_enabled', false ),
				'userLogin'                  => $current_user->user_login,
				'wpcomBlogId'                => $wpcom_blog_id,
				'wpcomUserId'                => $wpcom_user_id,
				'modelLimits'                => $model_limits,
				'modelChoices'               => $model_choices,
				'suggestions'                => self::get_suggestion_pool(),
				'suggestionHint'             => apply_filters( 'haydi_suggestion_hint', __( 'Try one of these, or ask me what tools I have:', 'haydi' ) ),
				'showToolActivity'           => (bool) HAYDI_SHOW_TOOL_ACTIVITY,
				'playgroundClient'           => 'https://playground.wordpress.net/client/index.js',
				'playgroundRemote'           => 'https://playground.wordpress.net/remote.html',
				'tracksEnabled'              => (bool) get_option( 'haydi_enable_tracks', false ),
			)
		);

		// Tracks is only useful on the chat page (the audit log page records nothing).
		// Loaded in the footer to keep the admin head render unblocked. Disabled by
		// default (opt-in); users enable it in Advanced Settings.
		$tracks_enabled = (bool) get_option( 'haydi_enable_tracks', false );

		if ( $tracks_enabled && $is_chat_page ) {
			wp_enqueue_script(
				'automattic-tracks',
				'https://stats.wp.com/w.js',
				array(),
				'1',
				true
			);
		}
	}

	public function render_main_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'haydi' ) );
		}
		include HAYDI_DIR . 'admin/main-page.php';
	}

	public function render_audit_log_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'haydi' ) );
		}
		include HAYDI_DIR . 'admin/audit-log-page.php';
	}
}

new Haydi_Plugin();
// AJAX handlers self-register via their constructor.
new Haydi_Ajax_Handlers();
// REST API self-registers via rest_api_init.
new Haydi_Rest_Api();
