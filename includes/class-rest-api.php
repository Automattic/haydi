<?php
/**
 * Haydi REST API — exposes all Haydi tools over the WordPress REST API.
 *
 * Authentication: Bearer token only, generated in WP-Admin → Haydi → Remote
 * Access. An active WordPress session is not sufficient on its own — the
 * token is the explicit, auditable act that stands in for the human
 * approval click the chat UI requires for the same operations.
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
	/** @var Haydi_Plugin_Tool Plugin-ops tool. */
	private Haydi_Plugin_Tool $plugin_tool;
	/** @var Haydi_Fetch_Url_Tool URL fetch tool. */
	private Haydi_Fetch_Url_Tool $url_tool;
	/** @var Haydi_Audit_Logger Audit logger. */
	private Haydi_Audit_Logger $logger;
	/** @var Haydi_Tool_Catalog|null Canonical MCP declarations and dispatch. */
	private ?Haydi_Tool_Catalog $tool_catalog = null;

	public function __construct( ?Haydi_Tool_Catalog $tool_catalog = null ) {
		$this->logger       = new Haydi_Audit_Logger();
		$this->guard        = new Haydi_Filesystem_Guard();
		$health             = new Haydi_Health_Check();
		$this->plugin_tool  = new Haydi_Plugin_Tool( $this->logger, $health );
		$this->url_tool     = new Haydi_Fetch_Url_Tool( $this->logger );
		$this->tool_catalog = $tool_catalog;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	private function tool_catalog(): Haydi_Tool_Catalog {
		if ( ! isset( $this->tool_catalog ) || null === $this->tool_catalog ) {
			$this->tool_catalog = haydi_get_tool_catalog();
		}
		return $this->tool_catalog;
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
	 * Verify a valid Bearer token. A WordPress session alone is not enough.
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
				'haydi_version' => '1.2.1',
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
		return new WP_REST_Response( $this->plugin_tool->list_plugins_for_ai() );
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
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'message' => $result->get_error_message() ), 400 );
		}

		return new WP_REST_Response( array( 'content' => $result['content'] ) );
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
							'version' => '1.2.1',
						),
					)
				);

			case 'ping':
				return $this->mcp_ok( $id, new \stdClass() );

			case 'tools/list':
				return $this->mcp_ok(
					$id,
					array( 'tools' => $this->tool_catalog()->declarations( Haydi_Tool_Catalog::MCP ) )
				);

			case 'tools/call':
				$name    = (string) ( $params['name'] ?? '' );
				$args    = (array) ( $params['arguments'] ?? array() );
				$outcome = $this->tool_catalog()->dispatch( Haydi_Tool_Catalog::MCP, $name, $args );
				$is_err  = is_wp_error( $outcome );
				if ( $is_err ) {
					$text = 'Error: ' . $outcome->get_error_message();
				} else {
					$result = $outcome['result'];
					$text   = is_string( $result ) ? $result : wp_json_encode( $result, JSON_PRETTY_PRINT );
					if ( ! is_string( $text ) ) {
						$text   = 'Error: Tool result could not be serialized.';
						$is_err = true;
					}
				}
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
	 * Build the haydi://agents resource content.
	 */
	private function build_agents_content(): string {
		// Tool groups are filterable by host-specific integrations.
		$tool_groups = apply_filters(
			'haydi_agents_tool_groups',
			array(
				'file'    => '**File ops** — read, write, edit, search, move, copy, delete, restore backups',
				'content' => '**Content** — list posts, list users, list options',
				'plugins' => '**Plugins** — list, install (from wordpress.org by slug), activate, deactivate',
				'sql'     => '**SQL** — run queries via wpdb; SELECT/SHOW/DESCRIBE/EXPLAIN return rows, writes return affected-row count',
				'php'     => '**PHP** — execute snippets in the live WordPress context; output is captured and returned',
				'url'     => '**URL** — fetch public HTTP/HTTPS URLs; private/internal addresses are blocked',
			)
		);
		$groups      = array_map( static fn( $g ) => '- ' . $g, array_values( $tool_groups ) );

		// Safety rules are auto-numbered by position.
		$rule_texts = apply_filters(
			'haydi_agents_safety_rules',
			array(
				'Call `haydi_get_allowed_roots` before writing files to confirm a valid target path.',
				'Prefer `haydi_edit_file` (exact-string substitution) over a full `haydi_write_file` rewrite.',
				'Always supply a `reason` field on mutating calls — it appears in the audit log.',
				'Before deleting anything, call `haydi_list_backups` so you know what is recoverable.',
				'Never drop or truncate core WordPress tables via `haydi_run_query`.',
				'PHP runs in the live site context — test defensively and keep snippets focused.',
			)
		);
		$rules      = array_map(
			static fn( $i, $r ) => ( $i + 1 ) . '. ' . $r,
			array_keys( array_values( $rule_texts ) ),
			array_values( $rule_texts )
		);

		// Workflows are complete blocks so integrations can append their own.
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
				implode(
					"\n",
					array(
						'**Edit a theme or plugin file**',
						'1. `haydi_list_files` — browse to locate the file',
						'2. `haydi_read_file` — read current contents',
						'3. `haydi_edit_file` — replace only the section that needs changing',
					)
				),
				implode(
					"\n",
					array(
						'**Recover from a mistake**',
						'1. `haydi_list_backups` — find the backup entry for the affected path',
						'2. `haydi_restore_backup` — restore it using the backup file name',
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
}
