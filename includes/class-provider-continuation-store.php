<?php
/**
 * Provider Continuation Store.
 *
 * Keeps provider-originated continuation data server-side while an assistant
 * Tool turn is paused. The browser receives only a high-entropy opaque handle;
 * the continuation itself remains bound to the user, login session, exact
 * provider/model source, Tool calls, and sanitized transcript prefix that
 * created it.
 */

defined( 'ABSPATH' ) || exit;

final class Haydi_Provider_Continuation_Store {

	/** Version of the transient payload schema. */
	private const RECORD_VERSION = 1;
	private const CIPHER         = 'aes-256-gcm';
	private const CIPHER_AAD     = 'haydi-provider-continuation-v1';
	private const LOCK_TTL       = 30;

	/** Opaque handles contain 256 bits of randomness. */
	private const HANDLE_BYTES = 32;

	/** Default lifetime: long enough for a human approval pause. */
	public const DEFAULT_TTL = 30 * 60;

	/** Hard lifetime cap, even when a caller requests longer retention. */
	public const MAX_TTL = 60 * 60;

	/** Keep records below common persistent-object-cache item limits. */
	public const MAX_PAYLOAD_BYTES = 512 * 1024;

	/** Defensive bounds for source identifiers and Tool-call bindings. */
	private const MAX_PROVIDER_ID_BYTES  = 128;
	private const MAX_MODEL_ID_BYTES     = 256;
	private const MAX_TOOL_CALLS         = 64;
	private const MAX_TOOL_CALL_ID_BYTES = 256;

	/**
	 * Store a continuation and return its opaque handle.
	 *
	 * The continuation may be any JSON-compatible value. It is normalized to
	 * plain PHP data before storage so transients never contain provider objects.
	 *
	 * @param int    $user_id                     Owner of this continuation.
	 * @param string $provider_id                 Exact provider/connector ID.
	 * @param string $model_id                    Exact source model ID.
	 * @param array  $tool_call_ids               Ordered Tool-call IDs in the turn.
	 * @param array  $sanitized_transcript_prefix Browser-safe history preceding it.
	 * @param mixed  $continuation                Provider-neutral continuation data.
	 * @param int    $ttl                         Requested lifetime in seconds.
	 * @return string|WP_Error Opaque handle or an error.
	 */
	public function create(
		int $user_id,
		string $provider_id,
		string $model_id,
		array $tool_call_ids,
		array $sanitized_transcript_prefix,
		mixed $continuation,
		int $ttl = self::DEFAULT_TTL
	): string|WP_Error {
		$identity_error = $this->validate_identity( $user_id, $provider_id, $model_id );
		if ( is_wp_error( $identity_error ) ) {
			return $identity_error;
		}

		if ( $ttl <= 0 ) {
			return $this->invalid_error( 'Continuation TTL must be greater than zero.' );
		}
		$ttl = min( $ttl, self::MAX_TTL );

		$tool_call_ids_digest = $this->tool_call_ids_digest( $tool_call_ids );
		if ( is_wp_error( $tool_call_ids_digest ) ) {
			return $tool_call_ids_digest;
		}

		$transcript_digest = $this->json_digest( $sanitized_transcript_prefix );
		if ( is_wp_error( $transcript_digest ) ) {
			return $transcript_digest;
		}

		$normalized_continuation = $this->normalize_json_value( $continuation );
		if ( is_wp_error( $normalized_continuation ) ) {
			return $normalized_continuation;
		}

		$now                    = time();
		$record                 = array(
			'version'              => self::RECORD_VERSION,
			'user_id'              => $user_id,
			'session_digest'       => $this->current_session_digest(),
			'provider_id'          => $provider_id,
			'model_id'             => $model_id,
			'tool_call_ids_digest' => $tool_call_ids_digest,
			'transcript_digest'    => $transcript_digest,
			'created_at'           => $now,
			'expires_at'           => $now + $ttl,
		);
		$encrypted_continuation = $this->encrypt_continuation(
			$normalized_continuation,
			$this->encryption_aad( $record )
		);
		if ( is_wp_error( $encrypted_continuation ) ) {
			return $encrypted_continuation;
		}
		$record['encrypted_continuation'] = $encrypted_continuation;

		$encoded_record = wp_json_encode( $record );
		if ( ! is_string( $encoded_record ) ) {
			return $this->invalid_error( 'Continuation payload is not JSON-compatible.' );
		}
		if ( strlen( $encoded_record ) > self::MAX_PAYLOAD_BYTES ) {
			return new WP_Error(
				'provider_continuation_too_large',
				'Continuation payload exceeds the server-side size limit.'
			);
		}

		try {
			$handle = bin2hex( random_bytes( self::HANDLE_BYTES ) );
		} catch ( Throwable $error ) {
			return new WP_Error(
				'provider_continuation_store_failed',
				'Could not generate a secure continuation handle.'
			);
		}

		if ( ! set_transient( $this->transient_key( $handle ), $record, $ttl ) ) {
			return new WP_Error(
				'provider_continuation_store_failed',
				'Could not store provider continuation data.'
			);
		}

		return $handle;
	}

	/**
	 * Consume a continuation exactly once.
	 *
	 * Successful lookup claims and deletes the transient before checking its
	 * bindings. A leaked handle therefore cannot be probed repeatedly, and a
	 * mismatched attempt invalidates it. The source is returned from the trusted
	 * record so the caller can pin the resumed turn to that provider and model.
	 *
	 * @param string $handle                      Opaque handle returned by create().
	 * @param int    $user_id                     Expected owner.
	 * @param array  $tool_call_ids               Ordered Tool-call IDs being resumed.
	 * @param array  $sanitized_transcript_prefix Same browser-safe history snapshot.
	 * @return array|WP_Error Trusted source and continuation, or an error.
	 */
	public function consume(
		string $handle,
		int $user_id,
		array $tool_call_ids,
		array $sanitized_transcript_prefix
	): array|WP_Error {
		if ( ! $this->is_valid_handle( $handle ) || $user_id <= 0 ) {
			return $this->invalid_error( 'Invalid continuation handle or user.' );
		}

		$tool_call_ids_digest = $this->tool_call_ids_digest( $tool_call_ids );
		if ( is_wp_error( $tool_call_ids_digest ) ) {
			return $tool_call_ids_digest;
		}

		$transcript_digest = $this->json_digest( $sanitized_transcript_prefix );
		if ( is_wp_error( $transcript_digest ) ) {
			return $transcript_digest;
		}

		$lock_key = $this->lock_key( $handle );
		if ( ! $this->acquire_lock( $lock_key ) ) {
			return $this->expired_error();
		}

		try {
			$key    = $this->transient_key( $handle );
			$record = get_transient( $key );
			if ( false === $record ) {
				return $this->expired_error();
			}

			// Never disclose continuation data unless its one-shot deletion worked.
			if ( ! delete_transient( $key ) ) {
				return new WP_Error(
					'provider_continuation_consume_failed',
					'Could not consume provider continuation data safely.'
				);
			}

			if ( ! $this->is_valid_record( $record ) ) {
				return $this->invalid_error( 'Stored continuation data is invalid.' );
			}

			if ( (int) $record['expires_at'] <= time() ) {
				return $this->expired_error();
			}

			if (
				(int) $record['user_id'] !== $user_id
				|| ! hash_equals( (string) $record['session_digest'], $this->current_session_digest() )
				|| ! hash_equals( (string) $record['tool_call_ids_digest'], $tool_call_ids_digest )
				|| ! hash_equals( (string) $record['transcript_digest'], $transcript_digest )
			) {
				return $this->mismatch_error();
			}

			$continuation = $this->decrypt_continuation(
				$record['encrypted_continuation'],
				$this->encryption_aad( $record )
			);
			if ( is_wp_error( $continuation ) ) {
				return $continuation;
			}

			return array(
				'provider_id'  => (string) $record['provider_id'],
				'model_id'     => (string) $record['model_id'],
				'continuation' => $continuation,
				'created_at'   => (int) $record['created_at'],
				'expires_at'   => (int) $record['expires_at'],
			);
		} finally {
			delete_option( $lock_key );
		}
	}

	/**
	 * Explicitly discard a continuation owned by this user/session.
	 *
	 * @return bool True only when an owned record was deleted.
	 */
	public function delete( string $handle, int $user_id ): bool {
		if ( ! $this->is_valid_handle( $handle ) || $user_id <= 0 ) {
			return false;
		}

		$lock_key = $this->lock_key( $handle );
		if ( ! $this->acquire_lock( $lock_key ) ) {
			return false;
		}

		try {
			$key    = $this->transient_key( $handle );
			$record = get_transient( $key );
			if ( ! $this->is_valid_record( $record ) ) {
				return false;
			}

			if (
				(int) $record['user_id'] !== $user_id
				|| ! hash_equals( (string) $record['session_digest'], $this->current_session_digest() )
			) {
				return false;
			}

			return delete_transient( $key );
		} finally {
			delete_option( $lock_key );
		}
	}

	/**
	 * Validate stable source identity fields without modifying them.
	 */
	private function validate_identity( int $user_id, string $provider_id, string $model_id ): true|WP_Error {
		if ( $user_id <= 0 ) {
			return $this->invalid_error( 'Continuation owner must be an authenticated user.' );
		}

		if (
			'' === $provider_id
			|| strlen( $provider_id ) > self::MAX_PROVIDER_ID_BYTES
			|| preg_match( '/[\x00-\x1F\x7F]/', $provider_id )
		) {
			return $this->invalid_error( 'Invalid continuation provider ID.' );
		}

		if (
			'' === $model_id
			|| strlen( $model_id ) > self::MAX_MODEL_ID_BYTES
			|| preg_match( '/[\x00-\x1F\x7F]/', $model_id )
		) {
			return $this->invalid_error( 'Invalid continuation model ID.' );
		}

		return true;
	}

	/**
	 * Hash the exact ordered Tool-call IDs after validating defensive bounds.
	 */
	private function tool_call_ids_digest( array $tool_call_ids ): string|WP_Error {
		$count = count( $tool_call_ids );
		if ( 0 === $count || $count > self::MAX_TOOL_CALLS || ! array_is_list( $tool_call_ids ) ) {
			return $this->invalid_error( 'Invalid continuation Tool-call IDs.' );
		}

		$normalized = array();
		foreach ( $tool_call_ids as $tool_call_id ) {
			if (
				! is_string( $tool_call_id )
				|| '' === $tool_call_id
				|| strlen( $tool_call_id ) > self::MAX_TOOL_CALL_ID_BYTES
				|| preg_match( '/[\x00-\x1F\x7F]/', $tool_call_id )
			) {
				return $this->invalid_error( 'Invalid continuation Tool-call ID.' );
			}
			$normalized[] = $tool_call_id;
		}

		return $this->json_digest( $normalized );
	}

	/**
	 * Hash canonical JSON so associative-key ordering does not affect bindings.
	 */
	private function json_digest( mixed $value ): string|WP_Error {
		$canonical = $this->canonicalize( $value );
		$encoded   = wp_json_encode(
			$canonical,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
		);

		if ( ! is_string( $encoded ) ) {
			return $this->invalid_error( 'Continuation binding data is not JSON-compatible.' );
		}

		return hash( 'sha256', $encoded );
	}

	/**
	 * Recursively sort object-like arrays while preserving list order.
	 */
	private function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array_is_list( $value ) ) {
			return array_map( array( $this, 'canonicalize' ), $value );
		}

		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonicalize( $item );
		}
		return $value;
	}

	/**
	 * Round-trip through JSON to prevent PHP objects entering transient storage.
	 */
	private function normalize_json_value( mixed $value ): mixed {
		$encoded = wp_json_encode( $value );
		if ( ! is_string( $encoded ) ) {
			return $this->invalid_error( 'Continuation payload is not JSON-compatible.' );
		}

		$normalized = json_decode( $encoded, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $this->invalid_error( 'Continuation payload is not JSON-compatible.' );
		}

		return $normalized;
	}

	/**
	 * Check the stored envelope before any field is trusted.
	 */
	private function is_valid_record( mixed $record ): bool {
		if ( ! is_array( $record ) ) {
			return false;
		}

		foreach (
			array(
				'version',
				'user_id',
				'session_digest',
				'provider_id',
				'model_id',
				'tool_call_ids_digest',
				'transcript_digest',
				'encrypted_continuation',
				'created_at',
				'expires_at',
			) as $required_key
		) {
			if ( ! array_key_exists( $required_key, $record ) ) {
				return false;
			}
		}

		return self::RECORD_VERSION === $record['version']
			&& is_int( $record['user_id'] )
			&& is_string( $record['session_digest'] )
			&& ( '' === $record['session_digest'] || 64 === strlen( $record['session_digest'] ) )
			&& is_string( $record['provider_id'] )
			&& is_string( $record['model_id'] )
			&& is_string( $record['tool_call_ids_digest'] )
			&& 64 === strlen( $record['tool_call_ids_digest'] )
			&& is_string( $record['transcript_digest'] )
			&& 64 === strlen( $record['transcript_digest'] )
			&& is_array( $record['encrypted_continuation'] )
			&& is_int( $record['created_at'] )
			&& is_int( $record['expires_at'] );
	}

	/**
	 * Encrypt Provider Continuation before it reaches a DB-backed transient.
	 */
	private function encrypt_continuation( mixed $continuation, string $aad ): array|WP_Error {
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'wp_salt' ) ) {
			return new WP_Error(
				'provider_continuation_encryption_unavailable',
				'Server-side continuation encryption is unavailable.'
			);
		}

		$plaintext = wp_json_encode( $continuation );
		if ( ! is_string( $plaintext ) ) {
			return $this->invalid_error( 'Continuation payload is not JSON-compatible.' );
		}

		try {
			$nonce = random_bytes( 12 );
		} catch ( Throwable $error ) {
			return new WP_Error(
				'provider_continuation_encryption_failed',
				'Could not encrypt provider continuation data.'
			);
		}

		$tag        = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$this->encryption_key(),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			$aad,
			16
		);
		if ( ! is_string( $ciphertext ) || 16 !== strlen( $tag ) ) {
			return new WP_Error(
				'provider_continuation_encryption_failed',
				'Could not encrypt provider continuation data.'
			);
		}

		return array(
			'cipher'     => self::CIPHER,
			'nonce'      => base64_encode( $nonce ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary encryption envelope, not code obfuscation
			'tag'        => base64_encode( $tag ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary encryption envelope, not code obfuscation
			'ciphertext' => base64_encode( $ciphertext ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary encryption envelope, not code obfuscation
		);
	}

	/**
	 * Authenticate and decrypt a stored Provider Continuation.
	 */
	private function decrypt_continuation( mixed $encrypted, string $aad ): mixed {
		if (
			! is_array( $encrypted )
			|| self::CIPHER !== ( $encrypted['cipher'] ?? '' )
			|| ! is_string( $encrypted['nonce'] ?? null )
			|| ! is_string( $encrypted['tag'] ?? null )
			|| ! is_string( $encrypted['ciphertext'] ?? null )
			|| ! function_exists( 'openssl_decrypt' )
			|| ! function_exists( 'wp_salt' )
		) {
			return $this->invalid_error( 'Stored continuation encryption data is invalid.' );
		}

		$nonce      = base64_decode( $encrypted['nonce'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary encryption envelope, not code obfuscation
		$tag        = base64_decode( $encrypted['tag'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary encryption envelope, not code obfuscation
		$ciphertext = base64_decode( $encrypted['ciphertext'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary encryption envelope, not code obfuscation
		if ( ! is_string( $nonce ) || 12 !== strlen( $nonce ) || ! is_string( $tag ) || 16 !== strlen( $tag ) || ! is_string( $ciphertext ) ) {
			return $this->invalid_error( 'Stored continuation encryption data is invalid.' );
		}

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$this->encryption_key(),
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			$aad
		);
		if ( ! is_string( $plaintext ) ) {
			return new WP_Error(
				'provider_continuation_decryption_failed',
				'Stored provider continuation data could not be authenticated.'
			);
		}

		$continuation = json_decode( $plaintext, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $this->invalid_error( 'Stored provider continuation data is invalid.' );
		}

		return $continuation;
	}

	/**
	 * Derive an at-rest encryption key from WordPress authentication salts.
	 */
	private function encryption_key(): string {
		return hash( 'sha256', self::CIPHER_AAD . "\0" . wp_salt( 'auth' ), true );
	}

	/**
	 * Bind ciphertext to its trusted envelope so metadata cannot be swapped.
	 */
	private function encryption_aad( array $record ): string {
		$fields  = array(
			'version'              => $record['version'] ?? null,
			'user_id'              => $record['user_id'] ?? null,
			'session_digest'       => $record['session_digest'] ?? null,
			'provider_id'          => $record['provider_id'] ?? null,
			'model_id'             => $record['model_id'] ?? null,
			'tool_call_ids_digest' => $record['tool_call_ids_digest'] ?? null,
			'transcript_digest'    => $record['transcript_digest'] ?? null,
			'created_at'           => $record['created_at'] ?? null,
			'expires_at'           => $record['expires_at'] ?? null,
		);
		$encoded = wp_json_encode( $fields );

		return self::CIPHER_AAD . "\0" . ( is_string( $encoded ) ? $encoded : '' );
	}

	/**
	 * Claim a one-shot handle, reclaiming locks abandoned by terminated workers.
	 */
	private function acquire_lock( string $lock_key ): bool {
		$now = time();
		if ( add_option( $lock_key, $now, '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( $lock_key, 0 );
		if ( $locked_at <= 0 || $locked_at > $now - self::LOCK_TTL ) {
			return false;
		}

		delete_option( $lock_key );
		return add_option( $lock_key, $now, '', false );
	}

	/**
	 * Bind records to the current WordPress login session when one is available.
	 */
	private function current_session_digest(): string {
		if ( ! function_exists( 'wp_get_session_token' ) ) {
			return '';
		}

		$token = (string) wp_get_session_token();
		return '' === $token ? '' : hash( 'sha256', $token );
	}

	private function is_valid_handle( string $handle ): bool {
		return 64 === strlen( $handle ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $handle );
	}

	private function transient_key( string $handle ): string {
		return 'haydi_provider_continuation_' . hash( 'sha256', $handle );
	}

	private function lock_key( string $handle ): string {
		return 'haydi_provider_continuation_lock_' . hash( 'sha256', $handle );
	}

	private function invalid_error( string $message ): WP_Error {
		return new WP_Error( 'provider_continuation_invalid', $message );
	}

	private function expired_error(): WP_Error {
		return new WP_Error(
			'provider_continuation_expired',
			'Provider continuation has expired or was already consumed.'
		);
	}

	private function mismatch_error(): WP_Error {
		return new WP_Error(
			'provider_continuation_mismatch',
			'Provider continuation does not match this user, session, Tool turn, or transcript.'
		);
	}
}
