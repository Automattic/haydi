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

	public function test_editor_capability_grants_access_by_default(): void {
		$checked_capability = null;

		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability ) use ( &$checked_capability ): bool {
				$checked_capability = $capability;
				return 'edit_others_posts' === $capability;
			}
		);

		$this->assertTrue( haydi_current_user_can_access() );
		$this->assertSame( 'edit_others_posts', $checked_capability );
	}

	public function test_user_without_editor_capability_is_denied(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( haydi_current_user_can_access() );
	}

	public function test_site_can_override_the_access_capability(): void {
		$checked_capability = null;

		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) {
				return 'haydi_access_capability' === $hook ? 'manage_options' : $value;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			static function ( string $capability ) use ( &$checked_capability ): bool {
				$checked_capability = $capability;
				return true;
			}
		);

		$this->assertTrue( haydi_current_user_can_access() );
		$this->assertSame( 'manage_options', $checked_capability );
	}
}
