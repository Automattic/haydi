<?php
/**
 * Health_Check — fires a loopback request to the dedicated haydi_health
 * endpoint after a mutating operation to confirm WordPress still loads, and
 * runs a per-operation undo callback when it doesn't.
 *
 * URL discovery tries home_url() first (works behind proxies), then
 * 127.0.0.1 with the port parsed from home_url(), then common fallback ports.
 * The first reachable URL is cached in a transient. A filter lets site owners
 * override the URL or skip the check entirely on hosts with unusual networking.
 */

defined( 'ABSPATH' ) || exit;

class Haydi_Health_Check {

	private const STATUS_HEALTHY     = 'healthy';
	private const STATUS_BROKEN      = 'broken';
	private const STATUS_UNREACHABLE = 'unreachable';

	/**
	 * Perform the loopback request and return one of three states:
	 *   healthy     — got a 200 with our success token
	 *   broken      — connected but WP returned an error body / bad token
	 *   unreachable — could not establish a connection at all
	 */
	private function check(): string {
		if ( apply_filters( 'haydi_skip_health_check', false ) ) {
			return self::STATUS_HEALTHY;
		}

		$health_url = apply_filters( 'haydi_health_url', $this->discover_health_url() );

		if ( null === $health_url ) {
			return self::STATUS_UNREACHABLE;
		}

		$response = wp_remote_get( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
			$health_url,
			array(
				'timeout'   => 10,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::STATUS_UNREACHABLE;
		}

		$body = wp_remote_retrieve_body( $response );
		return str_contains( $body, '"success":true' )
			? self::STATUS_HEALTHY
			: self::STATUS_BROKEN;
	}

	/**
	 * Walk candidate loopback URLs until one returns the health success token,
	 * cache it, and return it. Returns null when no candidate works.
	 *
	 * Only 127.0.0.1 addresses are tried: the health endpoint rejects requests
	 * whose REMOTE_ADDR is not loopback, so home_url() would always fail there.
	 * Discovery validates the full success response (not just TCP reachability)
	 * so a URL that connects but returns an error body is never cached.
	 */
	private function discover_health_url(): ?string {
		$cached = get_transient( 'haydi_health_url' );
		if ( false !== $cached ) {
			return $cached;
		}

		$path        = parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
		$query       = '?action=haydi_health';
		$server_port = isset( $_SERVER['SERVER_PORT'] ) ? intval( $_SERVER['SERVER_PORT'] ) : 80; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$candidates = array_unique(
			array(
				// Port PHP is actually receiving requests on in this process.
				'http://127.0.0.1:' . $server_port . $path . $query,
				// Standard fallbacks.
				'http://127.0.0.1' . $path . $query,
				'http://127.0.0.1:8080' . $path . $query,
			)
		);

		foreach ( $candidates as $url ) {
			$response = wp_remote_get( // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
				$url,
				array(
					'timeout'   => 5,
					'sslverify' => false,
				)
			);
			if ( ! is_wp_error( $response ) && str_contains( wp_remote_retrieve_body( $response ), '"success":true' ) ) {
				set_transient( 'haydi_health_url', $url, HOUR_IN_SECONDS );
				return $url;
			}
		}

		return null;
	}

	/**
	 * Convenience wrapper: true when the site is healthy.
	 */
	public function verify(): bool {
		return self::STATUS_HEALTHY === $this->check();
	}

	/**
	 * Run the post-mutation health check and, on failure, invoke $undo to
	 * roll back the operation.
	 *
	 * Loopback-unreachable is treated as "can't tell" — the revert is skipped
	 * and null is returned rather than rolling back a perfectly good change.
	 *
	 * @param  callable $undo    Returns true on successful revert, or WP_Error.
	 * @param  string   $context Human-readable label inserted into the error message
	 *                           (e.g. "File write", "Plugin activation").
	 * @return WP_Error|null     null when the site is healthy (or unverifiable);
	 *                           WP_Error when the site is confirmed broken.
	 */
	public function verify_or_revert( callable $undo, string $context ): ?WP_Error {
		$status = $this->check();

		if ( self::STATUS_HEALTHY === $status ) {
			return null;
		}

		if ( self::STATUS_UNREACHABLE === $status ) {
			// Loopback could not connect — networking issue, not a site error.
			return null;
		}

		$restored = $undo();
		$detail   = is_wp_error( $restored )
			? ' Restore also failed: ' . $restored->get_error_message()
			: ' Change reverted from backup.';

		return new WP_Error(
			'site_unhealthy',
			$context . ' caused a site error after being applied.' . $detail
		);
	}

	/**
	 * Detect-only variant for operations with no automatic undo (run_php,
	 * install_plugin). Surfaces a loud message so the user can use WP core's
	 * Fatal Error Recovery Mode if wp-admin is no longer reachable.
	 *
	 * Loopback-unreachable is treated as "can't tell" — no warning is raised
	 * since the site may be perfectly fine.
	 */
	public function verify_or_warn( string $context ): ?WP_Error {
		$status = $this->check();

		if ( self::STATUS_HEALTHY === $status ) {
			return null;
		}

		if ( self::STATUS_UNREACHABLE === $status ) {
			return null;
		}

		return new WP_Error(
			'site_unhealthy',
			$context . ' caused a site error and there is no automatic revert. ' .
			'If wp-admin is unreachable, check the admin email for a WordPress recovery-mode link.'
		);
	}
}
