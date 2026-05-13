<?php
/**
 * Shared base for every AI tool that exposes one or more AJAX endpoints.
 *
 * Holds the auth helper and POST sanitisers so each concrete tool only has to
 * focus on its own action handling. Each subclass implements register() to
 * wire its handler methods onto the appropriate wp_ajax_* hooks.
 */

defined( 'ABSPATH' ) || exit;

abstract class Haydi_Ajax_Tool_Base {

	/** @var Haydi_Audit_Logger Audit logger shared by every concrete tool. */
	protected Haydi_Audit_Logger $logger;

	public function __construct( Haydi_Audit_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Wire the tool's handlers onto wp_ajax_* hooks. Called once during plugin bootstrap.
	 */
	abstract public function register(): void;

	// -------------------------------------------------------------------------
	// Shared helpers — used by every concrete tool's AJAX handlers.
	// -------------------------------------------------------------------------

	/**
	 * Verify the current user has manage_options capability and a valid nonce.
	 * Halts execution via wp_send_json_error → wp_die when either check fails.
	 */
	protected function verify(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		if ( ! check_ajax_referer( 'haydi_nonce', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid or expired nonce.' ), 403 );
		}
	}

	/**
	 * Return a sanitized POST parameter, or empty string if absent.
	 * Nonce is verified by verify() before this is called.
	 */
	protected function post_param( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * Read a required POST string. Sends a JSON error and stops execution
	 * (via wp_send_json_error → wp_die) when the value is missing or empty.
	 */
	protected function require_param( string $key ): string {
		$value = $this->post_param( $key );
		if ( '' === $value ) {
			wp_send_json_error( array( 'message' => $key . ' is required.' ) );
		}
		return $value;
	}

	/**
	 * Read an optional model preference from the current AJAX request.
	 *
	 * @return array|null [provider_id, model_id] or null when no configured model was submitted.
	 */
	protected function get_model_preference_from_request(): ?array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by caller.
		$provider = isset( $_POST['model_provider'] ) ? sanitize_key( wp_unslash( $_POST['model_provider'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by caller.
		$model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';

		if ( '' === $provider || '' === $model ) {
			return null;
		}

		if ( ! preg_match( '/^[A-Za-z0-9._:-]+$/', $model ) ) {
			return null;
		}

		return array( $provider, $model );
	}

	/**
	 * Dispatch a Filesystem_Guard-style return value. WP_Error → JSON error;
	 * otherwise the success callback is invoked with the resolved result.
	 *
	 * The callback is responsible for calling wp_send_json_success() itself
	 * — that lets each handler shape its response and log appropriately.
	 */
	protected function dispatch_guard_result( $result, callable $on_success ): void {
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$on_success( $result );
	}
}
