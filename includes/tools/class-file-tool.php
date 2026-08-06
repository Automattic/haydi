<?php
/**
 * File read tool — exposes list_files, read_file, search_files, list_backups,
 * and the haydi_read_file AJAX endpoint (used by the inline file viewer in chat).
 *
 * Approval-gated mutations live in class-file-actions.php.
 */

defined( 'ABSPATH' ) || exit;

class Haydi_File_Tool extends Haydi_Ajax_Tool_Base {

	/** @var Haydi_Filesystem_Guard Filesystem access guard. */
	private Haydi_Filesystem_Guard $guard;

	public function __construct( Haydi_Audit_Logger $logger, Haydi_Filesystem_Guard $guard ) {
		parent::__construct( $logger );
		$this->guard = $guard;
	}

	public function register(): void {
		add_action( 'wp_ajax_haydi_read_file', array( $this, 'handle_read_file' ) );
	}

	/**
	 * Register the canonical file-read Tool Declarations and Implementations.
	 */
	public function register_tools( Haydi_Tool_Catalog $catalog ): void {
		$catalog->register(
			array(
				'name'           => 'list_files',
				'description'    => 'List files and sub-directories inside a directory within an allowed root.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'path' => array(
							'type'        => 'string',
							'description' => 'Absolute filesystem path of the directory to list.',
						),
					),
					'required'   => array( 'path' ),
				),
				'effect'         => 'automatic',
				'activity_label' => 'Listed files',
				'projections'    => array(
					'chat' => true,
					'mcp'  => array(
						'name'        => 'haydi_list_files',
						'description' => 'List files and directories inside the WordPress site\'s allowed roots.',
						'required'    => array(),
					),
				),
			),
			fn( array $arguments ): array|WP_Error => $this->list_files_for_ai( (string) ( $arguments['path'] ?? '' ) )
		);

		$catalog->register(
			array(
				'name'           => 'read_file',
				'description'    => 'Read and return the full text content of a file within an allowed root.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'path' => array(
							'type'        => 'string',
							'description' => 'Absolute filesystem path of the file to read.',
						),
					),
					'required'   => array( 'path' ),
				),
				'effect'         => 'automatic',
				'activity_label' => 'Read file',
				'projections'    => array(
					'chat' => true,
					'mcp'  => array(
						'name'        => 'haydi_read_file',
						'description' => 'Read the contents of a file inside the allowed roots.',
					),
				),
			),
			fn( array $arguments ): array|WP_Error => $this->read_file_for_ai( (string) ( $arguments['path'] ?? '' ) )
		);

		$catalog->register(
			array(
				'name'           => 'search_files',
				'description'    => 'Search text content inside files under the allowed roots. This is a PHP-based scanner for shared hosting, not shell grep. Use before reading many files manually when looking for hooks, functions, classes, shortcodes, option names, strings, or other code references. Empty strings use defaults for path, mode, extensions, and max_results.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'query'       => array(
							'type'        => 'string',
							'description' => 'Text or regex to search for.',
						),
						'path'        => array(
							'type'        => 'string',
							'description' => 'Absolute allowed directory/file to search, or empty string to search all allowed roots.',
						),
						'mode'        => array(
							'type'        => 'string',
							'description' => '"literal" for case-insensitive text search, "regex" for preg_match search, or empty string for literal.',
						),
						'extensions'  => array(
							'type'        => 'string',
							'description' => 'Comma-separated allowed extensions to scan (for example "php,js"), or empty string for all allowed extensions.',
						),
						'max_results' => array(
							'type'        => 'string',
							'description' => 'Maximum matches to return, 1-100, or empty string for the default.',
						),
					),
					'required'   => array( 'query', 'path', 'mode', 'extensions', 'max_results' ),
				),
				'effect'         => 'automatic',
				'activity_label' => 'Searched files',
				'projections'    => array(
					'chat' => true,
					'mcp'  => array(
						'name'               => 'haydi_search_files',
						'description'        => 'Search file contents inside the allowed roots.',
						'required'           => array( 'query' ),
						'property_overrides' => array(
							'query'       => array( 'description' => 'Search query.' ),
							'path'        => array( 'description' => 'Directory to search in (optional).' ),
							'mode'        => array(
								'enum'        => array( 'literal', 'regex' ),
								'description' => 'Search mode (default: literal).',
							),
							'extensions'  => array( 'description' => 'Comma-separated file extensions, e.g. "php,js" (optional).' ),
							'max_results' => array(
								'type'        => 'number',
								'description' => 'Maximum results (optional).',
							),
						),
					),
				),
			),
			fn( array $arguments ): array|WP_Error => $this->search_files_for_ai(
				(string) ( $arguments['query'] ?? '' ),
				(string) ( $arguments['path'] ?? '' ),
				(string) ( $arguments['mode'] ?? '' ),
				(string) ( $arguments['extensions'] ?? '' ),
				(string) ( $arguments['max_results'] ?? '' )
			)
		);

		$catalog->register(
			array(
				'name'           => 'list_backups',
				'description'    => 'List available backup files created by this plugin. Always call this tool when the user asks about backups or wants to restore a file — never assume or guess what backups exist. Returns backup_file names (needed for restore_backup), original filenames, and timestamps.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'path' => array(
							'type'        => 'string',
							'description' => 'Optional absolute path of the original file to filter backups for. Leave empty to list all backups.',
						),
					),
					'required'   => array(),
				),
				'effect'         => 'automatic',
				'activity_label' => 'Listed backups',
				'projections'    => array(
					'chat' => true,
					'mcp'  => array(
						'name'        => 'haydi_list_backups',
						'description' => 'List Haydi backup files, optionally filtered by original path.',
					),
				),
			),
			fn( array $arguments ): array => $this->list_backups_for_ai( (string) ( $arguments['path'] ?? '' ) )
		);

		$catalog->register(
			array(
				'name'           => 'get_allowed_roots',
				'description'    => 'Return the list of absolute directory paths that Haydi is allowed to read from and write to.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(),
					'required'   => array(),
				),
				'effect'         => 'automatic',
				'activity_label' => 'Listed allowed roots',
				'projections'    => array(
					'chat' => false,
					'mcp'  => array( 'name' => 'haydi_get_allowed_roots' ),
				),
			),
			fn(): array => array( 'allowed_roots' => array_values( $this->guard->get_allowed_roots() ) )
		);
	}

	// -------------------------------------------------------------------------
	// AI-facing read methods used by Tool Catalog dispatch.
	// -------------------------------------------------------------------------

	/**
	 * Return a structured directory listing for the AI.
	 *
	 * @param string $path Directory path to list.
	 * @return array|WP_Error
	 */
	public function list_files_for_ai( string $path ): array|WP_Error {
		$result = $this->guard->list_files( $path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->logger->log( 'list_files', $path );
		return array(
			'path'  => $path,
			'files' => $result,
		);
	}

	public function read_file_for_ai( string $path ): array|WP_Error {
		$result = $this->guard->read_file( $path );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->logger->log( 'read_file', $path );
		return array(
			'path'    => $path,
			'content' => $result,
		);
	}

	public function search_files_for_ai(
		string $query,
		string $path = '',
		string $mode = 'literal',
		string $extensions = '',
		string $max_results = ''
	): array|WP_Error {
		$max_results_int = Haydi_Filesystem_Guard::DEFAULT_SEARCH_RESULTS;
		if ( '' !== trim( $max_results ) ) {
			$max_results_int = (int) $max_results;
		}

		$result = $this->guard->search_files( $query, $path, $mode, $extensions, $max_results_int );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->logger->log( 'search_files', $path, $query );
		return $result;
	}

	public function list_backups_for_ai( string $original_path = '' ): array {
		$backups = $this->guard->list_backups( $original_path );
		foreach ( $backups as &$backup ) {
			$backup['date'] = gmdate( 'c', $backup['timestamp'] );
		}
		unset( $backup );

		return array( 'backups' => $backups );
	}

	// -------------------------------------------------------------------------
	// Browser-facing AJAX handler (inline file viewer in chat)
	// -------------------------------------------------------------------------

	/**
	 * Read a file and return its contents — used by the inline file viewer in chat.
	 */
	public function handle_read_file(): void {
		$this->verify();

		$path = $this->post_param( 'path' );
		if ( '' === $path ) {
			wp_send_json_error( array( 'message' => 'path is required.' ) );
		}

		$result = $this->guard->read_file( $path );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$this->logger->log( 'read_file', $path );
		wp_send_json_success(
			array(
				'content' => $result,
				'path'    => $path,
			)
		);
	}
}
