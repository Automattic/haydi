<?php
/**
 * SQL execution tool — runs human-approved queries via $wpdb.
 *
 * SELECT/SHOW/DESCRIBE/EXPLAIN return rows (capped at MAX_QUERY_ROWS for the
 * AI's benefit); everything else returns the affected-row count.
 */

defined( 'ABSPATH' ) || exit;

class Haydi_Query_Tool extends Haydi_Ajax_Tool_Base {

	/** Maximum rows returned to the AI from a SELECT query. */
	const MAX_QUERY_ROWS = 200;

	/** @var Haydi_Health_Check Post-mutation site health probe. */
	private Haydi_Health_Check $health;

	public function __construct( Haydi_Audit_Logger $logger, Haydi_Health_Check $health ) {
		parent::__construct( $logger );
		$this->health = $health;
	}

	public function register(): void {
		add_action( 'wp_ajax_haydi_execute_query', array( $this, 'handle_execute_query' ) );
	}

	/**
	 * Execute a human-approved SQL query via $wpdb and return results.
	 *
	 * SELECT → returns rows as JSON.
	 * Everything else → returns affected-row count or success/error.
	 */
	public function handle_execute_query(): void {
		$this->verify();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via $this->verify(); SQL is human-approved raw input
		$sql    = isset( $_POST['sql'] ) ? wp_unslash( $_POST['sql'] ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === trim( $sql ) ) {
			wp_send_json_error( array( 'message' => 'sql is required.' ) );
		}

		global $wpdb;

		// Detect query type by first keyword.
		$first_word = strtoupper( strtok( trim( $sql ), " \t\n\r" ) );
		$is_select  = ( 'SELECT' === $first_word || 'SHOW' === $first_word || 'DESCRIBE' === $first_word || 'EXPLAIN' === $first_word );

		$wpdb->show_errors();

		if ( $is_select ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- human-approved raw SQL
			$rows = $wpdb->get_results( $sql, ARRAY_A );

			if ( $wpdb->last_error ) {
				$this->logger->log( 'query_error', '', $wpdb->last_error );
				wp_send_json_error( array( 'message' => 'Query error: ' . $wpdb->last_error ) );
			}

			$truncated = false;
			if ( count( $rows ) > self::MAX_QUERY_ROWS ) {
				$rows      = array_slice( $rows, 0, self::MAX_QUERY_ROWS );
				$truncated = true;
			}

			$this->logger->log( 'query_executed', '', $reason );

			wp_send_json_success(
				array(
					'type'      => 'select',
					'rows'      => $rows,
					'count'     => count( $rows ),
					'truncated' => $truncated,
					'result'    => wp_json_encode( $rows ),
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- human-approved raw SQL
			$result = $wpdb->query( $sql );

			if ( false === $result ) {
				$error = $wpdb->last_error ? $wpdb->last_error : 'Query failed.';
				$this->logger->log( 'query_error', '', $error );
				wp_send_json_error( array( 'message' => 'Query error: ' . $error ) );
			}

			// Detect-only: a successful mutating query can still wreck the site
			// (DROP TABLE wp_options, UPDATE wp_options SET option_value = '' WHERE
			// option_name = 'siteurl', etc.). DB state has no .bak so there is no
			// automatic revert — surface the failure loudly instead.
			$err = $this->health->verify_or_warn( 'SQL query' );
			if ( $err ) {
				wp_send_json_error( array( 'message' => $err->get_error_message() ) );
				return;
			}

			$this->logger->log( 'query_executed', '', $reason );

			$summary = is_int( $result )
				? "Query OK, {$result} row(s) affected."
				: 'Query executed successfully.';

			wp_send_json_success(
				array(
					'type'   => 'write',
					'result' => $summary,
					'rows'   => $result,
				)
			);
		}
	}
}
