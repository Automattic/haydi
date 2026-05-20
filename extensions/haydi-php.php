<?php
/**
 * Haydi PHP Extension — run_php.
 * Install: drop into wp-content/plugins/haydi/extensions/
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'haydi_register_proposal' ) || ! class_exists( 'Haydi_Audit_Logger' ) ) {
	return;
}

haydi_register_proposal(
	'run_php',
	array(
		'label'            => 'Run PHP',
		'fields'           => array( 'code', 'reason' ),
		'ajax_action'      => 'haydi_run_php',
		'log_action'       => 'php_proposed',
		'log_path_field'   => '',
		'tool_description' => 'run_php(code, reason) — execute a PHP snippet in the WordPress context; opens an approval UI for the user; output is captured',
	)
);

add_filter(
	'haydi_greeting_capabilities',
	static function ( array $caps ): array {
		$caps[] = '⚙️ ' . __( 'Executing PHP snippets in the WordPress context', 'haydi' );
		return $caps;
	}
);

add_filter(
	'haydi_greeting_footer',
	static fn() => sprintf(
		/* translators: %s: link to WordPress Studio */
		__( 'For fast prototyping only — review AI output carefully. Consider <a href="%s" target="_blank" class="wpc-track-studio">WordPress Studio</a> for a more reliable solution.', 'haydi' ),
		'https://developer.wordpress.com/studio/'
	)
);

add_filter( 'haydi_greeting_question', static fn() => __( 'What would you like to work on today?', 'haydi' ) );

add_filter(
	'haydi_suggestion_pool',
	static function ( array $pool ): array {
		$pool['php'] = array(
			__( 'Create a draft About page I can edit', 'haydi' ),
			__( 'Fix broken links or pages after recent changes', 'haydi' ),
		);
		return $pool;
	}
);

add_filter( 'haydi_suggestion_hint', static fn() => __( 'Try one of these to see what I can do:', 'haydi' ) );

add_filter(
	'haydi_known_extensions',
	static function ( array $exts ): array {
		$exts[] = array(
			'extension' => 'haydi-php.php',
			'provides'  => 'run_php',
		);
		return $exts;
	}
);

add_filter(
	'haydi_agents_tool_groups',
	static function ( array $groups ): array {
		$groups['php'] = '**PHP** — execute snippets in the live WordPress context; output is captured and returned';
		return $groups;
	}
);

add_filter(
	'haydi_agents_safety_rules',
	static function ( array $rules ): array {
		$rules[] = 'PHP runs in the live site context — test defensively and keep snippets focused.';
		return $rules;
	}
);

add_filter(
	'haydi_tool_schemas',
	static function ( array $schemas ): array {
		$schemas['run_php'] = array(
			'description' => 'Execute a PHP code snippet in the WordPress context. Calling this tool opens an approval UI for the user; they see the code and the reason and confirm before execution. Output is captured and returned. Use for tasks that cannot be done via SQL or file writes alone (creating posts/pages, calling WP APIs, etc.). You must invoke this tool to trigger the approval — describing the snippet in plain text does nothing.',
			'fields'      => array(
				'code'   => 'PHP code to execute (without an opening <?php tag).',
				'reason' => 'Human-readable explanation of what this code does and why.',
			),
		);
		return $schemas;
	}
);

( static function () {
	$logger = new Haydi_Audit_Logger();
	$health = new Haydi_Health_Check();

	add_action(
		'wp_ajax_haydi_run_php',
		static function () use ( $logger, $health ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
			}
			if ( ! check_ajax_referer( 'haydi_nonce', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => 'Invalid or expired nonce.' ), 403 );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; code is human-approved
			$code = isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

			$result = haydi_php_ext_execute( $code, $reason, $logger, $health );
			if ( is_wp_error( $result ) ) {
				$data   = $result->get_error_data();
				$output = is_array( $data ) && isset( $data['output'] ) ? $data['output'] : '';
				wp_send_json_error(
					array(
						'message' => $result->get_error_message(),
						'output'  => $output,
					)
				);
				return;
			}
			wp_send_json_success( $result );
		}
	);

	add_filter(
		'haydi_mcp_tools',
		static function ( array $tools ) {
			return array_merge(
				$tools,
				array(
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
				)
			);
		}
	);

	add_filter(
		'haydi_mcp_execute_tool',
		static function ( $result, string $name, array $args ) use ( $logger, $health ) {
			if ( null !== $result || 'haydi_run_php' !== $name ) {
				return $result;
			}
			$r = haydi_php_ext_execute(
				trim( (string) ( $args['code'] ?? '' ) ),
				(string) ( $args['reason'] ?? '' ),
				$logger,
				$health
			);
			if ( is_wp_error( $r ) ) {
				$data   = $r->get_error_data();
				$output = is_array( $data ) && isset( $data['output'] ) ? $data['output'] : '';
				$msg    = $r->get_error_message();
				return new WP_Error( $r->get_error_code(), $msg . ( '' !== $output ? "\nOutput: {$output}" : '' ) );
			}
			return $r['output'];
		},
		10,
		3
	);

	add_action(
		'rest_api_init',
		static function () use ( $logger, $health ) {
			register_rest_route(
				'haydi/v1',
				'/php',
				array(
					'methods'             => 'POST',
					'callback'            => static function ( WP_REST_Request $r ) use ( $logger, $health ) {
						$body   = $r->get_json_params();
						$result = haydi_php_ext_execute(
							trim( (string) ( $body['code'] ?? '' ) ),
							(string) ( $body['reason'] ?? '' ),
							$logger,
							$health
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
					},
					'permission_callback' => 'haydi_is_authorized_api_request',
				)
			);
		}
	);
} )();

function haydi_php_ext_execute( string $code, string $reason, Haydi_Audit_Logger $logger, Haydi_Health_Check $health ): array|WP_Error {
	if ( '' === trim( $code ) ) {
		return new WP_Error( 'missing_param', 'code is required.', array( 'status' => 400 ) );
	}

	$logger->log( 'php_executed', '', $reason );

	ob_start();
	$exec_error = null;
	$tmp        = null;
	try {
		if ( haydi_php_ext_eval_available() ) {
			// phpcs:ignore Squiz.PHP.Eval.Discouraged, Generic.PHP.ForbiddenFunctions.Found -- intentional: human-approved PHP execution
			eval( $code ); // nosemgrep.
		} else {
			$tmp = wp_tempnam( 'haydi_run_' );
			file_put_contents( $tmp, "<?php\n" . $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			include $tmp;
		}
	} catch ( \Throwable $e ) {
		$msg = $e->getMessage();
		if ( null !== $tmp ) {
			$msg = str_replace( $tmp, '(snippet)', $msg );
		}
		$exec_error = get_class( $e ) . ': ' . $msg;
	} finally {
		if ( null !== $tmp ) {
			wp_delete_file( $tmp );
		}
	}
	$output = ob_get_clean();

	if ( null !== $exec_error ) {
		return new WP_Error(
			'php_error',
			'PHP error — ' . $exec_error,
			array(
				'status' => 400,
				'output' => $output,
			)
		);
	}

	$err = $health->verify_or_warn( 'PHP execution' );
	if ( $err ) {
		return new WP_Error(
			$err->get_error_code(),
			$err->get_error_message(),
			array(
				'status' => 500,
				'output' => '' !== $output ? $output : '(no output)',
			)
		);
	}

	return array(
		'message' => 'PHP executed successfully.',
		'output'  => '' !== $output ? $output : '(no output)',
	);
}

function haydi_php_ext_eval_available(): bool {
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}
	try {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged, Generic.PHP.ForbiddenFunctions.Found, WordPress.PHP.NoSilencedErrors.Discouraged
		$cached = (bool) @eval( 'return true;' );
	} catch ( \Throwable $e ) {
		$cached = false;
	}
	return $cached;
}
