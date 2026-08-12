<?php
/**
 * Unit tests for the administrator-owned model-picker policy.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ModelPolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_editor_gets_the_configured_model_as_a_restricted_picker(): void {
		Functions\when( 'get_option' )->justReturn(
			Haydi_Model_Policy::encode( 'anthropic', 'claude-sonnet-4-6' )
		);
		Functions\when( 'current_user_can' )->justReturn( false );

		$config = Haydi_Model_Policy::picker_config( $this->choices() );

		$this->assertTrue( $config['restricted'] );
		$this->assertTrue( $config['available'] );
		$this->assertSame( 'anthropic', $config['provider'] );
		$this->assertSame( 'claude-sonnet-4-6', $config['model'] );
		$this->assertSame( 'Claude Sonnet 4.6', $config['label'] );
	}

	public function test_administrator_keeps_the_full_picker(): void {
		Functions\when( 'get_option' )->justReturn(
			Haydi_Model_Policy::encode( 'anthropic', 'claude-sonnet-4-6' )
		);
		Functions\when( 'current_user_can' )->justReturn( true );

		$config = Haydi_Model_Policy::picker_config( $this->choices() );

		$this->assertFalse( $config['restricted'] );
		$this->assertSame( 'claude-sonnet-4-6', $config['model'] );
	}

	public function test_editor_keeps_the_full_picker_when_no_model_is_configured(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'current_user_can' )->justReturn( false );

		$config = Haydi_Model_Policy::picker_config( $this->choices() );

		$this->assertFalse( $config['restricted'] );
		$this->assertTrue( $config['available'] );
		$this->assertSame( '', $config['model'] );
	}

	public function test_stale_editor_model_is_reported_as_unavailable(): void {
		Functions\when( 'get_option' )->justReturn(
			Haydi_Model_Policy::encode( 'anthropic', 'claude-retired' )
		);
		Functions\when( 'current_user_can' )->justReturn( false );

		$config = Haydi_Model_Policy::picker_config( $this->choices() );

		$this->assertTrue( $config['restricted'] );
		$this->assertFalse( $config['available'] );
		$this->assertSame( 'claude-retired', $config['label'] );
	}

	public function test_malformed_stored_model_is_treated_as_no_policy(): void {
		Functions\when( 'get_option' )->justReturn( '{"provider":"anthropic","model":[]}' );
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->assertFalse( Haydi_Model_Policy::picker_config( $this->choices() )['restricted'] );
	}

	public function test_connector_model_identifiers_are_preserved_verbatim(): void {
		$value = Haydi_Model_Policy::encode( 'openrouter', 'anthropic/claude-sonnet' );

		$this->assertSame(
			array( 'openrouter', 'anthropic/claude-sonnet' ),
			Haydi_Model_Policy::decode( $value )
		);
	}

	private function choices(): array {
		return array(
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
		);
	}
}
