<?php
/**
 * API token manager — generate, validate, list, and revoke long-lived tokens
 * that allow external tools (Claude Code MCP, CLI) to authenticate against
 * the Haydi REST API without a browser session.
 *
 * Tokens are stored hashed (SHA-256) in wp_options so the plaintext is never
 * persisted — only shown once on generation. Metadata (label, prefix, created)
 * is stored alongside the hash.
 */

defined( 'ABSPATH' ) || exit;

class Haydi_Api_Token_Manager {

	const OPTION_KEY  = 'haydi_api_tokens';
	const TOKEN_BYTES = 32;

	/**
	 * Generate a new token, store it hashed, and return the plaintext once.
	 *
	 * @param string $label Human-readable label for the token.
	 * @return string The plaintext token (only available at generation time).
	 */
	public function generate_token( string $label ): string {
		$token  = bin2hex( random_bytes( self::TOKEN_BYTES ) );
		$hash   = hash( 'sha256', $token );
		$prefix = substr( $token, 0, 8 );

		$tokens          = get_option( self::OPTION_KEY, array() );
		$tokens[ $hash ] = array(
			'label'   => '' !== sanitize_text_field( $label ) ? sanitize_text_field( $label ) : __( 'Unnamed token', 'haydi' ),
			'prefix'  => $prefix,
			'created' => time(),
		);
		update_option( self::OPTION_KEY, $tokens, false );

		return $token;
	}

	/**
	 * Return true when the supplied plaintext token corresponds to a stored hash.
	 */
	public function validate_token( string $token ): bool {
		if ( strlen( $token ) !== self::TOKEN_BYTES * 2 ) {
			return false;
		}
		$hash   = hash( 'sha256', $token );
		$tokens = get_option( self::OPTION_KEY, array() );
		return isset( $tokens[ $hash ] );
	}

	/**
	 * Return metadata for every stored token (prefix, label, created timestamp).
	 * The full hash is included only so the UI can pass it back in a revoke call.
	 *
	 * @return array[]
	 */
	public function get_tokens(): array {
		$tokens = get_option( self::OPTION_KEY, array() );
		$result = array();
		foreach ( $tokens as $hash => $meta ) {
			$result[] = array(
				'hash'    => $hash,
				'prefix'  => $meta['prefix'] ?? substr( $hash, 0, 8 ),
				'label'   => $meta['label'] ?? '',
				'created' => $meta['created'] ?? 0,
			);
		}
		return $result;
	}

	/**
	 * Remove a stored token by its SHA-256 hash.
	 *
	 * @param string $hash Full SHA-256 hash of the token to revoke.
	 * @return bool False when the hash was not found.
	 */
	public function revoke_token( string $hash ): bool {
		$tokens = get_option( self::OPTION_KEY, array() );
		if ( ! isset( $tokens[ $hash ] ) ) {
			return false;
		}
		unset( $tokens[ $hash ] );
		update_option( self::OPTION_KEY, $tokens, false );
		return true;
	}
}
