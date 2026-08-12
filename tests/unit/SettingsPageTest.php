<?php
/**
 * Unit tests for the native administrator-only Haydi settings page.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SettingsPageTest extends TestCase {

	private array $settings_errors = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->settings_errors = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'add_settings_error' )->alias(
			function ( ...$args ): void {
				$this->settings_errors[] = $args;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_settings_page_is_registered_for_administrators_only(): void {
		$registered_capability = null;
		Functions\when( 'add_options_page' )->alias(
			static function ( $page_title, $menu_title, $capability ) use ( &$registered_capability ): string {
				unset( $page_title, $menu_title );
				$registered_capability = $capability;
				return 'settings_page_haydi-settings';
			}
		);

		$this->page()->add_settings_page();

		$this->assertSame( 'manage_options', $registered_capability );
	}

	public function test_valid_configured_model_is_sanitized_for_storage(): void {
		$value = Haydi_Model_Policy::encode( 'anthropic', 'claude-sonnet-4-6' );

		$this->assertSame( $value, $this->page()->sanitize_editor_model( $value ) );
		$this->assertSame( array(), $this->settings_errors );
	}

	public function test_empty_value_allows_editors_to_choose_models(): void {
		$this->assertSame( '', $this->page()->sanitize_editor_model( '' ) );
		$this->assertSame( array(), $this->settings_errors );
	}

	public function test_unknown_model_is_rejected_without_overwriting_current_policy(): void {
		$current = Haydi_Model_Policy::encode( 'anthropic', 'claude-sonnet-4-6' );
		Functions\when( 'get_option' )->justReturn( $current );

		$invalid = Haydi_Model_Policy::encode( 'anthropic', 'claude-opus-5' );

		$this->assertSame( $current, $this->page()->sanitize_editor_model( $invalid ) );
		$this->assertCount( 1, $this->settings_errors );
		$this->assertSame( 'haydi_editor_model_invalid', $this->settings_errors[0][1] );
	}

	private function page(): Haydi_Settings_Page {
		return new Haydi_Settings_Page(
			new Haydi_Test_Settings_Model_Limits(
				array(
					'anthropic' => array(
						'id'     => 'anthropic',
						'name'   => 'Anthropic',
						'models' => array(
							array(
								'id'   => 'claude-sonnet-4-6',
								'name' => 'Claude Sonnet 4.6',
							),
						),
					),
				)
			)
		);
	}
}

final class Haydi_Test_Settings_Model_Limits extends Haydi_Model_Limits {

	public function __construct( private array $choices ) {}

	public function get_model_choices_for_configured_providers(): array {
		return $this->choices;
	}
}
