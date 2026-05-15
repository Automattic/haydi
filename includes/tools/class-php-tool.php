<?php
/**
 * PHP execution tool — runs a human-approved PHP snippet in the WordPress
 * context with output buffering. Falls back to include() on hosts where
 * eval() is disabled (Suhosin, some shared hosts).
 */

defined( 'ABSPATH' ) || exit;

class Haydi_PHP_Tool extends Haydi_Ajax_Tool_Base {

	/** @var Haydi_Health_Check Post-execution site health probe. */
	private Haydi_Health_Check $health;

	public function __construct( Haydi_Audit_Logger $logger, Haydi_Health_Check $health ) {
		parent::__construct( $logger );
		$this->health = $health;
	}

	public function register(): void {
		add_action( 'wp_ajax_haydi_run_php', array( $this, 'handle_run_php' ) );
	}

	/**
	 * Execute a PHP snippet in the WordPress context and capture output.
	 *
	 * @param string $code   PHP code to execute (no opening <?php tag).
	 * @param string $reason Audit log reason.
	 * @return array|WP_Error Success payload or WP_Error on failure.
	 */
	public function execute_php( string $code, string $reason ): array|WP_Error {
		if ( '' === trim( $code ) ) {
			return new WP_Error( 'missing_param', 'code is required.', array( 'status' => 400 ) );
		}

		$this->logger->log( 'php_executed', '', $reason );

		ob_start();
		$exec_error = null;
		$tmp        = null;
		try {
			if ( self::eval_available() ) {
				// phpcs:ignore Squiz.PHP.Eval.Discouraged, Generic.PHP.ForbiddenFunctions.Found -- intentional: human-approved PHP execution
				eval( $code ); // nosemgrep.
			} else {
				// Fallback for hosts where eval is disabled (e.g. Suhosin). include
				// runs the snippet at file scope, which matches eval's behavior
				// closely enough for self-contained snippets.
				$tmp = wp_tempnam( 'haydi_run_' );
				file_put_contents( $tmp, "<?php\n" . $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				include $tmp;
			}
		} catch ( \Throwable $e ) {
			// Strip the temp-file path from the message so users see a stable location.
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

		// Detect-only: a snippet that returns cleanly can still leave the site
		// in a broken state (e.g. update_option('template', 'missing-theme')).
		// There is no automatic revert for arbitrary PHP, so we surface the
		// failure loudly along with whatever output the snippet produced.
		$err = $this->health->verify_or_warn( 'PHP execution' );
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

	/**
	 * Execute a human-approved PHP snippet in the WordPress context.
	 * Output (via echo/print/var_dump etc.) is captured and returned.
	 */
	public function handle_run_php(): void {
		$this->verify();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via $this->verify(); code is human-approved
		$code = isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

		$result = $this->execute_php( $code, $reason );
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

	/**
	 * Probe whether eval() is usable on this host.
	 *
	 * Eval is a language construct so function_exists() can't see it, and some
	 * hardening setups (Suhosin's executor.disable_eval, certain shared hosts)
	 * disable it without using disable_functions. Probing once per request is
	 * the only reliable detection.
	 */
	private static function eval_available(): bool {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		try {
			// @ suppresses the host-emitted warning when eval is disabled; the
			// return value tells us whether the construct actually ran.
			// phpcs:ignore Squiz.PHP.Eval.Discouraged, Generic.PHP.ForbiddenFunctions.Found, WordPress.PHP.NoSilencedErrors.Discouraged
			$cached = (bool) @eval( 'return true;' );
		} catch ( \Throwable $e ) {
			$cached = false;
		}
		return $cached;
	}
}
