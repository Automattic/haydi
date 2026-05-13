<?php
/**
 * Unit tests for AI client message conversion.
 */

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WordPress\AiClient\Messages\DTO\MessagePart' ) ) {
	eval(
		'namespace WordPress\AiClient\Messages\DTO {
			class MessagePart {
				private mixed $value;
				public function __construct( mixed $value ) { $this->value = $value; }
				public function getText(): ?string { return is_string( $this->value ) ? $this->value : null; }
				public function getFunctionCall(): ?\WordPress\AiClient\Tools\DTO\FunctionCall {
					return $this->value instanceof \WordPress\AiClient\Tools\DTO\FunctionCall ? $this->value : null;
				}
				public function getFunctionResponse(): ?\WordPress\AiClient\Tools\DTO\FunctionResponse {
					return $this->value instanceof \WordPress\AiClient\Tools\DTO\FunctionResponse ? $this->value : null;
				}
			}
			class UserMessage {
				private array $parts;
				public function __construct( array $parts ) { $this->parts = $parts; }
				public function getParts(): array { return $this->parts; }
			}
			class ModelMessage {
				private array $parts;
				public function __construct( array $parts ) { $this->parts = $parts; }
				public function getParts(): array { return $this->parts; }
			}
		}
		namespace WordPress\AiClient\Tools\DTO {
			class FunctionResponse {
				public function __construct(
					private ?string $id = null,
					private ?string $name = null,
					private mixed $response = null
				) {}
				public function getId(): ?string { return $this->id; }
				public function getResponse(): mixed { return $this->response; }
			}
			class FunctionCall {
				public function __construct(
					private ?string $id = null,
					private ?string $name = null,
					private mixed $args = null
				) {}
				public function getId(): ?string { return $this->id; }
				public function getName(): ?string { return $this->name; }
				public function getArgs(): mixed { return $this->args; }
			}
		}'
	);
}

class AIClientMessageConversionTest extends TestCase {

	public function test_openai_splits_function_response_parts_from_user_text(): void {
		$wp_messages = $this->convert_to_wp_messages(
			array(
				array(
					'role'    => 'assistant',
					'content' => array(
						array(
							'type'  => 'tool_use',
							'id'    => 'call_1',
							'name'  => 'write_file',
							'input' => array( 'path' => '/tmp/example.php' ),
						),
					),
				),
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'        => 'tool_result',
							'tool_use_id' => 'call_1',
							'content'     => 'The user did not approve the proposed file write and sent a new instruction instead.',
						),
						array(
							'type' => 'text',
							'text' => "I'm building this site as a portfolio but it's missing some stuff can you help me build it?",
						),
					),
				),
			),
			array( 'openai', 'gpt-5.4' )
		);

		$this->assertCount( 3, $wp_messages );
		$this->assertInstanceOf( \WordPress\AiClient\Messages\DTO\ModelMessage::class, $wp_messages[0] );
		$this->assertInstanceOf( \WordPress\AiClient\Messages\DTO\UserMessage::class, $wp_messages[1] );
		$this->assertInstanceOf( \WordPress\AiClient\Messages\DTO\UserMessage::class, $wp_messages[2] );

		$function_response_parts = $wp_messages[1]->getParts();
		$this->assertCount( 1, $function_response_parts );
		$this->assertSame( 'call_1', $function_response_parts[0]->getFunctionResponse()->getId() );
		$this->assertNull( $function_response_parts[0]->getText() );

		$text_parts = $wp_messages[2]->getParts();
		$this->assertCount( 1, $text_parts );
		$this->assertNull( $text_parts[0]->getFunctionResponse() );
		$this->assertStringContainsString( 'portfolio', $text_parts[0]->getText() );
	}

	public function test_non_openai_keeps_mixed_user_parts_together(): void {
		$wp_messages = $this->convert_to_wp_messages(
			array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'        => 'tool_result',
							'tool_use_id' => 'call_1',
							'content'     => 'Done.',
						),
						array(
							'type' => 'text',
							'text' => 'Continue with the portfolio page.',
						),
					),
				),
			),
			array( 'anthropic', 'claude-sonnet-4.5' )
		);

		$this->assertCount( 1, $wp_messages );
		$parts = $wp_messages[0]->getParts();
		$this->assertCount( 2, $parts );
		$this->assertSame( 'call_1', $parts[0]->getFunctionResponse()->getId() );
		$this->assertSame( 'Continue with the portfolio page.', $parts[1]->getText() );
	}

	/**
	 * Covers the auto-retry path in send_messages(): when model_preference is null
	 * (default connector, provider unknown) but the connector turns out to be OpenAI,
	 * to_wp_messages() is re-called with $openai_splitting=true.  Verify it produces
	 * the same split output as an explicit 'openai' preference.
	 */
	public function test_forced_openai_splitting_with_null_model_preference(): void {
		$messages = array(
			array(
				'role'    => 'assistant',
				'content' => array(
					array( 'type' => 'text', 'text' => 'Scanning your site…' ),
					array(
						'type'  => 'tool_use',
						'id'    => 'call_1',
						'name'  => 'list_plugins',
						'input' => array(),
					),
				),
			),
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'        => 'tool_result',
						'tool_use_id' => 'call_1',
						'content'     => 'Plugin list.',
					),
				),
			),
		);

		// Without forced splitting and null preference, messages are NOT split.
		$unsplit = $this->convert_to_wp_messages( $messages, null );
		$this->assertCount( 2, $unsplit );
		$this->assertCount( 2, $unsplit[0]->getParts() ); // text + FunctionCall together

		// With forced splitting (the retry path), they ARE split correctly.
		$split = $this->convert_to_wp_messages( $messages, null, true );
		$this->assertCount( 3, $split );
		$this->assertInstanceOf( \WordPress\AiClient\Messages\DTO\ModelMessage::class, $split[0] );
		$this->assertInstanceOf( \WordPress\AiClient\Messages\DTO\ModelMessage::class, $split[1] );
		$this->assertInstanceOf( \WordPress\AiClient\Messages\DTO\UserMessage::class, $split[2] );

		$text_parts = $split[0]->getParts();
		$this->assertCount( 1, $text_parts );
		$this->assertNull( $text_parts[0]->getFunctionCall() );
		$this->assertSame( 'Scanning your site…', $text_parts[0]->getText() );

		$call_parts = $split[1]->getParts();
		$this->assertCount( 1, $call_parts );
		$this->assertNotNull( $call_parts[0]->getFunctionCall() );

		$result_parts = $split[2]->getParts();
		$this->assertCount( 1, $result_parts );
		$this->assertSame( 'call_1', $result_parts[0]->getFunctionResponse()->getId() );
	}

	/**
	 * @param array<int,array<string,mixed>> $messages
	 * @param array{0:string,1:string}|null  $model_preference
	 * @param bool                           $openai_splitting
	 * @return array<int,object>
	 */
	private function convert_to_wp_messages( array $messages, ?array $model_preference, bool $openai_splitting = false ): array {
		$client = new Haydi_AI_Client();
		$method = new \ReflectionMethod( $client, 'to_wp_messages' );

		return $method->invoke( $client, $messages, $model_preference, $openai_splitting );
	}
}
