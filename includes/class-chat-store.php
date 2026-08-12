<?php
/**
 * Chat Store — persists chat history per user via wp_usermeta.
 *
 * Owns the index meta key, the per-chat payload meta keys, and all
 * AJAX endpoints the browser calls to list/save/load/delete chats
 * plus the apply-counter increment.
 */

defined( 'ABSPATH' ) || exit;

class Haydi_Chat_Store extends Haydi_Ajax_Tool_Base {

	/** Hard cap on the JSON-encoded chat history a single payload may carry (1 MB). */
	const MAX_MESSAGES_BYTES = 1048576;

	/** Maximum saved chats per user. */
	const MAX_CHATS = 100;

	/** Maximum generated chat title length stored in the index. */
	const MAX_TITLE_CHARS = 80;

	const TITLE_SOURCE_FALLBACK = 'fallback';
	const TITLE_SOURCE_AI       = 'ai';

	/** @var Haydi_AI_Client AI client used for background title generation. */
	private Haydi_AI_Client $client;

	public function __construct( Haydi_Audit_Logger $logger, ?Haydi_AI_Client $client = null ) {
		parent::__construct( $logger );
		$this->client = $client ?? new Haydi_AI_Client();
	}

	public function register(): void {
		foreach ( array(
			'haydi_list_chats'          => 'handle_list_chats',
			'haydi_save_chat'           => 'handle_save_chat',
			'haydi_load_chat'           => 'handle_load_chat',
			'haydi_delete_chat'         => 'handle_delete_chat',
			'haydi_record_apply'        => 'handle_record_apply',
			'haydi_generate_chat_title' => 'handle_generate_chat_title',
		) as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	/**
	 * Drop the oldest entries until the JSON-encoded list fits within $byte_cap.
	 *
	 * Always preserves at least the final entry so the caller still has
	 * something to act on — better to lose history than to fail outright.
	 *
	 * Each item is encoded once up front; the running total is then decremented
	 * by the recorded item size as we drop from the front, so trimming a long
	 * chat is O(n) rather than O(n²) from re-encoding the whole array
	 * each iteration.
	 *
	 * Static so the chat dispatcher can call it without holding a store instance.
	 */
	public static function trim_messages_to_fit( array $messages, int $byte_cap ): array {
		$count = count( $messages );
		if ( $count <= 1 ) {
			return $messages;
		}

		$sizes = array();
		foreach ( $messages as $msg ) {
			$enc     = wp_json_encode( $msg );
			$sizes[] = ( false === $enc ) ? 0 : strlen( $enc );
		}

		// Total JSON length: opening "[" + items + (count-1) commas + closing "]".
		$total = 2 + array_sum( $sizes ) + ( $count - 1 );

		$drop = 0;
		// Each drop removes one item plus the comma that joined it to the next.
		while ( $total > $byte_cap && ( $count - $drop ) > 1 ) {
			$total -= $sizes[ $drop ] + 1;
			++$drop;
		}

		return $drop > 0 ? array_slice( $messages, $drop ) : $messages;
	}

	private static function normalize_title_source( string $source ): string {
		return self::TITLE_SOURCE_AI === sanitize_key( $source )
			? self::TITLE_SOURCE_AI
			: self::TITLE_SOURCE_FALLBACK;
	}

	private static function clean_title(
		string $title,
		string $fallback = 'Chat',
		int $max_chars = self::MAX_TITLE_CHARS,
		bool $strip_label = false
	): string {
		$lines = preg_split( '/[\r\n]+/', $title );
		$title = is_array( $lines ) ? ( $lines[0] ?? '' ) : $title;
		$title = sanitize_text_field( $title );
		if ( $strip_label ) {
			$title = preg_replace( '/^\s*title\s*:\s*/i', '', (string) $title );
		}
		$title = preg_replace( '/\s+/', ' ', $title );
		$title = trim( (string) $title, " \t\n\r\0\x0B\"'`*#" );
		$title = rtrim( $title, '.!?;:,-' );

		if ( '' === $title ) {
			$title = $fallback;
		}

		if ( $max_chars > 0 ) {
			if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
				if ( mb_strlen( $title ) > $max_chars ) {
					$title = mb_substr( $title, 0, $max_chars );
				}
			} elseif ( strlen( $title ) > $max_chars ) {
				$title = substr( $title, 0, $max_chars );
			}
		}

		return $title;
	}

	/**
	 * Return the chat index for the current user.
	 * Each entry: { id, title, created_at, updated_at }.
	 */
	public function handle_list_chats(): void {
		$this->verify();
		$index = get_user_meta( get_current_user_id(), 'haydi_chats_index', true );
		wp_send_json_success(
			array(
				'chats' => is_array( $index ) ? $index : array(),
				'cap'   => self::MAX_CHATS,
			)
		);
	}

	/**
	 * Save or update a chat.
	 * Receives: id (optional — empty string to create), title, messages (JSON).
	 */
	public function handle_save_chat(): void {
		$this->verify();

		$id           = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$title        = self::clean_title( (string) wp_unslash( $_POST['title'] ?? 'Chat' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$title_source = self::normalize_title_source( (string) wp_unslash( $_POST['title_source'] ?? self::TITLE_SOURCE_FALLBACK ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_messages = wp_unslash( $_POST['messages'] ?? '[]' );
		$messages     = json_decode( $raw_messages, true );

		if ( ! is_array( $messages ) || empty( $messages ) ) {
			wp_send_json_error( array( 'message' => 'messages is required.' ) );
		}
		$messages = Haydi_AI_Client::sanitize_public_transcript( $messages );
		if ( empty( $messages ) ) {
			wp_send_json_error( array( 'message' => 'messages is required.' ) );
		}

		// Trim the oldest turns if the encoded payload is over budget.
		if ( strlen( $raw_messages ) > self::MAX_MESSAGES_BYTES ) {
			$messages = self::trim_messages_to_fit( $messages, self::MAX_MESSAGES_BYTES );
		}

		// Optional: verbatim display log of the rendered chat — what the user saw.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_display = wp_unslash( $_POST['display'] ?? '[]' );
		$display     = json_decode( $raw_display, true );

		if ( is_array( $display ) && strlen( $raw_display ) > self::MAX_MESSAGES_BYTES ) {
			$display = self::trim_messages_to_fit( $display, self::MAX_MESSAGES_BYTES );
		}
		if ( ! is_array( $display ) ) {
			$display = array();
		}
		$clean_display = array();
		foreach ( $display as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$role = isset( $entry['role'] ) ? sanitize_key( $entry['role'] ) : '';
			if ( '' === $role ) {
				continue;
			}
			$clean_entry = array(
				'role' => $role,
				'text' => isset( $entry['text'] ) ? (string) $entry['text'] : '',
			);
			// Preserve the per-message timestamp (Unix milliseconds) when the
			// browser sent one. Older logs predating timestamps simply omit it.
			if ( isset( $entry['t'] ) && is_numeric( $entry['t'] ) && $entry['t'] > 0 ) {
				$clean_entry['t'] = (int) $entry['t'];
			}
			$clean_display[] = $clean_entry;
		}

		// Optional: cumulative token usage for this chat.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_usage   = wp_unslash( $_POST['usage'] ?? '' );
		$usage_input = '' !== $raw_usage ? json_decode( $raw_usage, true ) : null;
		$clean_usage = null;
		if ( is_array( $usage_input ) ) {
			$clean_usage = array(
				'prompt'      => max( 0, (int) ( $usage_input['prompt'] ?? 0 ) ),
				'completion'  => max( 0, (int) ( $usage_input['completion'] ?? 0 ) ),
				'total'       => max( 0, (int) ( $usage_input['total'] ?? 0 ) ),
				'prompt_peak' => max( 0, (int) ( $usage_input['prompt_peak'] ?? 0 ) ),
				'prompt_last' => max( 0, (int) ( $usage_input['prompt_last'] ?? 0 ) ),
				'model'       => isset( $usage_input['model'] ) ? substr( sanitize_text_field( (string) $usage_input['model'] ), 0, 128 ) : '',
			);
		}

		$user_id = get_current_user_id();
		$now     = time();

		if ( '' === $id ) {
			$id = 'chat_' . $now . '_' . bin2hex( random_bytes( 8 ) );
		}

		// Store full message history under its own meta key.
		update_user_meta( $user_id, 'haydi_chat_' . $id, $messages );

		// Store the display log alongside, or remove it if empty.
		if ( ! empty( $clean_display ) ) {
			update_user_meta( $user_id, 'haydi_chat_display_' . $id, $clean_display );
		} else {
			delete_user_meta( $user_id, 'haydi_chat_display_' . $id );
		}

		// Update index entry.
		$index = get_user_meta( $user_id, 'haydi_chats_index', true );
		if ( ! is_array( $index ) ) {
			$index = array();
		}

		$found              = false;
		$saved_title        = $title;
		$saved_title_source = $title_source;
		foreach ( $index as &$entry ) {
			if ( $entry['id'] === $id ) {
				$existing_title_source = self::normalize_title_source( (string) ( $entry['title_source'] ?? self::TITLE_SOURCE_FALLBACK ) );
				if ( self::TITLE_SOURCE_AI !== $existing_title_source || self::TITLE_SOURCE_AI === $title_source ) {
					$entry['title']        = $title;
					$entry['title_source'] = $title_source;
				} else {
					$saved_title        = (string) ( $entry['title'] ?? $title );
					$saved_title_source = $existing_title_source;
				}
				$entry['updated_at'] = $now;
				if ( null !== $clean_usage ) {
					$entry['usage'] = $clean_usage;
				}
				$found = true;
				break;
			}
		}
		unset( $entry );

		if ( ! $found ) {
			$new_entry = array(
				'id'           => $id,
				'title'        => $title,
				'title_source' => $title_source,
				'created_at'   => $now,
				'updated_at'   => $now,
			);
			if ( null !== $clean_usage ) {
				$new_entry['usage'] = $clean_usage;
			}
			array_unshift( $index, $new_entry );
		}

		// Sort newest-first and enforce the cap.
		usort( $index, static fn( $a, $b ) => $b['updated_at'] - $a['updated_at'] );

		if ( count( $index ) > self::MAX_CHATS ) {
			$dropped = array_slice( $index, self::MAX_CHATS );
			foreach ( $dropped as $d ) {
				delete_user_meta( $user_id, 'haydi_chat_' . $d['id'] );
				delete_user_meta( $user_id, 'haydi_chat_display_' . $d['id'] );
			}
			$index = array_slice( $index, 0, self::MAX_CHATS );
		}

		update_user_meta( $user_id, 'haydi_chats_index', $index );

		wp_send_json_success(
			array(
				'id'           => $id,
				'title'        => $saved_title,
				'title_source' => $saved_title_source,
			)
		);
	}

	/**
	 * Return the full message array for a given chat id.
	 */
	public function handle_load_chat(): void {
		$this->verify();

		$id = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $id ) {
			wp_send_json_error( array( 'message' => 'id is required.' ) );
		}

		$user_id  = get_current_user_id();
		$messages = get_user_meta( $user_id, 'haydi_chat_' . $id, true );
		if ( ! is_array( $messages ) ) {
			wp_send_json_error( array( 'message' => 'Chat not found.' ) );
		}
		$messages = Haydi_AI_Client::sanitize_public_transcript( $messages );

		$display = get_user_meta( $user_id, 'haydi_chat_display_' . $id, true );
		if ( ! is_array( $display ) ) {
			$display = array();
		}

		$apply_count  = 0;
		$usage        = null;
		$title        = 'Chat';
		$title_source = self::TITLE_SOURCE_FALLBACK;
		$index        = get_user_meta( $user_id, 'haydi_chats_index', true );
		if ( is_array( $index ) ) {
			foreach ( $index as $entry ) {
				if ( $entry['id'] === $id ) {
					$apply_count  = $entry['apply_count'] ?? 0;
					$usage        = $entry['usage'] ?? null;
					$title        = (string) ( $entry['title'] ?? $title );
					$title_source = self::normalize_title_source( (string) ( $entry['title_source'] ?? self::TITLE_SOURCE_FALLBACK ) );
					break;
				}
			}
		}

		wp_send_json_success(
			array(
				'messages'     => $messages,
				'display'      => $display,
				'apply_count'  => $apply_count,
				'usage'        => $usage,
				'title'        => $title,
				'title_source' => $title_source,
			)
		);
	}

	/**
	 * Generate and persist a short AI title for an existing chat.
	 */
	public function handle_generate_chat_title(): void {
		$this->verify();

		$id = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $id ) {
			wp_send_json_error( array( 'message' => 'id is required.' ) );
		}

		$user_id = get_current_user_id();
		$index   = get_user_meta( $user_id, 'haydi_chats_index', true );
		if ( ! is_array( $index ) ) {
			wp_send_json_error( array( 'message' => 'Chat not found.' ) );
		}

		$entry_index = null;
		foreach ( $index as $i => $entry ) {
			if ( ( $entry['id'] ?? '' ) === $id ) {
				$entry_index = $i;
				break;
			}
		}
		if ( null === $entry_index ) {
			wp_send_json_error( array( 'message' => 'Chat not found.' ) );
		}

		$existing_title        = (string) ( $index[ $entry_index ]['title'] ?? 'Chat' );
		$existing_title_source = self::normalize_title_source( (string) ( $index[ $entry_index ]['title_source'] ?? self::TITLE_SOURCE_FALLBACK ) );
		if ( self::TITLE_SOURCE_AI === $existing_title_source ) {
			wp_send_json_success(
				array(
					'title'        => $existing_title,
					'title_source' => self::TITLE_SOURCE_AI,
					'generated'    => false,
				)
			);
		}

		$messages = get_user_meta( $user_id, 'haydi_chat_' . $id, true );
		if ( ! is_array( $messages ) ) {
			wp_send_json_error( array( 'message' => 'Chat not found.' ) );
		}
		$messages = Haydi_AI_Client::sanitize_public_transcript( $messages );

		$context = self::title_context_from_messages( $messages );
		if ( '' === $context || ! self::has_assistant_text( $messages ) ) {
			wp_send_json_error( array( 'message' => 'Chat needs an assistant response before a title can be generated.' ) );
		}

		$response = $this->client->send_messages(
			array(
				array(
					'role'    => 'user',
					'content' => "Chat:\n\n" . $context,
				),
			),
			$this->build_title_generation_prompt(),
			false,
			$this->get_model_preference_from_request()
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$title = self::clean_title( self::response_text( $response ), '', self::MAX_TITLE_CHARS, true );
		if ( '' === $title ) {
			wp_send_json_error( array( 'message' => 'The model did not return a usable title.' ) );
		}

		$index[ $entry_index ]['title']        = $title;
		$index[ $entry_index ]['title_source'] = self::TITLE_SOURCE_AI;
		update_user_meta( $user_id, 'haydi_chats_index', $index );

		wp_send_json_success(
			array(
				'title'        => $title,
				'title_source' => self::TITLE_SOURCE_AI,
				'generated'    => true,
			)
		);
	}

	private function build_title_generation_prompt(): string {
		return implode(
			"\n",
			array(
				'Write a concise sentence-case title for this WordPress admin assistant chat.',
				'Rules:',
				'- Return only the title.',
				'- Use 2 to 6 words.',
				'- Use sentence case, not title case.',
				'- No quotes, markdown, labels, or trailing punctuation.',
				'- Preserve acronyms, proper nouns, plugin names, theme names, file names, and feature names when useful.',
			)
		);
	}

	private static function title_context_from_messages( array $messages ): string {
		$lines = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			$role = (string) ( $message['role'] ?? '' );
			if ( 'user' !== $role && 'assistant' !== $role ) {
				continue;
			}
			$text = self::message_text( $message );
			$text = (string) preg_replace( '/\s+/', ' ', trim( $text ) );
			if ( '' === $text ) {
				continue;
			}
			$lines[] = ucfirst( $role ) . ': ' . self::truncate_text( $text, 800 );
			if ( strlen( implode( "\n\n", $lines ) ) >= 4000 ) {
				break;
			}
		}

		return self::truncate_text( implode( "\n\n", $lines ), 4000 );
	}

	private static function has_assistant_text( array $messages ): bool {
		foreach ( $messages as $message ) {
			if ( is_array( $message ) && 'assistant' === ( $message['role'] ?? '' ) && '' !== trim( self::message_text( $message ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function message_text( array $message ): string {
		$content = $message['content'] ?? '';
		if ( is_string( $content ) ) {
			return $content;
		}
		if ( ! is_array( $content ) ) {
			return '';
		}

		$text = '';
		foreach ( $content as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) && isset( $block['text'] ) ) {
				$text .= "\n" . (string) $block['text'];
			}
		}
		return trim( $text );
	}

	private static function response_text( array $response ): string {
		$text = '';
		foreach ( $response['content'] ?? array() as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) && isset( $block['text'] ) ) {
				$text .= "\n" . (string) $block['text'];
			}
		}
		return trim( $text );
	}

	private static function truncate_text( string $text, int $max_chars ): string {
		if ( $max_chars <= 0 ) {
			return '';
		}
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text ) > $max_chars ? mb_substr( $text, 0, $max_chars ) : $text;
		}
		return strlen( $text ) > $max_chars ? substr( $text, 0, $max_chars ) : $text;
	}

	/**
	 * Increment the apply_count for a chat in the index.
	 */
	public function handle_record_apply(): void {
		$this->verify();

		$id = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $id ) {
			wp_send_json_error( array( 'message' => 'id is required.' ) );
		}

		$user_id = get_current_user_id();
		$index   = get_user_meta( $user_id, 'haydi_chats_index', true );
		if ( ! is_array( $index ) ) {
			wp_send_json_error( array( 'message' => 'No chats found.' ) );
		}

		$count = 0;
		foreach ( $index as &$entry ) {
			if ( $entry['id'] === $id ) {
				$entry['apply_count'] = ( $entry['apply_count'] ?? 0 ) + 1;
				$count                = $entry['apply_count'];
				break;
			}
		}
		unset( $entry );

		update_user_meta( $user_id, 'haydi_chats_index', $index );
		wp_send_json_success( array( 'apply_count' => $count ) );
	}

	/**
	 * Delete a chat by id.
	 */
	public function handle_delete_chat(): void {
		$this->verify();

		$id = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $id ) {
			wp_send_json_error( array( 'message' => 'id is required.' ) );
		}

		$user_id = get_current_user_id();
		delete_user_meta( $user_id, 'haydi_chat_' . $id );
		delete_user_meta( $user_id, 'haydi_chat_display_' . $id );

		$index = get_user_meta( $user_id, 'haydi_chats_index', true );
		if ( is_array( $index ) ) {
			$index = array_values( array_filter( $index, static fn( $e ) => $e['id'] !== $id ) );
			update_user_meta( $user_id, 'haydi_chats_index', $index );
		}

		wp_send_json_success( array( 'message' => 'Chat deleted.' ) );
	}
}
