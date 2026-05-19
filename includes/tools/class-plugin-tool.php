<?php
/**
 * Plugin management tool — install / activate / deactivate AJAX handlers and
 * a list_plugins() AI read tool.
 */

defined( 'ABSPATH' ) || exit;

class Haydi_Plugin_Tool extends Haydi_Ajax_Tool_Base {

	/** @var Haydi_Health_Check Post-mutation site health probe. */
	private Haydi_Health_Check $health;

	public function __construct( Haydi_Audit_Logger $logger, Haydi_Health_Check $health ) {
		parent::__construct( $logger );
		$this->health = $health;
	}

	public function register(): void {
		foreach ( array(
			'haydi_install_plugin'    => 'handle_install_plugin',
			'haydi_activate_plugin'   => 'handle_activate_plugin',
			'haydi_deactivate_plugin' => 'handle_deactivate_plugin',
		) as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	// -------------------------------------------------------------------------
	// AI-facing read methods
	// -------------------------------------------------------------------------

	/**
	 * Return a JSON array describing every installed plugin (file, name,
	 * version, active flag) for the AI's reference.
	 */
	public function list_plugins_for_ai(): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins  = get_plugins();
		$active       = get_option( 'active_plugins', array() );
		$plugins_list = array();
		foreach ( $all_plugins as $file => $data ) {
			$plugins_list[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => in_array( $file, $active, true ),
			);
		}
		$this->logger->log( 'list_plugins', '' );
		return wp_json_encode( $plugins_list );
	}

	// -------------------------------------------------------------------------
	// Service-layer execute methods (shared by AJAX, REST API, and MCP)
	// -------------------------------------------------------------------------

	/**
	 * Install a plugin from WordPress.org by slug.
	 *
	 * @param string $slug   WordPress.org plugin slug.
	 * @param string $reason Audit log reason.
	 * @return array|WP_Error Success payload or WP_Error on failure.
	 */
	public function execute_install( string $slug, string $reason ): array|WP_Error {
		if ( ! wp_is_file_mod_allowed( 'plugin_files' ) ) {
			return new WP_Error( 'file_mod_not_allowed', 'Plugin installation is not allowed on this site.', array( 'status' => 403 ) );
		}

		if ( '' === $slug ) {
			return new WP_Error( 'missing_param', 'slug is required.', array( 'status' => 400 ) );
		}

		if ( ! preg_match( '/^[a-z0-9][a-z0-9\-]*$/', $slug ) ) {
			return new WP_Error( 'invalid_slug', 'Invalid plugin slug.', array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'short_description' => false,
					'sections'          => false,
					'reviews'           => false,
				),
			)
		);

		if ( is_wp_error( $api ) ) {
			return new WP_Error( 'plugin_not_found', 'Plugin not found on WordPress.org: ' . $api->get_error_message(), array( 'status' => 400 ) );
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_request_filesystem_credentials
		$creds = request_filesystem_credentials( '', '', false, false, null );
		if ( ! WP_Filesystem( $creds ) ) {
			return new WP_Error( 'filesystem_error', 'Could not initialise the WordPress filesystem.', array( 'status' => 500 ) );
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'install_failed', 'Installation failed: ' . $result->get_error_message(), array( 'status' => 500 ) );
		}

		if ( false === $result || null === $result ) {
			$errors = $skin->get_errors();
			$msg    = ( is_wp_error( $errors ) && $errors->has_errors() )
				? $errors->get_error_message()
				: 'Installation failed for an unknown reason.';
			return new WP_Error( 'install_failed', $msg, array( 'status' => 500 ) );
		}

		$plugin_file = $upgrader->plugin_info();

		$err = $this->health->verify_or_warn( "Plugin install ({$slug})" );
		if ( $err ) {
			return new WP_Error( $err->get_error_code(), $err->get_error_message(), array( 'status' => 500 ) );
		}

		$this->logger->log( 'plugin_installed', $slug, $reason );
		return array(
			'message'     => "Plugin '{$slug}' installed successfully.",
			'plugin_file' => $plugin_file,
			'slug'        => $slug,
		);
	}

	/**
	 * Activate an installed WordPress plugin.
	 *
	 * @param string $plugin Plugin file path (e.g. "woocommerce/woocommerce.php").
	 * @param string $reason Audit log reason.
	 * @return array|WP_Error Success payload or WP_Error on failure.
	 */
	public function execute_activate( string $plugin, string $reason ): array|WP_Error {
		if ( '' === $plugin ) {
			return new WP_Error( 'missing_param', 'plugin is required.', array( 'status' => 400 ) );
		}

		if ( ! self::is_valid_plugin_path( $plugin ) ) {
			return new WP_Error( 'invalid_plugin', 'Invalid plugin file path.', array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
			return new WP_Error( 'plugin_not_found', "Plugin file not found: {$plugin}", array( 'status' => 400 ) );
		}

		$activate_result = activate_plugin( $plugin );
		if ( is_wp_error( $activate_result ) ) {
			return new WP_Error( $activate_result->get_error_code(), $activate_result->get_error_message(), array( 'status' => 500 ) );
		}

		$err = $this->health->verify_or_revert(
			function () use ( $plugin ) {
				deactivate_plugins( $plugin );
				return true;
			},
			"Plugin activation ({$plugin})"
		);
		if ( $err ) {
			return new WP_Error( $err->get_error_code(), $err->get_error_message(), array( 'status' => 500 ) );
		}

		$this->logger->log( 'plugin_activated', $plugin, $reason );
		return array(
			'message' => "Plugin '{$plugin}' activated successfully.",
			'plugin'  => $plugin,
		);
	}

	/**
	 * Deactivate a WordPress plugin.
	 *
	 * @param string $plugin Plugin file path (e.g. "woocommerce/woocommerce.php").
	 * @param string $reason Audit log reason.
	 * @return array|WP_Error Success payload or WP_Error on failure.
	 */
	public function execute_deactivate( string $plugin, string $reason ): array|WP_Error {
		if ( '' === $plugin ) {
			return new WP_Error( 'missing_param', 'plugin is required.', array( 'status' => 400 ) );
		}

		if ( ! self::is_valid_plugin_path( $plugin ) ) {
			return new WP_Error( 'invalid_plugin', 'Invalid plugin file path.', array( 'status' => 400 ) );
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( $plugin );

		$this->logger->log( 'plugin_deactivated', $plugin, $reason );
		return array(
			'message' => "Plugin '{$plugin}' deactivated successfully.",
			'plugin'  => $plugin,
		);
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * Install a plugin from WordPress.org after human approval.
	 */
	public function handle_install_plugin(): void {
		$this->verify();
		$slug   = $this->post_param( 'slug' );
		$reason = $this->post_param( 'reason' );
		$result = $this->execute_install( $slug, $reason );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}
		wp_send_json_success( $result );
	}

	/**
	 * Activate a plugin after human approval. WordPress's own sandbox catches
	 * fatals during the activation include but not those that fire on a
	 * later request (init/admin_init), so we run a loopback health check
	 * and auto-deactivate on failure to keep wp-admin reachable.
	 */
	public function handle_activate_plugin(): void {
		$this->verify();
		$plugin = $this->require_param( 'plugin' );
		$reason = $this->post_param( 'reason' );
		$result = $this->execute_activate( $plugin, $reason );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}
		wp_send_json_success( $result );
	}

	/**
	 * Deactivate a plugin after human approval.
	 */
	public function handle_deactivate_plugin(): void {
		$this->verify();
		$plugin = $this->require_param( 'plugin' );
		$reason = $this->post_param( 'reason' );
		$result = $this->execute_deactivate( $plugin, $reason );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}
		wp_send_json_success( $result );
	}

	/**
	 * Validate a WordPress plugin file path supplied to activate/deactivate.
	 *
	 * Accepts a single .php filename or any number of nested folder segments
	 * separated by `/`. Each segment is restricted to alphanumerics, underscore,
	 * and hyphen — no dots, so traversal sequences like `..` cannot pass.
	 */
	public static function is_valid_plugin_path( string $plugin ): bool {
		return 1 === preg_match( '/^[a-zA-Z0-9_\-]+(\/[a-zA-Z0-9_\-]+)*\.php$/', $plugin );
	}
}
