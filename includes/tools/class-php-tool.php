<?php
/**
 * Built-in PHP execution tool.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the PHP Tool Declaration and existing execution Implementation.
 */
function haydi_register_php_tool(
	Haydi_Tool_Catalog $catalog,
	Haydi_Audit_Logger $logger,
	Haydi_Health_Check $health
): void {
	$catalog->register(
		array(
			'name'           => 'run_php',
			'description'    => 'Execute a PHP code snippet in the WordPress context. Calling this tool opens an approval UI for the user; they see the code and the reason and confirm before execution. Output is captured and returned. Use for tasks that cannot be done via SQL or file writes alone (creating posts/pages, calling WP APIs, etc.). You must invoke this tool to trigger the approval — describing the snippet in plain text does nothing.',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'code'   => array(
						'type'        => 'string',
						'description' => 'PHP code to execute (without an opening <?php tag).',
					),
					'reason' => array(
						'type'        => 'string',
						'description' => 'Human-readable explanation of what this code does and why.',
					),
				),
				'required'   => array( 'code', 'reason' ),
			),
			'effect'         => 'approval',
			'activity_label' => 'Prepared run PHP',
			'proposal'       => array(
				'label'          => 'Run PHP',
				'log_action'     => 'php_proposed',
				'log_path_field' => '',
			),
			'projections'    => array(
				'chat' => true,
				'mcp'  => array(
					'name'        => 'haydi_run_php',
					'description' => 'Execute a PHP snippet in the WordPress context. Output is captured and returned.',
					'required'    => array( 'code' ),
				),
			),
			'presenters'     => array(
				'mcp' => static fn( array $result ): string => $result['output'],
			),
		),
		static function ( array $arguments ) use ( $logger, $health ): array|WP_Error {
			$result = haydi_php_execute(
				trim( (string) ( $arguments['code'] ?? '' ) ),
				(string) ( $arguments['reason'] ?? '' ),
				$logger,
				$health
			);
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$data   = $result->get_error_data();
			$output = is_array( $data ) && isset( $data['output'] ) ? (string) $data['output'] : '';
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message() . ( '' !== $output ? "\nOutput: {$output}" : '' ),
				$data
			);
		}
	);
}

( static function () {
	$logger = new Haydi_Audit_Logger();
	$health = new Haydi_Health_Check();

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
