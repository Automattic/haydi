<?php
/**
 * Built-in file mutation tools — write, edit, delete, move, copy, and restore.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register file mutation Tool Declarations and their existing Implementations.
 */
function haydi_register_file_action_tools(
	Haydi_Tool_Catalog $catalog,
	Haydi_Filesystem_Guard $guard,
	Haydi_Health_Check $health,
	Haydi_Audit_Logger $logger
): void {
	$mcp_available = static fn(): bool => wp_is_file_mod_allowed( 'plugin_files' ) || wp_is_file_mod_allowed( 'theme_files' );

	$catalog->register(
		array(
			'name'           => 'write_file',
			'description'    => 'Write new content to a file within an allowed root. Calling this tool opens an approval UI for the user; they will see the content and the reason and confirm before it is written. Include the complete file content, not a diff. Only one write per turn is accepted. You must invoke this tool to trigger the approval — describing the change in plain text does nothing.',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'path'    => array(
						'type'        => 'string',
						'description' => 'Absolute filesystem path of the file to write.',
					),
					'content' => array(
						'type'        => 'string',
						'description' => 'Complete new file content (full replacement, not a diff).',
					),
					'reason'  => array(
						'type'        => 'string',
						'description' => 'Human-readable explanation of what this change does and why.',
					),
				),
				'required'   => array( 'path', 'content', 'reason' ),
			),
			'effect'         => 'approval',
			'activity_label' => 'Prepared write file',
			'proposal'       => array(
				'label'          => 'Write File',
				'log_action'     => 'write_proposed',
				'log_path_field' => 'path',
			),
			'projections'    => array(
				'chat' => true,
				'mcp'  => array(
					'name'        => 'haydi_write_file',
					'description' => 'Create or overwrite a file inside the allowed roots. PHP syntax is validated before writing.',
					'required'    => array( 'path', 'content' ),
					'available'   => $mcp_available,
				),
			),
		),
		static fn( array $arguments ): array|WP_Error => haydi_file_execute_write(
			(string) ( $arguments['path'] ?? '' ),
			(string) ( $arguments['content'] ?? '' ),
			(string) ( $arguments['reason'] ?? '' ),
			$guard,
			$health,
			$logger
		)
	);

	$catalog->register(
		array(
			'name'           => 'edit',
			'description'    => 'Modify an existing file by exact string replacement. Calling this tool opens an approval UI for the user; they will see the diff and confirm before it is written. Prefer this for small edits to existing files. oldString must be copied exactly from the current file and should match once unless replaceAll is true.',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'filePath'   => array(
						'type'        => 'string',
						'description' => 'Absolute filesystem path of the existing file to edit.',
					),
					'oldString'  => array(
						'type'        => 'string',
						'description' => 'Exact text currently in the file. Preserve indentation, whitespace, and newlines exactly.',
					),
					'newString'  => array(
						'type'        => 'string',
						'description' => 'Replacement text. Use an empty string to delete oldString.',
					),
					'replaceAll' => array(
						'type'        => 'boolean',
						'description' => 'Whether to replace every occurrence of oldString. Defaults to false.',
					),
					'reason'     => array(
						'type'        => 'string',
						'description' => 'Human-readable explanation of what this edit does and why.',
					),
				),
				'required'   => array( 'filePath', 'oldString', 'newString', 'reason' ),
			),
			'effect'         => 'approval',
			'activity_label' => 'Prepared edit file',
			'proposal'       => array(
				'label'          => 'Edit File',
				'log_action'     => 'edit_proposed',
				'log_path_field' => 'filePath',
			),
			'projections'    => array(
				'chat' => true,
				'mcp'  => array(
					'name'          => 'haydi_edit_file',
					'description'   => 'Exact-string substitution in an existing file.',
					'input_aliases' => array(
						'path'        => 'filePath',
						'old_string'  => 'oldString',
						'new_string'  => 'newString',
						'replace_all' => 'replaceAll',
					),
					'required'      => array( 'path', 'old_string', 'new_string' ),
					'available'     => $mcp_available,
				),
			),
		),
		static fn( array $arguments ): array|WP_Error => haydi_file_execute_edit(
			(string) ( $arguments['filePath'] ?? '' ),
			(string) ( $arguments['oldString'] ?? '' ),
			(string) ( $arguments['newString'] ?? '' ),
			filter_var( $arguments['replaceAll'] ?? false, FILTER_VALIDATE_BOOLEAN ),
			(string) ( $arguments['reason'] ?? '' ),
			$guard,
			$health,
			$logger
		)
	);

	$catalog->register(
		array(
			'name'           => 'delete_file',
			'description'    => 'Delete a file within an allowed root. Calling this tool opens an approval UI for the user; they confirm before deletion. A backup is created automatically. You must invoke this tool to trigger the approval — describing the deletion in plain text does nothing.',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'path'   => array(
						'type'        => 'string',
						'description' => 'Absolute filesystem path of the file to delete.',
					),
					'reason' => array(
						'type'        => 'string',
						'description' => 'Human-readable explanation of why this file should be deleted.',
					),
				),
				'required'   => array( 'path', 'reason' ),
			),
			'effect'         => 'approval',
			'activity_label' => 'Prepared delete file',
			'proposal'       => array(
				'label'          => 'Delete File',
				'log_action'     => 'delete_proposed',
				'log_path_field' => 'path',
			),
			'projections'    => array(
				'chat' => true,
				'mcp'  => array(
					'name'        => 'haydi_delete_file',
					'description' => 'Delete a file. A backup is created automatically.',
					'required'    => array( 'path' ),
					'available'   => $mcp_available,
				),
			),
		),
		static fn( array $arguments ): array|WP_Error => haydi_file_execute_delete(
			(string) ( $arguments['path'] ?? '' ),
			'' !== (string) ( $arguments['reason'] ?? '' ) ? (string) $arguments['reason'] : 'MCP-initiated deletion.',
			$guard,
			$health,
			$logger
		)
	);

	foreach ( array(
		'move_file' => array(
			'label'       => 'Move File',
			'description' => 'Move or rename a file within the allowed roots. Calling this tool opens an approval UI for the user; they confirm before the move. A backup of the source is created automatically. You must invoke this tool to trigger the approval — describing the move in plain text does nothing.',
			'mcp_desc'    => 'Move or rename a file.',
			'log_action'  => 'move_proposed',
		),
		'copy_file' => array(
			'label'       => 'Copy File',
			'description' => 'Copy a file within the allowed roots. Calling this tool opens an approval UI for the user; they confirm before the copy. The destination is backed up if it already exists. You must invoke this tool to trigger the approval — describing the copy in plain text does nothing.',
			'mcp_desc'    => 'Copy a file.',
			'log_action'  => 'copy_proposed',
		),
	) as $name => $config ) {
		$catalog->register(
			array(
				'name'           => $name,
				'description'    => $config['description'],
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'src'    => array(
							'type'        => 'string',
							'description' => 'Absolute filesystem path of the source file.',
						),
						'dest'   => array(
							'type'        => 'string',
							'description' => 'Absolute filesystem path of the destination.',
						),
						'reason' => array(
							'type'        => 'string',
							'description' => 'Human-readable explanation of why this file operation is needed.',
						),
					),
					'required'   => array( 'src', 'dest', 'reason' ),
				),
				'effect'         => 'approval',
				'activity_label' => 'Prepared ' . strtolower( $config['label'] ),
				'proposal'       => array(
					'label'          => $config['label'],
					'log_action'     => $config['log_action'],
					'log_path_field' => 'src',
				),
				'projections'    => array(
					'chat' => true,
					'mcp'  => array(
						'name'        => 'haydi_' . $name,
						'description' => $config['mcp_desc'],
						'required'    => array( 'src', 'dest' ),
						'available'   => $mcp_available,
					),
				),
			),
			static function ( array $arguments ) use ( $name, $guard, $health, $logger ): array|WP_Error {
				$function = 'move_file' === $name ? 'haydi_file_execute_move' : 'haydi_file_execute_copy';
				return $function(
					(string) ( $arguments['src'] ?? '' ),
					(string) ( $arguments['dest'] ?? '' ),
					(string) ( $arguments['reason'] ?? '' ),
					$guard,
					$health,
					$logger
				);
			}
		);
	}

	$catalog->register(
		array(
			'name'           => 'delete_dir',
			'description'    => 'Recursively delete a directory and all its contents. Calling this tool opens an approval UI for the user; they confirm before deletion. All files are backed up automatically. Root directories cannot be deleted. You must invoke this tool to trigger the approval — describing the deletion in plain text does nothing.',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'path'   => array(
						'type'        => 'string',
						'description' => 'Absolute filesystem path of the directory to delete.',
					),
					'reason' => array(
						'type'        => 'string',
						'description' => 'Human-readable explanation of why this directory should be deleted.',
					),
				),
				'required'   => array( 'path', 'reason' ),
			),
			'effect'         => 'approval',
			'activity_label' => 'Prepared delete directory',
			'proposal'       => array(
				'label'          => 'Delete Directory',
				'log_action'     => 'rmdir_proposed',
				'log_path_field' => 'path',
			),
			'projections'    => array(
				'chat' => true,
				'mcp'  => array(
					'name'        => 'haydi_delete_directory',
					'description' => 'Recursively delete a directory.',
					'required'    => array( 'path' ),
					'available'   => $mcp_available,
				),
			),
		),
		static fn( array $arguments ): array|WP_Error => haydi_file_execute_delete_dir(
			(string) ( $arguments['path'] ?? '' ),
			'' !== (string) ( $arguments['reason'] ?? '' ) ? (string) $arguments['reason'] : 'MCP-initiated directory deletion.',
			$guard,
			$health,
			$logger
		)
	);

	$catalog->register(
		array(
			'name'           => 'restore_backup',
			'description'    => 'Restore a file from a specific backup. Calling this tool opens an approval UI for the user; they confirm before the restore happens. Use list_backups first to find the correct backup_file name. A new backup of the current file is created before restoring so the restore is itself reversible.',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'backup_file'   => array(
						'type'        => 'string',
						'description' => 'The backup filename as returned by list_backups.',
					),
					'original_path' => array(
						'type'        => 'string',
						'description' => 'Absolute filesystem path where the file should be restored.',
					),
					'reason'        => array(
						'type'        => 'string',
						'description' => 'Human-readable explanation of why this backup is being restored.',
					),
				),
				'required'   => array( 'backup_file', 'original_path', 'reason' ),
			),
			'effect'         => 'approval',
			'activity_label' => 'Prepared restore backup',
			'proposal'       => array(
				'label'          => 'Restore Backup',
				'log_action'     => 'restore_proposed',
				'log_path_field' => 'original_path',
			),
			'projections'    => array(
				'chat' => true,
				'mcp'  => array(
					'name'        => 'haydi_restore_backup',
					'description' => 'Restore a file from a Haydi backup.',
					'required'    => array( 'backup_file', 'original_path' ),
					'available'   => $mcp_available,
				),
			),
		),
		static fn( array $arguments ): array|WP_Error => haydi_file_execute_restore_backup(
			(string) ( $arguments['backup_file'] ?? '' ),
			(string) ( $arguments['original_path'] ?? '' ),
			(string) ( $arguments['reason'] ?? '' ),
			$guard,
			$health,
			$logger
		)
	);
}

// -------------------------------------------------------------------------
// Browser preflight and direct REST Adapters.
// -------------------------------------------------------------------------

( static function () {
	$logger = new Haydi_Audit_Logger();
	$guard  = new Haydi_Filesystem_Guard();
	$health = new Haydi_Health_Check();

	$verify = static function () {
		if ( ! haydi_current_user_can_access() ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		if ( ! check_ajax_referer( 'haydi_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid or expired nonce.' ), 403 );
		}
	};

	$post_param = static function ( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by $verify
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	};

	add_action(
		'wp_ajax_haydi_prepare_playground_preflight',
		static function () use ( $verify, $post_param, $guard ) {
			$verify();
			$path = $post_param( 'path' );
			if ( '' === $path ) {
				wp_send_json_error( array( 'message' => 'path is required.' ) );
				return; }
			$result = $guard->prepare_playground_preflight( $path );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return; }
			wp_send_json_success( $result );
		}
	);

	add_action(
		'rest_api_init',
		static function () use ( $guard, $health, $logger ) {
			$perm = 'haydi_is_authorized_api_request';
			$ns   = 'haydi/v1';

			$wrap = static function ( $result ): WP_REST_Response {
				if ( is_wp_error( $result ) ) {
					$status = (int) ( $result->get_error_data()['status'] ?? 400 );
					return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
				}
				return new WP_REST_Response( $result );
			};

			register_rest_route(
				$ns,
				'/file',
				array(
					array(
						'methods'             => 'POST',
						'permission_callback' => $perm,
						'callback'            => static function ( WP_REST_Request $r ) use ( $wrap, $guard, $health, $logger ) {
									$b = $r->get_json_params();
									return $wrap( haydi_file_execute_write( (string) ( $b['path'] ?? '' ), (string) ( $b['content'] ?? '' ), (string) ( $b['reason'] ?? '' ), $guard, $health, $logger ) ); },
					),
					array(
						'methods'             => 'PATCH',
						'permission_callback' => $perm,
						'callback'            => static function ( WP_REST_Request $r ) use ( $wrap, $guard, $health, $logger ) {
								$b = $r->get_json_params();
								return $wrap( haydi_file_execute_edit( (string) ( $b['path'] ?? '' ), (string) ( $b['old_string'] ?? '' ), (string) ( $b['new_string'] ?? '' ), filter_var( $b['replace_all'] ?? false, FILTER_VALIDATE_BOOLEAN ), (string) ( $b['reason'] ?? '' ), $guard, $health, $logger ) ); },
					),
					array(
						'methods'             => 'DELETE',
						'permission_callback' => $perm,
						'callback'            => static function ( WP_REST_Request $r ) use ( $wrap, $guard, $health, $logger ) {
								$reason = (string) ( $r->get_param( 'reason' ) ?? '' );
								return $wrap( haydi_file_execute_delete( (string) ( $r->get_param( 'path' ) ?? '' ), '' !== $reason ? $reason : 'API-initiated deletion.', $guard, $health, $logger ) ); },
					),
				)
			);
			register_rest_route(
				$ns,
				'/files/restore',
				array(
					'methods'             => 'POST',
					'permission_callback' => $perm,
					'callback'            => static function ( WP_REST_Request $r ) use ( $wrap, $guard, $health, $logger ) {
						$b = $r->get_json_params();
						return $wrap( haydi_file_execute_restore_backup( (string) ( $b['backup_file'] ?? '' ), (string) ( $b['original_path'] ?? '' ), (string) ( $b['reason'] ?? '' ), $guard, $health, $logger ) );
					},
				)
			);
			register_rest_route(
				$ns,
				'/files/move',
				array(
					'methods'             => 'POST',
					'permission_callback' => $perm,
					'callback'            => static function ( WP_REST_Request $r ) use ( $wrap, $guard, $health, $logger ) {
						$b = $r->get_json_params();
						return $wrap( haydi_file_execute_move( (string) ( $b['src'] ?? '' ), (string) ( $b['dest'] ?? '' ), (string) ( $b['reason'] ?? '' ), $guard, $health, $logger ) );
					},
				)
			);
			register_rest_route(
				$ns,
				'/files/copy',
				array(
					'methods'             => 'POST',
					'permission_callback' => $perm,
					'callback'            => static function ( WP_REST_Request $r ) use ( $wrap, $guard, $health, $logger ) {
						$b = $r->get_json_params();
						return $wrap( haydi_file_execute_copy( (string) ( $b['src'] ?? '' ), (string) ( $b['dest'] ?? '' ), (string) ( $b['reason'] ?? '' ), $guard, $health, $logger ) );
					},
				)
			);
			register_rest_route(
				$ns,
				'/directory',
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $perm,
					'callback'            => static function ( WP_REST_Request $r ) use ( $wrap, $guard, $health, $logger ) {
						$reason = (string) ( $r->get_param( 'reason' ) ?? '' );
						return $wrap( haydi_file_execute_delete_dir( (string) ( $r->get_param( 'path' ) ?? '' ), '' !== $reason ? $reason : 'API-initiated directory deletion.', $guard, $health, $logger ) );
					},
				)
			);
		}
	);
} )();

// -------------------------------------------------------------------------
// Write execute functions
// -------------------------------------------------------------------------

/** Write a file; validates PHP syntax before writing; runs health check for .php files. */
function haydi_file_execute_write( string $path, string $content, string $reason, Haydi_Filesystem_Guard $guard, Haydi_Health_Check $health, Haydi_Audit_Logger $logger ): array|WP_Error {
	if ( '' === $path ) {
		return new WP_Error( 'missing_param', 'path is required.', array( 'status' => 400 ) ); }
	if ( str_ends_with( $path, '.php' ) ) {
		try {
			token_get_all( $content, TOKEN_PARSE ); } catch ( \ParseError | \CompileError $e ) {
			return new WP_Error( 'syntax_error', 'PHP syntax error — file not written: ' . $e->getMessage() . ' on line ' . $e->getLine(), array( 'status' => 400 ) ); }
	}
	$result = $guard->write_file( $path, $content );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) ); }
	if ( str_ends_with( $result, '.php' ) ) {
		$err = $health->verify_or_revert( fn() => $guard->restore_latest_backup( $result ), 'File write' );
		if ( $err ) {
			return new WP_Error( $err->get_error_code(), $err->get_error_message(), array( 'status' => 500 ) ); }
	}
	$logger->log( 'write_applied', $result, $reason );
	return array(
		'message' => 'File written successfully.',
		'path'    => $result,
	);
}

/** Apply an exact-string edit to an existing file; validates PHP syntax; runs health check. */
function haydi_file_execute_edit( string $file_path, string $old_string, string $new_string, bool $replace_all, string $reason, Haydi_Filesystem_Guard $guard, Haydi_Health_Check $health, Haydi_Audit_Logger $logger ): array|WP_Error {
	if ( '' === $file_path ) {
		return new WP_Error( 'missing_param', 'filePath is required.', array( 'status' => 400 ) ); }
	$prepared = $guard->prepare_edit_file( $file_path, $old_string, $new_string, $replace_all );
	if ( is_wp_error( $prepared ) ) {
		return new WP_Error( $prepared->get_error_code(), $prepared->get_error_message(), array( 'status' => 400 ) ); }
	if ( str_ends_with( $prepared['path'], '.php' ) ) {
		try {
			token_get_all( $prepared['content'], TOKEN_PARSE ); } catch ( \ParseError | \CompileError $e ) {
			return new WP_Error( 'syntax_error', 'PHP syntax error — file not written: ' . $e->getMessage() . ' on line ' . $e->getLine(), array( 'status' => 400 ) ); }
	}
	$result = $guard->write_file( $prepared['path'], $prepared['content'] );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) ); }
	if ( str_ends_with( $result, '.php' ) ) {
		$err = $health->verify_or_revert( fn() => $guard->restore_latest_backup( $result ), 'File edit' );
		if ( $err ) {
			return new WP_Error( $err->get_error_code(), $err->get_error_message(), array( 'status' => 500 ) ); }
	}
	$logger->log( 'edit_applied', $result, $reason );
	return array(
		'message' => 'File edited successfully.',
		'path'    => $result,
		'matches' => $prepared['matches'],
	);
}

/** Delete a file; auto-restores from backup on health failure. */
function haydi_file_execute_delete( string $path, string $reason, Haydi_Filesystem_Guard $guard, Haydi_Health_Check $health, Haydi_Audit_Logger $logger ): array|WP_Error {
	if ( '' === $path ) {
		return new WP_Error( 'missing_param', 'path is required.', array( 'status' => 400 ) ); }
	$result = $guard->delete_file( $path );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) ); }
	$err = $health->verify_or_revert( fn() => $guard->restore_latest_backup( $result ), 'File deletion' );
	if ( $err ) {
		return new WP_Error( $err->get_error_code(), $err->get_error_message(), array( 'status' => 500 ) ); }
	$logger->log( 'file_deleted', $result, '' !== $reason ? $reason : 'Human-initiated deletion.' );
	return array(
		'message' => 'File deleted successfully.',
		'path'    => $result,
	);
}

/** Move a file; on health failure, undoes both sides of the rename. */
function haydi_file_execute_move( string $src, string $dest, string $reason, Haydi_Filesystem_Guard $guard, Haydi_Health_Check $health, Haydi_Audit_Logger $logger ): array|WP_Error {
	if ( '' === $src || '' === $dest ) {
		return new WP_Error( 'missing_param', 'src and dest are required.', array( 'status' => 400 ) ); }
	$result = $guard->move_file( $src, $dest );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) ); }
	$err = $health->verify_or_revert(
		static function () use ( $result, $guard ) {
			if ( is_file( $result['dest'] ) && ! unlink( $result['dest'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return new WP_Error( 'undo_failed', 'Could not delete moved file at destination.' );
			}
			return $guard->restore_latest_backup( $result['src'] );
		},
		'File move'
	);
	if ( $err ) {
		return new WP_Error( $err->get_error_code(), $err->get_error_message(), array( 'status' => 500 ) ); }
	$logger->log( 'file_moved', $result['src'] . ' → ' . $result['dest'], $reason );
	return array(
		'message' => 'File moved successfully.',
		'src'     => $result['src'],
		'dest'    => $result['dest'],
	);
}

/** Copy a file; on health failure, restores the destination. */
function haydi_file_execute_copy( string $src, string $dest, string $reason, Haydi_Filesystem_Guard $guard, Haydi_Health_Check $health, Haydi_Audit_Logger $logger ): array|WP_Error {
	if ( '' === $src || '' === $dest ) {
		return new WP_Error( 'missing_param', 'src and dest are required.', array( 'status' => 400 ) ); }
	$result = $guard->copy_file( $src, $dest );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) ); }
	$err = $health->verify_or_revert( fn() => $guard->restore_latest_backup( $result['dest'] ), 'File copy' );
	if ( $err ) {
		return new WP_Error( $err->get_error_code(), $err->get_error_message(), array( 'status' => 500 ) ); }
	$logger->log( 'file_copied', $result['src'] . ' → ' . $result['dest'], $reason );
	return array(
		'message' => 'File copied successfully.',
		'src'     => $result['src'],
		'dest'    => $result['dest'],
	);
}

/** Recursively delete a directory; detect-only health check (no automatic revert). */
function haydi_file_execute_delete_dir( string $path, string $reason, Haydi_Filesystem_Guard $guard, Haydi_Health_Check $health, Haydi_Audit_Logger $logger ): array|WP_Error {
	if ( '' === $path ) {
		return new WP_Error( 'missing_param', 'path is required.', array( 'status' => 400 ) ); }
	$result = $guard->delete_dir( $path );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) ); }
	$err = $health->verify_or_warn( 'Directory deletion' );
	if ( $err ) {
		return new WP_Error( $err->get_error_code(), $err->get_error_message(), array( 'status' => 500 ) ); }
	$logger->log( 'dir_deleted', $result, '' !== $reason ? $reason : 'Human-initiated directory deletion.' );
	return array(
		'message' => 'Directory deleted successfully.',
		'path'    => $result,
	);
}

/** Restore a specific backup; creates a pre-restore backup; runs health check. */
function haydi_file_execute_restore_backup( string $backup_file, string $original_path, string $reason, Haydi_Filesystem_Guard $guard, Haydi_Health_Check $health, Haydi_Audit_Logger $logger ): array|WP_Error {
	if ( '' === $backup_file || '' === $original_path ) {
		return new WP_Error( 'missing_param', 'backup_file and original_path are required.', array( 'status' => 400 ) ); }
	$result = $guard->restore_specific_backup( $original_path, $backup_file );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) ); }
	$err = $health->verify_or_revert( fn() => $guard->restore_latest_backup( $original_path ), 'Backup restore' );
	if ( $err ) {
		return new WP_Error( $err->get_error_code(), $err->get_error_message(), array( 'status' => 500 ) ); }
	$logger->log( 'backup_restored', $original_path, $backup_file );
	return array(
		'message'       => 'Backup restored successfully.',
		'original_path' => $original_path,
		'backup_file'   => $backup_file,
	);
}
