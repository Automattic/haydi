<?php
/**
 * Fetch_url tool — SSRF-protected outbound HTTP fetch.
 *
 * No AJAX endpoint of its own: the Tool Catalog invokes fetch_for_ai(). The
 * SSRF guard, DNS pinning, transport enforcement, and private-range checks live
 * in one focused unit so the security perimeter is easy to audit.
 */

defined( 'ABSPATH' ) || exit;

class Haydi_Fetch_Url_Tool {

	/** Max response body size returned to the AI (100 KB). */
	const MAX_FETCH_BYTES = 102400;

	/** Hard cap on bytes downloaded from upstream before truncation logic runs (200 KB). */
	const MAX_FETCH_RESPONSE_BYTES = 204800;

	/** @var Haydi_Audit_Logger Audit logger for fetch_url calls. */
	private Haydi_Audit_Logger $logger;

	public function __construct( Haydi_Audit_Logger $logger ) {
		$this->logger = $logger;
	}

	public function register_tools( Haydi_Tool_Catalog $catalog ): void {
		$catalog->register(
			array(
				'name'           => 'fetch_url',
				'description'    => 'Fetch the text content of a public HTTP/HTTPS URL for reference. Calling this tool opens an approval UI showing the exact URL before any request is sent. You must invoke this tool to trigger the approval; describing the fetch in plain text does nothing. Private/internal addresses are blocked.',
				'input_schema'   => array(
					'type'       => 'object',
					'properties' => array(
						'url' => array(
							'type'        => 'string',
							'description' => 'Fully-qualified public HTTP or HTTPS URL to fetch.',
						),
					),
					'required'   => array( 'url' ),
				),
				'effect'         => 'approval',
				'activity_label' => 'Prepared URL fetch',
				'proposal'       => array(
					'label'          => 'Fetch URL',
					'log_action'     => 'fetch_url_proposed',
					'log_path_field' => 'url',
				),
				'projections'    => array(
					'chat' => true,
					'mcp'  => array(
						'name'        => 'haydi_fetch_url',
						'description' => 'Fetch a public HTTP/HTTPS URL and return its text content. Private/internal addresses are blocked.',
					),
				),
			),
			fn( array $arguments ): array|WP_Error => $this->fetch_for_ai( (string) ( $arguments['url'] ?? '' ) )
		);
	}

	/**
	 * AI-facing wrapper used by Tool Catalog dispatch.
	 * Returns the requested URL and response body on success.
	 */
	public function fetch_for_ai( string $url ): array|WP_Error {
		$result = $this->fetch( $url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$this->logger->log( 'fetch_url', $url );
		return array(
			'url'     => $url,
			'content' => $result,
		);
	}

	/**
	 * Fetch a public URL and return its text content.
	 *
	 * Security controls:
	 *  - Scheme must be http or https.
	 *  - Hostname is resolved to every A and AAAA record; each is checked
	 *    against private/loopback/link-local ranges before the request is made.
	 *  - WordPress Requests is forced to its cURL transport for this URL. The
	 *    cURL handle is pinned to those validated IPs (CURLOPT_RESOLVE), while
	 *    unpinned Fsockopen and proxy-side origin resolution fail closed, so a
	 *    DNS-rebinding attacker cannot swap in a private IP between the SSRF
	 *    check and the actual fetch.
	 *  - Redirects are disabled; otherwise a public host could 302 to an
	 *    internal address that would not be re-validated.
	 *  - Response body is capped server-side (limit_response_size), then
	 *    stripped of HTML tags and truncated again before returning.
	 *
	 * @return string|WP_Error
	 */
	public function fetch( string $url ) {
		// Basic format check.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_url', 'Not a valid URL.' );
		}

		$parsed = wp_parse_url( $url );
		if ( ! $parsed ) {
			return new WP_Error( 'invalid_url', 'Could not parse URL.' );
		}

		// Scheme allow-list.
		$scheme = strtolower( $parsed['scheme'] ?? '' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'invalid_scheme', "Only http and https URLs are allowed (got '{$scheme}')." );
		}

		$host = $parsed['host'] ?? '';
		if ( '' === $host ) {
			return new WP_Error( 'invalid_url', 'URL has no host.' );
		}

		// Resolve every A and AAAA record and validate each against the
		// private-range allowlist. Returning the resolved set lets us pin
		// cURL's DNS resolution below, defeating rebinding attacks.
		$ips = $this->resolve_and_validate_host( $host );
		if ( is_wp_error( $ips ) ) {
			return $ips;
		}

		// Pin DNS resolution for this single request to the IPs we just
		// validated. Without this, wp_remote_get would re-resolve the
		// hostname inside its selected transport and a hostile DNS server could
		// return a different (private) address than the one we checked.
		//
		// Force this URL through Requests' cURL transport. Core's Fsockopen
		// fallback performs its own DNS lookup at socket-connect time and cannot
		// consume the validated IP set, so it must never handle this request.
		// Host checks keep the temporary hooks from changing unrelated nested
		// wp_remote_* calls fired by filters during this synchronous request.
		$lookup_host     = ltrim( rtrim( $host, ']' ), '[' );
		$port            = $parsed['port'] ?? ( 'https' === $scheme ? 443 : 80 );
		$resolve_arg     = $lookup_host . ':' . (int) $port . ':' . implode( ',', $ips );
		$force_curl      = static function ( &$request_url, &$_headers, &$_data, &$_type, &$options ) use ( $lookup_host ) {
			$request_host = wp_parse_url( (string) $request_url, PHP_URL_HOST );
			if ( ! is_string( $request_host ) ) {
				return;
			}
			$request_host = ltrim( rtrim( $request_host, ']' ), '[' );
			if ( 0 !== strcasecmp( $request_host, $lookup_host ) ) {
				return;
			}
			if ( ! empty( $options['proxy'] ) ) {
				throw new \RuntimeException( 'Haydi cannot guarantee DNS pinning when the HTTP proxy resolves the origin host.' );
			}

			$options['transport'] = \WpOrg\Requests\Transport\Curl::class;
		};
		$pin             = static function ( $handle, $r = null, $url_arg = null ) use ( $resolve_arg, $lookup_host ) {
			if ( ! is_resource( $handle ) && ! ( $handle instanceof \CurlHandle ) ) {
				throw new \RuntimeException( 'Haydi could not apply DNS pinning to the selected HTTP transport.' );
			}
			$handle_host = is_string( $url_arg ) ? wp_parse_url( $url_arg, PHP_URL_HOST ) : null;
			if ( null !== $handle_host ) {
				$handle_host = ltrim( rtrim( (string) $handle_host, ']' ), '[' );
				if ( 0 !== strcasecmp( $handle_host, $lookup_host ) ) {
					return;
				}
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- need raw cURL to set CURLOPT_RESOLVE; wp_remote_get does not expose this option
			if ( ! curl_setopt( $handle, CURLOPT_RESOLVE, array( $resolve_arg ) ) ) {
				throw new \RuntimeException( 'Haydi could not pin the validated IP address on the cURL handle.' );
			}
		};
		$block_fsockopen = static function () {
			throw new \RuntimeException( 'Haydi fetch_url requires the DNS-pinned cURL transport.' );
		};

		add_action( 'requests-requests.before_request', $force_curl, PHP_INT_MAX, 5 );
		add_action( 'requests-fsockopen.before_request', $block_fsockopen, PHP_INT_MAX, 0 );
		add_action( 'http_api_curl', $pin, 10, 3 );

		try {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'             => 15,
					'redirection'         => 0,
					'limit_response_size' => self::MAX_FETCH_RESPONSE_BYTES,
					'user-agent'          => 'Haydi/1.0 (WordPress site; fetch_url tool)',
					'sslverify'           => true,
					'reject_unsafe_urls'  => true,
				)
			); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		} catch ( \Throwable ) {
			$response = new WP_Error( 'unpinned_http_transport', 'A DNS-pinned cURL transport is required to fetch this URL safely.' );
		} finally {
			remove_action( 'http_api_curl', $pin, 10 );
			remove_action( 'requests-fsockopen.before_request', $block_fsockopen, PHP_INT_MAX );
			remove_action( 'requests-requests.before_request', $force_curl, PHP_INT_MAX );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 && $code < 400 ) {
			return new WP_Error( 'fetch_redirect_blocked', "URL returned HTTP {$code} (redirects are not followed; supply the final URL)." );
		}
		if ( $code < 200 || $code >= 400 ) {
			return new WP_Error( 'fetch_error', "URL returned HTTP {$code}." );
		}

		$body = wp_remote_retrieve_body( $response );

		// Strip HTML tags; collapse whitespace; truncate.
		$text = wp_strip_all_tags( $body );
		$text = preg_replace( '/\s{3,}/', "\n\n", $text ); // Collapse blank lines.
		$text = trim( $text );

		if ( strlen( $text ) > self::MAX_FETCH_BYTES ) {
			$text  = substr( $text, 0, self::MAX_FETCH_BYTES );
			$text .= "\n\n[…truncated at " . self::MAX_FETCH_BYTES . ' bytes]';
		}

		return $text;
	}

	/**
	 * Resolve $host to every A and AAAA address and ensure none are private.
	 *
	 * Returning the full list lets the caller pin cURL's resolver to exactly
	 * those IPs. gethostbyname() — which the previous implementation used —
	 * only returns a single IPv4, so a host with a private AAAA could slip
	 * through and a rebinding attacker could swap addresses between the check
	 * and the request.
	 *
	 * @return string[]|WP_Error
	 */
	private function resolve_and_validate_host( string $host ) {
		$lookup_host = ltrim( rtrim( $host, ']' ), '[' );

		// Bare IP — no DNS lookup, just validate directly.
		if ( filter_var( $lookup_host, FILTER_VALIDATE_IP ) ) {
			if ( $this->is_private_ip( $lookup_host ) ) {
				return new WP_Error( 'ssrf_blocked', 'Requests to private/internal addresses are not allowed.' );
			}
			return array( $lookup_host );
		}

		// Single combined query for A and AAAA records — one DNS round-trip
		// instead of two. Suppress notices on resolution failure; we treat
		// empty result as failure below.
		$ips     = array();
		$records = @dns_get_record( $lookup_host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $records ) ) {
			foreach ( $records as $rec ) {
				if ( ! empty( $rec['ip'] ) ) {
					$ips[] = $rec['ip'];
				} elseif ( ! empty( $rec['ipv6'] ) ) {
					$ips[] = $rec['ipv6'];
				}
			}
		}

		$ips = array_values( array_unique( $ips ) );

		if ( empty( $ips ) ) {
			return new WP_Error( 'dns_failure', "Could not resolve host '{$host}'." );
		}

		foreach ( $ips as $ip ) {
			if ( $this->is_private_ip( $ip ) ) {
				return new WP_Error( 'ssrf_blocked', "Requests to private/internal addresses are not allowed (resolved to {$ip})." );
			}
		}

		return $ips;
	}

	/**
	 * Return true if $ip is a private, loopback, link-local, or reserved address.
	 * Handles both IPv4 and IPv6, since PHP's FILTER_FLAG_NO_RES_RANGE does not
	 * cover all IPv6 reserved ranges (e.g. ::1 loopback, fe80::/10 link-local).
	 */
	private function is_private_ip( string $ip ): bool {
		// IPv4: FILTER_FLAG_NO_PRIV_RANGE blocks 10.x, 172.16-31.x, 192.168.x
		// FILTER_FLAG_NO_RES_RANGE  blocks 127.x, 0.x, 169.254.x, 240.x, etc.
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if ( filter_var( $ip, FILTER_VALIDATE_IP, $flags ) === false ) {
			return true;
		}

		// PHP's filter flags do not reliably cover IPv6 reserved ranges.
		// Explicitly block known private/reserved IPv6 ranges.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false === $packed ) {
				return true; // Unparseable — treat as unsafe.
			}

			// ::1 — loopback.
			if ( inet_pton( '::1' ) === $packed ) {
				return true;
			}
			// ::ffff:0:0/96 — IPv4-mapped addresses (could map to private IPv4).
			if ( substr( $packed, 0, 12 ) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" ) {
				return true;
			}
			// fe80::/10 — link-local.
			if ( ( ord( $packed[0] ) & 0xc0 ) === 0x80 && ( ord( $packed[0] ) & 0xfe ) === 0xfe ) {
				return true;
			}
			// fc00::/7 — unique local (ULA), analogous to RFC-1918 for IPv6.
			if ( ( ord( $packed[0] ) & 0xfe ) === 0xfc ) {
				return true;
			}
		}

		return false;
	}
}
