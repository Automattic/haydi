<?php
/**
 * Unit tests for handle_save_settings().
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class AjaxHandlersSaveSettingsTest extends TestCase {

	private mixed $lastData    = null;
	private ?bool $lastSuccess = null;

	/** In-memory option store. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->lastData    = null;
		$this->lastSuccess = null;
		$this->options     = array();

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->returnArg();

		$opts =& $this->options;

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$opts ) {
				return array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$opts ) {
				$opts[ $key ] = $value;
				return true;
			}
		);

		Functions\when( 'wp_send_json_success' )->alias(
			function ( $data = null ) {
				$this->lastSuccess = true;
				$this->lastData    = $data;
				throw new \HaydiTestHaltException();
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			function ( $data = null ) {
				$this->lastSuccess = false;
				$this->lastData    = $data;
				throw new \HaydiTestHaltException();
			}
		);

		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function makeHandler(): Haydi_Ajax_Handlers {
		$handler = ( new \ReflectionClass( Haydi_Ajax_Handlers::class ) )
			->newInstanceWithoutConstructor();

		$logger = $this->createStub( Haydi_Audit_Logger::class );

		$prop = new \ReflectionProperty( $handler, 'logger' );
		$prop->setValue( $handler, $logger );

		return $handler;
	}

	private function call( array $post ): void {
		$_POST = array_merge( array( 'nonce' => 'test' ), $post );
		try {
			$this->makeHandler()->handle_save_settings();
		} catch ( \HaydiTestHaltException $e ) {
			unset( $e );
		}
	}

	public function test_saves_valid_settings(): void {
		$this->call( array(
			'max_tokens'                   => '4096',
			'playground_preflight_enabled' => '1',
			'enable_tracks'                => '1',
		) );

		$this->assertTrue( $this->lastSuccess );
		$this->assertSame( 4096, $this->options['haydi_max_tokens'] );
		$this->assertSame( 1, $this->options['haydi_playground_preflight_enabled'] );
		$this->assertSame( 1, $this->options['haydi_enable_tracks'] );
	}

	public function test_tracks_disabled_by_default_when_omitted(): void {
		$this->call( array( 'max_tokens' => '2048' ) );

		$this->assertTrue( $this->lastSuccess );
		$this->assertSame( 0, $this->options['haydi_enable_tracks'] );
	}

	public function test_tracks_can_be_explicitly_disabled(): void {
		$this->call( array(
			'max_tokens'    => '2048',
			'enable_tracks' => '0',
		) );

		$this->assertTrue( $this->lastSuccess );
		$this->assertSame( 0, $this->options['haydi_enable_tracks'] );
		$this->assertFalse( $this->lastData['enable_tracks'] );
	}

	public function test_response_includes_enable_tracks(): void {
		$this->call( array(
			'max_tokens'    => '2048',
			'enable_tracks' => '1',
		) );

		$this->assertTrue( $this->lastSuccess );
		$this->assertTrue( $this->lastData['enable_tracks'] );
	}

	public function test_rejects_max_tokens_below_minimum(): void {
		$this->call( array( 'max_tokens' => '512' ) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( '1024', $this->lastData['message'] );
	}

	public function test_rejects_max_tokens_above_maximum(): void {
		$this->call( array( 'max_tokens' => '99999' ) );

		$this->assertFalse( $this->lastSuccess );
		$this->assertStringContainsString( '32768', $this->lastData['message'] );
	}

	public function test_playground_preflight_saved_correctly(): void {
		$this->call( array(
			'max_tokens'                   => '8192',
			'playground_preflight_enabled' => 'false',
		) );

		$this->assertTrue( $this->lastSuccess );
		$this->assertSame( 0, $this->options['haydi_playground_preflight_enabled'] );
		$this->assertFalse( $this->lastData['playground_preflight_enabled'] );
	}

	public function test_playground_preflight_falls_back_to_stored_option_when_omitted(): void {
		$this->options['haydi_playground_preflight_enabled'] = 1;

		$this->call( array( 'max_tokens' => '2048' ) );

		$this->assertTrue( $this->lastSuccess );
		$this->assertTrue( $this->lastData['playground_preflight_enabled'] );
	}
}
