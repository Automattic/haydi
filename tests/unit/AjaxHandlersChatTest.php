<?php
/**
 * Unit tests for handle_save_chat() / handle_load_chat() —
 * specifically the fields added for issue #3 (token usage + per-message
 * timestamps).
 *
 * Strategy
 * --------
 * - newInstanceWithoutConstructor() builds the handler without registering
 *   AJAX hooks.
 * - get_user_meta / update_user_meta / delete_user_meta are backed by a tiny
 *   in-memory store so save → load round-trips work without a live DB.
 * - $_POST is populated per call to mimic an AJAX request.
 */

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class AjaxHandlersChatTest extends TestCase {

	private \ReflectionClass $ref;
	private \Haydi_Chat_Store $handler;

	/** In-memory user-meta store: $userMeta[user_id][key] => value. */
	private array $userMeta = array();

	private ?bool $lastSuccess = null;
	private mixed $lastData    = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->userMeta    = array();
		$this->lastSuccess = null;
		$this->lastData    = null;

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );

		// Pass-through sanitisers — the production code adds its own coercion.
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$store =& $this->userMeta;

		Functions\when( 'get_user_meta' )->alias(
			static function ( $user_id, $key, $single = false ) use ( &$store ) {
				return $store[ $user_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_user_meta' )->alias(
			static function ( $user_id, $key, $value ) use ( &$store ) {
				$store[ $user_id ][ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_user_meta' )->alias(
			static function ( $user_id, $key ) use ( &$store ) {
				unset( $store[ $user_id ][ $key ] );
				return true;
			}
		);

		// Production wp_die()s after sending JSON; throw to mirror that halt.
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

		$this->ref     = new \ReflectionClass( Haydi_Chat_Store::class );
		$this->handler = $this->ref->newInstanceWithoutConstructor();

		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Save a chat via handle_save_chat() and return the new id.
	 *
	 * @param array       $messages Chat messages (already JSON-decoded).
	 * @param array       $display  Display-log entries (already JSON-decoded).
	 * @param array|null  $usage    Optional cumulative-usage payload.
	 * @param string      $id       Optional existing chat id.
	 */
	private function saveChat(
		array $messages,
		array $display = array(),
		?array $usage = null,
		string $id = '',
		string $title = 'Test',
		string $title_source = 'fallback'
	): string {
		$this->lastSuccess = null;
		$this->lastData    = null;

		$_POST = array(
			'nonce'        => 'test',
			'id'           => $id,
			'title'        => $title,
			'title_source' => $title_source,
			'messages'     => json_encode( $messages ),
			'display'      => json_encode( $display ),
		);
		if ( null !== $usage ) {
			$_POST['usage'] = json_encode( $usage );
		}

		try {
			$this->handler->handle_save_chat();
		} catch ( \HaydiTestHaltException $e ) {
			unset( $e );
		}

		$this->assertTrue( $this->lastSuccess, 'save_chat should succeed' );
		$this->assertIsArray( $this->lastData );
		$this->assertArrayHasKey( 'id', $this->lastData );

		return (string) $this->lastData['id'];
	}

	/** Invoke handle_load_chat() for $id and return its data payload. */
	private function loadChat( string $id ): array {
		$this->lastSuccess = null;
		$this->lastData    = null;

		$_POST = array(
			'nonce' => 'test',
			'id'    => $id,
		);

		try {
			$this->handler->handle_load_chat();
		} catch ( \HaydiTestHaltException $e ) {
			unset( $e );
		}

		$this->assertTrue( $this->lastSuccess, 'load_chat should succeed' );
		$this->assertIsArray( $this->lastData );
		return $this->lastData;
	}

	private function setPrivateProperty( object $object, string $property, mixed $value ): void {
		$prop = new \ReflectionProperty( $object, $property );
		$prop->setValue( $object, $value );
	}

	// -------------------------------------------------------------------------
	// Chat title metadata
	// -------------------------------------------------------------------------

	public function test_save_round_trips_title_metadata(): void {
		$id = $this->saveChat(
			array( array( 'role' => 'user', 'content' => 'Add a contact form to my site' ) ),
			array(),
			null,
			'',
			'Add a contact form to my site',
			'fallback'
		);

		$loaded = $this->loadChat( $id );

		$this->assertSame( 'Add a contact form to my site', $loaded['title'] );
		$this->assertSame( 'fallback', $loaded['title_source'] );
	}

	public function test_save_preserves_ai_title_when_later_fallback_save_arrives(): void {
		$id = $this->saveChat(
			array( array( 'role' => 'user', 'content' => 'Add testimonials to my homepage' ) ),
			array(),
			null,
			'',
			'Add testimonials to my homepage',
			'fallback'
		);

		$index = $this->userMeta[1]['haydi_chats_index'];
		foreach ( $index as &$entry ) {
			if ( $entry['id'] === $id ) {
				$entry['title']        = 'Homepage Testimonials';
				$entry['title_source'] = 'ai';
			}
		}
		unset( $entry );
		$this->userMeta[1]['haydi_chats_index'] = $index;

		$this->saveChat(
			array(
				array( 'role' => 'user', 'content' => 'Add testimonials to my homepage' ),
				array( 'role' => 'assistant', 'content' => 'I can do that.' ),
			),
			array(),
			null,
			$id,
			'Add testimonials to my homepage',
			'fallback'
		);

		$loaded = $this->loadChat( $id );
		$this->assertSame( 'Homepage Testimonials', $loaded['title'] );
		$this->assertSame( 'ai', $loaded['title_source'] );
	}

	public function test_generate_chat_title_uses_selected_model_without_tools_and_updates_index(): void {
		$id = $this->saveChat(
			array(
				array( 'role' => 'user', 'content' => 'Create a contact form plugin' ),
				array(
					'role'    => 'assistant',
					'content' => array(
						array( 'type' => 'text', 'text' => 'I will create a small plugin with a shortcode and settings.' ),
					),
				),
			),
			array(),
			null,
			'',
			'Create a contact form plugin',
			'fallback'
		);

		$client = new Haydi_Test_Title_Client(
			array(
				'stop_reason' => 'end_turn',
				'content'     => array(
					array( 'type' => 'text', 'text' => '"Contact Form Plugin."' ),
				),
			)
		);
		$this->setPrivateProperty( $this->handler, 'client', $client );

		$_POST = array(
			'nonce'          => 'test',
			'id'             => $id,
			'model_provider' => 'openai',
			'model'          => 'gpt-5.4',
		);

		try {
			$this->handler->handle_generate_chat_title();
		} catch ( \HaydiTestHaltException $e ) {
			unset( $e );
		}

		$this->assertTrue( $this->lastSuccess );
		$this->assertSame( 'Contact Form Plugin', $this->lastData['title'] );
		$this->assertSame( 'ai', $this->lastData['title_source'] );
		$this->assertTrue( $this->lastData['generated'] );
		$this->assertFalse( $client->last_include_tools );
		$this->assertSame( array( 'openai', 'gpt-5.4' ), $client->last_model_preference );
		$this->assertStringContainsString( 'Create a contact form plugin', $client->last_messages[0]['content'] );
		$this->assertStringContainsString( 'sentence-case title', $client->last_system );
		$this->assertStringContainsString( 'Use sentence case, not title case.', $client->last_system );

		$loaded = $this->loadChat( $id );
		$this->assertSame( 'Contact Form Plugin', $loaded['title'] );
		$this->assertSame( 'ai', $loaded['title_source'] );
	}

	// -------------------------------------------------------------------------
	// Token-usage persistence
	// -------------------------------------------------------------------------

	public function test_save_persists_usage_payload(): void {
		$id = $this->saveChat(
			array( array( 'role' => 'user', 'content' => 'hi' ) ),
			array(),
			array(
				'prompt'      => 1200,
				'completion'  => 350,
				'total'       => 1550,
				'prompt_peak' => 1000,
				'prompt_last' => 900,
				'model'       => 'claude-sonnet-4-6',
			)
		);

		$loaded = $this->loadChat( $id );

		$this->assertSame(
			array(
				'prompt'      => 1200,
				'completion'  => 350,
				'total'       => 1550,
				'prompt_peak' => 1000,
				'prompt_last' => 900,
				'model'       => 'claude-sonnet-4-6',
			),
			$loaded['usage']
		);
	}

	public function test_load_returns_null_usage_for_legacy_chats(): void {
		// Save without a usage payload — represents a chat created
		// before token monitoring existed.
		$id     = $this->saveChat( array( array( 'role' => 'user', 'content' => 'hi' ) ) );
		$loaded = $this->loadChat( $id );

		$this->assertNull( $loaded['usage'] );
	}

	public function test_save_coerces_usage_fields_to_integers(): void {
		// Strings and floats in the payload should be coerced to non-negative ints.
		$id = $this->saveChat(
			array( array( 'role' => 'user', 'content' => 'hi' ) ),
			array(),
			array(
				'prompt'      => '42',
				'completion'  => 10.7,
				'total'       => -5,         // negative clamps to 0
				'prompt_peak' => '38',
				'prompt_last' => -9,         // negative clamps to 0
				'model'       => 'gpt-4o',
			)
		);

		$loaded = $this->loadChat( $id );
		$this->assertSame( 42,       $loaded['usage']['prompt'] );
		$this->assertSame( 10,       $loaded['usage']['completion'] );
		$this->assertSame( 0,        $loaded['usage']['total'] );
		$this->assertSame( 38,       $loaded['usage']['prompt_peak'] );
		$this->assertSame( 0,        $loaded['usage']['prompt_last'] );
		$this->assertSame( 'gpt-4o', $loaded['usage']['model'] );
	}

	public function test_save_overwrites_existing_usage_on_subsequent_save(): void {
		$id = $this->saveChat(
			array( array( 'role' => 'user', 'content' => 'hi' ) ),
			array(),
			array( 'prompt' => 100, 'completion' => 50, 'total' => 150, 'model' => 'm1' )
		);

		// Subsequent save replaces usage with the larger running total.
		$this->saveChat(
			array( array( 'role' => 'user', 'content' => 'hi' ) ),
			array(),
			array( 'prompt' => 500, 'completion' => 200, 'total' => 700, 'model' => 'm1' ),
			$id
		);

		$loaded = $this->loadChat( $id );
		$this->assertSame( 500, $loaded['usage']['prompt'] );
		$this->assertSame( 700, $loaded['usage']['total'] );
	}

	public function test_save_truncates_runaway_model_strings(): void {
		// substr( …, 0, 128 ) keeps us safe from a malicious or buggy client.
		$long = str_repeat( 'x', 500 );

		$id = $this->saveChat(
			array( array( 'role' => 'user', 'content' => 'hi' ) ),
			array(),
			array( 'prompt' => 1, 'completion' => 1, 'total' => 2, 'model' => $long )
		);

		$loaded = $this->loadChat( $id );
		$this->assertSame( 128, strlen( $loaded['usage']['model'] ) );
	}

	// -------------------------------------------------------------------------
	// Per-message timestamp persistence
	// -------------------------------------------------------------------------

	public function test_save_preserves_display_log_timestamps(): void {
		$display = array(
			array( 'role' => 'user',      'text' => 'hello',  't' => 1700000000000 ),
			array( 'role' => 'assistant', 'text' => 'hi',     't' => 1700000000500 ),
		);

		$id     = $this->saveChat(
			array( array( 'role' => 'user', 'content' => 'hi' ) ),
			$display
		);
		$loaded = $this->loadChat( $id );

		$this->assertSame( 1700000000000, $loaded['display'][0]['t'] );
		$this->assertSame( 1700000000500, $loaded['display'][1]['t'] );
	}

	public function test_save_drops_invalid_or_missing_timestamps(): void {
		// Mix: legacy entry (no t), invalid string, zero, negative — all dropped.
		$display = array(
			array( 'role' => 'user',      'text' => 'one'                          ),
			array( 'role' => 'assistant', 'text' => 'two',  't' => 'not-a-number'  ),
			array( 'role' => 'user',      'text' => 'three','t' => 0               ),
			array( 'role' => 'assistant', 'text' => 'four', 't' => -1              ),
			array( 'role' => 'user',      'text' => 'five', 't' => 1700000005000   ),
		);

		$id     = $this->saveChat(
			array( array( 'role' => 'user', 'content' => 'x' ) ),
			$display
		);
		$loaded = $this->loadChat( $id );

		$this->assertArrayNotHasKey( 't', $loaded['display'][0] );
		$this->assertArrayNotHasKey( 't', $loaded['display'][1] );
		$this->assertArrayNotHasKey( 't', $loaded['display'][2] );
		$this->assertArrayNotHasKey( 't', $loaded['display'][3] );
		$this->assertSame( 1700000005000, $loaded['display'][4]['t'] );
	}

	public function test_save_preserves_usage_role_entries_in_display_log(): void {
		// Per-turn token captions are persisted as {role: 'usage', text, t}.
		$display = array(
			array( 'role' => 'user',      'text' => 'hello', 't' => 1700000000000 ),
			array( 'role' => 'assistant', 'text' => 'hi',    't' => 1700000000300 ),
			array( 'role' => 'usage',     'text' => '120 in · 30 out · 150 total', 't' => 1700000000400 ),
		);

		$id     = $this->saveChat(
			array( array( 'role' => 'user', 'content' => 'hi' ) ),
			$display
		);
		$loaded = $this->loadChat( $id );

		$this->assertCount( 3, $loaded['display'] );
		$this->assertSame( 'usage', $loaded['display'][2]['role'] );
		$this->assertStringContainsString( '150 total', $loaded['display'][2]['text'] );
	}

	// -------------------------------------------------------------------------
	// Regression: usage and apply_count coexist on the index entry
	// -------------------------------------------------------------------------

	public function test_save_preserves_apply_count_when_writing_usage(): void {
		$id = $this->saveChat(
			array( array( 'role' => 'user', 'content' => 'hi' ) ),
			array(),
			array( 'prompt' => 10, 'completion' => 5, 'total' => 15, 'model' => 'm' )
		);

		// Simulate handle_record_apply having bumped the counter on the index.
		$index = $this->userMeta[1]['haydi_chats_index'];
		foreach ( $index as &$entry ) {
			if ( $entry['id'] === $id ) {
				$entry['apply_count'] = 3;
			}
		}
		unset( $entry );
		$this->userMeta[1]['haydi_chats_index'] = $index;

		// A subsequent save (with new usage) must not clobber apply_count.
		$this->saveChat(
			array( array( 'role' => 'user', 'content' => 'hi' ) ),
			array(),
			array( 'prompt' => 50, 'completion' => 25, 'total' => 75, 'model' => 'm' ),
			$id
		);

		$loaded = $this->loadChat( $id );
		$this->assertSame( 3,  $loaded['apply_count'] );
		$this->assertSame( 75, $loaded['usage']['total'] );
	}
}

class Haydi_Test_Title_Client extends Haydi_AI_Client {
	public ?bool $last_include_tools = null;
	public ?array $last_model_preference = null;
	public array $last_messages = array();
	public string $last_system = '';

	public function __construct( private array $response ) {}

	public function send_messages(
		array $messages,
		string $system = '',
		bool $include_tools = true,
		?array $model_preference = null
	): array|WP_Error {
		$this->last_messages          = $messages;
		$this->last_system            = $system;
		$this->last_include_tools     = $include_tools;
		$this->last_model_preference  = $model_preference;
		return $this->response;
	}
}
