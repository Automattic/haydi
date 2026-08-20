<?php
/**
 * Unit tests for Haydi's WordPress capability boundary.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AccessControlTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_administrator_with_code_editing_capability_grants_access(): void {
		$checked_capabilities = array();

		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability ) use ( &$checked_capabilities ): bool {
				$checked_capabilities[] = $capability;
				return in_array( $capability, array( 'edit_plugins', 'manage_options' ), true );
			}
		);

		$this->assertTrue( haydi_current_user_can_access() );
		$this->assertSame( array( 'edit_plugins', 'manage_options' ), $checked_capabilities );
	}

	public function test_multisite_site_administrator_without_edit_plugins_is_denied(): void {
		Functions\when( 'current_user_can' )->alias(
			static fn( string $capability ): bool => 'manage_options' === $capability
		);

		$this->assertFalse( haydi_current_user_can_access() );
	}

	public function test_user_without_manage_options_is_denied(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( haydi_current_user_can_access() );
	}

	public function test_site_cannot_lower_the_code_editing_floor_with_the_access_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'haydi_access_capability' === $hook ? 'edit_others_posts' : $value;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			static fn( string $capability ): bool => 'edit_others_posts' === $capability
		);

		$this->assertFalse( haydi_current_user_can_access() );
	}

	public function test_site_can_add_an_access_capability_above_the_code_editing_floor(): void {
		$checked_capabilities = array();

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'haydi_access_capability' === $hook ? 'manage_network_options' : $value;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability ) use ( &$checked_capabilities ): bool {
				$checked_capabilities[] = $capability;
				return true;
			}
		);

		$this->assertTrue( haydi_current_user_can_access() );
		$this->assertSame( array( 'edit_plugins', 'manage_network_options' ), $checked_capabilities );
	}
}
