<?php
/**
 * AI Client — thin wrapper around the WordPress AI Client API (WP 7.0+).
 *
 * Delegates all AI calls to the connector configured in Settings → Connectors.
 * Converts between the internal message format (used for storage and history)
 * and the WP AI Client DTO objects expected by wp_ai_client_prompt().
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

class Haydi_AI_Client {

	const DEFAULT_MAX_TOKENS      = 16384;
	const DEFAULT_REQUEST_TIMEOUT = 300;

	private const CONVERSION_MODE_DEFAULT           = 'default';
	private const CONVERSION_MODE_OPENAI_SPLIT      = 'openai_split';
	private const CONVERSION_MODE_TOOL_RESULT_SPLIT = 'tool_result_split';
	private const CONVERSION_MODE_TOOL_TRANSCRIPT   = 'tool_transcript';

	/** @var int|null Max output tokens; loaded lazily so handlers that never call the AI don't hit wp_options. */
	private ?int $max_tokens = null;

	/** @var int|null Request timeout in seconds, loaded lazily from wp_options. */
	private ?int $request_timeout = null;

	/** @var Haydi_Tool_Catalog|null Tool declarations loaded lazily after all active plugins register. */
	private ?Haydi_Tool_Catalog $tool_catalog = null;

	public function __construct( ?Haydi_Tool_Catalog $tool_catalog = null ) {
		$this->tool_catalog = $tool_catalog;
	}

	private function tool_catalog(): Haydi_Tool_Catalog {
		if ( ! isset( $this->tool_catalog ) || null === $this->tool_catalog ) {
			$this->tool_catalog = haydi_get_tool_catalog();
		}
		return $this->tool_catalog;
	}

	private function get_max_tokens(): int {
		if ( null === $this->max_tokens ) {
			$this->max_tokens = (int) get_option( 'haydi_max_tokens', self::DEFAULT_MAX_TOKENS );
		}
		return $this->max_tokens;
	}

	private function get_request_timeout(): int {
		if ( null === $this->request_timeout ) {
			$this->request_timeout = (int) get_option( 'haydi_request_timeout', self::DEFAULT_REQUEST_TIMEOUT );
		}
		return $this->request_timeout;
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Send a chat to the configured AI connector.
	 *
	 * Accepts and returns messages in the internal format so the agentic loop in
	 * Haydi_Ajax_Handlers::handle_chat() requires no changes.
	 *
	 * @param  array      $messages Internal messages array.
	 * @param  string     $system   Optional system prompt.
	 * @param  bool       $include_tools Whether function declarations should be available to the model.
	 * @param  array|null $model_preference Optional [provider_id, model_id] preference.
	 * @param  array|null $exact_model Optional [provider_id, model_id] source lock for Provider Continuation replay.
	 * @return array|WP_Error        ['stop_reason' => ..., 'content' => [...], 'usage' => [...], 'model' => ...], or WP_Error.
	 */
	public function send_messages(
		array $messages,
		string $system = '',
		bool $include_tools = true,
		?array $model_preference = null,
		?array $exact_model = null
	): array|WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'no_ai_client',
				'The WordPress AI Client is not available. Please ensure you are running WordPress 7.0+ with a connector configured in Settings → Connectors.'
			);
		}

		$conversion_model = $exact_model ?? $model_preference;
		$wp_messages      = $this->to_wp_messages( $messages, $conversion_model );
		$result           = $this->execute_prompt( $wp_messages, $system, $include_tools, $model_preference, $exact_model );

		// When no explicit provider is given and the provider (e.g. OpenAI's Responses API)
		// rejects a mixed function-call/-response message layout, retry once with OpenAI-style
		// message splitting. validateMessages() runs before any HTTP request, so this retry
		// is free — the first attempt never left the process.
		if (
			is_wp_error( $result ) &&
			null === $exact_model &&
			null === $model_preference &&
			false !== strpos( $result->get_error_message(), 'must be the only part in a message' )
		) {
			$wp_messages = $this->to_wp_messages( $messages, $model_preference, self::CONVERSION_MODE_OPENAI_SPLIT );
			$result      = $this->execute_prompt( $wp_messages, $system, $include_tools, $model_preference, null );
		}

		if ( null === $exact_model && is_wp_error( $result ) && $this->should_retry_with_google_tool_transcript( $model_preference, $result ) ) {
			$wp_messages = $this->to_wp_messages( $messages, $model_preference, self::CONVERSION_MODE_TOOL_TRANSCRIPT );
			$result      = $this->execute_prompt( $wp_messages, $system, $include_tools, $model_preference, null );
		}

		if ( is_wp_error( $result ) ) {
			$this->log_ai_client_event(
				'prompt_failed',
				array(
					'error' => $this->format_wp_error_for_log( $result ),
				),
				$model_preference,
				count( $wp_messages ),
				$include_tools
			);
			return $result;
		}

		$response = $this->to_internal_format( $result );
		if (
			null !== $exact_model &&
			(
				(string) ( $exact_model[0] ?? '' ) !== (string) ( $response['provider'] ?? '' ) ||
				(string) ( $exact_model[1] ?? '' ) !== (string) ( $response['model'] ?? '' )
			)
		) {
			return new WP_Error(
				'provider_continuation_source_changed',
				'The provider or model changed while resuming a tool turn. The private continuation was not replayed to another source.'
			);
		}
		$this->log_ai_client_event(
			'prompt_internal_response',
			array(
				'stop_reason' => $response['stop_reason'] ?? '',
				'block_types' => $this->get_content_block_types_for_log( $response['content'] ?? array() ),
				'model'       => (string) ( $response['model'] ?? '' ),
			),
			$model_preference,
			count( $wp_messages ),
			$include_tools
		);

		return $response;
	}

	/**
	 * Project untrusted browser/storage history onto Haydi's portable transcript.
	 *
	 * Provider Continuation fields are intentionally not part of this schema. This
	 * method is the defensive boundary that prevents a client from submitting
	 * thought-channel data or provider metadata for privileged same-source replay.
	 */
	public static function sanitize_public_transcript( array $messages ): array {
		$sanitized = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role = (string) ( $message['role'] ?? '' );
			if ( 'user' !== $role && 'assistant' !== $role ) {
				continue;
			}

			$content = $message['content'] ?? '';
			if ( is_string( $content ) ) {
				$sanitized[] = array(
					'role'    => $role,
					'content' => $content,
				);
				continue;
			}

			if ( ! is_array( $content ) ) {
				continue;
			}

			$blocks = array();
			foreach ( $content as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}

				$type = (string) ( $block['type'] ?? '' );
				if ( 'thought' === ( $block['channel'] ?? '' ) && ! ( 'assistant' === $role && 'tool_use' === $type ) ) {
					continue;
				}
				if ( 'text' === $type && isset( $block['text'] ) && is_string( $block['text'] ) ) {
					$blocks[] = array(
						'type' => 'text',
						'text' => $block['text'],
					);
					continue;
				}

				if ( 'assistant' === $role && 'tool_use' === $type ) {
					$input    = $block['input'] ?? array();
					$blocks[] = array(
						'type'  => 'tool_use',
						'id'    => is_string( $block['id'] ?? null ) ? $block['id'] : '',
						'name'  => is_string( $block['name'] ?? null ) ? $block['name'] : '',
						'input' => is_array( $input ) || is_object( $input ) ? $input : array(),
					);
					continue;
				}

				if ( 'user' === $role && 'tool_result' === $type ) {
					$blocks[] = array(
						'type'        => 'tool_result',
						'tool_use_id' => is_string( $block['tool_use_id'] ?? null ) ? $block['tool_use_id'] : '',
						'name'        => is_string( $block['name'] ?? null ) ? $block['name'] : '',
						'content'     => $block['content'] ?? '',
					);
				}
			}

			if ( ! empty( $blocks ) ) {
				$sanitized[] = array(
					'role'    => $role,
					'content' => $blocks,
				);
			}
		}

		return $sanitized;
	}

	/**
	 * Adapt completed portable Tool turns to ordinary text for a fresh request.
	 *
	 * Native Tool messages can require provider-private signatures or reasoning
	 * that Haydi deliberately does not persist after the active Tool turn. Textual
	 * history retains the semantics without replaying incomplete protocol state.
	 */
	public static function portable_provider_history( array $messages ): array {
		$history = self::sanitize_public_transcript( $messages );

		foreach ( $history as &$message ) {
			if ( ! is_array( $message['content'] ) ) {
				continue;
			}

			foreach ( $message['content'] as &$block ) {
				if ( 'assistant' === $message['role'] && 'tool_use' === $block['type'] ) {
					$input = wp_json_encode( $block['input'] );
					$block = array(
						'type' => 'text',
						'text' => sprintf(
							'Tool requested%s: %s' . "\n" . 'Input: %s',
							'' !== $block['id'] ? ' (' . $block['id'] . ')' : '',
							'' !== $block['name'] ? $block['name'] : 'unknown_tool',
							is_string( $input ) ? $input : '{}'
						),
					);
					continue;
				}

				if ( 'user' === $message['role'] && 'tool_result' === $block['type'] ) {
					$result = $block['content'];
					if ( ! is_string( $result ) ) {
						$encoded = wp_json_encode( $result );
						$result  = is_string( $encoded ) ? $encoded : '';
					}
					$block = array(
						'type' => 'text',
						'text' => sprintf(
							'Tool result%s: %s' . "\n" . 'Output: %s',
							'' !== $block['tool_use_id'] ? ' (' . $block['tool_use_id'] . ')' : '',
							'' !== $block['name'] ? $block['name'] : 'unknown_tool',
							$result
						),
					);
				}
			}
			unset( $block );
		}
		unset( $message );

		return $history;
	}

	/**
	 * Build and invoke the WP AI Client prompt with the given pre-converted messages.
	 *
	 * Extracted so send_messages() can call it twice (normal + OpenAI-split retry)
	 * without duplicating the builder setup.
	 *
	 * @param  array      $wp_messages      WP AI Client Message DTOs.
	 * @param  string     $system           Optional system prompt.
	 * @param  bool       $include_tools    Whether to attach function declarations.
	 * @param  array|null $model_preference [provider_id, model_id] or null.
	 * @param  array|null $exact_model      Exact [provider_id, model_id] for source-bound replay.
	 * @return mixed GenerativeAiResult or WP_Error.
	 * @throws \Throwable When the underlying AI Client throws during generation.
	 */
	private function execute_prompt(
		array $wp_messages,
		string $system,
		bool $include_tools,
		?array $model_preference,
		?array $exact_model = null
	): mixed {
		// The WP AI Client prompt builder reads wp_ai_client_default_request_timeout
		// in its constructor, so the filter must be in place before wp_ai_client_prompt().
		$request_timeout = $this->get_request_timeout();
		$extend_timeout  = static function () use ( $request_timeout ): int {
			return $request_timeout;
		};
		add_filter( 'wp_ai_client_default_request_timeout', $extend_timeout );

		$builder = wp_ai_client_prompt( $wp_messages )
			->using_max_tokens( $this->get_max_tokens() );

		if ( $exact_model ) {
			try {
				$model   = AiClient::defaultRegistry()->getProviderModel(
					(string) ( $exact_model[0] ?? '' ),
					(string) ( $exact_model[1] ?? '' )
				);
				$builder = $builder->using_model( $model );
			} catch ( \Throwable $e ) {
				remove_filter( 'wp_ai_client_default_request_timeout', $extend_timeout );
				return new WP_Error(
					'provider_continuation_source_unavailable',
					'The provider and model that started this tool turn are no longer available.'
				);
			}
		} elseif ( $model_preference ) {
			try {
				$builder = $builder->using_model_preference( $model_preference );
			} catch ( \Throwable $e ) {
				remove_filter( 'wp_ai_client_default_request_timeout', $extend_timeout );
				$this->log_ai_client_event(
					'invalid_model_preference',
					array(
						'exception' => get_class( $e ),
						'message'   => $e->getMessage(),
					),
					$model_preference,
					count( $wp_messages ),
					$include_tools
				);
				return new WP_Error(
					'invalid_model_preference',
					'The selected model could not be used as a model preference.'
				);
			}
		}

		if ( $include_tools ) {
			// Let each connector use its native automatic Tool selection default.
			// Custom tool_choice shapes differ between provider transports.
			$builder = $builder->using_function_declarations( ...$this->get_function_declarations() );
		}

		remove_filter( 'wp_ai_client_default_request_timeout', $extend_timeout );

		if ( '' !== $system ) {
			$builder = $builder->using_system_instruction( $system );
		}

		$this->log_ai_client_event(
			'prompt_request',
			array(),
			$model_preference,
			count( $wp_messages ),
			$include_tools
		);

		try {
			$result = $builder->generate_result();
		} catch ( \Throwable $e ) {
			$this->log_ai_client_event(
				'prompt_exception',
				array(
					'exception' => get_class( $e ),
					'message'   => $e->getMessage(),
				),
				$model_preference,
				count( $wp_messages ),
				$include_tools
			);
			throw $e;
		}

		if ( is_wp_error( $result ) ) {
			$this->log_ai_client_event(
				'prompt_response_error',
				array(
					'error' => $this->format_wp_error_for_log( $result ),
				),
				$model_preference,
				count( $wp_messages ),
				$include_tools
			);
		} elseif ( is_object( $result ) ) {
			$this->log_ai_client_event(
				'prompt_response',
				array(
					'result_class' => get_class( $result ),
					'model'        => $this->get_result_model_id_for_log( $result ),
				),
				$model_preference,
				count( $wp_messages ),
				$include_tools
			);
		} else {
			$this->log_ai_client_event(
				'prompt_response_unexpected',
				array(
					'result_type' => gettype( $result ),
				),
				$model_preference,
				count( $wp_messages ),
				$include_tools
			);
		}

		return $result;
	}

	/**
	 * Log AI Client diagnostics without prompt content or credentials.
	 */
	private function log_ai_client_event(
		string $event,
		array $context = array(),
		?array $model_preference = null,
		int $message_count = 0,
		bool $include_tools = false
	): void {
		$payload = array_merge(
			array(
				'event'            => $event,
				'model_preference' => $model_preference,
				'message_count'    => $message_count,
				'include_tools'    => $include_tools,
			),
			$context
		);

		error_log( '[Haydi] AI client: ' . wp_json_encode( $payload ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Format a WP_Error for logs.
	 */
	private function format_wp_error_for_log( WP_Error $error ): array {
		$data = method_exists( $error, 'get_error_data' ) ? $error->get_error_data() : null;
		return array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
			'data'    => $this->redact_log_value( $data ),
		);
	}

	/**
	 * Get the result model ID without risking a secondary failure.
	 */
	private function get_result_model_id_for_log( object $result ): string {
		try {
			if ( ! method_exists( $result, 'getModelMetadata' ) ) {
				return '';
			}
			$metadata = $result->getModelMetadata();
			if ( is_object( $metadata ) && method_exists( $metadata, 'getId' ) ) {
				return (string) $metadata->getId();
			}
		} catch ( \Throwable $e ) {
			return '';
		}

		return '';
	}

	/**
	 * Summarize response block types without logging model text or tool inputs.
	 */
	private function get_content_block_types_for_log( array $content ): array {
		$types = array();
		foreach ( $content as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) ) {
				$types[] = (string) $block['type'];
			}
		}
		return $types;
	}

	/**
	 * Redact common secret-bearing fields before logging connector error data.
	 */
	private function redact_log_value( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$redacted = array();
			foreach ( $value as $key => $item ) {
				$key_string = is_string( $key ) ? strtolower( $key ) : '';
				if (
					str_contains( $key_string, 'key' )
					|| str_contains( $key_string, 'token' )
					|| str_contains( $key_string, 'secret' )
					|| str_contains( $key_string, 'authorization' )
				) {
					$redacted[ $key ] = '[redacted]';
					continue;
				}
				$redacted[ $key ] = $this->redact_log_value( $item );
			}
			return $redacted;
		}

		return $value;
	}

	/**
	 * Decide whether an unknown default provider failure looks like Gemini's
	 * missing thoughtSignature requirement.
	 */
	private function should_retry_with_google_tool_transcript( ?array $model_preference, WP_Error $error ): bool {
		if ( null !== $model_preference ) {
			return false;
		}

		/*
		 * The Google provider bundled with WP 7.0 does not preserve Gemini's
		 * thoughtSignature on functionCall parts, but newer Gemini models require it
		 * when a function call is replayed with a function response. If the default
		 * provider is unknown and the connector reports that exact failure, retry with
		 * a plain-text tool transcript. If the provider error wording changes, this
		 * substring is the compatibility point to update.
		 */
		return false !== stripos( $error->get_error_message(), 'thought_signature' );
	}

	// -------------------------------------------------------------------------
	// Message conversion: internal format ↔ WP AI Client DTOs
	// -------------------------------------------------------------------------

	/**
	 * Convert an internal messages array to WP AI Client Message objects.
	 *
	 * @param array       $messages         Internal messages array.
	 * @param array|null  $model_preference [provider_id, model_id] or null.
	 * @param string|null $conversion_mode Explicit conversion mode for retry paths.
	 */
	private function to_wp_messages(
		array $messages,
		?array $model_preference = null,
		?string $conversion_mode = null
	): array {
		$wp_messages     = array();
		$conversion_mode = $this->message_conversion_mode( $model_preference, $conversion_mode );

		foreach ( $messages as $msg ) {
			$role    = $msg['role'] ?? '';
			$content = $msg['content'] ?? '';

			if ( 'user' === $role ) {
				foreach ( $this->user_messages( $content, $conversion_mode ) as $user_message ) {
					$wp_messages[] = $user_message;
				}
			} elseif ( 'assistant' === $role ) {
				// Server-created continuation data retains provider protocol state that is
				// deliberately absent from the public transcript. Prefer it when present,
				// while continuing to accept the legacy content-only history shape.
				if ( array_key_exists( 'continuation', $msg ) && is_array( $msg['continuation'] ) ) {
					$content = $msg['continuation'];
				}
				foreach ( $this->model_messages( $content, $conversion_mode ) as $model_message ) {
					$wp_messages[] = $model_message;
				}
			}
		}

		return $wp_messages;
	}

	/**
	 * Resolve the provider-specific internal-to-WP message conversion mode.
	 */
	private function message_conversion_mode( ?array $model_preference, ?string $explicit_mode = null ): string {
		if ( null !== $explicit_mode ) {
			return $explicit_mode;
		}

		return match ( strtolower( (string) ( $model_preference[0] ?? '' ) ) ) {
			'deepseek' => self::CONVERSION_MODE_TOOL_RESULT_SPLIT,
			'google'   => self::CONVERSION_MODE_TOOL_TRANSCRIPT,
			'openai'   => self::CONVERSION_MODE_OPENAI_SPLIT,
			default    => self::CONVERSION_MODE_DEFAULT,
		};
	}

	/**
	 * Split a user content array into one or more UserMessage objects.
	 *
	 * OpenAI-compatible adapters require function response parts to be isolated.
	 * Internal history can still store a single user turn containing tool_result
	 * blocks followed by the user's next text; this only adapts the provider
	 * request shape. DeepSeek uses this split for results while retaining its
	 * complete assistant message so reasoning_content stays attached to tool calls.
	 *
	 * @return UserMessage[]
	 */
	private function user_messages(
		string|array $content,
		string $conversion_mode = self::CONVERSION_MODE_DEFAULT
	): array {
		if ( self::CONVERSION_MODE_TOOL_TRANSCRIPT === $conversion_mode ) {
			$parts = $this->user_parts( $content, $conversion_mode );
			return ! empty( $parts ) ? array( new UserMessage( $parts ) ) : array();
		}

		if (
			self::CONVERSION_MODE_OPENAI_SPLIT !== $conversion_mode &&
			self::CONVERSION_MODE_TOOL_RESULT_SPLIT !== $conversion_mode
		) {
			$parts = $this->user_parts( $content );
			return ! empty( $parts ) ? array( new UserMessage( $parts ) ) : array();
		}

		if ( is_string( $content ) ) {
			$parts = $this->user_parts( $content );
			return ! empty( $parts ) ? array( new UserMessage( $parts ) ) : array();
		}

		$messages   = array();
		$text_parts = array();

		foreach ( $content as $block ) {
			$type = $block['type'] ?? '';

			if ( 'text' === $type && '' !== ( $block['text'] ?? '' ) ) {
				$text_parts[] = new MessagePart( $block['text'] );
				continue;
			}

			if ( 'tool_result' !== $type ) {
				continue;
			}

			if ( ! empty( $text_parts ) ) {
				$messages[] = new UserMessage( $text_parts );
				$text_parts = array();
			}

			$tool_parts = $this->user_parts( array( $block ) );
			if ( ! empty( $tool_parts ) ) {
				$messages[] = new UserMessage( $tool_parts );
			}
		}

		if ( ! empty( $text_parts ) ) {
			$messages[] = new UserMessage( $text_parts );
		}

		return $messages;
	}

	/**
	 * Build MessagePart[] for a user message.
	 * Content is a plain string or an array of text/tool_result blocks. A
	 * tool_result may hold any JSON-compatible value until an Adapter serializes it.
	 */
	private function user_parts(
		string|array $content,
		string $conversion_mode = self::CONVERSION_MODE_DEFAULT
	): array {
		if ( is_string( $content ) ) {
			return '' !== $content ? array( new MessagePart( $content ) ) : array();
		}

		$parts = array();
		foreach ( $content as $block ) {
			$type = $block['type'] ?? '';
			if ( 'text' === $type && '' !== ( $block['text'] ?? '' ) ) {
				$parts[] = new MessagePart( $block['text'] );
			} elseif ( 'tool_result' === $type ) {
				if ( self::CONVERSION_MODE_TOOL_TRANSCRIPT === $conversion_mode ) {
					$parts[] = new MessagePart( $this->format_tool_result_transcript( $block ) );
					continue;
				}
				$parts[] = new MessagePart( $this->function_response_from_tool_result( $block ) );
			}
		}

		return $parts;
	}

	/**
	 * Build a provider-neutral function response DTO from an internal tool_result block.
	 */
	private function function_response_from_tool_result( array $block ): FunctionResponse {
		return new FunctionResponse(
			id:       $block['tool_use_id'] ?? null,
			name: ! empty( $block['name'] ) ? (string) $block['name'] : null,
			response: $block['content'] ?? '',
		);
	}

	/**
	 * Build MessagePart[] for an assistant (model) message.
	 * Content is a plain string or an array of text/tool_use blocks.
	 */
	private function model_parts(
		string|array $content,
		string $conversion_mode = self::CONVERSION_MODE_DEFAULT
	): array {
		if ( is_string( $content ) ) {
			return '' !== $content ? array( new MessagePart( $content ) ) : array();
		}

		$parts = array();
		foreach ( $content as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$type              = $block['type'] ?? '';
			$channel           = $this->message_part_channel( $block );
			$thought_signature = array_key_exists( 'thought_signature', $block )
				? (string) $block['thought_signature']
				: null;

			// A plain-text compatibility transcript must never expose hidden model
			// thought as regular content.
			if ( self::CONVERSION_MODE_TOOL_TRANSCRIPT === $conversion_mode && $channel->isThought() ) {
				continue;
			}

			if ( 'text' === $type && array_key_exists( 'text', $block ) && is_string( $block['text'] ) ) {
				$parts[] = new MessagePart( $block['text'], $channel, $thought_signature );
			} elseif ( 'tool_use' === $type ) {
				if ( self::CONVERSION_MODE_TOOL_TRANSCRIPT === $conversion_mode ) {
					$parts[] = new MessagePart( $this->format_tool_use_transcript( $block ) );
					continue;
				}
				// json_decode( $raw, true ) turns every {} into [], so an
				// empty-input tool call gets stored as []. Cast back to a
				// stdClass so it serialises as {} (a JSON object/dictionary)
				// rather than [] (a JSON array), which providers reject.
				$raw_input = $block['input'] ?? array();
				$parts[]   = new MessagePart(
					new FunctionCall(
						id:   $block['id'] ?? null,
						name: $block['name'] ?? null,
						args: ! empty( $raw_input ) ? $raw_input : new \stdClass(),
					),
					$channel,
					$thought_signature
				);
			} elseif ( 'tool_result' === $type ) {
				$parts[] = new MessagePart(
					$this->function_response_from_tool_result( $block ),
					$channel,
					$thought_signature
				);
			} elseif ( 'file' === $type && isset( $block['file'] ) && is_array( $block['file'] ) ) {
				$parts[] = new MessagePart(
					File::fromArray( $block['file'] ),
					$channel,
					$thought_signature
				);
			}
		}

		return $parts;
	}

	/**
	 * Resolve a continuation block's provider-neutral channel.
	 */
	private function message_part_channel( array $block ): MessagePartChannelEnum {
		return 'thought' === ( $block['channel'] ?? 'content' )
			? MessagePartChannelEnum::thought()
			: MessagePartChannelEnum::content();
	}

	/**
	 * Split an assistant content array into one or more ModelMessage objects.
	 *
	 * Providers like OpenAI's Responses API require function call parts to be
	 * in their own message with no other parts. A turn that has both text and
	 * tool_use blocks is therefore split: text parts go into one message, then
	 * each function call gets its own message.
	 *
	 * @return ModelMessage[]
	 */
	private function model_messages(
		string|array $content,
		string $conversion_mode = self::CONVERSION_MODE_DEFAULT
	): array {
		$parts = $this->model_parts( $content, $conversion_mode );
		if ( empty( $parts ) ) {
			return array();
		}

		if ( self::CONVERSION_MODE_OPENAI_SPLIT !== $conversion_mode ) {
			return array( new ModelMessage( $parts ) );
		}

		// OpenAI Responses API: function call parts must be in their own message.
		// Flush adjacent non-call parts around each call so splitting does not
		// reorder thought/content parts from the provider continuation.
		$messages       = array();
		$non_call_parts = array();
		foreach ( $parts as $part ) {
			if ( null !== $part->getFunctionCall() ) {
				if ( ! empty( $non_call_parts ) ) {
					$messages[]     = new ModelMessage( $non_call_parts );
					$non_call_parts = array();
				}
				$messages[] = new ModelMessage( array( $part ) );
				continue;
			}

			$non_call_parts[] = $part;
		}

		if ( ! empty( $non_call_parts ) ) {
			$messages[] = new ModelMessage( $non_call_parts );
		}

		return $messages;
	}

	/**
	 * Format a prior model tool request as regular transcript text.
	 */
	private function format_tool_use_transcript( array $block ): string {
		$name       = (string) ( $block['name'] ?? 'unknown_tool' );
		$id         = (string) ( $block['id'] ?? '' );
		$input      = $block['input'] ?? array();
		$input_json = wp_json_encode( $input );
		if ( ! is_string( $input_json ) ) {
			$input_json = '{}';
		}

		return sprintf(
			'Tool requested%s: %s' . "\n" . 'Input: %s',
			'' !== $id ? ' (' . $id . ')' : '',
			$name,
			$input_json
		);
	}

	/**
	 * Format a tool result as regular transcript text.
	 */
	private function format_tool_result_transcript( array $block ): string {
		$name    = (string) ( $block['name'] ?? 'unknown_tool' );
		$id      = (string) ( $block['tool_use_id'] ?? '' );
		$content = $block['content'] ?? '';
		if ( ! is_string( $content ) ) {
			$encoded = wp_json_encode( $content );
			$content = is_string( $encoded ) ? $encoded : '';
		}

		return sprintf(
			'Tool result%s: %s' . "\n" . 'Output: %s',
			'' !== $id ? ' (' . $id . ')' : '',
			$name,
			$content
		);
	}

	/**
	 * Convert a GenerativeAiResult back to the internal message format.
	 *
	 * The public content projection excludes provider thought and protocol metadata.
	 * Continuation retains every ordered part so a same-provider/model subturn can
	 * faithfully reconstruct the assistant message.
	 *
	 * Stop_reason is 'tool_use' when the model made function calls, 'end_turn' otherwise.
	 */
	private function to_internal_format( object $result ): array {
		$content_blocks      = array();
		$continuation_blocks = array();
		$has_tool_calls      = false;

		// toMessage() returns only candidates[0], which misses function_call candidates
		// that arrive as separate output items (e.g. OpenAI Responses API). Iterate all.
		foreach ( $result->getCandidates() as $candidate ) {
			foreach ( $candidate->getMessage()->getParts() as $part ) {
				$continuation_block = $this->continuation_block_from_part( $part );
				if ( null === $continuation_block ) {
					continue;
				}

				$continuation_blocks[] = $continuation_block;
				if ( 'tool_use' === $continuation_block['type'] ) {
					$has_tool_calls = true;
				}

				$public_block = $this->public_content_block( $continuation_block );
				if ( null !== $public_block ) {
					$content_blocks[] = $public_block;
				}
			}
		}

		$token_usage = $result->getTokenUsage();
		$model       = $this->result_metadata_id( $result, 'getModelMetadata' );
		$provider    = $this->result_metadata_id( $result, 'getProviderMetadata' );

		$response = array(
			'stop_reason'  => $has_tool_calls ? 'tool_use' : 'end_turn',
			'content'      => $content_blocks,
			'continuation' => $continuation_blocks,
			'usage'        => array(
				'prompt'     => $token_usage->getPromptTokens(),
				'completion' => $token_usage->getCompletionTokens(),
				'total'      => $token_usage->getTotalTokens(),
			),
			'provider'     => $provider,
			'model'        => $model,
		);

		if ( method_exists( $result, 'getId' ) ) {
			$response['response_id'] = (string) $result->getId();
		}

		return $response;
	}

	/**
	 * Convert one WP AI Client MessagePart into Haydi's provider-neutral shape.
	 */
	private function continuation_block_from_part( MessagePart $part ): ?array {
		$block = array(
			'channel' => $part->getChannel()->isContent() ? 'content' : 'thought',
		);

		$text = $part->getText();
		if ( null !== $text ) {
			$block['type'] = 'text';
			$block['text'] = $text;
		} else {
			$call = $part->getFunctionCall();
			if ( null !== $call ) {
				$call_args = $call->getArgs();
				if ( is_object( $call_args ) ) {
					$encoded_args = wp_json_encode( $call_args );
					$call_args    = is_string( $encoded_args ) ? json_decode( $encoded_args, true ) : array();
				}
				if ( ! is_array( $call_args ) ) {
					$call_args = array();
				}
				$block['type']  = 'tool_use';
				$block['id']    = $call->getId() ?? uniqid( 'tool_' );
				$block['name']  = $call->getName() ?? '';
				$block['input'] = $call_args;
			} else {
				$response = method_exists( $part, 'getFunctionResponse' ) ? $part->getFunctionResponse() : null;
				if ( null !== $response ) {
					$block['type']        = 'tool_result';
					$block['tool_use_id'] = $response->getId();
					$block['name']        = $response->getName();
					$block['content']     = $response->getResponse();
				} else {
					$file = method_exists( $part, 'getFile' ) ? $part->getFile() : null;
					if ( null === $file ) {
						return null;
					}

					$block['type'] = 'file';
					$block['file'] = $file->toArray();
				}
			}
		}

		$thought_signature = $part->getThoughtSignature();
		if ( null !== $thought_signature ) {
			// Empty and null are semantically distinct for some provider protocols.
			$block['thought_signature'] = $thought_signature;
		}

		return $block;
	}

	/**
	 * Project a rich continuation block into browser/persistence-safe content.
	 */
	private function public_content_block( array $block ): ?array {
		// Function calls remain actionable even if a connector labels their part as
		// thought. All other hidden-channel data stays server-side.
		if ( 'thought' === $block['channel'] && 'tool_use' !== $block['type'] ) {
			return null;
		}

		$public_block = $block;
		unset( $public_block['channel'], $public_block['thought_signature'] );

		if ( 'text' === $public_block['type'] && '' === $public_block['text'] ) {
			return null;
		}

		return $public_block;
	}

	/**
	 * Read an actual result provider/model ID without masking a valid response if
	 * a third-party connector returns incomplete metadata.
	 */
	private function result_metadata_id( object $result, string $getter ): string {
		try {
			if ( ! method_exists( $result, $getter ) ) {
				return '';
			}

			$metadata = $result->{$getter}();
			return is_object( $metadata ) && method_exists( $metadata, 'getId' )
				? (string) $metadata->getId()
				: '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	// -------------------------------------------------------------------------
	// Tool definitions
	// -------------------------------------------------------------------------

	/**
	 * Return FunctionDeclaration objects for all tools the AI can invoke.
	 * Uses JSON Schema for parameters — compatible with all major AI providers.
	 */
	private function get_function_declarations(): array {
		$declarations = array();
		foreach ( $this->tool_catalog()->declarations( Haydi_Tool_Catalog::CHAT ) as $tool ) {
			$declarations[] = new FunctionDeclaration(
				$tool['name'],
				$tool['description'],
				$tool['inputSchema']
			);
		}
		return $declarations;
	}
}
