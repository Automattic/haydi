<?php
/**
 * Haydi REST API — exposes all Haydi tools over the WordPress REST API.
 *
 * Authentication: Bearer token (generated in WP-Admin → Haydi → Remote Access)
 * or an active WordPress session with manage_options capability.
 *
 * All write/execute operations are performed immediately when called via the
 * REST API — the token itself acts as the approval gate, equivalent to a
 * human clicking "Approve" in the browser UI.
 *
 * Base namespace: haydi/v1
 * Base URL:       /wp-json/haydi/v1/
 */

defined( 'ABSPATH' ) || exit;

class Haydi_Rest_Api {

	const NAMESPACE = 'haydi/v1';

	/** @var Haydi_Api_Token_Manager API token manager. */
	private Haydi_Api_Token_Manager $token_manager;
	/** @var Haydi_Filesystem_Guard Filesystem access guard. */
	private Haydi_Filesystem_Guard $guard;
	/** @var Haydi_File_Tool File-ops tool. */
	private Haydi_File_Tool $file_tool;
	/** @var Haydi_Plugin_Tool Plugin-ops tool. */
	private Haydi_Plugin_Tool $plugin_tool;
	/** @var Haydi_Query_Tool SQL query tool. */
	private Haydi_Query_Tool $query_tool;
	/** @var Haydi_PHP_Tool PHP execution tool. */
	private Haydi_PHP_Tool $php_tool;
	/** @var Haydi_Fetch_Url_Tool URL fetch tool. */
	private Haydi_Fetch_Url_Tool $url_tool;
	/** @var Haydi_Audit_Logger Audit logger. */
	private Haydi_Audit_Logger $logger;
	/** @var Haydi_Health_Check Post-mutation health probe. */
	private Haydi_Health_Check $health;

	public function __construct() {
		$this->token_manager = new Haydi_Api_Token_Manager();
		$this->logger        = new Haydi_Audit_Logger();
		$this->guard         = new Haydi_Filesystem_Guard();
		$this->health        = new Haydi_Health_Check();
		$this->file_tool     = new Haydi_File_Tool( $this->logger, $this->guard, $this->health );
		$this->plugin_tool   = new Haydi_Plugin_Tool( $this->logger, $this->health );
		$this->query_tool    = new Haydi_Query_Tool( $this->logger, $this->health );
		$this->php_tool      = new Haydi_PHP_Tool( $this->logger, $this->health );
		$this->url_tool      = new Haydi_Fetch_Url_Tool( $this->logger );

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$perm = array( $this, 'check_permission' );

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => $perm,
			)
		);

		// File read/list.
		register_rest_route(
			self::NAMESPACE,
			'/files',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_list_files' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/files/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_search_files' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/files/backups',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_list_backups' ),
				'permission_callback' => $perm,
			)
		);

		// File write/mutate.
		register_rest_route(
			self::NAMESPACE,
			'/files/restore',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_restore_backup' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/files/move',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_move_file' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/files/copy',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_copy_file' ),
				'permission_callback' => $perm,
			)
		);

		// Single file CRUD.
		register_rest_route(
			self::NAMESPACE,
			'/file',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_read_file' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_write_file' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'handle_edit_file' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'handle_delete_file' ),
					'permission_callback' => $perm,
				),
			)
		);

		// Directory.
		register_rest_route(
			self::NAMESPACE,
			'/directory',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_delete_dir' ),
				'permission_callback' => $perm,
			)
		);

		// Plugins.
		register_rest_route(
			self::NAMESPACE,
			'/plugins',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_list_plugins' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/plugins/install',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_install_plugin' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/plugins/activate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_activate_plugin' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/plugins/deactivate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_deactivate_plugin' ),
				'permission_callback' => $perm,
			)
		);

		// SQL + PHP + URL.
		register_rest_route(
			self::NAMESPACE,
			'/query',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_run_query' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/php',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_run_php' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/fetch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_fetch_url' ),
				'permission_callback' => $perm,
			)
		);

		// MCP — Streamable HTTP transport (JSON-RPC 2.0).
		register_rest_route(
			self::NAMESPACE,
			'/mcp',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_mcp' ),
				'permission_callback' => $perm,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Auth
	// -------------------------------------------------------------------------

	/**
	 * Verify Bearer token or active WordPress session with manage_options capability.
	 */
	public function check_permission( WP_REST_Request $request ): bool {
		$auth = $request->get_header( 'Authorization' );

		// In Apache + FastCGI/PHP-FPM environments the Authorization header is
		// often stripped from the FastCGI environment. Fall back to the $_SERVER
		// variables that Apache may still populate, mirroring the approach used
		// by WordPress core for Application Passwords.
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
			if ( $this->token_manager->validate_token( $token ) ) {
				return true;
			}
		}
		return current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------------
	// Status
	// -------------------------------------------------------------------------

	/**
	 * Return basic site info as a connectivity check.
	 */
	public function handle_status(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'site_url'      => get_site_url(),
				'site_name'     => get_bloginfo( 'name' ),
				'haydi_version' => '1.0.0',
			)
		);
	}

	// -------------------------------------------------------------------------
	// File read
	// -------------------------------------------------------------------------

	/**
	 * List files and directories inside an allowed root.
	 */
	public function handle_list_files( WP_REST_Request $request ): WP_REST_Response {
		$path   = (string) ( $request->get_param( 'path' ) ?? '' );
		$result = $this->guard->list_files( $path );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}
		$this->logger->log( 'list_files', $path );
		return new WP_REST_Response( array( 'files' => $result ) );
	}

	public function handle_read_file( WP_REST_Request $request ): WP_REST_Response {
		$path = (string) ( $request->get_param( 'path' ) ?? '' );
		if ( '' === $path ) {
			return new WP_REST_Response( array( 'message' => 'path is required.' ), 400 );
		}
		$result = $this->guard->read_file( $path );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}
		$this->logger->log( 'read_file', $path );
		return new WP_REST_Response(
			array(
				'path'    => $path,
				'content' => $result,
			)
		);
	}

	public function handle_search_files( WP_REST_Request $request ): WP_REST_Response {
		$query = (string) ( $request->get_param( 'query' ) ?? '' );
		if ( '' === $query ) {
			return new WP_REST_Response( array( 'message' => 'query is required.' ), 400 );
		}
		$path        = (string) ( $request->get_param( 'path' ) ?? '' );
		$mode        = (string) ( $request->get_param( 'mode' ) ?? 'literal' );
		$extensions  = (string) ( $request->get_param( 'extensions' ) ?? '' );
		$max_results = (int) ( $request->get_param( 'max_results' ) ?? Haydi_Filesystem_Guard::DEFAULT_SEARCH_RESULTS );

		$result = $this->guard->search_files( $query, $path, $mode, $extensions, $max_results );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}
		$this->logger->log( 'search_files', $path, $query );
		return new WP_REST_Response( $result );
	}

	public function handle_list_backups( WP_REST_Request $request ): WP_REST_Response {
		$path    = (string) ( $request->get_param( 'path' ) ?? '' );
		$backups = $this->guard->list_backups( $path );
		return new WP_REST_Response( array( 'backups' => $backups ) );
	}

	// -------------------------------------------------------------------------
	// File write
	// -------------------------------------------------------------------------

	/**
	 * Create or overwrite a file inside an allowed root.
	 */
	public function handle_write_file( WP_REST_Request $request ): WP_REST_Response {
		$body   = $request->get_json_params();
		$result = $this->file_tool->execute_write(
			(string) ( $body['path'] ?? '' ),
			(string) ( $body['content'] ?? '' ),
			(string) ( $body['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
		}
		return new WP_REST_Response( $result );
	}

	public function handle_edit_file( WP_REST_Request $request ): WP_REST_Response {
		$body   = $request->get_json_params();
		$result = $this->file_tool->execute_edit(
			(string) ( $body['path'] ?? '' ),
			(string) ( $body['old_string'] ?? '' ),
			(string) ( $body['new_string'] ?? '' ),
			(bool) ( $body['replace_all'] ?? false ),
			(string) ( $body['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
		}
		return new WP_REST_Response( $result );
	}

	public function handle_delete_file( WP_REST_Request $request ): WP_REST_Response {
		$reason = (string) ( $request->get_param( 'reason' ) ?? '' );
		$result = $this->file_tool->execute_delete(
			(string) ( $request->get_param( 'path' ) ?? '' ),
			'' !== $reason ? $reason : 'API-initiated deletion.'
		);
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
		}
		return new WP_REST_Response( $result );
	}

	public function handle_move_file( WP_REST_Request $request ): WP_REST_Response {
		$body   = $request->get_json_params();
		$result = $this->file_tool->execute_move(
			(string) ( $body['src'] ?? '' ),
			(string) ( $body['dest'] ?? '' ),
			(string) ( $body['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
		}
		return new WP_REST_Response( $result );
	}

	public function handle_copy_file( WP_REST_Request $request ): WP_REST_Response {
		$body   = $request->get_json_params();
		$result = $this->file_tool->execute_copy(
			(string) ( $body['src'] ?? '' ),
			(string) ( $body['dest'] ?? '' ),
			(string) ( $body['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
		}
		return new WP_REST_Response( $result );
	}

	public function handle_delete_dir( WP_REST_Request $request ): WP_REST_Response {
		$reason = (string) ( $request->get_param( 'reason' ) ?? '' );
		$result = $this->file_tool->execute_delete_dir(
			(string) ( $request->get_param( 'path' ) ?? '' ),
			'' !== $reason ? $reason : 'API-initiated directory deletion.'
		);
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
		}
		return new WP_REST_Response( $result );
	}

	public function handle_restore_backup( WP_REST_Request $request ): WP_REST_Response {
		$body   = $request->get_json_params();
		$result = $this->file_tool->execute_restore_backup(
			(string) ( $body['backup_file'] ?? '' ),
			(string) ( $body['original_path'] ?? '' ),
			(string) ( $body['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
		}
		return new WP_REST_Response( $result );
	}

	// -------------------------------------------------------------------------
	// Plugins
	// -------------------------------------------------------------------------

	/**
	 * Return all installed plugins with name, version, file, and active status.
	 */
	public function handle_list_plugins(): WP_REST_Response {
		$plugins = json_decode( $this->plugin_tool->list_plugins_for_ai(), true );
		return new WP_REST_Response( array( 'plugins' => $plugins ) );
	}

	public function handle_install_plugin( WP_REST_Request $request ): WP_REST_Response {
		$body   = $request->get_json_params();
		$result = $this->plugin_tool->execute_install(
			sanitize_key( (string) ( $body['slug'] ?? '' ) ),
			(string) ( $body['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
		}
		return new WP_REST_Response( $result );
	}

	public function handle_activate_plugin( WP_REST_Request $request ): WP_REST_Response {
		$body   = $request->get_json_params();
		$result = $this->plugin_tool->execute_activate(
			(string) ( $body['plugin'] ?? '' ),
			(string) ( $body['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
		}
		return new WP_REST_Response( $result );
	}

	public function handle_deactivate_plugin( WP_REST_Request $request ): WP_REST_Response {
		$body   = $request->get_json_params();
		$result = $this->plugin_tool->execute_deactivate(
			(string) ( $body['plugin'] ?? '' ),
			(string) ( $body['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
		}
		return new WP_REST_Response( $result );
	}

	// -------------------------------------------------------------------------
	// SQL / PHP / URL
	// -------------------------------------------------------------------------

	/**
	 * Execute a SQL query via wpdb. SELECT returns rows; other statements return row count.
	 */
	public function handle_run_query( WP_REST_Request $request ): WP_REST_Response {
		$body   = $request->get_json_params();
		$result = $this->query_tool->execute_query(
			trim( (string) ( $body['sql'] ?? '' ) ),
			(string) ( $body['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
		}
		// REST API omits the raw 'result' string from the select payload — only rows/count/truncated.
		unset( $result['result'] );
		return new WP_REST_Response( $result );
	}

	public function handle_run_php( WP_REST_Request $request ): WP_REST_Response {
		$body   = $request->get_json_params();
		$result = $this->php_tool->execute_php(
			trim( (string) ( $body['code'] ?? '' ) ),
			(string) ( $body['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			$output = is_array( $data ) && isset( $data['output'] ) ? $data['output'] : '';
			return new WP_REST_Response(
				array(
					'message' => $result->get_error_message(),
					'output'  => $output,
				),
				$status
			);
		}
		return new WP_REST_Response( $result );
	}

	public function handle_fetch_url( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		$url  = (string) ( $body['url'] ?? '' );

		if ( '' === $url ) {
			return new WP_REST_Response( array( 'message' => 'url is required.' ), 400 );
		}

		$result = $this->url_tool->fetch_for_ai( $url );
		if ( str_starts_with( $result, 'Error: ' ) ) {
			return new WP_REST_Response( array( 'message' => substr( $result, 7 ) ), 400 );
		}

		return new WP_REST_Response( array( 'content' => $result ) );
	}

	// -------------------------------------------------------------------------
	// MCP — Streamable HTTP transport (JSON-RPC 2.0)
	//
	// A single POST endpoint speaks the Model Context Protocol so local AI
	// tools (Claude Code, etc.) can connect without any extra process:
	//
	// {
	// "mcpServers": {
	// "haydi": {
	// "url": "https://yoursite.com/wp-json/haydi/v1/mcp",
	// "headers": { "Authorization": "Bearer <token>" }
	// }
	// }
	// }
	// -------------------------------------------------------------------------

	/**
	 * MCP Streamable HTTP endpoint — dispatches JSON-RPC 2.0 requests to tool handlers.
	 */
	public function handle_mcp( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return $this->mcp_error( null, -32700, 'Parse error' );
		}

		if ( '2.0' !== ( $body['jsonrpc'] ?? '' ) ) {
			return $this->mcp_error( null, -32600, 'Invalid Request' );
		}

		$method = (string) ( $body['method'] ?? '' );
		$id     = $body['id'] ?? null;
		$params = (array) ( $body['params'] ?? array() );

		// Notifications carry no id and require no response body.
		if ( ! array_key_exists( 'id', $body ) && str_starts_with( $method, 'notifications/' ) ) {
			return new WP_REST_Response( null, 204 );
		}

		switch ( $method ) {
			case 'initialize':
				return $this->mcp_ok(
					$id,
					array(
						'protocolVersion' => '2024-11-05',
						'capabilities'    => array( 'tools' => new \stdClass() ),
						'serverInfo'      => array(
							'name'    => 'haydi',
							'version' => '1.0.0',
						),
					)
				);

			case 'ping':
				return $this->mcp_ok( $id, new \stdClass() );

			case 'tools/list':
				return $this->mcp_ok( $id, array( 'tools' => $this->mcp_tool_definitions() ) );

			case 'tools/call':
				$name   = (string) ( $params['name'] ?? '' );
				$args   = (array) ( $params['arguments'] ?? array() );
				$result = $this->mcp_execute_tool( $name, $args );
				$is_err = is_wp_error( $result );
				$text   = $is_err
					? 'Error: ' . $result->get_error_message()
					: ( is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT ) );
				return $this->mcp_ok(
					$id,
					array_filter(
						array(
							'content' => array(
								array(
									'type' => 'text',
									'text' => $text,
								),
							),
							'isError' => $is_err ? true : null,
						),
						fn( $v ) => null !== $v
					)
				);

			default:
				return $this->mcp_error( $id, -32601, 'Method not found' );
		}
	}

	private function mcp_ok( mixed $id, mixed $result ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			)
		);
	}

	private function mcp_error( mixed $id, int $code, string $message ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			400
		);
	}

	private function mcp_tool_definitions(): array {
		return array(
			// File — read.
			array(
				'name'        => 'haydi_list_files',
				'description' => 'List files and directories inside the WordPress site\'s allowed roots.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path' => array(
							'type'        => 'string',
							'description' => 'Directory path to list. Leave empty for all roots.',
						),
					),
				),
			),
			array(
				'name'        => 'haydi_read_file',
				'description' => 'Read the contents of a file inside the allowed roots.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path' => array(
							'type'        => 'string',
							'description' => 'Absolute path to the file.',
						),
					),
					'required'   => array( 'path' ),
				),
			),
			array(
				'name'        => 'haydi_search_files',
				'description' => 'Search file contents inside the allowed roots.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'       => array(
							'type'        => 'string',
							'description' => 'Search query.',
						),
						'path'        => array(
							'type'        => 'string',
							'description' => 'Directory to search in (optional).',
						),
						'mode'        => array(
							'type'        => 'string',
							'enum'        => array( 'literal', 'regex' ),
							'description' => 'Search mode (default: literal).',
						),
						'extensions'  => array(
							'type'        => 'string',
							'description' => 'Comma-separated file extensions, e.g. "php,js" (optional).',
						),
						'max_results' => array(
							'type'        => 'number',
							'description' => 'Maximum results (optional).',
						),
					),
					'required'   => array( 'query' ),
				),
			),
			array(
				'name'        => 'haydi_list_backups',
				'description' => 'List Haydi backup files, optionally filtered by original path.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path' => array(
							'type'        => 'string',
							'description' => 'Filter by original file path (optional).',
						),
					),
				),
			),
			// File — write.
			array(
				'name'        => 'haydi_write_file',
				'description' => 'Create or overwrite a file inside the allowed roots. PHP syntax is validated before writing.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path'    => array(
							'type'        => 'string',
							'description' => 'Absolute path to write to.',
						),
						'content' => array(
							'type'        => 'string',
							'description' => 'Complete file contents.',
						),
						'reason'  => array(
							'type'        => 'string',
							'description' => 'Reason (shown in audit log).',
						),
					),
					'required'   => array( 'path', 'content' ),
				),
			),
			array(
				'name'        => 'haydi_edit_file',
				'description' => 'Exact-string substitution in an existing file. old_string must match current content exactly.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path'        => array(
							'type'        => 'string',
							'description' => 'Absolute path to the file.',
						),
						'old_string'  => array(
							'type'        => 'string',
							'description' => 'Exact string to replace.',
						),
						'new_string'  => array(
							'type'        => 'string',
							'description' => 'Replacement string.',
						),
						'replace_all' => array(
							'type'        => 'boolean',
							'description' => 'Replace all occurrences (default: false).',
						),
						'reason'      => array(
							'type'        => 'string',
							'description' => 'Reason (shown in audit log).',
						),
					),
					'required'   => array( 'path', 'old_string', 'new_string' ),
				),
			),
			array(
				'name'        => 'haydi_delete_file',
				'description' => 'Delete a file from the allowed roots. A backup is created automatically.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path'   => array(
							'type'        => 'string',
							'description' => 'Absolute path to the file.',
						),
						'reason' => array(
							'type'        => 'string',
							'description' => 'Reason (shown in audit log).',
						),
					),
					'required'   => array( 'path' ),
				),
			),
			array(
				'name'        => 'haydi_move_file',
				'description' => 'Move or rename a file inside the allowed roots.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'src'    => array(
							'type'        => 'string',
							'description' => 'Source path.',
						),
						'dest'   => array(
							'type'        => 'string',
							'description' => 'Destination path.',
						),
						'reason' => array(
							'type'        => 'string',
							'description' => 'Reason (shown in audit log).',
						),
					),
					'required'   => array( 'src', 'dest' ),
				),
			),
			array(
				'name'        => 'haydi_copy_file',
				'description' => 'Copy a file inside the allowed roots.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'src'    => array(
							'type'        => 'string',
							'description' => 'Source path.',
						),
						'dest'   => array(
							'type'        => 'string',
							'description' => 'Destination path.',
						),
						'reason' => array(
							'type'        => 'string',
							'description' => 'Reason (shown in audit log).',
						),
					),
					'required'   => array( 'src', 'dest' ),
				),
			),
			array(
				'name'        => 'haydi_delete_directory',
				'description' => 'Recursively delete a directory inside the allowed roots. All files are backed up first.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'path'   => array(
							'type'        => 'string',
							'description' => 'Absolute path to the directory.',
						),
						'reason' => array(
							'type'        => 'string',
							'description' => 'Reason (shown in audit log).',
						),
					),
					'required'   => array( 'path' ),
				),
			),
			array(
				'name'        => 'haydi_restore_backup',
				'description' => 'Restore a file from a Haydi backup. Call haydi_list_backups first to get the backup_file name.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'backup_file'   => array(
							'type'        => 'string',
							'description' => 'Backup file name from haydi_list_backups.',
						),
						'original_path' => array(
							'type'        => 'string',
							'description' => 'Original file path to restore to.',
						),
						'reason'        => array(
							'type'        => 'string',
							'description' => 'Reason (shown in audit log).',
						),
					),
					'required'   => array( 'backup_file', 'original_path' ),
				),
			),
			// Plugins.
			array(
				'name'        => 'haydi_list_plugins',
				'description' => 'List all installed WordPress plugins with name, version, file path, and activation status.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
			),
			array(
				'name'        => 'haydi_install_plugin',
				'description' => 'Install a plugin from WordPress.org by slug.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'slug'   => array(
							'type'        => 'string',
							'description' => 'WordPress.org plugin slug, e.g. "woocommerce".',
						),
						'reason' => array(
							'type'        => 'string',
							'description' => 'Reason (shown in audit log).',
						),
					),
					'required'   => array( 'slug' ),
				),
			),
			array(
				'name'        => 'haydi_activate_plugin',
				'description' => 'Activate an installed WordPress plugin.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => 'Plugin file path, e.g. "woocommerce/woocommerce.php".',
						),
						'reason' => array(
							'type'        => 'string',
							'description' => 'Reason (shown in audit log).',
						),
					),
					'required'   => array( 'plugin' ),
				),
			),
			array(
				'name'        => 'haydi_deactivate_plugin',
				'description' => 'Deactivate an active WordPress plugin.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'plugin' => array(
							'type'        => 'string',
							'description' => 'Plugin file path, e.g. "woocommerce/woocommerce.php".',
						),
						'reason' => array(
							'type'        => 'string',
							'description' => 'Reason (shown in audit log).',
						),
					),
					'required'   => array( 'plugin' ),
				),
			),
			// SQL / PHP / URL.
			array(
				'name'        => 'haydi_run_query',
				'description' => 'Run a SQL query via wpdb. SELECT/SHOW/DESCRIBE/EXPLAIN return rows; other statements return affected-row count.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'sql'    => array(
							'type'        => 'string',
							'description' => 'SQL query to execute.',
						),
						'reason' => array(
							'type'        => 'string',
							'description' => 'Reason (shown in audit log).',
						),
					),
					'required'   => array( 'sql' ),
				),
			),
			array(
				'name'        => 'haydi_run_php',
				'description' => 'Execute a PHP snippet in the WordPress context. Output is captured and returned.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'code'   => array(
							'type'        => 'string',
							'description' => 'PHP code to execute (no opening <?php tag).',
						),
						'reason' => array(
							'type'        => 'string',
							'description' => 'Reason (shown in audit log).',
						),
					),
					'required'   => array( 'code' ),
				),
			),
			array(
				'name'        => 'haydi_fetch_url',
				'description' => 'Fetch a public HTTP/HTTPS URL and return its text content. Private/internal addresses are blocked.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array(
							'type'        => 'string',
							'description' => 'URL to fetch.',
						),
					),
					'required'   => array( 'url' ),
				),
			),
		);
	}

	/**
	 * Execute a named MCP tool and return a string result or WP_Error.
	 */
	private function mcp_execute_tool( string $name, array $args ): string|WP_Error {
		switch ( $name ) {
			// Read tools — existing *_for_ai() helpers return strings already.
			case 'haydi_list_files':
				return $this->file_tool->list_files_for_ai( (string) ( $args['path'] ?? '' ) );
			case 'haydi_read_file':
				return $this->file_tool->read_file_for_ai( (string) ( $args['path'] ?? '' ) );
			case 'haydi_search_files':
				return $this->file_tool->search_files_for_ai(
					(string) ( $args['query'] ?? '' ),
					(string) ( $args['path'] ?? '' ),
					(string) ( $args['mode'] ?? '' ),
					(string) ( $args['extensions'] ?? '' ),
					(string) ( $args['max_results'] ?? '' )
				);
			case 'haydi_list_backups':
				return $this->file_tool->list_backups_for_ai( (string) ( $args['path'] ?? '' ) );
			case 'haydi_list_plugins':
				return $this->plugin_tool->list_plugins_for_ai();
			case 'haydi_fetch_url':
				return $this->url_tool->fetch_for_ai( (string) ( $args['url'] ?? '' ) );

			// Write tools — inline the guard calls and return a summary string.
			case 'haydi_write_file':
				return $this->mcp_do_write_file( $args );
			case 'haydi_edit_file':
				return $this->mcp_do_edit_file( $args );
			case 'haydi_delete_file':
				return $this->mcp_do_delete_file( $args );
			case 'haydi_move_file':
				return $this->mcp_do_move_file( $args );
			case 'haydi_copy_file':
				return $this->mcp_do_copy_file( $args );
			case 'haydi_delete_directory':
				return $this->mcp_do_delete_dir( $args );
			case 'haydi_restore_backup':
				return $this->mcp_do_restore_backup( $args );
			case 'haydi_install_plugin':
				return $this->mcp_do_install_plugin( $args );
			case 'haydi_activate_plugin':
				return $this->mcp_do_activate_plugin( $args );
			case 'haydi_deactivate_plugin':
				return $this->mcp_do_deactivate_plugin( $args );
			case 'haydi_run_query':
				return $this->mcp_do_run_query( $args );
			case 'haydi_run_php':
				return $this->mcp_do_run_php( $args );

			default:
				return new WP_Error( 'unknown_tool', "Unknown tool: {$name}" );
		}
	}

	private function mcp_do_write_file( array $args ): string|WP_Error {
		$result = $this->file_tool->execute_write(
			(string) ( $args['path'] ?? '' ),
			(string) ( $args['content'] ?? '' ),
			(string) ( $args['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return "File written successfully: {$result['path']}";
	}

	private function mcp_do_edit_file( array $args ): string|WP_Error {
		$result = $this->file_tool->execute_edit(
			(string) ( $args['path'] ?? '' ),
			(string) ( $args['old_string'] ?? '' ),
			(string) ( $args['new_string'] ?? '' ),
			(bool) ( $args['replace_all'] ?? false ),
			(string) ( $args['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return "File edited successfully ({$result['matches']} match(es)): {$result['path']}";
	}

	private function mcp_do_delete_file( array $args ): string|WP_Error {
		$reason = (string) ( $args['reason'] ?? '' );
		$result = $this->file_tool->execute_delete(
			(string) ( $args['path'] ?? '' ),
			'' !== $reason ? $reason : 'MCP-initiated deletion.'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return "File deleted successfully: {$result['path']}";
	}

	private function mcp_do_move_file( array $args ): string|WP_Error {
		$result = $this->file_tool->execute_move(
			(string) ( $args['src'] ?? '' ),
			(string) ( $args['dest'] ?? '' ),
			(string) ( $args['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return "File moved successfully: {$result['src']} → {$result['dest']}";
	}

	private function mcp_do_copy_file( array $args ): string|WP_Error {
		$result = $this->file_tool->execute_copy(
			(string) ( $args['src'] ?? '' ),
			(string) ( $args['dest'] ?? '' ),
			(string) ( $args['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return "File copied successfully: {$result['src']} → {$result['dest']}";
	}

	private function mcp_do_delete_dir( array $args ): string|WP_Error {
		$reason = (string) ( $args['reason'] ?? '' );
		$result = $this->file_tool->execute_delete_dir(
			(string) ( $args['path'] ?? '' ),
			'' !== $reason ? $reason : 'MCP-initiated directory deletion.'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return "Directory deleted successfully: {$result['path']}";
	}

	private function mcp_do_restore_backup( array $args ): string|WP_Error {
		$result = $this->file_tool->execute_restore_backup(
			(string) ( $args['backup_file'] ?? '' ),
			(string) ( $args['original_path'] ?? '' ),
			(string) ( $args['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return "Backup restored successfully: {$result['backup_file']} → {$result['original_path']}";
	}

	private function mcp_do_install_plugin( array $args ): string|WP_Error {
		$result = $this->plugin_tool->execute_install(
			sanitize_key( (string) ( $args['slug'] ?? '' ) ),
			(string) ( $args['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return "Plugin '{$result['slug']}' installed successfully. Plugin file: {$result['plugin_file']}";
	}

	private function mcp_do_activate_plugin( array $args ): string|WP_Error {
		$result = $this->plugin_tool->execute_activate(
			(string) ( $args['plugin'] ?? '' ),
			(string) ( $args['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return "Plugin '{$result['plugin']}' activated successfully.";
	}

	private function mcp_do_deactivate_plugin( array $args ): string|WP_Error {
		$result = $this->plugin_tool->execute_deactivate(
			(string) ( $args['plugin'] ?? '' ),
			(string) ( $args['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return "Plugin '{$result['plugin']}' deactivated successfully.";
	}

	private function mcp_do_run_query( array $args ): string|WP_Error {
		$result = $this->query_tool->execute_query(
			trim( (string) ( $args['sql'] ?? '' ) ),
			(string) ( $args['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 'select' === $result['type'] ) {
			return wp_json_encode( $result['rows'], JSON_PRETTY_PRINT );
		}
		return $result['result'];
	}

	private function mcp_do_run_php( array $args ): string|WP_Error {
		$result = $this->php_tool->execute_php(
			trim( (string) ( $args['code'] ?? '' ) ),
			(string) ( $args['reason'] ?? '' )
		);
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$output = is_array( $data ) && isset( $data['output'] ) ? $data['output'] : '';
			$msg    = $result->get_error_message();
			return new WP_Error( $result->get_error_code(), $msg . ( '' !== $output ? "\nOutput: {$output}" : '' ) );
		}
		return $result['output'];
	}
}
