<?php
/**
 * Audit Logger — persists a tamper-evident activity log in wp_options.
 */

defined( 'ABSPATH' ) || exit;

class Haydi_Audit_Logger {

	const OPTION_KEY  = 'haydi_audit_log';
	const MAX_ENTRIES = 200;

	/**
	 * Record an action.
	 *
	 * @param string $action  Short verb: list_files, read_file, plugin_installed, etc.
	 * @param string $path    Filesystem path involved.
	 * @param string $details Optional human-readable note.
	 */
	public function log( string $action, string $path, string $details = '' ): void {
		$user  = wp_get_current_user();
		$entry = array(
			'time'    => current_time( 'mysql' ),
			'user_id' => $user->ID,
			'user'    => $user->user_login,
			'action'  => sanitize_key( $action ),
			'path'    => $path,
			'details' => sanitize_text_field( $details ),
		);

		$log = $this->get_log();
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::MAX_ENTRIES );

		update_option( self::OPTION_KEY, $log, false );
	}

	/** @return array[]  All log entries, newest first. */
	public function get_log(): array {
		$log = get_option( self::OPTION_KEY, array() );
		return is_array( $log ) ? $log : array();
	}

	/** Erase all log entries. */
	public function clear_log(): void {
		delete_option( self::OPTION_KEY );
	}
}
