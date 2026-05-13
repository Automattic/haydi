<?php
/**
 * File operations tool — exposes list_files / read_file as AI read tools and
 * write_file / delete_file / move_file / copy_file / delete_dir as approval-
 * gated tools that surface a proposal to the browser before executing.
 *
 * All path checks are deferred to Haydi_Filesystem_Guard; this class
 * only marshals input, dispatches success/error responses, and writes audit
 * log entries.
 */

defined( 'ABSPATH' ) || exit;

class Haydi_File_Tool extends Haydi_Ajax_Tool_Base {

	/** @var Haydi_Filesystem_Guard Filesystem access guard. */
	private Haydi_Filesystem_Guard $guard;

	/** @var Haydi_Health_Check Post-mutation site health probe. */
	private Haydi_Health_Check $health;

	public function __construct(
		Haydi_Audit_Logger $logger,
		Haydi_Filesystem_Guard $guard,
		Haydi_Health_Check $health
	) {
		parent::__construct( $logger );
		$this->guard  = $guard;
		$this->health = $health;
	}

	public function register(): void {
		foreach ( array(
			'haydi_read_file'                    => 'handle_read_file',
			'haydi_prepare_playground_preflight' => 'handle_prepare_playground_preflight',
			'haydi_edit_file'                    => 'handle_edit_file',
			'haydi_apply_write'                  => 'handle_apply_write',
			'haydi_delete_file'                  => 'handle_delete_file',
			'haydi_move_file'                    => 'handle_move_file',
			'haydi_copy_file'                    => 'handle_copy_file',
			'haydi_delete_dir'                   => 'handle_delete_dir',
			'haydi_restore_backup'               => 'handle_restore_backup',
		) as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	// -------------------------------------------------------------------------
	// AI-facing read methods (called from the chat dispatcher's read-tool loop)
	// -------------------------------------------------------------------------

	/**
	 * Return a JSON-encoded directory listing for the AI, or a leading "Error: "
	 * string on failure (the agentic loop expects a string back from every tool).
	 */
	public function list_files_for_ai( string $path ): string {
		$result = $this->guard->list_files( $path );
		if ( is_wp_error( $result ) ) {
			return 'Error: ' . $result->get_error_message();
		}
		$this->logger->log( 'list_files', $path );
		return wp_json_encode( $result );
	}

	/**
	 * Return raw file contents for the AI, or a leading "Error: " string on failure.
	 */
	public function read_file_for_ai( string $path ): string {
		$result = $this->guard->read_file( $path );
		if ( is_wp_error( $result ) ) {
			return 'Error: ' . $result->get_error_message();
		}
		$this->logger->log( 'read_file', $path );
		return $result;
	}

	/**
	 * Return JSON-encoded content-search results for the AI.
	 */
	public function search_files_for_ai(
		string $query,
		string $path = '',
		string $mode = 'literal',
		string $extensions = '',
		string $max_results = ''
	): string {
		$max_results_int = Haydi_Filesystem_Guard::DEFAULT_SEARCH_RESULTS;
		if ( '' !== trim( $max_results ) ) {
			$max_results_int = (int) $max_results;
		}

		$result = $this->guard->search_files(
			$query,
			$path,
			$mode,
			$extensions,
			$max_results_int
		);
		if ( is_wp_error( $result ) ) {
			return 'Error: ' . $result->get_error_message();
		}
		$this->logger->log( 'search_files', $path, $query );
		return wp_json_encode( $result );
	}

	/**
	 * Return a JSON-encoded list of backup files for the AI.
	 */
	public function list_backups_for_ai( string $original_path = '' ): string {
		$backups = $this->guard->list_backups( $original_path );
		if ( empty( $backups ) ) {
			return $original_path
				? 'No backups found for ' . basename( $original_path ) . '.'
				: 'No backups found.';
		}
		$lines = array( 'Available backups (newest first):' );
		foreach ( $backups as $b ) {
			$path_info = $b['original_path'] ? 'original_path: ' . $b['original_path'] : 'original_basename: ' . $b['original_basename'] . ' (full path unknown — ask the user)';
			$lines[]   = sprintf(
				'- backup_file: %s | %s | date: %s',
				$b['backup_file'],
				$path_info,
				gmdate( 'Y-m-d H:i:s', $b['timestamp'] ) . ' UTC'
			);
		}
		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// Browser-facing AJAX handlers
	// -------------------------------------------------------------------------

	/**
	 * Read a file from disk and return its contents.
	 *
	 * Used by the write-proposal diff renderer to fetch the current on-disk
	 * version (the "before" side of the diff) before showing what the AI
	 * wants to change.
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

	/**
	 * Build the file bundle used by the browser-side WordPress Playground
	 * preflight. The actual proposed content is sent back to the browser and
	 * overlaid there, after the user approves the proposal but before the real
	 * write touches disk.
	 */
	public function handle_prepare_playground_preflight(): void {
		$this->verify();

		$path = $this->post_param( 'path' );
		if ( '' === $path ) {
			wp_send_json_error( array( 'message' => 'path is required.' ) );
			return;
		}

		$result = $this->guard->prepare_playground_preflight( $path );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		wp_send_json_success( $result );
	}

	/**
	 * Apply a human-approved exact-string edit proposed by the AI.
	 */
	public function handle_edit_file(): void {
		$this->verify();

		$file_path = $this->post_param( 'filePath' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified; exact strings must preserve code/newlines.
		$old_string = isset( $_POST['oldString'] ) ? wp_unslash( $_POST['oldString'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified; exact strings must preserve code/newlines.
		$new_string = isset( $_POST['newString'] ) ? wp_unslash( $_POST['newString'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified.
		$replace_all = isset( $_POST['replaceAll'] ) ? filter_var( wp_unslash( $_POST['replaceAll'] ), FILTER_VALIDATE_BOOLEAN ) : false;
		$reason      = $this->post_param( 'reason' );

		if ( '' === $file_path ) {
			wp_send_json_error( array( 'message' => 'filePath is required.' ) );
			return;
		}

		$prepared = $this->guard->prepare_edit_file( $file_path, $old_string, $new_string, $replace_all );
		if ( is_wp_error( $prepared ) ) {
			wp_send_json_error( array( 'message' => $prepared->get_error_message() ) );
			return;
		}

		if ( str_ends_with( $prepared['path'], '.php' ) ) {
			try {
				token_get_all( $prepared['content'], TOKEN_PARSE );
			} catch ( \ParseError | \CompileError $e ) {
				wp_send_json_error(
					array(
						'message' => 'PHP syntax error — file not written: '
							. $e->getMessage() . ' on line ' . $e->getLine(),
					)
				);
				return;
			}
		}

		$result = $this->guard->write_file( $prepared['path'], $prepared['content'] );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		if ( str_ends_with( $result, '.php' ) ) {
			$err = $this->health->verify_or_revert(
				fn() => $this->guard->restore_latest_backup( $result ),
				'File edit'
			);
			if ( $err ) {
				wp_send_json_error( array( 'message' => $err->get_error_message() ) );
				return;
			}
		}

		$this->logger->log( 'edit_applied', $result, $reason );

		wp_send_json_success(
			array(
				'message' => 'File edited successfully.',
				'path'    => $result,
				'matches' => $prepared['matches'],
			)
		);
	}

	/**
	 * Apply a human-approved file write proposed by the AI.
	 */
	public function handle_apply_write(): void {
		$this->verify();

		$path = $this->post_param( 'path' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via $this->verify(); content validated by filesystem guard
		$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';

		if ( '' === $path ) {
			wp_send_json_error( array( 'message' => 'path is required.' ) );
			return;
		}

		// Validate PHP syntax before writing to disk.
		// TOKEN_PARSE makes the tokenizer throw ParseError/CompileError on syntax
		// errors, giving us the same coverage as `php -l` without exec() — which
		// is disabled on most shared hosts.
		if ( str_ends_with( $path, '.php' ) ) {
			try {
				token_get_all( $content, TOKEN_PARSE );
			} catch ( \ParseError | \CompileError $e ) {
				wp_send_json_error(
					array(
						'message' => 'PHP syntax error — file not written: '
							. $e->getMessage() . ' on line ' . $e->getLine(),
					)
				);
				return;
			}
		}

		$result = $this->guard->write_file( $path, $content );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		// Post-write health check catches fatal runtime errors and exit()/die()
		// calls that php -l cannot detect. Skipped for non-PHP writes since they
		// cannot fatal WordPress on the next request.
		if ( str_ends_with( $path, '.php' ) ) {
			$err = $this->health->verify_or_revert(
				fn() => $this->guard->restore_latest_backup( $result ),
				'File write'
			);
			if ( $err ) {
				wp_send_json_error( array( 'message' => $err->get_error_message() ) );
				return;
			}
		}

		$this->logger->log( 'write_applied', $result, 'Human-approved write.' );

		wp_send_json_success(
			array(
				'message' => 'File written successfully.',
				'path'    => $result,
			)
		);
	}

	/**
	 * Delete a file after human approval. Auto-restores from backup if the
	 * deletion fataled the site (e.g. an autoloaded class file removed while
	 * its plugin was still active).
	 */
	public function handle_delete_file(): void {
		$this->verify();
		$path = $this->require_param( 'path' );
		$this->dispatch_guard_result(
			$this->guard->delete_file( $path ),
			function ( $r ) {
				$err = $this->health->verify_or_revert(
					fn() => $this->guard->restore_latest_backup( $r ),
					'File deletion'
				);
				if ( $err ) {
					wp_send_json_error( array( 'message' => $err->get_error_message() ) );
					return;
				}
				$this->logger->log( 'file_deleted', $r, 'Human-initiated deletion.' );
				wp_send_json_success(
					array(
						'message' => 'File deleted successfully.',
						'path'    => $r,
					)
				);
			}
		);
	}

	/**
	 * Restore a specific backup to its original path after human approval.
	 */
	public function handle_restore_backup(): void {
		$this->verify();
		$backup_file   = $this->require_param( 'backup_file' );
		$original_path = $this->require_param( 'original_path' );
		$result        = $this->guard->restore_specific_backup( $original_path, $backup_file );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}
		$err = $this->health->verify_or_revert(
			fn() => $this->guard->restore_latest_backup( $original_path ),
			'Backup restore'
		);
		if ( $err ) {
			wp_send_json_error( array( 'message' => $err->get_error_message() ) );
			return;
		}
		$this->logger->log( 'backup_restored', $original_path, $backup_file );
		wp_send_json_success(
			array(
				'message'       => 'Backup restored successfully.',
				'original_path' => $original_path,
				'backup_file'   => $backup_file,
			)
		);
	}

	/**
	 * Move a file after human approval. On post-move site failure, undoes both
	 * sides of the rename: deletes the new destination and restores the source
	 * from its pre-move backup.
	 */
	public function handle_move_file(): void {
		$this->verify();
		$src    = $this->require_param( 'src' );
		$dest   = $this->require_param( 'dest' );
		$reason = $this->post_param( 'reason' );
		$this->dispatch_guard_result(
			$this->guard->move_file( $src, $dest ),
			function ( $r ) use ( $reason ) {
				$err = $this->health->verify_or_revert(
					function () use ( $r ) {
						if ( is_file( $r['dest'] ) && ! unlink( $r['dest'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
							return new WP_Error( 'undo_failed', 'Could not delete moved file at destination.' );
						}
						return $this->guard->restore_latest_backup( $r['src'] );
					},
					'File move'
				);
				if ( $err ) {
					wp_send_json_error( array( 'message' => $err->get_error_message() ) );
					return;
				}
				$this->logger->log( 'file_moved', $r['src'] . ' → ' . $r['dest'], $reason );
				wp_send_json_success(
					array(
						'message' => 'File moved successfully.',
						'src'     => $r['src'],
						'dest'    => $r['dest'],
					)
				);
			}
		);
	}

	/**
	 * Copy a file after human approval. On post-copy site failure, restore
	 * the destination — restore_latest_backup() handles both the "destination
	 * existed and was backed up" case (copies backup back) and the "destination
	 * was new" case (deletes the file we just wrote).
	 */
	public function handle_copy_file(): void {
		$this->verify();
		$src    = $this->require_param( 'src' );
		$dest   = $this->require_param( 'dest' );
		$reason = $this->post_param( 'reason' );
		$this->dispatch_guard_result(
			$this->guard->copy_file( $src, $dest ),
			function ( $r ) use ( $reason ) {
				$err = $this->health->verify_or_revert(
					fn() => $this->guard->restore_latest_backup( $r['dest'] ),
					'File copy'
				);
				if ( $err ) {
					wp_send_json_error( array( 'message' => $err->get_error_message() ) );
					return;
				}
				$this->logger->log( 'file_copied', $r['src'] . ' → ' . $r['dest'], $reason );
				wp_send_json_success(
					array(
						'message' => 'File copied successfully.',
						'src'     => $r['src'],
						'dest'    => $r['dest'],
					)
				);
			}
		);
	}

	/**
	 * Delete a directory after human approval. Detect-only: there is no
	 * automatic revert because the backup scheme stores files by basename in
	 * a flat directory and cannot reconstruct nested paths. Surfaces a loud
	 * warning that points the user at WordPress recovery mode.
	 */
	public function handle_delete_dir(): void {
		$this->verify();
		$path = $this->require_param( 'path' );
		$this->dispatch_guard_result(
			$this->guard->delete_dir( $path ),
			function ( $r ) {
				$err = $this->health->verify_or_warn( 'Directory deletion' );
				if ( $err ) {
					wp_send_json_error( array( 'message' => $err->get_error_message() ) );
					return;
				}
				$this->logger->log( 'dir_deleted', $r, 'Human-initiated directory deletion.' );
				wp_send_json_success(
					array(
						'message' => 'Directory deleted successfully.',
						'path'    => $r,
					)
				);
			}
		);
	}
}
