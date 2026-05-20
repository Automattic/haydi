<?php
/**
 * Haydi Files Extension — write_file, edit, delete_file, move_file, copy_file, delete_dir, restore_backup.
 * Install: drop into wp-content/plugins/haydi/extensions/
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'haydi_register_proposal' ) || ! class_exists( 'Haydi_Filesystem_Guard' ) ) {
	return;
}

// Register proposals.
haydi_register_proposal(
	'write_file',
	array(
		'label'            => 'Write File',
		'fields'           => array( 'path', 'content', 'reason' ),
		'ajax_action'      => 'haydi_apply_write',
		'log_action'       => 'write_proposed',
		'log_path_field'   => 'path',
		'tool_description' => 'write_file(path, content, reason) — write a file; opens an approval UI for the user',
	)
);
haydi_register_proposal(
	'edit',
	array(
		'label'            => 'Edit File',
		'fields'           => array( 'filePath', 'oldString', 'newString', 'replaceAll', 'reason' ),
		'ajax_action'      => 'haydi_edit_file',
		'log_action'       => 'edit_proposed',
		'log_path_field'   => 'filePath',
		'tool_description' => 'edit(filePath, oldString, newString, replaceAll, reason) — exact-string edit of an existing file; opens an approval UI for the user',
	)
);
haydi_register_proposal(
	'delete_file',
	array(
		'label'            => 'Delete File',
		'fields'           => array( 'path', 'reason' ),
		'ajax_action'      => 'haydi_delete_file',
		'log_action'       => 'delete_proposed',
		'log_path_field'   => 'path',
		'tool_description' => 'delete_file(path, reason) — delete a file; opens an approval UI for the user; a backup is created automatically',
	)
);
haydi_register_proposal(
	'move_file',
	array(
		'label'            => 'Move File',
		'fields'           => array( 'src', 'dest', 'reason' ),
		'ajax_action'      => 'haydi_move_file',
		'log_action'       => 'move_proposed',
		'log_path_field'   => 'src',
		'tool_description' => 'move_file(src, dest, reason) — move or rename a file; opens an approval UI for the user; a backup of src is created automatically',
	)
);
haydi_register_proposal(
	'copy_file',
	array(
		'label'            => 'Copy File',
		'fields'           => array( 'src', 'dest', 'reason' ),
		'ajax_action'      => 'haydi_copy_file',
		'log_action'       => 'copy_proposed',
		'log_path_field'   => 'src',
		'tool_description' => 'copy_file(src, dest, reason) — copy a file; opens an approval UI for the user; dest is backed up if it already exists',
	)
);
haydi_register_proposal(
	'delete_dir',
	array(
		'label'            => 'Delete Directory',
		'fields'           => array( 'path', 'reason' ),
		'ajax_action'      => 'haydi_delete_dir',
		'log_action'       => 'rmdir_proposed',
		'log_path_field'   => 'path',
		'tool_description' => 'delete_dir(path, reason) — recursively delete a directory; opens an approval UI for the user; all files are backed up; root directories cannot be deleted',
	)
);
haydi_register_proposal(
	'restore_backup',
	array(
		'label'            => 'Restore Backup',
		'fields'           => array( 'backup_file', 'original_path', 'reason' ),
		'ajax_action'      => 'haydi_restore_backup',
		'log_action'       => 'restore_proposed',
		'log_path_field'   => 'original_path',
		'tool_description' => 'restore_backup(backup_file, original_path, reason) — restore a file from a specific backup; opens an approval UI for the user; call list_backups first to get the backup_file name',
	)
);

// -------------------------------------------------------------------------
// Greeting / chip / agents filters
// -------------------------------------------------------------------------

add_filter(
	'haydi_greeting_capabilities',
	static function ( array $caps ): array {
		return array_map( static fn( $c ) => str_starts_with( $c, '📂' ) ? '📂 ' . __( 'Browsing, reading, editing, moving, or deleting plugin/theme files', 'haydi' ) : $c, $caps );
	}
);
add_filter( 'haydi_greeting_footer', static fn() => sprintf( __( 'For fast prototyping only — review AI output carefully. Consider <a href="%s" target="_blank" class="wpc-track-studio">WordPress Studio</a> for a more reliable solution.', 'haydi' ), 'https://developer.wordpress.com/studio/' ) ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
add_filter( 'haydi_greeting_question', static fn() => __( 'What would you like to work on today?', 'haydi' ) );
add_filter(
	'haydi_suggestion_pool',
	static function ( array $pool ): array {
		$backup_dir = WP_CONTENT_DIR . '/uploads/haydi-backups';
		$bak_files  = glob( $backup_dir . '/*.bak' );
		$chips      = array( __( 'Add testimonials to my homepage', 'haydi' ), __( 'Add the current year to my site footer', 'haydi' ), __( 'Check my theme for risky code and explain what you find', 'haydi' ), __( 'Add a simple feedback slider to a page', 'haydi' ) );
		if ( is_dir( $backup_dir ) && ! empty( $bak_files ) ) {
			$chips[] = __( 'Show me what backups are available and help me restore one', 'haydi' );
		}
		$pool['file']       = $chips;
		$pool['extensions'] = null;
		return array_filter( $pool );
	}
);
add_filter( 'haydi_suggestion_hint', static fn() => __( 'Try one of these to see what I can do:', 'haydi' ) );
add_filter(
	'haydi_known_extensions',
	static function ( array $exts ): array {
		$exts[] = array(
			'extension' => 'haydi-files.php',
			'provides'  => 'write_file, edit, delete_file, move_file, copy_file, delete_dir, restore_backup',
		);
		return $exts;
	}
);
add_filter(
	'haydi_agents_tool_groups',
	static function ( array $groups ): array {
		$groups['file'] = '**File ops** — read, write, edit, search, move, copy, delete, restore backups';
		return $groups;
	}
);
add_filter(
	'haydi_agents_safety_rules',
	static function ( array $rules ): array {
		$rules[] = 'Prefer `haydi_edit_file` (exact-string substitution) over a full `haydi_write_file` rewrite.';
		$rules[] = 'Before deleting anything, call `haydi_list_backups` so you know what is recoverable.';
		return $rules;
	}
);
add_filter(
	'haydi_agents_workflows',
	static function ( array $blocks ): array {
		$blocks[] = "**Edit a theme or plugin file**\n1. \`haydi_list_files\` — browse to locate the file\n2. \`haydi_read_file\` — read current contents\n3. \`haydi_edit_file\` — replace only the section that needs changing";
		$blocks[] = "**Recover from a mistake**\n1. \`haydi_list_backups\` — find the backup entry for the affected path\n2. \`haydi_restore_backup\` — restore it using the backup file name";
		return $blocks;
	}
);

// -------------------------------------------------------------------------
// Tool schemas (AI function declarations)
// -------------------------------------------------------------------------

add_filter(
	'haydi_tool_schemas',
	static function ( array $schemas ): array {
		return array_merge(
			$schemas,
			array(
				'write_file'     => array(
					'description' => 'Write new content to a file within an allowed root. Calling this tool opens an approval UI for the user; they will see the content and the reason and confirm before it is written. Include the complete file content, not a diff. Only one write per turn is accepted. You must invoke this tool to trigger the approval — describing the change in plain text does nothing.',
					'fields'      => array(
						'path'    => 'Absolute filesystem path of the file to write.',
						'content' => 'Complete new file content (full replacement, not a diff).',
						'reason'  => 'Human-readable explanation of what this change does and why.',
					),
				),
				'edit'           => array(
					'description' => 'Modify an existing file by exact string replacement. Calling this tool opens an approval UI for the user; they will see the diff and confirm before it is written. Prefer this for small edits to existing files. oldString must be copied exactly from the current file and should match once unless replaceAll is true.',
					'fields'      => array(
						'filePath'   => 'Absolute filesystem path of the existing file to edit.',
						'oldString'  => 'Exact text currently in the file. Preserve indentation, whitespace, and newlines exactly.',
						'newString'  => 'Replacement text. Use an empty string to delete oldString.',
						'replaceAll' => array(
							'type'        => 'boolean',
							'description' => 'Whether to replace every occurrence of oldString. Defaults to false.',
							'required'    => false,
						),
						'reason'     => 'Human-readable explanation of what this edit does and why.',
					),
				),
				'delete_file'    => array(
					'description' => 'Delete a file within an allowed root. Calling this tool opens an approval UI for the user; they confirm before deletion. A backup is created automatically. You must invoke this tool to trigger the approval — describing the deletion in plain text does nothing.',
					'fields'      => array(
						'path'   => 'Absolute filesystem path of the file to delete.',
						'reason' => 'Human-readable explanation of why this file should be deleted.',
					),
				),
				'move_file'      => array(
					'description' => 'Move or rename a file within the allowed roots. Calling this tool opens an approval UI for the user; they confirm before the move. A backup of the source is created automatically. You must invoke this tool to trigger the approval — describing the move in plain text does nothing.',
					'fields'      => array(
						'src'    => 'Absolute filesystem path of the source file.',
						'dest'   => 'Absolute filesystem path of the destination.',
						'reason' => 'Human-readable explanation of why this file is being moved.',
					),
				),
				'copy_file'      => array(
					'description' => 'Copy a file within the allowed roots. Calling this tool opens an approval UI for the user; they confirm before the copy. The destination is backed up if it already exists. You must invoke this tool to trigger the approval — describing the copy in plain text does nothing.',
					'fields'      => array(
						'src'    => 'Absolute filesystem path of the source file.',
						'dest'   => 'Absolute filesystem path of the destination.',
						'reason' => 'Human-readable explanation of why this file is being copied.',
					),
				),
				'delete_dir'     => array(
					'description' => 'Recursively delete a directory and all its contents. Calling this tool opens an approval UI for the user; they confirm before deletion. All files are backed up automatically. Root directories cannot be deleted. You must invoke this tool to trigger the approval — describing the deletion in plain text does nothing.',
					'fields'      => array(
						'path'   => 'Absolute filesystem path of the directory to delete.',
						'reason' => 'Human-readable explanation of why this directory should be deleted.',
					),
				),
				'restore_backup' => array(
					'description' => 'Restore a file from a specific backup. Calling this tool opens an approval UI for the user; they confirm before the restore happens. Use list_backups first to find the correct backup_file name. A new backup of the current file is created before restoring so the restore is itself reversible.',
					'fields'      => array(
						'backup_file'   => 'The backup filename (e.g. functions.php.1746960123.abc123.bak) as returned by list_backups.',
						'original_path' => 'Absolute filesystem path where the file should be restored.',
						'reason'        => 'Human-readable explanation of why this backup is being restored.',
					),
				),
			)
		);
	}
);

// -------------------------------------------------------------------------
// AJAX handlers, MCP, and REST routes
// -------------------------------------------------------------------------

( static function () {
	$logger = new Haydi_Audit_Logger();
	$guard  = new Haydi_Filesystem_Guard();
	$health = new Haydi_Health_Check();

	$verify = static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
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

	$require_param = static function ( string $key ) use ( $post_param ): string {
		$value = $post_param( $key );
		if ( '' === $value ) {
			wp_send_json_error( array( 'message' => $key . ' is required.' ) );
		}
		return $value;
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
		'wp_ajax_haydi_apply_write',
		static function () use ( $verify, $post_param, $guard, $health, $logger ) {
			$verify();
			$path = $post_param( 'path' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified; content validated by guard
			$content = isset( $_POST['content'] ) ? wp_unslash( $_POST['content'] ) : '';
			$result  = haydi_files_ext_execute_write( $path, $content, '', $guard, $health, $logger );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return; }
			wp_send_json_success( $result );
		}
	);

	add_action(
		'wp_ajax_haydi_edit_file',
		static function () use ( $verify, $post_param, $guard, $health, $logger ) {
			$verify();
			$file_path = $post_param( 'filePath' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified; exact strings must preserve code/newlines
			$old_string = isset( $_POST['oldString'] ) ? wp_unslash( $_POST['oldString'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$new_string = isset( $_POST['newString'] ) ? wp_unslash( $_POST['newString'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$replace_all = isset( $_POST['replaceAll'] ) ? filter_var( wp_unslash( $_POST['replaceAll'] ), FILTER_VALIDATE_BOOLEAN ) : false;
			$reason      = $post_param( 'reason' );
			$result      = haydi_files_ext_execute_edit( $file_path, $old_string, $new_string, $replace_all, $reason, $guard, $health, $logger );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return; }
			wp_send_json_success( $result );
		}
	);

	add_action(
		'wp_ajax_haydi_delete_file',
		static function () use ( $verify, $require_param, $guard, $health, $logger ) {
			$verify();
			$result = haydi_files_ext_execute_delete( $require_param( 'path' ), 'Human-initiated deletion.', $guard, $health, $logger );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return; }
			wp_send_json_success( $result );
		}
	);

	add_action(
		'wp_ajax_haydi_move_file',
		static function () use ( $verify, $require_param, $post_param, $guard, $health, $logger ) {
			$verify();
			$result = haydi_files_ext_execute_move( $require_param( 'src' ), $require_param( 'dest' ), $post_param( 'reason' ), $guard, $health, $logger );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return; }
			wp_send_json_success( $result );
		}
	);

	add_action(
		'wp_ajax_haydi_copy_file',
		static function () use ( $verify, $require_param, $post_param, $guard, $health, $logger ) {
			$verify();
			$result = haydi_files_ext_execute_copy( $require_param( 'src' ), $require_param( 'dest' ), $post_param( 'reason' ), $guard, $health, $logger );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return; }
			wp_send_json_success( $result );
		}
	);

	add_action(
		'wp_ajax_haydi_delete_dir',
		static function () use ( $verify, $require_param, $post_param, $guard, $health, $logger ) {
			$verify();
			$reason = $post_param( 'reason' );
			$result = haydi_files_ext_execute_delete_dir( $require_param( 'path' ), '' !== $reason ? $reason : 'Human-initiated directory deletion.', $guard, $health, $logger );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return; }
			wp_send_json_success( $result );
		}
	);

	add_action(
		'wp_ajax_haydi_restore_backup',
		static function () use ( $verify, $require_param, $guard, $health, $logger ) {
			$verify();
			$result = haydi_files_ext_execute_restore_backup( $require_param( 'backup_file' ), $require_param( 'original_path' ), '', $guard, $health, $logger );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return; }
			wp_send_json_success( $result );
		}
	);

	add_filter(
		'haydi_mcp_tools',
		static function ( array $tools ): array {
			if ( ! wp_is_file_mod_allowed( 'plugin_files' ) && ! wp_is_file_mod_allowed( 'theme_files' ) ) {
				return $tools;
			}
			return array_merge(
				$tools,
				array(
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
						'description' => 'Exact-string substitution in an existing file.',
						'inputSchema' => array(
							'type'       => 'object',
							'properties' => array(
								'path'        => array(
									'type'        => 'string',
									'description' => 'Absolute path.',
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
									'description' => 'Reason.',
								),
							),
							'required'   => array( 'path', 'old_string', 'new_string' ),
						),
					),
					array(
						'name'        => 'haydi_delete_file',
						'description' => 'Delete a file. A backup is created automatically.',
						'inputSchema' => array(
							'type'       => 'object',
							'properties' => array(
								'path'   => array(
									'type'        => 'string',
									'description' => 'Absolute path.',
								),
								'reason' => array(
									'type'        => 'string',
									'description' => 'Reason.',
								),
							),
							'required'   => array( 'path' ),
						),
					),
					array(
						'name'        => 'haydi_move_file',
						'description' => 'Move or rename a file.',
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
									'description' => 'Reason.',
								),
							),
							'required'   => array( 'src', 'dest' ),
						),
					),
					array(
						'name'        => 'haydi_copy_file',
						'description' => 'Copy a file.',
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
									'description' => 'Reason.',
								),
							),
							'required'   => array( 'src', 'dest' ),
						),
					),
					array(
						'name'        => 'haydi_delete_directory',
						'description' => 'Recursively delete a directory.',
						'inputSchema' => array(
							'type'       => 'object',
							'properties' => array(
								'path'   => array(
									'type'        => 'string',
									'description' => 'Absolute path.',
								),
								'reason' => array(
									'type'        => 'string',
									'description' => 'Reason.',
								),
							),
							'required'   => array( 'path' ),
						),
					),
					array(
						'name'        => 'haydi_restore_backup',
						'description' => 'Restore a file from a Haydi backup.',
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
									'description' => 'Reason.',
								),
							),
							'required'   => array( 'backup_file', 'original_path' ),
						),
					),
				)
			);
		}
	);

	add_filter(
		'haydi_mcp_execute_tool',
		static function ( $result, string $name, array $args ) use ( $guard, $health, $logger ) {
			if ( null !== $result ) {
				return $result; }
			switch ( $name ) {
				case 'haydi_write_file':
					$r = haydi_files_ext_execute_write( (string) ( $args['path'] ?? '' ), (string) ( $args['content'] ?? '' ), (string) ( $args['reason'] ?? '' ), $guard, $health, $logger );
					return is_wp_error( $r ) ? $r : "File written successfully: {$r['path']}";
				case 'haydi_edit_file':
					$r = haydi_files_ext_execute_edit( (string) ( $args['path'] ?? '' ), (string) ( $args['old_string'] ?? '' ), (string) ( $args['new_string'] ?? '' ), (bool) ( $args['replace_all'] ?? false ), (string) ( $args['reason'] ?? '' ), $guard, $health, $logger );
					return is_wp_error( $r ) ? $r : "File edited successfully ({$r['matches']} match(es)): {$r['path']}";
				case 'haydi_delete_file':
					$reason = (string) ( $args['reason'] ?? '' );
					$r      = haydi_files_ext_execute_delete( (string) ( $args['path'] ?? '' ), '' !== $reason ? $reason : 'MCP-initiated deletion.', $guard, $health, $logger );
					return is_wp_error( $r ) ? $r : "File deleted successfully: {$r['path']}";
				case 'haydi_move_file':
					$r = haydi_files_ext_execute_move( (string) ( $args['src'] ?? '' ), (string) ( $args['dest'] ?? '' ), (string) ( $args['reason'] ?? '' ), $guard, $health, $logger );
					return is_wp_error( $r ) ? $r : "File moved: {$r['src']} → {$r['dest']}";
				case 'haydi_copy_file':
					$r = haydi_files_ext_execute_copy( (string) ( $args['src'] ?? '' ), (string) ( $args['dest'] ?? '' ), (string) ( $args['reason'] ?? '' ), $guard, $health, $logger );
					return is_wp_error( $r ) ? $r : "File copied: {$r['src']} → {$r['dest']}";
				case 'haydi_delete_directory':
					$reason = (string) ( $args['reason'] ?? '' );
					$r      = haydi_files_ext_execute_delete_dir( (string) ( $args['path'] ?? '' ), '' !== $reason ? $reason : 'MCP-initiated directory deletion.', $guard, $health, $logger );
					return is_wp_error( $r ) ? $r : "Directory deleted: {$r['path']}";
				case 'haydi_restore_backup':
					$r = haydi_files_ext_execute_restore_backup( (string) ( $args['backup_file'] ?? '' ), (string) ( $args['original_path'] ?? '' ), (string) ( $args['reason'] ?? '' ), $guard, $health, $logger );
					return is_wp_error( $r ) ? $r : "Backup restored: {$r['backup_file']} → {$r['original_path']}";
			}
			return null;
		},
		10,
		3
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
									return $wrap( haydi_files_ext_execute_write( (string) ( $b['path'] ?? '' ), (string) ( $b['content'] ?? '' ), (string) ( $b['reason'] ?? '' ), $guard, $health, $logger ) ); },
					),
					array(
						'methods'             => 'PATCH',
						'permission_callback' => $perm,
						'callback'            => static function ( WP_REST_Request $r ) use ( $wrap, $guard, $health, $logger ) {
								$b = $r->get_json_params();
								return $wrap( haydi_files_ext_execute_edit( (string) ( $b['path'] ?? '' ), (string) ( $b['old_string'] ?? '' ), (string) ( $b['new_string'] ?? '' ), (bool) ( $b['replace_all'] ?? false ), (string) ( $b['reason'] ?? '' ), $guard, $health, $logger ) ); },
					),
					array(
						'methods'             => 'DELETE',
						'permission_callback' => $perm,
						'callback'            => static function ( WP_REST_Request $r ) use ( $wrap, $guard, $health, $logger ) {
								$reason = (string) ( $r->get_param( 'reason' ) ?? '' );
								return $wrap( haydi_files_ext_execute_delete( (string) ( $r->get_param( 'path' ) ?? '' ), '' !== $reason ? $reason : 'API-initiated deletion.', $guard, $health, $logger ) ); },
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
						return $wrap( haydi_files_ext_execute_restore_backup( (string) ( $b['backup_file'] ?? '' ), (string) ( $b['original_path'] ?? '' ), (string) ( $b['reason'] ?? '' ), $guard, $health, $logger ) );
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
						return $wrap( haydi_files_ext_execute_move( (string) ( $b['src'] ?? '' ), (string) ( $b['dest'] ?? '' ), (string) ( $b['reason'] ?? '' ), $guard, $health, $logger ) );
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
						return $wrap( haydi_files_ext_execute_copy( (string) ( $b['src'] ?? '' ), (string) ( $b['dest'] ?? '' ), (string) ( $b['reason'] ?? '' ), $guard, $health, $logger ) );
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
						return $wrap( haydi_files_ext_execute_delete_dir( (string) ( $r->get_param( 'path' ) ?? '' ), '' !== $reason ? $reason : 'API-initiated directory deletion.', $guard, $health, $logger ) );
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
function haydi_files_ext_execute_write( string $path, string $content, string $reason, Haydi_Filesystem_Guard $guard, Haydi_Health_Check $health, Haydi_Audit_Logger $logger ): array|WP_Error {
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
function haydi_files_ext_execute_edit( string $file_path, string $old_string, string $new_string, bool $replace_all, string $reason, Haydi_Filesystem_Guard $guard, Haydi_Health_Check $health, Haydi_Audit_Logger $logger ): array|WP_Error {
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
function haydi_files_ext_execute_delete( string $path, string $reason, Haydi_Filesystem_Guard $guard, Haydi_Health_Check $health, Haydi_Audit_Logger $logger ): array|WP_Error {
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
function haydi_files_ext_execute_move( string $src, string $dest, string $reason, Haydi_Filesystem_Guard $guard, Haydi_Health_Check $health, Haydi_Audit_Logger $logger ): array|WP_Error {
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
function haydi_files_ext_execute_copy( string $src, string $dest, string $reason, Haydi_Filesystem_Guard $guard, Haydi_Health_Check $health, Haydi_Audit_Logger $logger ): array|WP_Error {
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
function haydi_files_ext_execute_delete_dir( string $path, string $reason, Haydi_Filesystem_Guard $guard, Haydi_Health_Check $health, Haydi_Audit_Logger $logger ): array|WP_Error {
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
function haydi_files_ext_execute_restore_backup( string $backup_file, string $original_path, string $reason, Haydi_Filesystem_Guard $guard, Haydi_Health_Check $health, Haydi_Audit_Logger $logger ): array|WP_Error {
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
