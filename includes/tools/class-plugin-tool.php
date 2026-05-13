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
	// AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * Install a plugin from WordPress.org after human approval.
	 */
	public function handle_install_plugin(): void {
		$this->verify();

		$slug   = $this->post_param( 'slug' );
		$reason = $this->post_param( 'reason' );

		if ( '' === $slug ) {
			wp_send_json_error( array( 'message' => 'slug is required.' ) );
			return;
		}

		// Only allow lowercase alphanumeric slugs with hyphens.
		if ( ! preg_match( '/^[a-z0-9][a-z0-9\-]*$/', $slug ) ) {
			wp_send_json_error( array( 'message' => 'Invalid plugin slug.' ) );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Fetch plugin info from WordPress.org API.
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
			wp_send_json_error( array( 'message' => 'Plugin not found on WordPress.org: ' . $api->get_error_message() ) );
			return;
		}

		// Initialise WP_Filesystem (FS_METHOD = direct on most managed hosts).
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_request_filesystem_credentials
		$creds = request_filesystem_credentials( '', '', false, false, null );
		if ( ! WP_Filesystem( $creds ) ) {
			wp_send_json_error( array( 'message' => 'Could not initialise the WordPress filesystem.' ) );
			return;
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => 'Installation failed: ' . $result->get_error_message() ) );
			return;
		}

		if ( false === $result || null === $result ) {
			$errors = $skin->get_errors();
			$msg    = ( is_wp_error( $errors ) && $errors->has_errors() )
				? $errors->get_error_message()
				: 'Installation failed for an unknown reason.';
			wp_send_json_error( array( 'message' => $msg ) );
			return;
		}

		$plugin_file = $upgrader->plugin_info();

		// Detect-only: an installed-but-inactive plugin should not be able to
		// fatal the site, but a broken upgrader run can leave WP in a bad state
		// (corrupt zip extraction, partial directory). Surface the failure
		// loudly rather than auto-deleting plugin files we did not back up.
		$err = $this->health->verify_or_warn( "Plugin install ({$slug})" );
		if ( $err ) {
			wp_send_json_error( array( 'message' => $err->get_error_message() ) );
			return;
		}

		$this->logger->log( 'plugin_installed', $slug, $reason );

		wp_send_json_success(
			array(
				'message'     => "Plugin '{$slug}' installed successfully.",
				'plugin_file' => $plugin_file,
				'slug'        => $slug,
			)
		);
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

		if ( ! self::is_valid_plugin_path( $plugin ) ) {
			wp_send_json_error( array( 'message' => 'Invalid plugin file path.' ) );
		}

		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin ) ) {
			wp_send_json_error( array( 'message' => "Plugin file not found: {$plugin}" ) );
		}

		$this->dispatch_guard_result(
			activate_plugin( $plugin ),
			function () use ( $plugin, $reason ) {
				$err = $this->health->verify_or_revert(
					function () use ( $plugin ) {
						deactivate_plugins( $plugin );
						return true;
					},
					"Plugin activation ({$plugin})"
				);
				if ( $err ) {
					wp_send_json_error( array( 'message' => $err->get_error_message() ) );
					return;
				}
				$this->logger->log( 'plugin_activated', $plugin, $reason );
				wp_send_json_success(
					array(
						'message' => "Plugin '{$plugin}' activated successfully.",
						'plugin'  => $plugin,
					)
				);
			}
		);
	}

	/**
	 * Deactivate a plugin after human approval.
	 */
	public function handle_deactivate_plugin(): void {
		$this->verify();
		$plugin = $this->require_param( 'plugin' );
		$reason = $this->post_param( 'reason' );

		if ( ! self::is_valid_plugin_path( $plugin ) ) {
			wp_send_json_error( array( 'message' => 'Invalid plugin file path.' ) );
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( $plugin );

		$this->logger->log( 'plugin_deactivated', $plugin, $reason );

		wp_send_json_success(
			array(
				'message' => "Plugin '{$plugin}' deactivated successfully.",
				'plugin'  => $plugin,
			)
		);
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
