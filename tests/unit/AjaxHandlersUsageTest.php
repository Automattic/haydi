<?php
/**
 * Unit tests for chat usage aggregation.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class AjaxHandlersUsageTest extends TestCase {

	private mixed $lastData = null;
	private ?bool $lastSuccess = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->lastData    = null;
		$this->lastSuccess = null;

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) {
				return Haydi_Jetpack_Context::PROMPT_CACHE_KEY === $key ? '' : false;
			}
		);
		Functions\when( 'set_transient' )->justReturn( true );

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

		$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
		$_POST          = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_handle_chat_tracks_total_prompt_cost_and_peak_prompt_context_separately(): void {
		$handler = ( new \ReflectionClass( Haydi_Ajax_Handlers::class ) )
			->newInstanceWithoutConstructor();

		$this->setPrivateProperty( $handler, 'guard', new Haydi_Filesystem_Guard() );
		$client = new Haydi_Test_Usage_Client(
			array(
				array(
					'stop_reason' => 'tool_use',
					'content'     => array(
						array(
							'type'  => 'tool_use',
							'id'    => 'tool_1',
							'name'  => 'unknown_tool',
							'input' => array(),
						),
					),
					'usage'       => array(
						'prompt'     => 5000,
						'completion' => 100,
						'total'      => 5100,
					),
					'model'       => 'test-model',
				),
				array(
					'stop_reason' => 'end_turn',
					'content'     => array(
						array(
							'type' => 'text',
							'text' => 'Done.',
						),
					),
					'usage'       => array(
						'prompt'     => 7000,
						'completion' => 200,
						'total'      => 7200,
					),
					'model'       => 'test-model',
				),
			)
		);
		$this->setPrivateProperty( $handler, 'client', $client );

		$_POST = array(
			'nonce'          => 'test',
			'messages'       => wp_json_encode( array( array( 'role' => 'user', 'content' => 'Use a tool.' ) ) ),
			'model_provider' => 'openai',
			'model'          => 'gpt-5.4',
		);

		try {
			$handler->handle_chat();
		} catch ( \HaydiTestHaltException $e ) {
			unset( $e );
		}

		$this->assertTrue( $this->lastSuccess );
		$this->assertSame( 12000, $this->lastData['usage']['prompt'] );
		$this->assertSame( 300, $this->lastData['usage']['completion'] );
		$this->assertSame( 12300, $this->lastData['usage']['total'] );
		$this->assertSame( 2, $this->lastData['usage']['calls'] );
		$this->assertSame( 7000, $this->lastData['usage']['prompt_peak'] );
		$this->assertSame( 7000, $this->lastData['usage']['prompt_last'] );
		$this->assertSame( 'test-model', $this->lastData['usage']['model'] );
		$this->assertSame( array( 'openai', 'gpt-5.4' ), $client->last_model_preference );
	}

	public function test_handle_compact_chat_returns_summary_messages_without_tools(): void {
		$handler = ( new \ReflectionClass( Haydi_Ajax_Handlers::class ) )
			->newInstanceWithoutConstructor();
		$client  = new Haydi_Test_Usage_Client(
			array(
				array(
					'stop_reason' => 'end_turn',
					'content'     => array(
						array(
							'type' => 'text',
							'text' => "- User wants a small plugin feature.\n- Relevant file is wp-content/plugins/example/example.php.\n- Next step is to propose a focused file write.",
						),
					),
					'usage'       => array(
						'prompt'     => 800,
						'completion' => 120,
						'total'      => 920,
					),
					'model'       => 'test-model',
				),
			)
		);

		$this->setPrivateProperty( $handler, 'client', $client );

		$_POST = array(
			'nonce'    => 'test',
			'messages' => wp_json_encode(
				array(
					array( 'role' => 'user', 'content' => 'Build a small plugin feature.' ),
					array( 'role' => 'assistant', 'content' => 'I will inspect the plugin.' ),
				)
			),
		);

		try {
			$handler->handle_compact_chat();
		} catch ( \HaydiTestHaltException $e ) {
			unset( $e );
		}

		$this->assertTrue( $this->lastSuccess );
		$this->assertStringContainsString( 'small plugin feature', $this->lastData['summary'] );
		$this->assertSame( 'user', $this->lastData['messages'][0]['role'] );
		$this->assertStringContainsString( 'Previous chat summary:', $this->lastData['messages'][0]['content'] );
		$this->assertSame( 'assistant', $this->lastData['messages'][1]['role'] );
		$this->assertSame( 800, $this->lastData['usage']['prompt_peak'] );
		$this->assertFalse( $client->last_include_tools );
	}

	private function setPrivateProperty( object $object, string $property, mixed $value ): void {
		$prop = new \ReflectionProperty( $object, $property );
		$prop->setValue( $object, $value );
	}
}

class Haydi_Test_Usage_Client extends Haydi_AI_Client {
	public ?bool $last_include_tools = null;
	public ?array $last_model_preference = null;

	public function __construct( private array $responses ) {}

	public function send_messages(
		array $messages,
		string $system = '',
		bool $include_tools = true,
		?array $model_preference = null,
		?array $exact_model = null
	): array|WP_Error {
		unset( $messages, $system, $exact_model );
		$this->last_include_tools = $include_tools;
		$this->last_model_preference = $model_preference;
		return array_shift( $this->responses );
	}
}
