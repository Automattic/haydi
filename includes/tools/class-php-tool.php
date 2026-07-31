<?php
/**
 * Built-in PHP execution tool.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'haydi_register_action_proposal' ) || ! class_exists( 'Haydi_Audit_Logger' ) ) {
	return;
}

haydi_register_action_proposal(
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

			$result = haydi_php_execute( $code, $reason, $logger, $health );
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
			$r = haydi_php_execute(
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
						$result = haydi_php_execute(
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

function haydi_php_execute( string $code, string $reason, Haydi_Audit_Logger $logger, Haydi_Health_Check $health ): array|WP_Error {
	if ( '' === trim( $code ) ) {
		return new WP_Error( 'missing_param', 'code is required.', array( 'status' => 400 ) );
	}

	$logger->log( 'php_executed', '', $reason );

	ob_start();
	$exec_error = null;
	$tmp        = null;
	try {
		if ( haydi_php_eval_available() ) {
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

function haydi_php_eval_available(): bool {
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
