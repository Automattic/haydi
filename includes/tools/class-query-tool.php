<?php
/**
 * Built-in SQL query tool.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the SQL Tool Declaration and existing query Implementation.
 */
function haydi_register_query_tool(
	Haydi_Tool_Catalog $catalog,
	Haydi_Audit_Logger $logger,
	Haydi_Health_Check $health
): void {
	$catalog->register(
		array(
			'name'           => 'run_query',
			'description'    => 'Run a SQL query via $wpdb. Calling this tool opens an approval UI for the user; they will see the SQL and the reason and confirm before it executes. You must invoke this tool to trigger the approval — describing the query in plain text does nothing.',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'sql'    => array(
						'type'        => 'string',
						'description' => 'The SQL query to execute.',
					),
					'reason' => array(
						'type'        => 'string',
						'description' => 'Human-readable explanation of what this query does and why.',
					),
				),
				'required'   => array( 'sql', 'reason' ),
			),
			'effect'         => 'approval',
			'activity_label' => 'Prepared run SQL query',
			'proposal'       => array(
				'label'          => 'Run SQL Query',
				'log_action'     => 'query_proposed',
				'log_path_field' => '',
			),
			'projections'    => array(
				'chat' => true,
				'mcp'  => array(
					'name'        => 'haydi_run_query',
					'description' => 'Run a SQL query via wpdb. SELECT/SHOW/DESCRIBE/EXPLAIN return rows; other statements return affected-row count.',
					'required'    => array( 'sql' ),
				),
			),
		),
		static fn( array $arguments ): array|WP_Error => haydi_query_execute(
			trim( (string) ( $arguments['sql'] ?? '' ) ),
			(string) ( $arguments['reason'] ?? '' ),
			$logger,
			$health
		)
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
				'/query',
				array(
					'methods'             => 'POST',
					'callback'            => static function ( WP_REST_Request $r ) use ( $logger, $health ) {
						$body   = $r->get_json_params();
						$result = haydi_query_execute(
							trim( (string) ( $body['sql'] ?? '' ) ),
							(string) ( $body['reason'] ?? '' ),
							$logger,
							$health
						);
						if ( is_wp_error( $result ) ) {
							$data   = $result->get_error_data();
							$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
							return new WP_REST_Response( array( 'message' => $result->get_error_message() ), $status );
						}
						return new WP_REST_Response( $result );
					},
					'permission_callback' => 'haydi_is_authorized_api_request',
				)
			);
		}
	);
} )();

function haydi_query_execute( string $sql, string $reason, Haydi_Audit_Logger $logger, Haydi_Health_Check $health ): array|WP_Error {
	if ( '' === trim( $sql ) ) {
		return new WP_Error( 'missing_param', 'sql is required.', array( 'status' => 400 ) );
	}

	global $wpdb;
	$first_word = strtoupper( strtok( trim( $sql ), " \t\n\r" ) );
	$is_select  = in_array( $first_word, array( 'SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN' ), true );

	$wpdb->show_errors();

	if ( $is_select ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( $wpdb->last_error ) {
			$logger->log( 'query_error', '', $wpdb->last_error );
			return new WP_Error( 'query_error', 'Query error: ' . $wpdb->last_error, array( 'status' => 400 ) );
		}
		$truncated = false;
		if ( count( $rows ) > 200 ) {
			$rows      = array_slice( $rows, 0, 200 );
			$truncated = true;
		}
		$logger->log( 'query_executed', '', $reason );
		return array(
			'type'      => 'select',
			'rows'      => $rows,
			'count'     => count( $rows ),
			'truncated' => $truncated,
		);
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$result = $wpdb->query( $sql );
	if ( false === $result ) {
		$error = $wpdb->last_error ? $wpdb->last_error : 'Query failed.';
		$logger->log( 'query_error', '', $error );
		return new WP_Error( 'query_error', 'Query error: ' . $error, array( 'status' => 400 ) );
	}

	$err = $health->verify_or_warn( 'SQL query' );
	if ( $err ) {
		return new WP_Error( $err->get_error_code(), $err->get_error_message(), array( 'status' => 500 ) );
	}

	$logger->log( 'query_executed', '', $reason );
	return array(
		'type' => 'write',
		'rows' => $result,
	);
}
