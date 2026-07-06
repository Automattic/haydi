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

	/** @var Haydi_Filesystem_Guard Filesystem access guard. */
	private Haydi_Filesystem_Guard $guard;
	/** @var Haydi_File_Tool File-ops tool. */
	private Haydi_File_Tool $file_tool;
	/** @var Haydi_Plugin_Tool Plugin-ops tool. */
	private Haydi_Plugin_Tool $plugin_tool;
	/** @var Haydi_Fetch_Url_Tool URL fetch tool. */
	private Haydi_Fetch_Url_Tool $url_tool;
	/** @var Haydi_Audit_Logger Audit logger. */
	private Haydi_Audit_Logger $logger;
	/** @var Haydi_Health_Check Post-mutation health probe. */
	private Haydi_Health_Check $health;

	public function __construct() {
		$this->logger      = new Haydi_Audit_Logger();
		$this->guard       = new Haydi_Filesystem_Guard();
		$this->health      = new Haydi_Health_Check();
		$this->file_tool   = new Haydi_File_Tool( $this->logger, $this->guard );
		$this->plugin_tool = new Haydi_Plugin_Tool( $this->logger, $this->health );
		$this->url_tool    = new Haydi_Fetch_Url_Tool( $this->logger );

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

		// Single file read.
		register_rest_route(
			self::NAMESPACE,
			'/file',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_read_file' ),
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

		// URL fetch.
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
		return haydi_is_authorized_api_request( $request );
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
				'haydi_version' => '1.0.1',
				'allowed_roots' => array_values( $this->guard->get_allowed_roots() ),
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
	// URL
	// -------------------------------------------------------------------------

	/**
	 * Fetch a public URL and return its text content.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
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
						'capabilities'    => array(
							'tools'     => new \stdClass(),
							'resources' => new \stdClass(),
						),
						'serverInfo'      => array(
							'name'    => 'haydi',
							'version' => '1.0.1',
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

			case 'resources/list':
				return $this->mcp_ok(
					$id,
					array(
						'resources' => array(
							array(
								'uri'      => 'haydi://agents',
								'name'     => 'Agent instructions',
								'mimeType' => 'text/markdown',
							),
						),
					)
				);

			case 'resources/read':
				$uri = (string) ( $params['uri'] ?? '' );
				if ( 'haydi://agents' !== $uri ) {
					return $this->mcp_error( $id, -32602, 'Unknown resource URI' );
				}
				return $this->mcp_ok(
					$id,
					array(
						'contents' => array(
							array(
								'uri'      => 'haydi://agents',
								'mimeType' => 'text/markdown',
								'text'     => $this->build_agents_content(),
							),
						),
					)
				);

			default:
				return $this->mcp_error( $id, -32601, 'Method not found' );
		}
	}

	/**
	 * Build the haydi://agents resource content dynamically based on which
	 * extensions are currently loaded, so MCP clients only see tools that exist.
	 */
	private function build_agents_content(): string {
		// Tool groups — keyed so extensions can replace core entries (e.g. upgrade
		// 'file' from read-only to read+write) or add new ones.
		$tool_groups = apply_filters(
			'haydi_agents_tool_groups',
			array(
				'file'    => '**File ops** — read and search (install haydi-files.php to enable writes)',
				'content' => '**Content** — list posts, list users, list options',
				'plugins' => '**Plugins** — list, install (from wordpress.org by slug), activate, deactivate',
				'url'     => '**URL** — fetch public HTTP/HTTPS URLs; private/internal addresses are blocked',
			)
		);
		$groups      = array_map( static fn( $g ) => '- ' . $g, array_values( $tool_groups ) );

		// Safety rules — extensions append their own; auto-numbered by position.
		$rule_texts = apply_filters(
			'haydi_agents_safety_rules',
			array(
				'Call `haydi_get_allowed_roots` before writing files to confirm a valid target path.',
				'Always supply a `reason` field on mutating calls — it appears in the audit log.',
			)
		);
		$rules      = array_map(
			static fn( $i, $r ) => ( $i + 1 ) . '. ' . $r,
			array_keys( array_values( $rule_texts ) ),
			array_values( $rule_texts )
		);

		// Workflows — extensions append complete workflow blocks (plain strings).
		$workflow_blocks = apply_filters(
			'haydi_agents_workflows',
			array(
				implode(
					"\n",
					array(
						'**Install and activate a plugin**',
						'1. `haydi_install_plugin` (provide slug, e.g. `"woocommerce"`)',
						'2. `haydi_list_plugins` — find the plugin file path in the results',
						'3. `haydi_activate_plugin` (provide that file path)',
					)
				),
			)
		);

		return implode(
			"\n",
			array(
				'# Haydi — AI Agent Instructions',
				'',
				'You are connected to a WordPress site via the Haydi MCP server.',
				'',
				'## Available tool groups',
				'',
				implode( "\n", $groups ),
				'',
				'## Safety rules — follow these in order',
				'',
				implode( "\n", $rules ),
				'',
				'## Common workflows',
				'',
				implode( "\n\n", $workflow_blocks ),
			)
		);
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
		$can_mod_plugins = wp_is_file_mod_allowed( 'plugin_files' );

		$tools = array(
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
			array(
				'name'        => 'haydi_get_allowed_roots',
				'description' => 'Return the list of absolute directory paths that Haydi is allowed to read from and write to.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
			),
			// Content.
			array(
				'name'        => 'haydi_list_posts',
				'description' => 'List WordPress posts/pages with ID, title, status, type, date, and content.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array(
							'type'        => 'string',
							'description' => 'Post status filter (optional, default: any).',
						),
						'type'   => array(
							'type'        => 'string',
							'description' => 'Post type filter (optional, default: post,page).',
						),
						'limit'  => array(
							'type'        => 'number',
							'description' => 'Maximum number to return (optional, default: 50, max: 200).',
						),
					),
				),
			),
			array(
				'name'        => 'haydi_list_users',
				'description' => 'List WordPress users with ID, login, email, display_name, and roles.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'role'  => array(
							'type'        => 'string',
							'description' => 'Role filter (optional).',
						),
						'limit' => array(
							'type'        => 'number',
							'description' => 'Maximum number to return (optional, default: 50, max: 200).',
						),
					),
				),
			),
			array(
				'name'        => 'haydi_list_options',
				'description' => 'List WordPress options. Without search returns autoloaded options; with search filters by option_name substring.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search' => array(
							'type'        => 'string',
							'description' => 'Substring to filter option_name (optional).',
						),
					),
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
			// URL.
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
			// Extensions.
			array(
				'name'        => 'haydi_list_extensions',
				'description' => 'List known Haydi extensions and whether each one is currently installed.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
			),
		);

		if ( ! $can_mod_plugins ) {
			$tools = array_values( array_filter( $tools, fn( $t ) => 'haydi_install_plugin' !== $t['name'] ) );
		}

		return apply_filters( 'haydi_mcp_tools', $tools );
	}

	/**
	 * Execute a named MCP tool and return a string result or WP_Error.
	 */
	private function mcp_execute_tool( string $name, array $args ): string|WP_Error {
		switch ( $name ) {
			case 'haydi_get_allowed_roots':
				return wp_json_encode( array_values( $this->guard->get_allowed_roots() ), JSON_PRETTY_PRINT );
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
			case 'haydi_list_posts':
				return $this->mcp_do_list_posts( $args );
			case 'haydi_list_users':
				return $this->mcp_do_list_users( $args );
			case 'haydi_list_options':
				return $this->mcp_do_list_options( $args );
			case 'haydi_list_extensions':
				return $this->mcp_do_list_extensions();
			case 'haydi_install_plugin':
				return $this->mcp_do_install_plugin( $args );
			case 'haydi_activate_plugin':
				return $this->mcp_do_activate_plugin( $args );
			case 'haydi_deactivate_plugin':
				return $this->mcp_do_deactivate_plugin( $args );
			default:
				$filtered = apply_filters( 'haydi_mcp_execute_tool', null, $name, $args );
				if ( null !== $filtered ) {
					return $filtered;
				}
				return new WP_Error( 'unknown_tool', "Unknown tool: {$name}" );
		}
	}

	private function mcp_do_list_extensions(): string {
		$this->logger->log( 'list_extensions', '' );
		return wp_json_encode( apply_filters( 'haydi_known_extensions', array() ), JSON_PRETTY_PRINT );
	}

	private function mcp_do_list_posts( array $args ): string {
		$status = (string) ( $args['status'] ?? '' );
		$type   = (string) ( $args['type'] ?? '' );
		$limit  = (string) ( $args['limit'] ?? '' );
		$query  = array(
			'post_status'    => '' !== $status ? sanitize_text_field( $status ) : 'any',
			'post_type'      => '' !== $type ? sanitize_text_field( $type ) : array( 'post', 'page' ),
			'posts_per_page' => '' !== $limit ? min( 200, (int) $limit ) : 50,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		$posts  = get_posts( $query );
		$rows   = array();
		foreach ( $posts as $post ) {
			$rows[] = array(
				'ID'      => $post->ID,
				'title'   => $post->post_title,
				'status'  => $post->post_status,
				'type'    => $post->post_type,
				'date'    => $post->post_date,
				'content' => $post->post_content,
			);
		}
		$this->logger->log( 'list_posts', '' );
		return wp_json_encode( $rows, JSON_PRETTY_PRINT );
	}

	private function mcp_do_list_users( array $args ): string {
		$role  = (string) ( $args['role'] ?? '' );
		$limit = (string) ( $args['limit'] ?? '' );
		$query = array(
			'number'  => '' !== $limit ? min( 200, (int) $limit ) : 50,
			'orderby' => 'user_registered',
			'order'   => 'DESC',
		);
		if ( '' !== $role ) {
			$query['role'] = sanitize_text_field( $role );
		}
		$users = get_users( $query );
		$rows  = array();
		foreach ( $users as $user ) {
			$rows[] = array(
				'ID'           => $user->ID,
				'login'        => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
				'roles'        => $user->roles,
			);
		}
		$this->logger->log( 'list_users', '' );
		return wp_json_encode( $rows, JSON_PRETTY_PRINT );
	}

	private function mcp_do_list_options( array $args ): string {
		global $wpdb;
		$search = (string) ( $args['search'] ?? '' );
		if ( '' !== $search ) {
			$like = $wpdb->esc_like( sanitize_text_field( $search ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 100", '%' . $like . '%' ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE autoload = 'yes' ORDER BY option_name LIMIT 100",
				ARRAY_A
			);
		}
		foreach ( $rows as &$row ) {
			if ( strlen( $row['option_value'] ) > 500 ) {
				$row['option_value'] = substr( $row['option_value'], 0, 500 ) . '...(truncated)';
			}
		}
		unset( $row );
		$this->logger->log( 'list_options', '', $search );
		return wp_json_encode( $rows, JSON_PRETTY_PRINT );
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
}
