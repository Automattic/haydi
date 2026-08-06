<?php
/**
 * Plugin management Tool Implementations.
 */

defined( 'ABSPATH' ) || exit;

class Haydi_Plugin_Tool {

	/** @var Haydi_Audit_Logger Tool audit logger. */
	private Haydi_Audit_Logger $logger;

	/** @var Haydi_Health_Check Post-mutation site health probe. */
	private Haydi_Health_Check $health;

	public function __construct( Haydi_Audit_Logger $logger, Haydi_Health_Check $health ) {
		$this->logger = $logger;
		$this->health = $health;
	}

	/**
	 * Register plugin inventory and mutation tools with the Tool Catalog.
	 */
	public function register_tools( Haydi_Tool_Catalog $catalog ): void {
		$catalog->register(
			array(
				'name'           => 'list_plugins',
				'description'    => 'List all installed WordPress plugins with their activation status, version, and plugin file path. Use this to discover what is installed before installing or activating anything.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(),
					'required'   => array(),
				),
				'effect'         => 'automatic',
				'activity_label' => 'Listed plugins',
				'projections'    => array(
					'chat' => true,
					'mcp'  => array(
						'name'        => 'haydi_list_plugins',
						'description' => 'List all installed WordPress plugins with name, version, file path, and activation status.',
					),
				),
			),
			fn(): array => $this->list_plugins_for_ai()
		);

		$catalog->register(
			array(
				'name'           => 'install_plugin',
				'description'    => 'Install a plugin from WordPress.org by its slug. Calling this tool opens an approval UI for the user; they confirm before anything is downloaded or installed. You must invoke this tool to trigger the approval — describing the install in plain text does nothing.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'slug'   => array(
							'type'        => 'string',
							'description' => 'The WordPress.org plugin slug, e.g. "woocommerce".',
						),
						'reason' => array(
							'type'        => 'string',
							'description' => 'Human-readable explanation of why this plugin should be installed.',
						),
					),
					'required'   => array( 'slug', 'reason' ),
				),
				'effect'         => 'approval',
				'activity_label' => 'Prepared install',
				'proposal'       => array(
					'label'          => 'Install Plugin',
					'log_action'     => 'install_proposed',
					'log_path_field' => 'slug',
				),
				'projections'    => array(
					'chat' => true,
					'mcp'  => array(
						'name'        => 'haydi_install_plugin',
						'description' => 'Install a plugin from WordPress.org by slug.',
						'required'    => array( 'slug' ),
						'available'   => static fn(): bool => wp_is_file_mod_allowed( 'plugin_files' ),
					),
				),
			),
			fn( array $arguments ): array|WP_Error => $this->execute_install(
				(string) ( $arguments['slug'] ?? '' ),
				(string) ( $arguments['reason'] ?? '' )
			)
		);

		$catalog->register(
			array(
				'name'           => 'activate_plugin',
				'description'    => 'Activate an already-installed WordPress plugin. Calling this tool opens an approval UI for the user; they confirm before activation. Use list_plugins first to get the correct plugin file path. You must invoke this tool to trigger the approval — describing the activation in plain text does nothing.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => 'Plugin file path relative to the plugins directory, e.g. "woocommerce/woocommerce.php".',
						),
						'reason' => array(
							'type'        => 'string',
							'description' => 'Human-readable explanation of why this plugin is being activated.',
						),
					),
					'required'   => array( 'plugin', 'reason' ),
				),
				'effect'         => 'approval',
				'activity_label' => 'Prepared activation',
				'proposal'       => array(
					'label'          => 'Activate Plugin',
					'log_action'     => 'activate_proposed',
					'log_path_field' => 'plugin',
				),
				'projections'    => array(
					'chat' => true,
					'mcp'  => array(
						'name'        => 'haydi_activate_plugin',
						'description' => 'Activate an installed WordPress plugin.',
						'required'    => array( 'plugin' ),
					),
				),
			),
			fn( array $arguments ): array|WP_Error => $this->execute_activate(
				(string) ( $arguments['plugin'] ?? '' ),
				(string) ( $arguments['reason'] ?? '' )
			)
		);

		$catalog->register(
			array(
				'name'           => 'deactivate_plugin',
				'description'    => 'Deactivate an active WordPress plugin. Calling this tool opens an approval UI for the user; they confirm before deactivation. You must invoke this tool to trigger the approval — describing the deactivation in plain text does nothing.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => 'Plugin file path relative to the plugins directory, e.g. "woocommerce/woocommerce.php".',
						),
						'reason' => array(
							'type'        => 'string',
							'description' => 'Human-readable explanation of why this plugin is being deactivated.',
						),
					),
					'required'   => array( 'plugin', 'reason' ),
				),
				'effect'         => 'approval',
				'activity_label' => 'Prepared deactivation',
				'proposal'       => array(
					'label'          => 'Deactivate Plugin',
					'log_action'     => 'deactivate_proposed',
					'log_path_field' => 'plugin',
				),
				'projections'    => array(
					'chat' => true,
					'mcp'  => array(
						'name'        => 'haydi_deactivate_plugin',
						'description' => 'Deactivate an active WordPress plugin.',
						'required'    => array( 'plugin' ),
					),
				),
			),
			fn( array $arguments ): array|WP_Error => $this->execute_deactivate(
				(string) ( $arguments['plugin'] ?? '' ),
				(string) ( $arguments['reason'] ?? '' )
			)
		);
	}

	// -------------------------------------------------------------------------
	// AI-facing read methods
	// -------------------------------------------------------------------------

	/**
	 * Return a structured inventory describing every installed plugin (file,
	 * name, version, active flag) for the AI's reference.
	 */
	public function list_plugins_for_ai(): array {
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
		return array( 'plugins' => $plugins_list );
	}

	// -------------------------------------------------------------------------
	// Service-layer execute methods shared by catalog, AJAX, and direct REST.
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
