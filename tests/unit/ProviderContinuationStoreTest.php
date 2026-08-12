<?php
/**
 * Unit tests for the one-shot, server-side Provider Continuation store.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/includes/class-provider-continuation-store.php';

class ProviderContinuationStoreTest extends TestCase {

	/** @var array<string,array{value:mixed,ttl:int}> */
	private array $transients = array();

	/** @var array<string,mixed> */
	private array $options = array();

	private string $session_token = 'session-a';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients   = array();
		$this->options      = array();
		$this->session_token = 'session-a';

		$transients =& $this->transients;
		$options    =& $this->options;
		$session    =& $this->session_token;

		Functions\when( 'set_transient' )->alias(
			static function ( string $key, mixed $value, int $ttl ) use ( &$transients ): bool {
				$transients[ $key ] = array(
					'value' => $value,
					'ttl'   => $ttl,
				);
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			static function ( string $key ) use ( &$transients ): mixed {
				return $transients[ $key ]['value'] ?? false;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$transients ): bool {
				if ( ! isset( $transients[ $key ] ) ) {
					return false;
				}
				unset( $transients[ $key ] );
				return true;
			}
		);

		// add_option() is an atomic claim in production and an in-memory one here.
		Functions\when( 'add_option' )->alias(
			static function ( string $key, mixed $value ) use ( &$options ): bool {
				if ( array_key_exists( $key, $options ) ) {
					return false;
				}
				$options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( string $key ) use ( &$options ): bool {
				if ( ! array_key_exists( $key, $options ) ) {
					return false;
				}
				unset( $options[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( string $key, mixed $default = false ) use ( &$options ): mixed {
				return $options[ $key ] ?? $default;
			}
		);
		Functions\when( 'wp_get_session_token' )->alias(
			static function () use ( &$session ): string {
				return $session;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function transcript(): array {
		return array(
			array(
				'role'    => 'user',
				'content' => 'Inspect installed plugins.',
			),
		);
	}

	private function createContinuation(
		?array $transcript = null,
		array $tool_call_ids = array( 'call-1' ),
		mixed $continuation = null,
		int $ttl = Haydi_Provider_Continuation_Store::DEFAULT_TTL
	): string {
		if ( null === $continuation ) {
			$continuation = array(
				'parts' => array(
					array(
						'type'      => 'thought',
						'text'      => 'private provider state',
						'signature' => 'opaque-signature',
					),
				),
			);
		}

		$result = ( new Haydi_Provider_Continuation_Store() )->create(
			7,
			'deepseek',
			'deepseek-reasoner',
			$tool_call_ids,
			$transcript ?? $this->transcript(),
			$continuation,
			$ttl
		);

		$this->assertIsString( $result );
		return $result;
	}

	public function test_create_returns_opaque_random_handle_and_hashes_transient_key(): void {
		$handle = $this->createContinuation();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $handle );
		$this->assertCount( 1, $this->transients );
		$this->assertStringNotContainsString( $handle, (string) array_key_first( $this->transients ) );
		$this->assertStringNotContainsString( 'private provider state', wp_json_encode( $this->transients ) );
		$this->assertStringNotContainsString( 'opaque-signature', wp_json_encode( $this->transients ) );
	}

	public function test_encrypted_payload_is_authenticated(): void {
		$handle = $this->createContinuation();
		$key    = (string) array_key_first( $this->transients );
		$this->transients[ $key ]['value']['encrypted_continuation']['ciphertext'] = base64_encode( 'tampered' );

		$result = ( new Haydi_Provider_Continuation_Store() )->consume(
			$handle,
			7,
			array( 'call-1' ),
			$this->transcript()
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'provider_continuation_decryption_failed', $result->get_error_code() );
	}

	public function test_abandoned_lock_is_reclaimed(): void {
		$handle   = $this->createContinuation();
		$lock_key = 'haydi_provider_continuation_lock_' . hash( 'sha256', $handle );
		$this->options[ $lock_key ] = time() - 60;

		$result = ( new Haydi_Provider_Continuation_Store() )->consume(
			$handle,
			7,
			array( 'call-1' ),
			$this->transcript()
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( $lock_key, $this->options );
	}

	public function test_consume_returns_exact_source_and_generic_payload(): void {
		$handle = $this->createContinuation();

		$result = ( new Haydi_Provider_Continuation_Store() )->consume(
			$handle,
			7,
			array( 'call-1' ),
			$this->transcript()
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'deepseek', $result['provider_id'] );
		$this->assertSame( 'deepseek-reasoner', $result['model_id'] );
		$this->assertSame( 'thought', $result['continuation']['parts'][0]['type'] );
		$this->assertSame( 'opaque-signature', $result['continuation']['parts'][0]['signature'] );
	}

	public function test_consume_is_one_shot(): void {
		$handle = $this->createContinuation();
		$store  = new Haydi_Provider_Continuation_Store();

		$this->assertIsArray( $store->consume( $handle, 7, array( 'call-1' ), $this->transcript() ) );
		$second = $store->consume( $handle, 7, array( 'call-1' ), $this->transcript() );

		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( 'provider_continuation_expired', $second->get_error_code() );
	}

	public function test_tool_call_mismatch_consumes_and_rejects_record(): void {
		$handle = $this->createContinuation();
		$store  = new Haydi_Provider_Continuation_Store();

		$mismatch = $store->consume( $handle, 7, array( 'different-call' ), $this->transcript() );
		$this->assertInstanceOf( WP_Error::class, $mismatch );
		$this->assertSame( 'provider_continuation_mismatch', $mismatch->get_error_code() );

		$retry = $store->consume( $handle, 7, array( 'call-1' ), $this->transcript() );
		$this->assertInstanceOf( WP_Error::class, $retry );
		$this->assertSame( 'provider_continuation_expired', $retry->get_error_code() );
	}

	public function test_transcript_mismatch_is_rejected(): void {
		$handle = $this->createContinuation();
		$changed = $this->transcript();
		$changed[0]['content'] = 'Tampered transcript.';

		$result = ( new Haydi_Provider_Continuation_Store() )->consume(
			$handle,
			7,
			array( 'call-1' ),
			$changed
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'provider_continuation_mismatch', $result->get_error_code() );
	}

	public function test_associative_key_order_does_not_change_transcript_binding(): void {
		$created_transcript = array(
			array(
				'role'    => 'user',
				'content' => array( 'b' => 2, 'a' => 1 ),
			),
		);
		$resumed_transcript = array(
			array(
				'content' => array( 'a' => 1, 'b' => 2 ),
				'role'    => 'user',
			),
		);

		$handle = $this->createContinuation( $created_transcript );
		$result = ( new Haydi_Provider_Continuation_Store() )->consume(
			$handle,
			7,
			array( 'call-1' ),
			$resumed_transcript
		);

		$this->assertIsArray( $result );
	}

	public function test_user_and_login_session_are_bound(): void {
		$user_handle = $this->createContinuation();
		$user_result = ( new Haydi_Provider_Continuation_Store() )->consume(
			$user_handle,
			8,
			array( 'call-1' ),
			$this->transcript()
		);
		$this->assertInstanceOf( WP_Error::class, $user_result );
		$this->assertSame( 'provider_continuation_mismatch', $user_result->get_error_code() );

		$session_handle       = $this->createContinuation();
		$this->session_token = 'session-b';
		$session_result       = ( new Haydi_Provider_Continuation_Store() )->consume(
			$session_handle,
			7,
			array( 'call-1' ),
			$this->transcript()
		);
		$this->assertInstanceOf( WP_Error::class, $session_result );
		$this->assertSame( 'provider_continuation_mismatch', $session_result->get_error_code() );
	}

	public function test_missing_or_unknown_handle_is_expired_but_malformed_handle_is_invalid(): void {
		$store = new Haydi_Provider_Continuation_Store();

		$unknown = $store->consume( str_repeat( 'a', 64 ), 7, array( 'call-1' ), $this->transcript() );
		$this->assertInstanceOf( WP_Error::class, $unknown );
		$this->assertSame( 'provider_continuation_expired', $unknown->get_error_code() );

		$invalid = $store->consume( 'not-a-handle', 7, array( 'call-1' ), $this->transcript() );
		$this->assertInstanceOf( WP_Error::class, $invalid );
		$this->assertSame( 'provider_continuation_invalid', $invalid->get_error_code() );
	}

	public function test_ttl_is_capped(): void {
		$this->createContinuation( null, array( 'call-1' ), null, Haydi_Provider_Continuation_Store::MAX_TTL * 2 );

		$stored = reset( $this->transients );
		$this->assertSame( Haydi_Provider_Continuation_Store::MAX_TTL, $stored['ttl'] );
	}

	public function test_oversized_payload_is_rejected_without_writing_transient(): void {
		$result = ( new Haydi_Provider_Continuation_Store() )->create(
			7,
			'openai',
			'gpt-5',
			array( 'call-1' ),
			$this->transcript(),
			array( 'reasoning' => str_repeat( 'x', Haydi_Provider_Continuation_Store::MAX_PAYLOAD_BYTES ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'provider_continuation_too_large', $result->get_error_code() );
		$this->assertSame( array(), $this->transients );
	}

	public function test_delete_is_explicit_and_owner_bound(): void {
		$handle = $this->createContinuation();
		$store  = new Haydi_Provider_Continuation_Store();

		$this->assertFalse( $store->delete( $handle, 8 ) );
		$this->assertTrue( $store->delete( $handle, 7 ) );
		$this->assertFalse( $store->delete( $handle, 7 ) );
	}
}
