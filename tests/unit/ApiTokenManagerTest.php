<?php
/**
 * Unit tests for Haydi_Api_Token_Manager.
 *
 * WordPress functions are stubbed via Brain\Monkey. An in-memory store backs
 * the option calls so issuer-bound generate → validate → list → revoke
 * round-trips work without a live DB.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class ApiTokenManagerTest extends TestCase {

	/** In-memory options store: $options[key] => value. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();
		$store         = &$this->options;

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$store ) {
				return $store[ $key ] ?? $default;
			}
		);

		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);

		// Pass-through sanitisers.
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'user_can' )->alias(
			static fn( int $user_id, string $capability ): bool => 7 === $user_id
				&& in_array( $capability, array( 'edit_plugins', 'manage_options' ), true )
		);
		Functions\when( 'apply_filters' )->alias(
			static fn( string $hook, $value ) => $value
		);

		// __ translation helper.
		Functions\when( '__' )->alias(
			static function ( string $text ) {
				return $text;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// generate_token()
	// -------------------------------------------------------------------------

	public function test_generate_token_returns_64_char_hex_string(): void {
		$mgr   = new Haydi_Api_Token_Manager();
		$token = $mgr->generate_token( 'test' );

		$this->assertSame( 64, strlen( $token ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $token );
	}

	public function test_generated_token_validates_successfully(): void {
		$mgr   = new Haydi_Api_Token_Manager();
		$token = $mgr->generate_token( 'my token' );

		$this->assertTrue( $mgr->validate_token( $token ) );
	}

	public function test_generated_token_is_bound_to_its_issuer(): void {
		$mgr   = new Haydi_Api_Token_Manager();
		$token = $mgr->generate_token( 'issuer-test' );
		$hash  = hash( 'sha256', $token );

		$this->assertSame( 7, $this->options[ Haydi_Api_Token_Manager::OPTION_KEY ][ $hash ]['user_id'] );
	}

	// -------------------------------------------------------------------------
	// validate_token() — rejection cases
	// -------------------------------------------------------------------------

	public function test_token_shorter_than_64_chars_is_rejected(): void {
		$mgr = new Haydi_Api_Token_Manager();
		$this->assertFalse( $mgr->validate_token( str_repeat( 'a', 63 ) ) );
	}

	public function test_token_longer_than_64_chars_is_rejected(): void {
		$mgr = new Haydi_Api_Token_Manager();
		$this->assertFalse( $mgr->validate_token( str_repeat( 'a', 65 ) ) );
	}

	public function test_unrecognised_64_char_hex_token_is_rejected(): void {
		$mgr = new Haydi_Api_Token_Manager();
		// Do not call generate_token() — option store is empty.
		$this->assertFalse( $mgr->validate_token( str_repeat( 'a', 64 ) ) );
	}

	public function test_legacy_token_without_an_issuer_is_rejected(): void {
		$token = str_repeat( 'a', 64 );
		$hash  = hash( 'sha256', $token );
		$this->options[ Haydi_Api_Token_Manager::OPTION_KEY ] = array(
			$hash => array(
				'label'   => 'Legacy',
				'prefix'  => substr( $token, 0, 8 ),
				'created' => time(),
			),
		);

		$this->assertFalse( ( new Haydi_Api_Token_Manager() )->validate_token( $token ) );
	}

	public function test_token_is_rejected_after_issuer_loses_code_editing_access(): void {
		$issuer_allowed = true;
		Functions\when( 'user_can' )->alias(
			static function ( int $user_id, string $capability ) use ( &$issuer_allowed ): bool {
				return 7 === $user_id
					&& $issuer_allowed
					&& in_array( $capability, array( 'edit_plugins', 'manage_options' ), true );
			}
		);
		$mgr   = new Haydi_Api_Token_Manager();
		$token = $mgr->generate_token( 'revoked-with-capability' );

		$this->assertTrue( $mgr->validate_token( $token ) );
		$issuer_allowed = false;
		$this->assertFalse( $mgr->validate_token( $token ) );
	}

	public function test_token_is_rejected_when_issuer_has_manage_options_without_edit_plugins(): void {
		Functions\when( 'user_can' )->alias(
			static fn( int $user_id, string $capability ): bool => 7 === $user_id
				&& 'manage_options' === $capability
		);
		$mgr   = new Haydi_Api_Token_Manager();
		$token = $mgr->generate_token( 'multisite-site-admin' );

		$this->assertFalse( $mgr->validate_token( $token ) );
	}

	// -------------------------------------------------------------------------
	// get_tokens()
	// -------------------------------------------------------------------------

	public function test_get_tokens_returns_expected_fields(): void {
		$mgr   = new Haydi_Api_Token_Manager();
		$token = $mgr->generate_token( 'label-test' );
		$list  = $mgr->get_tokens();

		$this->assertCount( 1, $list );
		$entry = $list[0];

		$this->assertArrayHasKey( 'prefix',  $entry );
		$this->assertArrayHasKey( 'label',   $entry );
		$this->assertArrayHasKey( 'created', $entry );
		$this->assertArrayHasKey( 'hash',    $entry );
	}

	public function test_get_tokens_prefix_matches_token_start(): void {
		$mgr   = new Haydi_Api_Token_Manager();
		$token = $mgr->generate_token( 'prefix-test' );
		$list  = $mgr->get_tokens();

		$this->assertStringStartsWith( $list[0]['prefix'], $token );
		$this->assertSame( 8, strlen( $list[0]['prefix'] ) );
	}

	public function test_empty_label_gets_non_empty_default(): void {
		$mgr = new Haydi_Api_Token_Manager();
		$mgr->generate_token( '' );
		$list = $mgr->get_tokens();

		$this->assertNotEmpty( $list[0]['label'] );
	}

	// -------------------------------------------------------------------------
	// revoke_token()
	// -------------------------------------------------------------------------

	public function test_revoke_makes_token_invalid(): void {
		$mgr   = new Haydi_Api_Token_Manager();
		$token = $mgr->generate_token( 'revoke-test' );
		$hash  = $mgr->get_tokens()[0]['hash'];

		$this->assertTrue( $mgr->revoke_token( $hash ) );
		$this->assertFalse( $mgr->validate_token( $token ) );
	}

	public function test_revoke_removes_token_from_get_tokens(): void {
		$mgr   = new Haydi_Api_Token_Manager();
		$mgr->generate_token( 'revoke-list-test' );
		$hash = $mgr->get_tokens()[0]['hash'];

		$mgr->revoke_token( $hash );
		$this->assertEmpty( $mgr->get_tokens() );
	}

	public function test_revoke_unknown_hash_returns_false(): void {
		$mgr = new Haydi_Api_Token_Manager();
		$this->assertFalse( $mgr->revoke_token( str_repeat( 'f', 64 ) ) );
	}

	// -------------------------------------------------------------------------
	// Multiple tokens are stored and validated independently
	// -------------------------------------------------------------------------

	public function test_two_tokens_stored_and_validated_independently(): void {
		$mgr    = new Haydi_Api_Token_Manager();
		$token1 = $mgr->generate_token( 'first' );
		$token2 = $mgr->generate_token( 'second' );

		$this->assertNotSame( $token1, $token2 );
		$this->assertTrue( $mgr->validate_token( $token1 ) );
		$this->assertTrue( $mgr->validate_token( $token2 ) );

		$this->assertCount( 2, $mgr->get_tokens() );

		// Revoke first; second must still be valid.
		$hash1 = hash( 'sha256', $token1 );
		$mgr->revoke_token( $hash1 );

		$this->assertFalse( $mgr->validate_token( $token1 ) );
		$this->assertTrue( $mgr->validate_token( $token2 ) );
		$this->assertCount( 1, $mgr->get_tokens() );
	}
}
