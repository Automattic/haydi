<?php
/**
 * AI Client — thin wrapper around the WordPress AI Client API (WP 7.0+).
 *
 * Delegates all AI calls to the connector configured in Settings → Connectors.
 * Converts between the internal message format (used for storage and history)
 * and the WP AI Client DTO objects expected by wp_ai_client_prompt().
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

class Haydi_AI_Client {

	const DEFAULT_MAX_TOKENS      = 16384;
	const DEFAULT_REQUEST_TIMEOUT = 300;

	private const CONVERSION_MODE_DEFAULT         = 'default';
	private const CONVERSION_MODE_OPENAI_SPLIT    = 'openai_split';
	private const CONVERSION_MODE_TOOL_TRANSCRIPT = 'tool_transcript';

	/** @var int|null Max output tokens; loaded lazily so handlers that never call the AI don't hit wp_options. */
	private ?int $max_tokens = null;

	/** @var int|null Request timeout in seconds, loaded lazily from wp_options. */
	private ?int $request_timeout = null;

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

	/**
	 * Return the tool_choice value for the given provider, or null if unknown.
	 *
	 * Only applied to providers that need an explicit value. OpenAI receives
	 * "auto" so plain chat can answer directly while site-management prompts
	 * can still call tools. Anthropic follows system prompt instructions
	 * reliably and must NOT be forced — tool_choice:"any" causes it to loop
	 * endlessly calling read-only tools rather than proceeding.
	 * When model_preference is null the active provider is unknown, so we skip
	 * setting tool_choice to avoid sending a wrong value to an unexpected provider.
	 *
	 * @param  array|null $model_preference [provider_id, model_id] or null.
	 * @return string|array|null
	 */
	private function get_tool_choice_for_provider( ?array $model_preference ): string|array|null {
		if ( null === $model_preference ) {
			return null;
		}
		return match ( $model_preference[0] ?? '' ) {
			'openai' => 'auto',
			default  => null,
		};
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
	 * @return array|WP_Error        ['stop_reason' => ..., 'content' => [...], 'usage' => [...], 'model' => ...], or WP_Error.
	 */
	public function send_messages(
		array $messages,
		string $system = '',
		bool $include_tools = true,
		?array $model_preference = null
	): array|WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error(
				'no_ai_client',
				'The WordPress AI Client is not available. Please ensure you are running WordPress 7.0+ with a connector configured in Settings → Connectors.'
			);
		}

		$wp_messages = $this->to_wp_messages( $messages, $model_preference );
		$result      = $this->execute_prompt( $wp_messages, $system, $include_tools, $model_preference );

		// When no explicit provider is given and the provider (e.g. OpenAI's Responses API)
		// rejects a mixed function-call/-response message layout, retry once with OpenAI-style
		// message splitting. validateMessages() runs before any HTTP request, so this retry
		// is free — the first attempt never left the process.
		if (
			is_wp_error( $result ) &&
			null === $model_preference &&
			false !== strpos( $result->get_error_message(), 'must be the only part in a message' )
		) {
			$wp_messages = $this->to_wp_messages( $messages, $model_preference, self::CONVERSION_MODE_OPENAI_SPLIT );
			$result      = $this->execute_prompt( $wp_messages, $system, $include_tools, $model_preference );
		}

		if ( is_wp_error( $result ) && $this->should_retry_with_google_tool_transcript( $model_preference, $result ) ) {
			$wp_messages = $this->to_wp_messages( $messages, $model_preference, self::CONVERSION_MODE_TOOL_TRANSCRIPT );
			$result      = $this->execute_prompt( $wp_messages, $system, $include_tools, $model_preference );
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
	 * Build and invoke the WP AI Client prompt with the given pre-converted messages.
	 *
	 * Extracted so send_messages() can call it twice (normal + OpenAI-split retry)
	 * without duplicating the builder setup.
	 *
	 * @param  array      $wp_messages      WP AI Client Message DTOs.
	 * @param  string     $system           Optional system prompt.
	 * @param  bool       $include_tools    Whether to attach function declarations.
	 * @param  array|null $model_preference [provider_id, model_id] or null.
	 * @return mixed GenerativeAiResult or WP_Error.
	 * @throws \Throwable When the underlying AI Client throws during generation.
	 */
	private function execute_prompt(
		array $wp_messages,
		string $system,
		bool $include_tools,
		?array $model_preference
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

		if ( $model_preference ) {
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
			$builder = $builder->using_function_declarations( ...$this->get_function_declarations() );

			$tool_choice = $this->get_tool_choice_for_provider( $model_preference );
			if ( null !== $tool_choice ) {
				$config = new ModelConfig();
				$config->setCustomOption( 'tool_choice', $tool_choice );
				$builder = $builder->using_model_config( $config );
			}
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
			'google' => self::CONVERSION_MODE_TOOL_TRANSCRIPT,
			'openai' => self::CONVERSION_MODE_OPENAI_SPLIT,
			default  => self::CONVERSION_MODE_DEFAULT,
		};
	}

	/**
	 * Split a user content array into one or more UserMessage objects.
	 *
	 * OpenAI's Responses API requires function response parts to be isolated
	 * from text/image input parts. Internal history can still store a single
	 * user turn containing tool_result blocks followed by the user's next text;
	 * this only adapts the provider request shape.
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

		if ( self::CONVERSION_MODE_OPENAI_SPLIT !== $conversion_mode ) {
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
	 * Content is a plain string or an array of text/tool_result blocks.
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
			$type = $block['type'] ?? '';
			if ( 'text' === $type && '' !== ( $block['text'] ?? '' ) ) {
				$parts[] = new MessagePart( $block['text'] );
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
					null,
					! empty( $block['thought_signature'] ) ? (string) $block['thought_signature'] : null
				);
			}
		}

		return $parts;
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
		$text_parts = array();
		$call_parts = array();
		foreach ( $parts as $part ) {
			if ( null !== $part->getFunctionCall() ) {
				$call_parts[] = $part;
			} else {
				$text_parts[] = $part;
			}
		}

		if ( empty( $call_parts ) ) {
			return array( new ModelMessage( $parts ) );
		}

		$messages = array();
		if ( ! empty( $text_parts ) ) {
			$messages[] = new ModelMessage( $text_parts );
		}
		foreach ( $call_parts as $call_part ) {
			$messages[] = new ModelMessage( array( $call_part ) );
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
	 * Stop_reason is 'tool_use' when the model made function calls, 'end_turn' otherwise.
	 */
	private function to_internal_format( object $result ): array {
		$content_blocks = array();
		$has_tool_calls = false;

		// toMessage() returns only candidates[0], which misses function_call candidates
		// that arrive as separate output items (e.g. OpenAI Responses API). Iterate all.
		foreach ( $result->getCandidates() as $candidate ) {
			foreach ( $candidate->getMessage()->getParts() as $part ) {
				$call = $part->getFunctionCall();

				// Skip non-content parts (reasoning/thinking tokens) unless they carry a function call.
				if ( ! $part->getChannel()->isContent() && null === $call ) {
					continue;
				}

				$text = $part->getText();
				if ( null !== $text && '' !== $text ) {
					$content_blocks[] = array(
						'type' => 'text',
						'text' => $text,
					);
				}
				if ( null !== $call ) {
					$has_tool_calls = true;
					$tool_use_block = array(
						'type'  => 'tool_use',
						'id'    => $call->getId() ?? uniqid( 'tool_' ),
						'name'  => $call->getName() ?? '',
						'input' => $call->getArgs() ?? array(),
					);

					$thought_signature = $part->getThoughtSignature();
					if ( null !== $thought_signature && '' !== $thought_signature ) {
						$tool_use_block['thought_signature'] = $thought_signature;
					}

					$content_blocks[] = $tool_use_block;
				}
			}
		}

		$token_usage = $result->getTokenUsage();
		$model       = $result->getModelMetadata()->getId();

		return array(
			'stop_reason' => $has_tool_calls ? 'tool_use' : 'end_turn',
			'content'     => $content_blocks,
			'usage'       => array(
				'prompt'     => $token_usage->getPromptTokens(),
				'completion' => $token_usage->getCompletionTokens(),
				'total'      => $token_usage->getTotalTokens(),
			),
			'model'       => $model,
		);
	}

	// -------------------------------------------------------------------------
	// Tool definitions
	// -------------------------------------------------------------------------

	/**
	 * Schema table for every tool the AI can invoke.
	 *
	 * Each entry: tool-name => [ description, fields => [ name => description|spec, ... ] ].
	 * Most fields are required strings. Tools can pass a field spec array with
	 * `type`, `description`, and optional `required => false` for other shapes.
	 *
	 * get_function_declarations() expands this into FunctionDeclaration objects.
	 */
	private const TOOL_SCHEMAS = array(
		'list_files'        => array(
			'description' => 'List files and sub-directories inside a directory within an allowed root.',
			'fields'      => array(
				'path' => 'Absolute filesystem path of the directory to list.',
			),
		),
		'read_file'         => array(
			'description' => 'Read and return the full text content of a file within an allowed root.',
			'fields'      => array(
				'path' => 'Absolute filesystem path of the file to read.',
			),
		),
		'search_files'      => array(
			'description' => 'Search text content inside files under the allowed roots. This is a PHP-based scanner for shared hosting, not shell grep. Use before reading many files manually when looking for hooks, functions, classes, shortcodes, option names, strings, or other code references. Empty strings use defaults for path, mode, extensions, and max_results.',
			'fields'      => array(
				'query'       => 'Text or regex to search for.',
				'path'        => 'Absolute allowed directory/file to search, or empty string to search all allowed roots.',
				'mode'        => '"literal" for case-insensitive text search, "regex" for preg_match search, or empty string for literal.',
				'extensions'  => 'Comma-separated allowed extensions to scan (for example "php,js"), or empty string for all allowed extensions.',
				'max_results' => 'Maximum matches to return, 1-100, or empty string for the default.',
			),
		),
		'fetch_url'         => array(
			'description' => 'Fetch the text content of a public HTTP/HTTPS URL for reference. Private/internal addresses are blocked.',
			'fields'      => array(
				'url' => 'Fully-qualified public HTTP or HTTPS URL to fetch.',
			),
		),
		'run_query'         => array(
			'description' => 'Run a SQL query via $wpdb. Calling this tool opens an approval UI for the user; they will see the SQL and the reason and confirm before it executes. You must invoke this tool to trigger the approval — describing the query in plain text does nothing.',
			'fields'      => array(
				'sql'    => 'The SQL query to execute.',
				'reason' => 'Human-readable explanation of what this query does and why.',
			),
		),
		'write_file'        => array(
			'description' => 'Write new content to a file within an allowed root. Calling this tool opens an approval UI for the user; they will see the diff and the reason and confirm before it is written. Include the complete file content, not a diff. Only one write per turn is accepted. You must invoke this tool to trigger the approval — describing the change in plain text does nothing.',
			'fields'      => array(
				'path'    => 'Absolute filesystem path of the file to write.',
				'content' => 'Complete new file content (full replacement, not a diff).',
				'reason'  => 'Human-readable explanation of what this change does and why.',
			),
		),
		'edit'              => array(
			'description' => 'Modify an existing file by exact string replacement. Calling this tool opens an approval UI for the user; they will see the diff and confirm before it is written. Prefer this for small edits to existing files. oldString must be copied exactly from the current file and should match once unless replaceAll is true.',
			'fields'      => array(
				'filePath'   => 'Absolute filesystem path of the existing file to edit.',
				'oldString'  => 'Exact text currently in the file. Preserve indentation, whitespace, and newlines exactly.',
				'newString'  => 'Replacement text. Use an empty string to delete oldString.',
				'replaceAll' => array(
					'type'        => 'boolean',
					'description' => 'Whether to replace every occurrence of oldString. Defaults to false.',
					'required'    => false,
				),
				'reason'     => 'Human-readable explanation of what this edit does and why.',
			),
		),
		'delete_file'       => array(
			'description' => 'Delete a file within an allowed root. Calling this tool opens an approval UI for the user; they confirm before deletion. A backup is created automatically. You must invoke this tool to trigger the approval — describing the deletion in plain text does nothing.',
			'fields'      => array(
				'path'   => 'Absolute filesystem path of the file to delete.',
				'reason' => 'Human-readable explanation of why this file should be deleted.',
			),
		),
		'move_file'         => array(
			'description' => 'Move or rename a file within the allowed roots. Calling this tool opens an approval UI for the user; they confirm before the move. A backup of the source is created automatically. You must invoke this tool to trigger the approval — describing the move in plain text does nothing.',
			'fields'      => array(
				'src'    => 'Absolute filesystem path of the source file.',
				'dest'   => 'Absolute filesystem path of the destination.',
				'reason' => 'Human-readable explanation of why this file is being moved.',
			),
		),
		'copy_file'         => array(
			'description' => 'Copy a file within the allowed roots. Calling this tool opens an approval UI for the user; they confirm before the copy. The destination is backed up if it already exists. You must invoke this tool to trigger the approval — describing the copy in plain text does nothing.',
			'fields'      => array(
				'src'    => 'Absolute filesystem path of the source file.',
				'dest'   => 'Absolute filesystem path of the destination.',
				'reason' => 'Human-readable explanation of why this file is being copied.',
			),
		),
		'delete_dir'        => array(
			'description' => 'Recursively delete a directory and all its contents. Calling this tool opens an approval UI for the user; they confirm before deletion. All files are backed up automatically. Root directories cannot be deleted. You must invoke this tool to trigger the approval — describing the deletion in plain text does nothing.',
			'fields'      => array(
				'path'   => 'Absolute filesystem path of the directory to delete.',
				'reason' => 'Human-readable explanation of why this directory should be deleted.',
			),
		),
		'list_plugins'      => array(
			'description' => 'List all installed WordPress plugins with their activation status, version, and plugin file path. Use this to discover what is installed before installing or activating anything.',
			'fields'      => array(),
		),
		'install_plugin'    => array(
			'description' => 'Install a plugin from WordPress.org by its slug. Calling this tool opens an approval UI for the user; they confirm before anything is downloaded or installed. You must invoke this tool to trigger the approval — describing the install in plain text does nothing.',
			'fields'      => array(
				'slug'   => 'The WordPress.org plugin slug, e.g. "woocommerce".',
				'reason' => 'Human-readable explanation of why this plugin should be installed.',
			),
		),
		'activate_plugin'   => array(
			'description' => 'Activate an already-installed WordPress plugin. Calling this tool opens an approval UI for the user; they confirm before activation. Use list_plugins first to get the correct plugin file path. You must invoke this tool to trigger the approval — describing the activation in plain text does nothing.',
			'fields'      => array(
				'plugin' => 'Plugin file path relative to the plugins directory, e.g. "woocommerce/woocommerce.php".',
				'reason' => 'Human-readable explanation of why this plugin is being activated.',
			),
		),
		'deactivate_plugin' => array(
			'description' => 'Deactivate an active WordPress plugin. Calling this tool opens an approval UI for the user; they confirm before deactivation. You must invoke this tool to trigger the approval — describing the deactivation in plain text does nothing.',
			'fields'      => array(
				'plugin' => 'Plugin file path relative to the plugins directory, e.g. "woocommerce/woocommerce.php".',
				'reason' => 'Human-readable explanation of why this plugin is being deactivated.',
			),
		),
		'run_php'           => array(
			'description' => 'Execute a PHP code snippet in the WordPress context. Calling this tool opens an approval UI for the user; they see the code and the reason and confirm before execution. Output is captured and returned. Use for tasks that cannot be done via SQL or file writes alone (creating posts/pages, calling WP APIs, etc.). You must invoke this tool to trigger the approval — describing the snippet in plain text does nothing.',
			'fields'      => array(
				'code'   => 'PHP code to execute (without an opening <?php tag).',
				'reason' => 'Human-readable explanation of what this code does and why.',
			),
		),
		'list_backups'      => array(
			'description' => 'List available backup files created by this plugin. Always call this tool when the user asks about backups or wants to restore a file — never assume or guess what backups exist. Returns backup_file names (needed for restore_backup), original filenames, and timestamps.',
			'fields'      => array(
				'path' => array(
					'type'        => 'string',
					'description' => 'Optional absolute path of the original file to filter backups for. Leave empty to list all backups.',
					'required'    => false,
				),
			),
		),
		'restore_backup'    => array(
			'description' => 'Restore a file from a specific backup. Calling this tool opens an approval UI for the user; they confirm before the restore happens. Use list_backups first to find the correct backup_file name. A new backup of the current file is created before restoring so the restore is itself reversible.',
			'fields'      => array(
				'backup_file'   => 'The backup filename (e.g. functions.php.1746960123.abc123.bak) as returned by list_backups.',
				'original_path' => 'Absolute filesystem path where the file should be restored.',
				'reason'        => 'Human-readable explanation of why this backup is being restored.',
			),
		),
	);

	/**
	 * Return FunctionDeclaration objects for all tools the AI can invoke.
	 * Uses JSON Schema for parameters — compatible with all major AI providers.
	 */
	private function get_function_declarations(): array {
		$declarations = array();
		foreach ( self::TOOL_SCHEMAS as $name => $spec ) {
			$properties = array();
			$required   = array();
			foreach ( $spec['fields'] as $field => $field_spec ) {
				$is_required = true;
				if ( is_array( $field_spec ) ) {
					$type        = $field_spec['type'] ?? 'string';
					$description = $field_spec['description'] ?? '';
					$is_required = $field_spec['required'] ?? true;
				} else {
					$type        = 'string';
					$description = $field_spec;
				}
				$properties[ $field ] = array(
					'type'        => $type,
					'description' => $description,
				);
				if ( $is_required ) {
					$required[] = $field;
				}
			}
			$declarations[] = new FunctionDeclaration(
				$name,
				$spec['description'],
				array(
					'type'       => 'object',
					// JSON Schema requires `properties` to be an object, not an
					// array — empty PHP arrays would serialise as [] which some
					// providers reject.
					'properties' => empty( $properties ) ? new \stdClass() : $properties,
					'required'   => $required,
				)
			);
		}
		return $declarations;
	}
}
