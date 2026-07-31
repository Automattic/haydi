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

	// -------------------------------------------------------------------------
	// AI-facing read methods (called from the chat dispatcher's read-tool loop)
	// -------------------------------------------------------------------------

	/**
	 * Return a JSON-encoded directory listing for the AI, or a leading "Error: " string on failure.
	 *
	 * @param string $path Directory path to list.
	 * @return string
	 */
	public function list_files_for_ai( string $path ): string {
		$result = $this->guard->list_files( $path );
		if ( is_wp_error( $result ) ) {
			return 'Error: ' . $result->get_error_message();
		}
		$this->logger->log( 'list_files', $path );
		return wp_json_encode( $result );
	}

	public function read_file_for_ai( string $path ): string {
		$result = $this->guard->read_file( $path );
		if ( is_wp_error( $result ) ) {
			return 'Error: ' . $result->get_error_message();
		}
		$this->logger->log( 'read_file', $path );
		return $result;
	}

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

		$result = $this->guard->search_files( $query, $path, $mode, $extensions, $max_results_int );
		if ( is_wp_error( $result ) ) {
			return 'Error: ' . $result->get_error_message();
		}
		$this->logger->log( 'search_files', $path, $query );
		return wp_json_encode( $result );
	}

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
