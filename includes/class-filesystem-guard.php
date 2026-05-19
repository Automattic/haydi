<?php
/**
 * Filesystem Guard — enforces all path and file-type restrictions.
 *
 * Every public method validates its path argument against the allowlist before
 * doing anything.  This class is the single chokepoint between the AI's tool
 * calls and the real filesystem.
 */

defined( 'ABSPATH' ) || exit;

class Haydi_Filesystem_Guard {

	/** Extensions that may be read or written. */
	const ALLOWED_EXTENSIONS = array( 'php', 'css', 'js', 'json', 'txt', 'md', 'html' );

	/** Hard cap on file read / write content (512 KB). */
	const MAX_FILE_SIZE = 524288;

	/** Hard cap on files included in a Playground preflight bundle. */
	const MAX_PLAYGROUND_FILES = 250;

	/** Hard cap on total bytes included in a Playground preflight bundle. */
	const MAX_PLAYGROUND_BYTES = 2097152;

	/** Default maximum number of content-search matches returned to the AI. */
	const DEFAULT_SEARCH_RESULTS = 50;

	/** Hard cap on content-search matches returned to the AI. */
	const MAX_SEARCH_RESULTS = 100;

	/** Hard cap on files scanned by one content-search call. */
	const MAX_SEARCH_FILES = 2000;

	/** Hard cap on content-search runtime in seconds. */
	const MAX_SEARCH_SECONDS = 2.0;

	/** @var array<string,string>  label => realpath */
	private array $allowed_roots = array();

	public function __construct() {
		$this->allowed_roots = $this->build_allowed_roots();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/** Return the resolved allowed-root map. */
	public function get_allowed_roots(): array {
		return $this->allowed_roots;
	}

	/**
	 * List allowed files/dirs inside $path.
	 *
	 * @return array[]|WP_Error
	 */
	public function list_files( string $path ) {
		$validated = $this->validate_path( $path );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( ! is_dir( $validated ) ) {
			return new WP_Error( 'not_a_directory', 'Path is not a directory.' );
		}

		$items   = array();
		$entries = scandir( $validated );

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			// Skip hidden / dot-files.
			if ( str_starts_with( $entry, '.' ) ) {
				continue;
			}

			$full = $validated . DIRECTORY_SEPARATOR . $entry;

			if ( is_dir( $full ) ) {
				$items[] = array(
					'name' => $entry,
					'type' => 'directory',
					'path' => $full,
				);
			} elseif ( is_file( $full ) ) {
				$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
				if ( in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
					$items[] = array(
						'name' => $entry,
						'type' => 'file',
						'path' => $full,
						'size' => filesize( $full ),
					);
				}
			}
		}

		return $items;
	}

	/**
	 * Read and return file content.
	 *
	 * @return string|WP_Error
	 */
	public function read_file( string $path ) {
		$validated = $this->validate_path( $path );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( ! is_file( $validated ) ) {
			return new WP_Error( 'not_a_file', 'Path is not a file.' );
		}

		if ( filesize( $validated ) > self::MAX_FILE_SIZE ) {
			return new WP_Error(
				'file_too_large',
				sprintf( 'File exceeds the %d byte limit.', self::MAX_FILE_SIZE )
			);
		}

		$content = file_get_contents( $validated ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $content ) {
			return new WP_Error( 'read_error', 'Could not read file.' );
		}

		return $content;
	}

	/**
	 * Search text content inside allowed files.
	 *
	 * This is a shared-host-friendly grep: no shell, no exec(), no external
	 * binaries. It walks only allowed roots/paths, scans allowed extensions,
	 * skips hidden/generated-heavy directories, and stops at conservative caps.
	 *
	 * @param  string $query       Literal text or regex body/pattern.
	 * @param  string $path        Optional allowed file/directory to search. Empty = all roots.
	 * @param  string $mode        "literal" (default) or "regex".
	 * @param  string $extensions  Optional comma/space-separated extension list.
	 * @param  int    $max_results Requested result cap.
	 * @return array|WP_Error
	 */
	public function search_files(
		string $query,
		string $path = '',
		string $mode = 'literal',
		string $extensions = '',
		int $max_results = self::DEFAULT_SEARCH_RESULTS
	) {
		$query = trim( $query );
		if ( '' === $query ) {
			return new WP_Error( 'empty_query', 'Search query is required.' );
		}

		$mode = '' === $mode ? 'literal' : strtolower( trim( $mode ) );
		if ( ! in_array( $mode, array( 'literal', 'regex' ), true ) ) {
			return new WP_Error( 'invalid_search_mode', 'Search mode must be "literal" or "regex".' );
		}

		$allowed_extensions = $this->normalise_search_extensions( $extensions );
		if ( is_wp_error( $allowed_extensions ) ) {
			return $allowed_extensions;
		}

		$roots = $this->search_roots_for_path( $path );
		if ( is_wp_error( $roots ) ) {
			return $roots;
		}

		$max_results = max( 1, min( self::MAX_SEARCH_RESULTS, $max_results ) );
		$started_at  = microtime( true );
		$matches     = array();
		$scanned     = 0;
		$skipped     = 0;
		$truncated   = false;

		foreach ( $roots as $root ) {
			$files = is_file( $root )
				? array( new SplFileInfo( $root ) )
				: $this->get_search_file_iterator( $root );

			foreach ( $files as $file ) {
				if ( ( microtime( true ) - $started_at ) >= self::MAX_SEARCH_SECONDS ) {
					$truncated = true;
					break 2;
				}

				if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
					continue;
				}

				$real = $file->getRealPath();
				if ( false === $real || ! $this->is_searchable_file( $real, $allowed_extensions ) ) {
					++$skipped;
					continue;
				}

				if ( $file->getSize() > self::MAX_FILE_SIZE ) {
					++$skipped;
					continue;
				}

				++$scanned;
				if ( $scanned > self::MAX_SEARCH_FILES ) {
					$truncated = true;
					break 2;
				}

				$file_matches = $this->search_file_lines( $real, $query, $mode, $max_results - count( $matches ) );
				if ( is_wp_error( $file_matches ) ) {
					return $file_matches;
				}
				foreach ( $file_matches as $match ) {
					$matches[] = $match;
					if ( count( $matches ) >= $max_results ) {
						$truncated = true;
						break 3;
					}
				}
			}
		}

		return array(
			'matches'       => $matches,
			'count'         => count( $matches ),
			'truncated'     => $truncated,
			'scanned_files' => $scanned,
			'skipped_files' => $skipped,
			'mode'          => $mode,
			'extensions'    => array_values( $allowed_extensions ),
		);
	}

	/**
	 * Prepare an exact-string edit against the current file content.
	 *
	 * This does not write. It re-reads the live file and returns the full
	 * proposed content so the caller can lint/preview/apply through write_file().
	 *
	 * @return array{path:string,content:string,matches:int}|WP_Error
	 */
	public function prepare_edit_file( string $path, string $old_string, string $new_string, bool $replace_all = false ) {
		$validated = $this->validate_path( $path );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( ! is_file( $validated ) ) {
			return new WP_Error( 'not_a_file', 'Path is not a file.' );
		}

		if ( '' === $old_string ) {
			return new WP_Error( 'empty_old_string', 'oldString is required.' );
		}

		if ( filesize( $validated ) > self::MAX_FILE_SIZE ) {
			return new WP_Error(
				'file_too_large',
				sprintf( 'File exceeds the %d byte limit.', self::MAX_FILE_SIZE )
			);
		}

		$content = file_get_contents( $validated ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $content ) {
			return new WP_Error( 'read_error', 'Could not read file.' );
		}

		$matches = substr_count( $content, $old_string );
		if ( 0 === $matches ) {
			return new WP_Error( 'edit_no_match', 'oldString was not found in the current file content.' );
		}

		if ( ! $replace_all && $matches > 1 ) {
			return new WP_Error( 'edit_ambiguous_match', 'oldString appears more than once. Set replaceAll to true or provide a more specific oldString.' );
		}

		$new_content = str_replace( $old_string, $new_string, $content );
		if ( strlen( $new_content ) > self::MAX_FILE_SIZE ) {
			return new WP_Error( 'content_too_large', 'Edited content exceeds the maximum file size limit.' );
		}

		return array(
			'path'    => $validated,
			'content' => $new_content,
			'matches' => $matches,
		);
	}

	/**
	 * Create a timestamped backup of an existing file.
	 *
	 * @return string|WP_Error  Path to the backup file on success.
	 */
	public function backup_file( string $path ) {
		$validated = $this->validate_path( $path );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( ! is_file( $validated ) ) {
			return new WP_Error( 'not_a_file', 'Cannot back up: path is not a file.' );
		}

		$backup_dir = WP_CONTENT_DIR . '/uploads/haydi-backups';
		if ( ! is_dir( $backup_dir ) ) {
			wp_mkdir_p( $backup_dir );
			// Apache: deny via .htaccess. Nginx/LiteSpeed/IIS ignore .htaccess,
			// so we also (a) drop a directory-listing stub and (b) randomise
			// each backup filename below — without the random token a remote
			// attacker cannot enumerate or guess backup URLs.
			file_put_contents( $backup_dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore
			file_put_contents( $backup_dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore
			file_put_contents( $backup_dir . '/index.html', '' ); // phpcs:ignore
		}

		$timestamp = gmdate( 'Y-m-d_H-i-s' );
		// Random suffix prevents URL guessing on web servers that ignore .htaccess.
		$token       = bin2hex( random_bytes( 8 ) );
		$backup_path = $backup_dir . '/' . basename( $validated ) . '.' . $timestamp . '.' . $token . '.bak';

		if ( ! copy( $validated, $backup_path ) ) {
			return new WP_Error( 'backup_failed', 'Failed to create backup.' );
		}

		file_put_contents( $backup_path . '.json', wp_json_encode( array( 'original_path' => $validated ) ) ); // phpcs:ignore

		return $backup_path;
	}

	/**
	 * Write $content to $path, creating a backup first if the file exists.
	 * MUST only be called after explicit human approval.
	 *
	 * @return string|WP_Error  Resolved path on success.
	 */
	public function write_file( string $path, string $content ) {
		if ( ! wp_is_file_mod_allowed( $this->resolve_file_mod_context( $path ) ) ) {
			return new WP_Error( 'file_mod_not_allowed', 'File modifications are not allowed on this site.', array( 'status' => 403 ) );
		}

		// Validate — existing or new file.
		$validated = file_exists( $path )
			? $this->validate_path( $path )
			: $this->validate_new_path( $path );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( strlen( $content ) > self::MAX_FILE_SIZE ) {
			return new WP_Error( 'content_too_large', 'Content exceeds the maximum file size limit.' );
		}

		// Backup before overwrite.
		if ( is_file( $validated ) ) {
			$backup = $this->backup_file( $validated );
			if ( is_wp_error( $backup ) ) {
				return $backup;
			}
		}

		// Create any missing parent directories.
		$parent = dirname( $validated );
		if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
			return new WP_Error( 'mkdir_failed', "Could not create directory: {$parent}" );
		}

		// Write via WP_Filesystem.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem->put_contents( $validated, $content, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'write_error', 'WP_Filesystem failed to write the file.' );
		}

		return $validated;
	}

	/**
	 * Restore a file from its most recent backup.
	 *
	 * If no backup exists (i.e. the file was newly created), the file is deleted
	 * so the site returns to its pre-write state.
	 *
	 * @param  string $path  Resolved path of the file to restore.
	 * @return true|WP_Error
	 */
	public function restore_latest_backup( string $path ) {
		$backup_dir = WP_CONTENT_DIR . '/uploads/haydi-backups';
		$pattern    = $backup_dir . '/' . basename( $path ) . '.*.bak';
		$backups    = glob( $pattern );

		if ( $backups ) {
			rsort( $backups ); // Lexicographic desc → most-recent timestamp first.
			if ( ! copy( $backups[0], $path ) ) {
				return new WP_Error( 'restore_failed', 'Failed to copy backup back to original path.' );
			}
			return true;
		}

		// No backup means the file was new — remove it to undo the write.
		if ( is_file( $path ) && ! wp_delete_file( $path ) ) {
			return new WP_Error( 'delete_failed', 'Could not delete newly written file after health-check failure.' );
		}

		return true;
	}

	/**
	 * List backup files, optionally filtered to those matching the basename of $original_path.
	 *
	 * @param  string $original_path Optional full path; when given, only backups for that filename are returned.
	 * @return array<int,array{backup_file:string,original_basename:string,timestamp:int}>
	 */
	public function list_backups( string $original_path = '' ): array {
		$backup_dir = WP_CONTENT_DIR . '/uploads/haydi-backups';
		if ( ! is_dir( $backup_dir ) ) {
			return array();
		}

		$pattern = $original_path
			? $backup_dir . '/' . basename( $original_path ) . '.*.bak'
			: $backup_dir . '/*.bak';

		$files = glob( $pattern );
		if ( ! $files ) {
			$files = array();
		}
		rsort( $files );

		$backups = array();
		foreach ( $files as $file ) {
			$without_bak = substr( basename( $file ), 0, -4 );
			$parts       = explode( '.', $without_bak );
			if ( count( $parts ) < 3 ) {
				continue;
			}
			array_pop( $parts ); // Remove token.
			$date_str  = array_pop( $parts ); // Format: 2026-05-11_11-58-06.
			$timestamp = strtotime( str_replace( '_', 'T', $date_str ) );
			if ( false === $timestamp ) {
				continue;
			}
			$meta          = file_exists( $file . '.json' ) ? json_decode( file_get_contents( $file . '.json' ), true ) : array(); // phpcs:ignore
			$original_path = $meta['original_path'] ?? '';
			$backups[]     = array(
				'backup_file'       => basename( $file ),
				'original_basename' => implode( '.', $parts ),
				'original_path'     => $original_path,
				'timestamp'         => $timestamp,
			);
		}

		return $backups;
	}

	/**
	 * Restore a specific backup file to its original path.
	 *
	 * A new backup of the current file is created before overwriting so the
	 * restore itself is also reversible.
	 *
	 * @param  string $original_path   Absolute path where the file should be restored.
	 * @param  string $backup_filename Basename of the .bak file (no directory component).
	 * @return true|WP_Error
	 */
	public function restore_specific_backup( string $original_path, string $backup_filename ) {
		if ( ! wp_is_file_mod_allowed( $this->resolve_file_mod_context( $original_path ) ) ) {
			return new WP_Error( 'file_mod_not_allowed', 'File modifications are not allowed on this site.', array( 'status' => 403 ) );
		}

		$backup_dir  = WP_CONTENT_DIR . '/uploads/haydi-backups';
		$backup_path = $backup_dir . '/' . basename( $backup_filename );

		if ( ! realpath( dirname( $backup_path ) ) || realpath( dirname( $backup_path ) ) !== realpath( $backup_dir ) ) {
			return new WP_Error( 'invalid_backup', 'Invalid backup file path.' );
		}

		if ( ! file_exists( $backup_path ) ) {
			return new WP_Error( 'backup_not_found', 'Backup file not found.' );
		}

		$validated = file_exists( $original_path )
			? $this->validate_path( $original_path )
			: $this->validate_new_path( $original_path );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( file_exists( $validated ) ) {
			$pre_backup = $this->backup_file( $validated );
			if ( is_wp_error( $pre_backup ) ) {
				return $pre_backup;
			}
		}

		if ( ! copy( $backup_path, $validated ) ) {
			return new WP_Error( 'restore_failed', 'Failed to restore backup.' );
		}

		return true;
	}

	/**
	 * Package the relevant plugin/theme files for browser-side WordPress
	 * Playground preflight. The returned bundle intentionally contains only
	 * allowed text/code extensions and conservative size caps.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function prepare_playground_preflight( string $path ) {
		$target = file_exists( $path )
			? $this->validate_path( $path )
			: $this->validate_new_path( $path );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$context = $this->detect_playground_context( $target );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		$files = is_dir( $context['root'] )
			? $this->collect_playground_files( $context['root'] )
			: array();
		if ( is_wp_error( $files ) ) {
			return $files;
		}

		return array_merge(
			$context,
			array(
				'target_relative_path' => $this->relative_path( $context['root'], $target ),
				'files'                => $files,
			)
		);
	}

	/**
	 * Delete $path after creating a backup.
	 * MUST only be called after explicit human approval.
	 *
	 * @return string|WP_Error  Resolved path of the deleted file on success.
	 */
	public function delete_file( string $path ) {
		if ( ! wp_is_file_mod_allowed( $this->resolve_file_mod_context( $path ) ) ) {
			return new WP_Error( 'file_mod_not_allowed', 'File modifications are not allowed on this site.', array( 'status' => 403 ) );
		}

		$validated = $this->validate_path( $path );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( ! is_file( $validated ) ) {
			return new WP_Error( 'not_a_file', 'Path is not a file.' );
		}

		// Backup before deletion.
		$backup = $this->backup_file( $validated );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		if ( ! unlink( $validated ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'delete_error', 'Could not delete file.' );
		}

		return $validated;
	}

	/**
	 * Move (rename) $src to $dest, backing up $src first.
	 * MUST only be called after explicit human approval.
	 *
	 * @return array{src:string,dest:string}|WP_Error
	 */
	public function move_file( string $src, string $dest ) {
		if ( ! wp_is_file_mod_allowed( $this->resolve_file_mod_context( $src ) ) ) {
			return new WP_Error( 'file_mod_not_allowed', 'File modifications are not allowed on this site.', array( 'status' => 403 ) );
		}
		if ( ! wp_is_file_mod_allowed( $this->resolve_file_mod_context( $dest ) ) ) {
			return new WP_Error( 'file_mod_not_allowed', 'File modifications are not allowed on this site.', array( 'status' => 403 ) );
		}

		$validated_src = $this->validate_path( $src );
		if ( is_wp_error( $validated_src ) ) {
			return $validated_src;
		}
		if ( ! is_file( $validated_src ) ) {
			return new WP_Error( 'not_a_file', 'Source is not a file.' );
		}

		$validated_dest = file_exists( $dest )
			? $this->validate_path( $dest )
			: $this->validate_new_path( $dest );
		if ( is_wp_error( $validated_dest ) ) {
			return $validated_dest;
		}

		// Backup source before moving.
		$backup = $this->backup_file( $validated_src );
		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		$parent = dirname( $validated_dest );
		if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
			return new WP_Error( 'mkdir_failed', "Could not create directory: {$parent}" );
		}

		if ( ! rename( $validated_src, $validated_dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'move_error', 'Could not move file.' );
		}

		return array(
			'src'  => $validated_src,
			'dest' => $validated_dest,
		);
	}

	/**
	 * Copy $src to $dest, backing up $dest first if it already exists.
	 * MUST only be called after explicit human approval.
	 *
	 * @return array{src:string,dest:string}|WP_Error
	 */
	public function copy_file( string $src, string $dest ) {
		if ( ! wp_is_file_mod_allowed( $this->resolve_file_mod_context( $dest ) ) ) {
			return new WP_Error( 'file_mod_not_allowed', 'File modifications are not allowed on this site.', array( 'status' => 403 ) );
		}

		$validated_src = $this->validate_path( $src );
		if ( is_wp_error( $validated_src ) ) {
			return $validated_src;
		}
		if ( ! is_file( $validated_src ) ) {
			return new WP_Error( 'not_a_file', 'Source is not a file.' );
		}
		if ( filesize( $validated_src ) > self::MAX_FILE_SIZE ) {
			return new WP_Error( 'file_too_large', 'Source file exceeds the size limit.' );
		}

		$validated_dest = file_exists( $dest )
			? $this->validate_path( $dest )
			: $this->validate_new_path( $dest );
		if ( is_wp_error( $validated_dest ) ) {
			return $validated_dest;
		}

		// Backup destination if it already exists.
		if ( is_file( $validated_dest ) ) {
			$backup = $this->backup_file( $validated_dest );
			if ( is_wp_error( $backup ) ) {
				return $backup;
			}
		}

		$parent = dirname( $validated_dest );
		if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
			return new WP_Error( 'mkdir_failed', "Could not create directory: {$parent}" );
		}

		if ( ! copy( $validated_src, $validated_dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'copy_error', 'Could not copy file.' );
		}

		return array(
			'src'  => $validated_src,
			'dest' => $validated_dest,
		);
	}

	/**
	 * Recursively delete a directory after backing up all files inside it.
	 * Root directories (plugins/, themes/) cannot be deleted.
	 * MUST only be called after explicit human approval.
	 *
	 * @return string|WP_Error  Resolved path of the deleted directory on success.
	 */
	public function delete_dir( string $path ) {
		if ( ! wp_is_file_mod_allowed( $this->resolve_file_mod_context( $path ) ) ) {
			return new WP_Error( 'file_mod_not_allowed', 'File modifications are not allowed on this site.', array( 'status' => 403 ) );
		}

		$validated = $this->validate_path( $path );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		if ( ! is_dir( $validated ) ) {
			return new WP_Error( 'not_a_directory', 'Path is not a directory.' );
		}
		if ( in_array( $validated, $this->allowed_roots, true ) ) {
			return new WP_Error( 'cannot_delete_root', 'Cannot delete an allowed root directory.' );
		}

		// Backup every file before deleting.
		$backup_result = $this->backup_dir_recursive( $validated );
		if ( is_wp_error( $backup_result ) ) {
			return $backup_result;
		}

		if ( ! $this->rmdir_recursive( $validated ) ) {
			return new WP_Error( 'delete_error', 'Could not fully delete directory.' );
		}

		return $validated;
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Identify whether a target belongs to a plugin, theme, or loose allowed file.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function detect_playground_context( string $target ) {
		$plugin_root = realpath( WP_PLUGIN_DIR );
		if ( false !== $plugin_root && $this->path_is_inside( $target, $plugin_root ) ) {
			return $this->detect_plugin_playground_context( $target, $plugin_root );
		}

		$themes_root = realpath( get_theme_root() );
		if ( false !== $themes_root && $this->path_is_inside( $target, $themes_root ) ) {
			return $this->detect_theme_playground_context( $target, $themes_root );
		}

		return array(
			'type'       => 'file',
			'root'       => is_file( $target ) ? dirname( $target ) : dirname( $target ),
			'wp_root'    => '/wordpress/wp-content/uploads/haydi-preflight',
			'boot'       => 'request',
			'active'     => false,
			'entry_file' => '',
		);
	}

	/**
	 * Build Playground metadata for a plugin target.
	 *
	 * @return array<string,mixed>
	 */
	private function detect_plugin_playground_context( string $target, string $plugin_root ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins       = get_plugins();
		$active        = get_option( 'active_plugins', array() );
		$network       = is_multisite() ? array_keys( get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$best_file     = '';
		$best_root     = '';
		$best_root_len = -1;

		foreach ( $plugins as $plugin_file => $_data ) {
			$root_rel = str_contains( $plugin_file, '/' ) ? dirname( $plugin_file ) : $plugin_file;
			$root_abs = str_contains( $plugin_file, '/' )
				? $plugin_root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $root_rel )
				: $plugin_root . DIRECTORY_SEPARATOR . $plugin_file;

			if ( $this->path_is_inside( $target, $root_abs ) && strlen( $root_abs ) > $best_root_len ) {
				$best_file     = $plugin_file;
				$best_root     = $root_abs;
				$best_root_len = strlen( $root_abs );
			}
		}

		if ( '' === $best_root ) {
			$rel       = ltrim( $this->relative_path( $plugin_root, $target ), '/' );
			$segments  = explode( '/', $rel );
			$root_name = count( $segments ) > 1 ? $segments[0] : basename( $target );
			$best_root = $plugin_root . DIRECTORY_SEPARATOR . $root_name;
			$best_file = count( $segments ) > 1 ? $root_name . '/' . $root_name . '.php' : basename( $target );
		}

		return array(
			'type'       => 'plugin',
			'root'       => $best_root,
			'wp_root'    => $best_root === $plugin_root || is_file( $best_root )
				? '/wordpress/wp-content/plugins'
				: '/wordpress/wp-content/plugins/' . basename( $best_root ),
			'boot'       => 'plugin',
			'active'     => in_array( $best_file, $active, true ) || in_array( $best_file, $network, true ),
			'entry_file' => $best_file,
		);
	}

	/**
	 * Build Playground metadata for a theme target.
	 *
	 * @return array<string,mixed>
	 */
	private function detect_theme_playground_context( string $target, string $themes_root ): array {
		$rel        = ltrim( $this->relative_path( $themes_root, $target ), '/' );
		$segments   = explode( '/', $rel );
		$stylesheet = $segments[0] ?? basename( dirname( $target ) );
		$theme_root = $themes_root . DIRECTORY_SEPARATOR . $stylesheet;

		return array(
			'type'       => 'theme',
			'root'       => $theme_root,
			'wp_root'    => '/wordpress/wp-content/themes/' . $stylesheet,
			'boot'       => 'theme',
			'active'     => get_stylesheet() === $stylesheet || get_template() === $stylesheet,
			'entry_file' => $stylesheet,
		);
	}

	/**
	 * Collect a bounded set of files from a plugin/theme root.
	 *
	 * @return array<int,array{path:string,content:string}>|WP_Error
	 */
	private function collect_playground_files( string $root ) {
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$files = array();
		$total = 0;
		foreach ( $this->get_search_file_iterator( $root ) as $file ) {
			if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
				continue;
			}

			$real = $file->getRealPath();
			if ( false === $real ) {
				continue;
			}

			$ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) || str_starts_with( basename( $real ), '.' ) ) {
				continue;
			}

			$size = $file->getSize();
			if ( $size > self::MAX_FILE_SIZE ) {
				continue;
			}
			$total += $size;
			if ( count( $files ) >= self::MAX_PLAYGROUND_FILES || $total > self::MAX_PLAYGROUND_BYTES ) {
				return new WP_Error( 'playground_bundle_too_large', 'The plugin/theme is too large to preflight in Playground.' );
			}

			$content = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $content ) {
				return new WP_Error( 'read_error', 'Could not read files for Playground preflight.' );
			}

			$files[] = array(
				'path'    => $this->relative_path( $root, $real ),
				'content' => $content,
			);
		}

		return $files;
	}

	/**
	 * Map a filesystem path to its wp_is_file_mod_allowed() context string.
	 *
	 * Resolves the path (or its nearest existing ancestor for new paths) and
	 * checks it against the plugin and theme roots so the correct context is
	 * passed to wp_is_file_mod_allowed().
	 */
	private function resolve_file_mod_context( string $path ): string {
		$real = realpath( $path );
		if ( false === $real ) {
			$real = realpath( dirname( $path ) );
		}
		if ( false === $real ) {
			return 'upload_files';
		}

		$plugin_root = realpath( WP_PLUGIN_DIR );
		if ( false !== $plugin_root && $this->path_is_inside( $real, $plugin_root ) ) {
			return 'plugin_files';
		}

		$themes_root = realpath( get_theme_root() );
		if ( false !== $themes_root && $this->path_is_inside( $real, $themes_root ) ) {
			return 'theme_files';
		}

		return 'upload_files';
	}

	private function path_is_inside( string $path, string $root ): bool {
		$root = rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return rtrim( $root, DIRECTORY_SEPARATOR ) === $path || str_starts_with( $path . DIRECTORY_SEPARATOR, $root );
	}

	private function relative_path( string $root, string $path ): string {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		$path = str_replace( '\\', '/', $path );
		if ( str_starts_with( $path, $root . '/' ) ) {
			return substr( $path, strlen( $root ) + 1 );
		}
		return basename( $path );
	}

	/**
	 * Convert a user-supplied extension list into an allowed extension set.
	 *
	 * @return array<string,string>|WP_Error Extension => extension.
	 */
	private function normalise_search_extensions( string $extensions ): array|WP_Error {
		if ( '' === trim( $extensions ) ) {
			return array_combine( self::ALLOWED_EXTENSIONS, self::ALLOWED_EXTENSIONS );
		}

		$requested = preg_split( '/[\s,]+/', strtolower( $extensions ), -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $requested || empty( $requested ) ) {
			return array_combine( self::ALLOWED_EXTENSIONS, self::ALLOWED_EXTENSIONS );
		}

		$allowed = array();
		foreach ( $requested as $ext ) {
			$ext = ltrim( trim( $ext ), '.' );
			if ( '' === $ext ) {
				continue;
			}
			if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
				return new WP_Error( 'invalid_extension', "File type '.{$ext}' is not allowed." );
			}
			$allowed[ $ext ] = $ext;
		}

		return empty( $allowed )
			? array_combine( self::ALLOWED_EXTENSIONS, self::ALLOWED_EXTENSIONS )
			: $allowed;
	}

	/**
	 * Resolve the requested search path into one or more allowed roots.
	 *
	 * @return string[]|WP_Error
	 */
	private function search_roots_for_path( string $path ): array|WP_Error {
		$path = trim( $path );
		if ( '' === $path ) {
			return array_values( $this->allowed_roots );
		}

		$validated = $this->validate_path( $path );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( ! is_dir( $validated ) && ! is_file( $validated ) ) {
			return new WP_Error( 'invalid_path', 'Path must be a file or directory.' );
		}

		return array( $validated );
	}

	/**
	 * Build a recursive file iterator that avoids hidden and generated-heavy dirs.
	 */
	private function get_search_file_iterator( string $root ): RecursiveIteratorIterator {
		$directory = new RecursiveDirectoryIterator(
			$root,
			RecursiveDirectoryIterator::SKIP_DOTS
		);

		$filter = new RecursiveCallbackFilterIterator(
			$directory,
			function ( SplFileInfo $current ): bool {
				$name = $current->getFilename();
				if ( str_starts_with( $name, '.' ) ) {
					return false;
				}
				if ( $current->isDir() ) {
					return ! in_array(
						$name,
						array( 'vendor', 'node_modules', 'build', 'dist', 'coverage', 'cache', 'tmp' ),
						true
					);
				}
				return true;
			}
		);

		return new RecursiveIteratorIterator( $filter );
	}

	/**
	 * Check whether a resolved file path may be scanned.
	 *
	 * @param string               $path               Resolved file path.
	 * @param array<string,string> $allowed_extensions Extension => extension.
	 */
	private function is_searchable_file( string $path, array $allowed_extensions ): bool {
		if ( str_starts_with( basename( $path ), '.' ) ) {
			return false;
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! isset( $allowed_extensions[ $ext ] ) ) {
			return false;
		}

		return is_readable( $path ) && ! is_wp_error( $this->validate_path( $path ) );
	}

	/**
	 * Search one file line-by-line and return compact matches for the AI.
	 *
	 * @return array<int,array{path:string,line:int,snippet:string}>|WP_Error
	 */
	private function search_file_lines( string $path, string $query, string $mode, int $remaining ) {
		if ( $remaining <= 0 ) {
			return array();
		}

		$matches = array();
		try {
			$file = new SplFileObject( $path, 'r' );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'read_error', 'Could not read file during search.' );
		}
		$line_no = 0;
		$regex   = null;

		if ( 'regex' === $mode ) {
			$regex = $this->normalise_search_regex( $query );
			if ( ! $this->is_valid_search_regex( $regex ) ) {
				return new WP_Error( 'invalid_regex', 'Search regex is invalid.' );
			}
		}

		while ( ! $file->eof() ) {
			$line = $file->fgets();
			++$line_no;

			$found = 'literal' === $mode
				? false !== stripos( $line, $query )
				: 1 === preg_match( $regex, $line );

			if ( ! $found ) {
				continue;
			}

			$matches[] = array(
				'path'    => $path,
				'line'    => $line_no,
				'snippet' => $this->compact_search_snippet( $line ),
			);

			if ( count( $matches ) >= $remaining ) {
				break;
			}
		}

		return $matches;
	}

	/**
	 * Accept either a full preg pattern (/foo/i) or a raw regex body.
	 */
	private function normalise_search_regex( string $query ): string {
		$first = substr( $query, 0, 1 );
		$last  = substr( $query, -1 );
		if ( $first && $last && ! ctype_alnum( $first ) && '\\' !== $first && false !== strrpos( $query, $first, 1 ) ) {
			return $query;
		}
		return '/' . str_replace( '/', '\/', $query ) . '/i';
	}

	/**
	 * Validate a preg pattern without letting parser warnings leak.
	 */
	private function is_valid_search_regex( string $regex ): bool {
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
			static function (): bool {
				return true;
			}
		);
		$result = preg_match( $regex, '' );
		restore_error_handler();

		return false !== $result;
	}

	/**
	 * Keep returned lines readable and small.
	 */
	private function compact_search_snippet( string $line ): string {
		$line = trim( preg_replace( '/\s+/', ' ', $line ) ?? $line );
		if ( strlen( $line ) <= 240 ) {
			return $line;
		}
		return substr( $line, 0, 237 ) . '...';
	}

	/** Backup every file inside $dir recursively (skips non-allowed extensions). */
	private function backup_dir_recursive( string $dir ): true|WP_Error {
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_file( $full ) ) {
				$ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
				if ( in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
					$backup = $this->backup_file( $full );
					if ( is_wp_error( $backup ) ) {
						return $backup;
					}
				}
			} elseif ( is_dir( $full ) ) {
				$result = $this->backup_dir_recursive( $full );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}
		return true;
	}

	/** Recursively remove $dir and all its contents. */
	private function rmdir_recursive( string $dir ): bool {
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$full = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $full ) ) {
				$this->rmdir_recursive( $full );
			} else {
				unlink( $full ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		return rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Build the allowed-root map.
	 *
	 * Always includes wp-content/plugins/ and wp-content/themes/ resolved via
	 * both WP_CONTENT_DIR and ABSPATH. On some hosts (e.g. Jurassic Ninja)
	 * WP_CONTENT_DIR is mounted at a different path than ABSPATH/wp-content,
	 * and the model may generate paths via either root. After realpath resolution
	 * duplicates collapse to a single entry; genuine differences are both allowed
	 * since both roots are controlled by WordPress.
	 *
	 * WordPress core, wp-config.php, .htaccess, and anything not explicitly
	 * listed here remain inaccessible.
	 */
	private function build_allowed_roots(): array {
		$raw = array(
			'plugins'         => WP_CONTENT_DIR . '/plugins',
			'themes'          => WP_CONTENT_DIR . '/themes',
			'abspath-plugins' => rtrim( ABSPATH, '/\\' ) . '/wp-content/plugins',
			'abspath-themes'  => rtrim( ABSPATH, '/\\' ) . '/wp-content/themes',
		);

		// Optional scratchpad folder — create it to enable.
		$ai_edits = WP_CONTENT_DIR . '/uploads/ai-edits';
		if ( is_dir( $ai_edits ) ) {
			$raw['ai-edits'] = $ai_edits;
		}

		// Resolve symlinks; discard anything that doesn't exist.
		// Deduplicate by resolved path so that identical physical dirs from
		// different logical roots only appear once.
		$by_real  = array();
		$resolved = array();
		foreach ( $raw as $label => $path ) {
			$real = realpath( $path );
			if ( false !== $real && is_dir( $real ) && ! isset( $by_real[ $real ] ) ) {
				$by_real[ $real ]   = true;
				$resolved[ $label ] = $real;
			}
		}

		return $resolved;
	}

	/**
	 * Validate that $path is within an allowed root.
	 *
	 * @return string|WP_Error  Resolved real path on success.
	 */
	private function validate_path( string $path ) {
		$real = realpath( $path );

		if ( false === $real ) {
			return new WP_Error( 'invalid_path', 'Path does not exist or could not be resolved.' );
		}

		foreach ( $this->allowed_roots as $root ) {
			$root_prefix = rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

			// The path must be the root itself or start with root + separator.
			if ( $real === $root || str_starts_with( $real . DIRECTORY_SEPARATOR, $root_prefix ) ) {
				// Extension check for files.
				if ( is_file( $real ) ) {
					$ext = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
					if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
						return new WP_Error( 'invalid_extension', "File type '.{$ext}' is not allowed." );
					}
					// Reject hidden files.
					if ( str_starts_with( basename( $real ), '.' ) ) {
						return new WP_Error( 'hidden_file', 'Hidden files are not allowed.' );
					}
				}

				return $real;
			}
		}

		return new WP_Error( 'path_not_allowed', 'Path is outside all allowed directories.' );
	}

	/**
	 * Validate a path that does not yet exist (new file or new subdirectory).
	 *
	 * The immediate parent directory may not exist yet (e.g. creating a new
	 * plugin folder).  We walk up the tree to the first existing ancestor,
	 * validate that ancestor against the allowlist, then reconstruct the full
	 * intended path so the caller can mkdir -p safely.
	 *
	 * @return string|WP_Error  The intended absolute path on success.
	 */
	private function validate_new_path( string $path ): string|WP_Error {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
			return new WP_Error( 'invalid_extension', "File type '.{$ext}' is not allowed." );
		}

		$filename = basename( $path );
		if ( str_starts_with( $filename, '.' ) ) {
			return new WP_Error( 'hidden_file', 'Hidden/dot-files are not allowed.' );
		}

		// Walk up until we find an existing directory (or exhaust the path).
		$dir    = dirname( $path );
		$suffix = '';                    // Path segments that don't exist yet.

		while ( DIRECTORY_SEPARATOR !== $dir && '.' !== $dir && ! is_dir( $dir ) ) {
			$suffix = DIRECTORY_SEPARATOR . basename( $dir ) . $suffix;
			$dir    = dirname( $dir );
		}

		if ( ! is_dir( $dir ) ) {
			return new WP_Error( 'invalid_path', 'No existing ancestor directory found.' );
		}

		// Validate the existing ancestor against the allowlist.
		$real_ancestor = $this->validate_path( $dir );
		if ( is_wp_error( $real_ancestor ) ) {
			return $real_ancestor;
		}

		// Reconstruct the full intended path (not yet on disk).
		return $real_ancestor . $suffix . DIRECTORY_SEPARATOR . $filename;
	}
}
