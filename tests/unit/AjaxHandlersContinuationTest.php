<?php
/**
 * Integration tests for Provider Continuation across Haydi's server Tool loop.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class AjaxHandlersContinuationTest extends TestCase {

	/** @var array<string,array{value:mixed,ttl:int}> */
	private array $transients = array();

	/** @var array<string,mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->transients = array();
		$this->options    = array();

		$transients =& $this->transients;
		$options    =& $this->options;

		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_get_session_token' )->justReturn( 'continuation-test-session' );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

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
				if ( Haydi_Jetpack_Context::PROMPT_CACHE_KEY === $key ) {
					return '';
				}
				return $transients[ $key ]['value'] ?? false;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( string $key ) use ( &$transients ): bool {
				if ( ! array_key_exists( $key, $transients ) ) {
					return false;
				}
				unset( $transients[ $key ] );
				return true;
			}
		);
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

		$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );
		$GLOBALS['haydi_action_proposals'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['haydi_tool_catalog'] );
		$GLOBALS['haydi_action_proposals'] = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_approval_pause_resumes_private_state_on_the_exact_source_without_exposing_it(): void {
		$client  = new Haydi_Test_Continuation_Client(
			array(
				$this->toolResponse( 'write_setting', 'call-write' ),
				$this->finalResponse( 'The setting was updated.' ),
			)
		);
		$catalog = $this->catalogWithApprovalTool( 'write_setting' );
		$handler = $this->handler( $client, $catalog );

		$initial_messages = array(
			array(
				'role'    => 'user',
				'content' => 'Update the setting.',
			),
		);

		$paused = $this->runChatLoop(
			$handler,
			$initial_messages,
			array( 'requested-provider', 'requested-model' )
		);

		$this->assertIsArray( $paused );
		$this->assertArrayHasKey( 'pending_action', $paused );
		$this->assertArrayHasKey( 'continuation_handle', $paused );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $paused['continuation_handle'] );
		$this->assertPortableMessages( $paused['messages'] );
		$this->assertStringNotContainsString( 'private chain state', wp_json_encode( $paused['messages'] ) );
		$this->assertNull( $client->calls[0]['exact_model'] );

		$resume_messages   = $paused['messages'];
		$resume_messages[] = array(
			'role'    => 'user',
			'content' => array(
				array(
					'type'        => 'tool_result',
					'tool_use_id' => 'call-write',
					'name'        => 'write_setting',
					'content'     => array( 'updated' => true ),
				),
			),
		);

		$finished = $this->runChatLoop(
			$handler,
			$resume_messages,
			array( 'requested-provider', 'requested-model' ),
			$paused['continuation_handle']
		);

		$this->assertIsArray( $finished );
		$this->assertSame( 'The setting was updated.', $finished['text'] );
		$this->assertArrayNotHasKey( 'continuation_handle', $finished );
		$this->assertPortableMessages( $finished['messages'] );
		$this->assertStringNotContainsString( 'private chain state', wp_json_encode( $finished['messages'] ) );

		$this->assertCount( 2, $client->calls );
		$this->assertSame( array( 'deepseek', 'deepseek-reasoner' ), $client->calls[1]['exact_model'] );
		$this->assertNull( $client->calls[1]['model_preference'] );
		$this->assertSame( 'private chain state', $client->calls[1]['messages'][1]['continuation'][0]['text'] );
		$this->assertSame( 'thought', $client->calls[1]['messages'][1]['continuation'][0]['channel'] );
		$this->assertSame( 'reasoning-signature', $client->calls[1]['messages'][1]['continuation'][0]['thought_signature'] );
		$this->assertSame( 'call-write', $client->calls[1]['messages'][2]['content'][0]['tool_use_id'] );
	}

	public function test_expired_handle_is_rejected_without_private_replay(): void {
		$client  = new Haydi_Test_Continuation_Client(
			array( $this->finalResponse( 'Continued safely.' ) )
		);
		$catalog = new Haydi_Tool_Catalog();
		$handler = $this->handler( $client, $catalog );
		$messages = array(
			array( 'role' => 'user', 'content' => 'Inspect the setting.' ),
			array(
				'role'    => 'assistant',
				'content' => array(
					array(
						'type'  => 'tool_use',
						'id'    => 'expired-call',
						'name'  => 'read_setting',
						'input' => array(),
					),
				),
			),
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'        => 'tool_result',
						'tool_use_id' => 'expired-call',
						'name'        => 'read_setting',
						'content'     => 'old result',
					),
				),
			),
		);

		$result = $this->runChatLoop(
			$handler,
			$messages,
			null,
			str_repeat( 'a', 64 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'provider_continuation_expired', $result->get_error_code() );
		$this->assertSame( array(), $client->calls );
	}

	public function test_automatic_tool_subturn_keeps_private_state_and_pins_the_source(): void {
		$received_arguments = null;
		$client             = new Haydi_Test_Continuation_Client(
			array(
				$this->toolResponse( 'read_setting', 'call-read' ),
				$this->finalResponse( 'The current value is enabled.' ),
			)
		);
		$catalog = $this->catalogWithAutomaticTool(
			'read_setting',
			static function ( array $arguments ) use ( &$received_arguments ): array {
				$received_arguments = $arguments;
				return array( 'enabled' => true );
			}
		);
		$handler = $this->handler( $client, $catalog );

		$result = $this->runChatLoop(
			$handler,
			array( array( 'role' => 'user', 'content' => 'Read the setting.' ) )
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'key' => 'feature_enabled' ), $received_arguments );
		$this->assertCount( 2, $client->calls );
		$this->assertSame( array( 'deepseek', 'deepseek-reasoner' ), $client->calls[1]['exact_model'] );
		$this->assertSame( 'private chain state', $client->calls[1]['messages'][1]['continuation'][0]['text'] );
		$this->assertSame( array( 'enabled' => true ), $client->calls[1]['messages'][2]['content'][0]['content'] );
		$this->assertPortableMessages( $result['messages'] );
		$this->assertStringNotContainsString( 'private chain state', wp_json_encode( $result['messages'] ) );
	}

	public function test_results_keep_provider_order_when_approval_is_between_automatic_tools(): void {
		$client  = new Haydi_Test_Continuation_Client(
			array(
				$this->multiToolResponse(),
				$this->finalResponse( 'All three Tool calls were resolved in order.' ),
			)
		);
		$catalog = new Haydi_Tool_Catalog();
		$catalog->register( $this->toolDefinition( 'read_before', 'automatic' ), static fn(): string => 'before' );
		$catalog->register( $this->toolDefinition( 'write_middle', 'approval' ), static fn(): string => 'middle' );
		$catalog->register( $this->toolDefinition( 'read_after', 'automatic' ), static fn(): string => 'after' );
		$handler = $this->handler( $client, $catalog );

		$paused = $this->runChatLoop(
			$handler,
			array( array( 'role' => 'user', 'content' => 'Run the three Tools.' ) )
		);

		$this->assertSame( 'call-before', $paused['pending_action']['pre_results'][0]['tool_use_id'] );
		$this->assertSame( 'call-after', $paused['pending_action']['post_results'][0]['tool_use_id'] );

		$pending = $paused['pending_action'];
		$ordered_results = array_merge(
			$pending['pre_results'],
			array(
				array(
					'type'        => 'tool_result',
					'tool_use_id' => 'call-middle',
					'name'        => 'write_middle',
					'content'     => 'approved',
				),
			),
			$pending['post_results']
		);
		$resume_messages   = $paused['messages'];
		$resume_messages[] = array( 'role' => 'user', 'content' => $ordered_results );

		$finished = $this->runChatLoop(
			$handler,
			$resume_messages,
			null,
			$paused['continuation_handle']
		);

		$this->assertIsArray( $finished );
		$this->assertSame(
			array( 'call-before', 'call-middle', 'call-after' ),
			array_column( $client->calls[1]['messages'][2]['content'], 'tool_use_id' )
		);
	}

	private function handler(
		Haydi_Test_Continuation_Client $client,
		Haydi_Tool_Catalog $catalog
	): Haydi_Ajax_Handlers {
		$handler = ( new ReflectionClass( Haydi_Ajax_Handlers::class ) )->newInstanceWithoutConstructor();

		$this->setProperty( $handler, 'logger', new Haydi_Test_Continuation_Logger() );
		$this->setProperty( $handler, 'guard', new Haydi_Filesystem_Guard() );
		$this->setProperty( $handler, 'client', $client );
		$this->setProperty( $handler, 'tool_catalog', $catalog );
		$this->setProperty( $handler, 'continuation_store', new Haydi_Provider_Continuation_Store() );
		$GLOBALS['haydi_tool_catalog'] = $catalog;

		return $handler;
	}

	private function runChatLoop(
		Haydi_Ajax_Handlers $handler,
		array $messages,
		?array $model_preference = null,
		string $continuation_handle = ''
	): array|WP_Error {
		$method = new ReflectionMethod( $handler, 'run_chat_loop' );
		return $method->invoke( $handler, $messages, $model_preference, null, $continuation_handle );
	}

	private function toolResponse( string $tool_name, string $tool_id ): array {
		return array(
			'stop_reason'  => 'tool_use',
			'content'      => array(
				array( 'type' => 'text', 'text' => 'I will use the tool.' ),
				array(
					'type'  => 'tool_use',
					'id'    => $tool_id,
					'name'  => $tool_name,
					'input' => array( 'key' => 'feature_enabled' ),
				),
			),
			'continuation' => array(
				array(
					'type'              => 'text',
					'channel'           => 'thought',
					'text'              => 'private chain state',
					'thought_signature' => 'reasoning-signature',
				),
				array(
					'type'    => 'text',
					'channel' => 'content',
					'text'    => 'I will use the tool.',
				),
				array(
					'type'              => 'tool_use',
					'channel'           => 'content',
					'id'                => $tool_id,
					'name'              => $tool_name,
					'input'             => array( 'key' => 'feature_enabled' ),
					'thought_signature' => 'tool-signature',
				),
			),
			'usage'        => array(
				'prompt'     => 100,
				'completion' => 25,
				'total'      => 125,
			),
			'provider'     => 'deepseek',
			'model'        => 'deepseek-reasoner',
		);
	}

	private function finalResponse( string $text ): array {
		return array(
			'stop_reason'  => 'end_turn',
			'content'      => array( array( 'type' => 'text', 'text' => $text ) ),
			'continuation' => array(
				array(
					'type'    => 'text',
					'channel' => 'content',
					'text'    => $text,
				),
			),
			'usage'        => array(
				'prompt'     => 150,
				'completion' => 30,
				'total'      => 180,
			),
			'provider'     => 'deepseek',
			'model'        => 'deepseek-reasoner',
		);
	}

	private function multiToolResponse(): array {
		$calls = array(
			array( 'type' => 'tool_use', 'id' => 'call-before', 'name' => 'read_before', 'input' => array( 'key' => 'feature_enabled' ) ),
			array( 'type' => 'tool_use', 'id' => 'call-middle', 'name' => 'write_middle', 'input' => array( 'key' => 'feature_enabled' ) ),
			array( 'type' => 'tool_use', 'id' => 'call-after', 'name' => 'read_after', 'input' => array( 'key' => 'feature_enabled' ) ),
		);
		$continuation = array(
			array( 'type' => 'text', 'channel' => 'thought', 'text' => 'private ordered state' ),
		);
		foreach ( $calls as $call ) {
			$continuation[] = array_merge( array( 'channel' => 'content' ), $call );
		}

		return array(
			'stop_reason'  => 'tool_use',
			'content'      => $calls,
			'continuation' => $continuation,
			'usage'        => array( 'prompt' => 100, 'completion' => 25, 'total' => 125 ),
			'provider'     => 'deepseek',
			'model'        => 'deepseek-reasoner',
		);
	}

	private function catalogWithApprovalTool( string $name ): Haydi_Tool_Catalog {
		$catalog = new Haydi_Tool_Catalog();
		$catalog->register(
			$this->toolDefinition( $name, 'approval' ),
			static fn(): string => 'not executed before approval'
		);
		return $catalog;
	}

	private function catalogWithAutomaticTool( string $name, callable $implementation ): Haydi_Tool_Catalog {
		$catalog = new Haydi_Tool_Catalog();
		$catalog->register( $this->toolDefinition( $name, 'automatic' ), $implementation );
		return $catalog;
	}

	private function toolDefinition( string $name, string $effect ): array {
		$definition = array(
			'name'           => $name,
			'description'    => 'Read or update a setting for this test.',
			'input_schema'   => array(
				'type'       => 'object',
				'properties' => array(
					'key' => array(
						'type'        => 'string',
						'description' => 'Setting key.',
					),
				),
				'required'   => array( 'key' ),
			),
			'effect'         => $effect,
			'activity_label' => 'Read setting',
			'projections'    => array( 'chat' => true ),
		);

		if ( 'approval' === $effect ) {
			$definition['proposal'] = array(
				'label'          => 'Update Setting',
				'log_action'     => 'setting_update_proposed',
				'log_path_field' => 'key',
			);
		}

		return $definition;
	}

	private function assertPortableMessages( array $messages ): void {
		$this->assertFalse( $this->arrayHasKeyRecursively( $messages, 'continuation' ) );
		$this->assertFalse( $this->arrayHasKeyRecursively( $messages, 'channel' ) );
		$this->assertFalse( $this->arrayHasKeyRecursively( $messages, 'thought_signature' ) );
	}

	private function arrayHasKeyRecursively( mixed $value, string $key ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( array_key_exists( $key, $value ) ) {
			return true;
		}
		foreach ( $value as $child ) {
			if ( $this->arrayHasKeyRecursively( $child, $key ) ) {
				return true;
			}
		}
		return false;
	}

	private function setProperty( object $object, string $property, mixed $value ): void {
		$reflection = new ReflectionProperty( $object, $property );
		$reflection->setValue( $object, $value );
	}
}

class Haydi_Test_Continuation_Client extends Haydi_AI_Client {

	/** @var array<int,array{messages:array,system:string,include_tools:bool,model_preference:?array,exact_model:?array}> */
	public array $calls = array();

	public function __construct( private array $responses ) {}

	public function send_messages(
		array $messages,
		string $system = '',
		bool $include_tools = true,
		?array $model_preference = null,
		?array $exact_model = null
	): array|WP_Error {
		$this->calls[] = array(
			'messages'         => $messages,
			'system'           => $system,
			'include_tools'    => $include_tools,
			'model_preference' => $model_preference,
			'exact_model'      => $exact_model,
		);

		return array_shift( $this->responses );
	}
}

class Haydi_Test_Continuation_Logger extends Haydi_Audit_Logger {

	/** @var array<int,array{action:string,path:string,details:string}> */
	public array $entries = array();

	public function log( string $action, string $path, string $details = '' ): void {
		$this->entries[] = compact( 'action', 'path', 'details' );
	}
}
