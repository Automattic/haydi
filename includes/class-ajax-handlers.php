<?php
/**
 * AJAX dispatcher — runs the agentic chat loop and wires per-tool classes
 * onto their respective wp_ajax_* hooks.
 *
 * Security model:
 *  - Every action verifies current_user_can('manage_options') AND a nonce
 *    (handled by Haydi_Ajax_Tool_Base::verify on the per-tool classes,
 *    and inline by handle_chat / handle_save_settings / handle_clear_log
 *    / handle_dismiss_jetpack_notice on this class).
 *  - All filesystem paths are re-validated by Haydi_Filesystem_Guard.
 *  - Approval-gated tools (write_file, run_query, run_php, …) are NEVER
 *    executed automatically; the loop pauses and surfaces a "pending_*"
 *    payload that the human must explicitly approve.
 *  - The agentic loop runs server-side so list_files / read_file / fetch_url
 *    / list_plugins results flow back to the AI without a browser round-trip.
 */

defined( 'ABSPATH' ) || exit;

class Haydi_Ajax_Handlers extends Haydi_Ajax_Tool_Base {

	/** Maximum AI→Tool→AI iterations per user request. */
	const MAX_LOOP = 10;

	/**
	 * Tools that pause the agentic loop pending human approval.
	 *
	 * Each entry maps a tool name to:
	 *   - response_key:    JSON key the front-end expects (e.g. 'pending_write').
	 *   - fields:          tool-input keys to copy verbatim into the payload.
	 *   - log_action:      audit-log verb to record.
	 *   - log_path_field:  which field to record as the audit-log "path" column;
	 *                      empty string = log no path (run_query, run_php).
	 */
	const APPROVAL_TOOLS = array(
		'write_file'        => array(
			'response_key'   => 'pending_write',
			'fields'         => array( 'path', 'content', 'reason' ),
			'log_action'     => 'write_proposed',
			'log_path_field' => 'path',
		),
		'edit'              => array(
			'response_key'   => 'pending_edit',
			'fields'         => array( 'filePath', 'oldString', 'newString', 'replaceAll', 'reason' ),
			'log_action'     => 'edit_proposed',
			'log_path_field' => 'filePath',
		),
		'delete_file'       => array(
			'response_key'   => 'pending_delete',
			'fields'         => array( 'path', 'reason' ),
			'log_action'     => 'delete_proposed',
			'log_path_field' => 'path',
		),
		'move_file'         => array(
			'response_key'   => 'pending_move',
			'fields'         => array( 'src', 'dest', 'reason' ),
			'log_action'     => 'move_proposed',
			'log_path_field' => 'src',
		),
		'copy_file'         => array(
			'response_key'   => 'pending_copy',
			'fields'         => array( 'src', 'dest', 'reason' ),
			'log_action'     => 'copy_proposed',
			'log_path_field' => 'src',
		),
		'delete_dir'        => array(
			'response_key'   => 'pending_rmdir',
			'fields'         => array( 'path', 'reason' ),
			'log_action'     => 'rmdir_proposed',
			'log_path_field' => 'path',
		),
		'run_query'         => array(
			'response_key'   => 'pending_query',
			'fields'         => array( 'sql', 'reason' ),
			'log_action'     => 'query_proposed',
			'log_path_field' => '',
		),
		'install_plugin'    => array(
			'response_key'   => 'pending_install',
			'fields'         => array( 'slug', 'reason' ),
			'log_action'     => 'install_proposed',
			'log_path_field' => 'slug',
		),
		'activate_plugin'   => array(
			'response_key'   => 'pending_activate',
			'fields'         => array( 'plugin', 'reason' ),
			'log_action'     => 'activate_proposed',
			'log_path_field' => 'plugin',
		),
		'deactivate_plugin' => array(
			'response_key'   => 'pending_deactivate',
			'fields'         => array( 'plugin', 'reason' ),
			'log_action'     => 'deactivate_proposed',
			'log_path_field' => 'plugin',
		),
		'run_php'           => array(
			'response_key'   => 'pending_php',
			'fields'         => array( 'code', 'reason' ),
			'log_action'     => 'php_proposed',
			'log_path_field' => '',
		),
		'restore_backup'    => array(
			'response_key'   => 'pending_restore',
			'fields'         => array( 'backup_file', 'original_path', 'reason' ),
			'log_action'     => 'restore_proposed',
			'log_path_field' => 'original_path',
		),
	);

	/** @var Haydi_Filesystem_Guard Filesystem access guard. */
	private Haydi_Filesystem_Guard $guard;
	/** @var Haydi_AI_Client AI client. */
	private Haydi_AI_Client $client;
	/** @var Haydi_File_Tool File-ops tool, retained for read-tool dispatch. */
	private Haydi_File_Tool $file_tool;
	/** @var Haydi_Plugin_Tool Plugin-ops tool, retained for read-tool dispatch. */
	private Haydi_Plugin_Tool $plugin_tool;
	/** @var Haydi_Fetch_Url_Tool URL fetch tool, retained for read-tool dispatch. */
	private Haydi_Fetch_Url_Tool $url_tool;
	/** @var Haydi_Api_Token_Manager API token manager. */
	private Haydi_Api_Token_Manager $token_manager;

	public function __construct() {
		parent::__construct( new Haydi_Audit_Logger() );

		$this->guard         = new Haydi_Filesystem_Guard();
		$this->client        = new Haydi_AI_Client();
		$this->token_manager = new Haydi_Api_Token_Manager();
		$health              = new Haydi_Health_Check();

		// Construct each tool with its dependencies and let it register its
		// own AJAX hooks. Centralising registration here would force this class
		// to know every tool's hook name; delegating it keeps each tool a
		// self-contained unit.
		$this->file_tool   = new Haydi_File_Tool( $this->logger, $this->guard, $health );
		$this->plugin_tool = new Haydi_Plugin_Tool( $this->logger, $health );
		$this->url_tool    = new Haydi_Fetch_Url_Tool( $this->logger );
		$query_tool        = new Haydi_Query_Tool( $this->logger, $health );
		$php_tool          = new Haydi_PHP_Tool( $this->logger, $health );
		$chat_store        = new Haydi_Chat_Store( $this->logger, $this->client );

		$this->file_tool->register();
		$this->plugin_tool->register();
		$query_tool->register();
		$php_tool->register();
		$chat_store->register();
		$this->register();
	}

	public function register(): void {
		// Lightweight health-check endpoint used after a write to verify WordPress
		// still loads. Restricted to loopback callers so it cannot be used by
		// unauthenticated remote clients to fingerprint the plugin. Only registered
		// during admin-ajax requests so it does not exist on regular page loads.
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			add_action(
				'wp_ajax_nopriv_haydi_health',
				function () {
					$remote = $_SERVER['REMOTE_ADDR'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
					if ( '127.0.0.1' !== $remote && '::1' !== $remote ) {
						wp_send_json_error( array( 'message' => 'Not found.' ), 404 );
					}
					wp_send_json_success( 'ok' );
				}
			);
		}

		foreach ( array(
			'haydi_chat'                   => 'handle_chat',
			'haydi_chat_stream'            => 'handle_chat_stream',
			'haydi_compact_chat'           => 'handle_compact_chat',
			'haydi_save_settings'          => 'handle_save_settings',
			'haydi_clear_log'              => 'handle_clear_log',
			'haydi_get_audit_log'          => 'handle_get_audit_log',
			'haydi_dismiss_jetpack_notice' => 'handle_dismiss_jetpack_notice',
			'haydi_complete_onboarding'    => 'handle_complete_onboarding',
			'haydi_generate_token'         => 'handle_generate_token',
			'haydi_revoke_token'           => 'handle_revoke_token',
			'haydi_list_tokens'            => 'handle_list_tokens',
		) as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	// -------------------------------------------------------------------------
	// Chat / agentic loop
	// -------------------------------------------------------------------------

	/**
	 * Run the AI agentic loop server-side.
	 *
	 * Read tools (list_files / read_file / fetch_url / list_plugins) execute
	 * automatically. Approval tools (write_file, run_query, …) stop the loop
	 * and surface a pending_* payload for human approval.
	 */
	public function handle_chat(): void {
		$this->verify();

		$request = $this->prepare_chat_request();
		if ( is_wp_error( $request ) ) {
			$this->send_chat_error( $request );
		}

		$response = $this->run_chat_loop( $request['messages'], $request['model_preference'] );
		if ( is_wp_error( $response ) ) {
			$this->send_chat_error( $response );
		}

		wp_send_json_success( $response );
	}

	/**
	 * Run the chat loop and stream coarse progress events to the browser.
	 *
	 * This intentionally streams safe progress summaries, not raw tool results
	 * or hidden model reasoning. The final event carries the same payload as
	 * handle_chat() so the front-end can reuse the existing rendering logic.
	 */
	public function handle_chat_stream(): void {
		$this->verify();

		$request = $this->prepare_chat_request();
		$this->send_stream_headers();

		if ( is_wp_error( $request ) ) {
			$this->send_stream_event(
				'error',
				array( 'message' => $request->get_error_message() )
			);
			exit;
		}

		$emit = function ( string $event, array $data = array() ): void {
			$this->send_stream_event( $event, $data );
		};

		$emit( 'status', array( 'message' => 'Working...' ) );

		$response = $this->run_chat_loop( $request['messages'], $request['model_preference'], $emit );
		if ( is_wp_error( $response ) ) {
			$emit( 'error', array( 'message' => $response->get_error_message() ) );
			exit;
		}

		$emit( 'final', $response );
		$emit( 'done' );
		exit;
	}

	/**
	 * Validate and normalize the current chat request.
	 *
	 * @return array|WP_Error {
	 *     @type array      $messages         Internal message history.
	 *     @type array|null $model_preference Optional [provider_id, model_id].
	 * }
	 */
	private function prepare_chat_request(): array|WP_Error {
		// Rate-limit: max 30 chat requests per user per minute.
		$rate_key = 'haydi_rl_' . get_current_user_id();
		$count    = (int) get_transient( $rate_key );
		if ( $count >= 30 ) {
			return new WP_Error(
				'rate_limit_exceeded',
				'Rate limit exceeded. Please wait before sending another message.',
				array( 'status' => 429 )
			);
		}
		set_transient( $rate_key, $count + 1, MINUTE_IN_SECONDS );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via $this->verify(); messages are JSON-decoded and validated
		$raw_messages = isset( $_POST['messages'] ) ? wp_unslash( $_POST['messages'] ) : '[]';
		$messages     = json_decode( $raw_messages, true );

		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return new WP_Error( 'messages_required', 'messages array is required.' );
		}
		$model_preference = $this->get_model_preference_from_request();

		// If the chat has grown beyond our budget, drop the oldest
		// turns so the request still succeeds with a shorter context window.
		if ( strlen( $raw_messages ) > Haydi_Chat_Store::MAX_MESSAGES_BYTES ) {
			$messages = Haydi_Chat_Store::trim_messages_to_fit( $messages, Haydi_Chat_Store::MAX_MESSAGES_BYTES );
		}

		return array(
			'messages'         => $messages,
			'model_preference' => $model_preference,
		);
	}

	/**
	 * Execute the AI/tool loop and return the final chat response payload.
	 *
	 * @param array         $messages Internal message history.
	 * @param array|null    $model_preference Optional [provider_id, model_id].
	 * @param callable|null $emit Optional progress emitter: fn( string $event, array $data = array() ).
	 * @return array|WP_Error Final response payload, or an error.
	 */
	private function run_chat_loop( array $messages, ?array $model_preference = null, ?callable $emit = null ): array|WP_Error {
		$system   = $this->build_system_prompt();
		$loops    = 0;
		$all_text = '';
		$usage    = array(
			'prompt'      => 0,
			'completion'  => 0,
			'total'       => 0,
			'calls'       => 0,
			'prompt_peak' => 0,
			'prompt_last' => 0,
			'model'       => '',
		);
		$activity = array();

		while ( $loops < self::MAX_LOOP ) {
			++$loops;

			$this->emit_chat_event(
				$emit,
				'status',
				array(
					'message' => 1 === $loops ? 'Thinking...' : 'Continuing with tool results...',
					'loop'    => $loops,
				)
			);

			$response = $this->client->send_messages( $messages, $system, true, $model_preference );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$stop_reason    = $response['stop_reason'] ?? 'end_turn';
			$content_blocks = $response['content'] ?? array();

			if ( isset( $response['usage'] ) ) {
				$prompt_tokens        = (int) $response['usage']['prompt'];
				$usage['prompt']     += $prompt_tokens;
				$usage['completion'] += (int) $response['usage']['completion'];
				$usage['total']      += (int) $response['usage']['total'];
				++$usage['calls'];
				$usage['prompt_last'] = $prompt_tokens;
				$usage['prompt_peak'] = max( $usage['prompt_peak'], $prompt_tokens );
			}
			if ( ! empty( $response['model'] ) ) {
				$usage['model'] = $response['model'];
			}

			$tool_results    = array();
			$pending_key     = null;
			$pending_payload = null;
			$text_before     = $all_text;

			foreach ( $content_blocks as $block ) {
				if ( 'text' === $block['type'] ) {
					if ( '' !== $all_text && '' !== $block['text'] ) {
						$last  = substr( $all_text, -1 );
						$first = $block['text'][0];
						if ( ' ' !== $last && "\n" !== $last && ' ' !== $first && "\n" !== $first ) {
							$all_text .= ' ';
						}
					}
					$all_text .= $block['text'];
					continue;
				}

				if ( 'tool_use' !== $block['type'] ) {
					continue;
				}

				$tool_name = $block['name'] ?? '';
				$tool_id   = $block['id'] ?? '';
				$input     = $block['input'] ?? array();

				$proposal_spec = self::APPROVAL_TOOLS[ $tool_name ] ?? null;

				if ( null !== $proposal_spec ) {
					$this->emit_chat_event(
						$emit,
						'tool_start',
						array(
							'name'    => $tool_name,
							'label'   => $this->tool_activity_label( $tool_name ),
							'summary' => 'Preparing approval request.',
						)
					);

					if ( null !== $pending_payload ) {
						$tool_results[] = array(
							'type'        => 'tool_result',
							'tool_use_id' => $tool_id,
							'content'     => 'Deferred: only one action can be approved at a time. Propose this again after the current action is approved.',
						);
						continue;
					}

					$payload = array( 'tool_use_id' => $tool_id );
					foreach ( $proposal_spec['fields'] as $field ) {
						$payload[ $field ] = $input[ $field ] ?? '';
					}
					$log_path = '' !== $proposal_spec['log_path_field']
						? ( $payload[ $proposal_spec['log_path_field'] ] ?? '' )
						: '';
					$this->logger->log( $proposal_spec['log_action'], $log_path, $payload['reason'] ?? '' );

					$pending_key     = $proposal_spec['response_key'];
					$pending_payload = $payload;
					continue;
				}

				// Execute safe read-only tools.
				$this->emit_chat_event(
					$emit,
					'tool_start',
					array(
						'name'    => $tool_name,
						'label'   => $this->tool_activity_label( $tool_name ),
						'summary' => $this->summarize_tool_start_activity( $tool_name, $input ),
					)
				);
				$result        = $this->execute_read_tool( $tool_name, $input );
				$activity_item = $this->build_tool_activity( $tool_name, $input, $result );
				if ( null !== $activity_item ) {
					$activity[] = $activity_item;
				}
				$this->emit_chat_event(
					$emit,
					'tool_done',
					array(
						'name'     => $tool_name,
						'activity' => $activity_item,
						'status'   => is_string( $result ) && str_starts_with( $result, 'Error: ' ) ? 'error' : 'ok',
					)
				);
				$tool_results[] = array(
					'type'        => 'tool_result',
					'tool_use_id' => $tool_id,
					'content'     => is_string( $result ) ? $result : wp_json_encode( $result ),
				);
			}

			// Append assistant turn to history.
			$messages[] = array(
				'role'    => 'assistant',
				'content' => $content_blocks,
			);

			if ( $all_text !== $text_before ) {
				$this->emit_chat_event(
					$emit,
					'assistant_text',
					array( 'text' => $all_text )
				);
			}

			// Surface the pending action (if any) for human approval.
			if ( null !== $pending_payload ) {
				$pending_payload['pre_results'] = $tool_results;
				return array(
					'text'       => $all_text,
					'messages'   => $messages,
					'usage'      => $usage,
					'activity'   => $activity,
					$pending_key => $pending_payload,
				);
			}

			if ( 'end_turn' === $stop_reason || empty( $tool_results ) ) {
				return array(
					'text'     => $all_text,
					'messages' => $messages,
					'usage'    => $usage,
					'activity' => $activity,
				);
			}

			// Feed tool results back and loop.
			$messages[] = array(
				'role'    => 'user',
				'content' => $tool_results,
			);
		}

		return new WP_Error( 'max_loop_reached', 'Reached the maximum number of tool-call iterations.' );
	}

	/**
	 * Send a JSON error response for chat endpoints.
	 */
	private function send_chat_error( WP_Error $error ): void {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		if ( $status > 0 ) {
			wp_send_json_error( array( 'message' => $error->get_error_message() ), $status );
		}

		wp_send_json_error( array( 'message' => $error->get_error_message() ) );
	}

	/**
	 * Emit a progress event when streaming is active.
	 */
	private function emit_chat_event( ?callable $emit, string $event, array $data = array() ): void {
		if ( null !== $emit ) {
			$emit( $event, $data );
		}
	}

	/**
	 * Prepare HTTP headers for a server-sent event stream.
	 */
	private function send_stream_headers(): void {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/event-stream; charset=utf-8' );
			header( 'Cache-Control: no-cache, no-transform' );
			header( 'X-Accel-Buffering: no' );
		}

		ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged

		while ( ob_get_level() > 0 ) {
			if ( ! @ob_end_flush() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				break;
			}
		}
	}

	/**
	 * Send one server-sent event and flush it immediately when possible.
	 */
	private function send_stream_event( string $event, array $data = array() ): void {
		$encoded = wp_json_encode( $data );
		if ( false === $encoded ) {
			$encoded = '{}';
		}

		$event = preg_replace( '/[^A-Za-z0-9_.-]/', '', $event );
		echo 'event: ' . $event . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo 'data: ' . $encoded . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		flush();
	}

	/**
	 * Summarize the current chat and replace it with a compact handoff.
	 */
	public function handle_compact_chat(): void {
		$this->verify();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified via $this->verify(); messages are JSON-decoded and validated
		$raw_messages = isset( $_POST['messages'] ) ? wp_unslash( $_POST['messages'] ) : '[]';
		$messages     = json_decode( $raw_messages, true );

		if ( ! is_array( $messages ) || empty( $messages ) ) {
			wp_send_json_error( array( 'message' => 'messages array is required.' ) );
		}
		$model_preference = $this->get_model_preference_from_request();

		$summary_input = wp_json_encode( $messages );
		if ( false === $summary_input ) {
			wp_send_json_error( array( 'message' => 'Could not encode chat for compaction.' ) );
		}

		$system = $this->build_compaction_prompt();
		$user   = "Summarize this WordPress agent chat so another assistant can continue from the summary.\n\nChat JSON:\n" . $summary_input;

		$response = $this->client->send_messages(
			array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			$system,
			false,
			$model_preference
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$summary = $this->extract_text_from_content_blocks( $response['content'] ?? array() );
		$summary = trim( $summary );

		if ( strlen( $summary ) < 80 ) {
			wp_send_json_error( array( 'message' => 'Compaction produced a summary that was too short. Please try again.' ) );
		}

		wp_send_json_success(
			array(
				'summary'  => $summary,
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => "Previous chat summary:\n\n" . $summary,
					),
					array(
						'role'    => 'assistant',
						'content' => 'I have the context from the previous chat and will continue from it.',
					),
				),
				'usage'    => array(
					'prompt'      => (int) ( $response['usage']['prompt'] ?? 0 ),
					'completion'  => (int) ( $response['usage']['completion'] ?? 0 ),
					'total'       => (int) ( $response['usage']['total'] ?? 0 ),
					'calls'       => isset( $response['usage'] ) ? 1 : 0,
					'prompt_peak' => (int) ( $response['usage']['prompt'] ?? 0 ),
					'prompt_last' => (int) ( $response['usage']['prompt'] ?? 0 ),
					'model'       => (string) ( $response['model'] ?? '' ),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	/**
	 * Save plugin settings (max output tokens) submitted from the settings form.
	 */
	public function handle_save_settings(): void {
		$this->verify();

		$max_tokens = isset( $_POST['max_tokens'] ) ? (int) $_POST['max_tokens'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by $this->verify().
		$playground_preflight = isset( $_POST['playground_preflight_enabled'] )
			? filter_var( wp_unslash( $_POST['playground_preflight_enabled'] ), FILTER_VALIDATE_BOOLEAN )
			: (bool) get_option( 'haydi_playground_preflight_enabled', false );

		$enable_tracks = isset( $_POST['enable_tracks'] )
			? filter_var( wp_unslash( $_POST['enable_tracks'] ), FILTER_VALIDATE_BOOLEAN )
			: (bool) get_option( 'haydi_enable_tracks', false );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $max_tokens < 1024 || $max_tokens > 32768 ) {
			wp_send_json_error(
				array( 'message' => 'Max response length must be a whole number between 1024 and 32768.' )
			);
			return;
		}

		$commit  = ! empty( $_POST['commit'] ) && '1' === $_POST['commit']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$changed = (int) get_option( 'haydi_max_tokens', 0 ) !== $max_tokens
			|| (bool) get_option( 'haydi_playground_preflight_enabled', false ) !== $playground_preflight
			|| (bool) get_option( 'haydi_enable_tracks', false ) !== $enable_tracks;

		update_option( 'haydi_max_tokens', $max_tokens, false );
		update_option( 'haydi_playground_preflight_enabled', $playground_preflight ? 1 : 0, false );
		update_option( 'haydi_enable_tracks', $enable_tracks ? 1 : 0, false );

		if ( $commit && $changed ) {
			$this->logger->log( 'settings_saved', '', 'Settings updated.' );
		}

		wp_send_json_success(
			array(
				'message'                      => 'Settings saved.',
				'playground_preflight_enabled' => $playground_preflight,
				'enable_tracks'                => $enable_tracks,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Audit log
	// -------------------------------------------------------------------------

	/**
	 * Return the most recent audit log entries (capped at 100) for the sidebar.
	 */
	public function handle_get_audit_log(): void {
		$this->verify();
		$entries = array_slice( $this->logger->get_log(), 0, 100 );
		wp_send_json_success( array( 'entries' => $entries ) );
	}

	/**
	 * Clear the audit log.
	 */
	public function handle_clear_log(): void {
		$this->verify();
		$this->logger->clear_log();
		$this->logger->log( 'log_cleared', '', 'Audit log cleared by admin.' );
		wp_send_json_success( array( 'message' => 'Log cleared.' ) );
	}

	// -------------------------------------------------------------------------
	// Jetpack onboarding notice
	// -------------------------------------------------------------------------

	/**
	 * Persist the Jetpack onboarding notice dismissal for the current user.
	 */
	public function handle_dismiss_jetpack_notice(): void {
		$this->verify();
		update_user_meta( get_current_user_id(), 'haydi_jetpack_notice_dismissed', '1' );
		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// API token management
	// -------------------------------------------------------------------------

	/**
	 * Generate a new API token and return it along with the updated token list.
	 * The plaintext token is returned only once; subsequent calls return prefixes.
	 */
	public function handle_generate_token(): void {
		$this->verify();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by $this->verify().
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$token = $this->token_manager->generate_token( $label );
		wp_send_json_success(
			array(
				'token'  => $token,
				'tokens' => $this->token_manager->get_tokens(),
			)
		);
	}

	/**
	 * Revoke an API token by its SHA-256 hash.
	 */
	public function handle_revoke_token(): void {
		$this->verify();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by $this->verify().
		$hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
		if ( '' === $hash ) {
			wp_send_json_error( array( 'message' => 'hash is required.' ) );
			return;
		}
		$this->token_manager->revoke_token( $hash );
		wp_send_json_success( array( 'tokens' => $this->token_manager->get_tokens() ) );
	}

	/**
	 * Return the current token list (prefix + label + created, no plaintext).
	 */
	public function handle_list_tokens(): void {
		$this->verify();
		wp_send_json_success( array( 'tokens' => $this->token_manager->get_tokens() ) );
	}

	/**
	 * Complete first-run onboarding: mark the site as onboarded and persist
	 * the analytics opt-in choice made on the welcome screen.
	 */
	public function handle_complete_onboarding(): void {
		$this->verify();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by $this->verify().
		$enable_tracks = isset( $_POST['enable_tracks'] )
			? filter_var( wp_unslash( $_POST['enable_tracks'] ), FILTER_VALIDATE_BOOLEAN )
			: false;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		update_option( 'haydi_onboarded', 1, false );
		if ( $enable_tracks ) {
			update_option( 'haydi_enable_tracks', 1, false );
		}
		wp_send_json_success( array( 'enable_tracks' => $enable_tracks ) );
	}

	// -------------------------------------------------------------------------
	// System prompt and read-tool dispatch
	// -------------------------------------------------------------------------

	/**
	 * Extract plain text from internal content blocks.
	 */
	private function extract_text_from_content_blocks( array $content_blocks ): string {
		$text = '';
		foreach ( $content_blocks as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		return $text;
	}

	/**
	 * Build the instruction used by manual chat compaction.
	 */
	private function build_compaction_prompt(): string {
		return implode(
			"\n",
			array(
				'You compact WordPress agent chats into a concise handoff summary.',
				'',
				'Write bullet points that preserve:',
				'- User goal',
				'- Latest user request, especially if it is the message the next assistant must answer',
				'- Important constraints and preferences',
				'- Completed work',
				'- Pending or proposed actions',
				'- Relevant files, plugins, settings, URLs, database tables, or errors',
				'- Next best step',
				'',
				'Do not include raw tool protocol details unless they are needed to continue.',
				'Do not call tools.',
			)
		);
	}

	/**
	 * Build the system prompt that describes allowed directories and tools to the AI.
	 */
	private function build_system_prompt(): string {
		global $wpdb;
		$db_prefix = $wpdb->prefix;
		$roots     = $this->guard->get_allowed_roots();
		$list      = implode(
			"\n",
			array_map(
				static fn( $k, $v ) => "  - {$k}: {$v}",
				array_keys( $roots ),
				array_values( $roots )
			)
		);

		$jetpack_section = ( new Haydi_Jetpack_Context() )->to_prompt_section();
		$jetpack_block   = $jetpack_section ? "\n\n{$jetpack_section}" : '';

		// Core's plugin/theme editors are disabled on many managed hosts (via
		// DISALLOW_FILE_EDIT / DISALLOW_FILE_MODS) and gated by capability on
		// multisite. The capability checks below already fold both in.
		$can_edit_plugins  = current_user_can( 'edit_plugins' );
		$can_edit_themes   = current_user_can( 'edit_themes' );
		$linking_block     = $this->build_linking_section( $can_edit_plugins, $can_edit_themes );
		$visibility_block  = $this->build_plugin_visibility_section();
		$third_party_block = $this->build_third_party_plugin_section();
		$rule_10           = $this->build_rule_10( $can_edit_plugins, $can_edit_themes );

		$tools = "TOOLS:\n"
			. "- list_files(path)  — browse directories inside the allowed roots\n"
			. "- read_file(path)   — read a file inside the allowed roots\n"
			. "- search_files(query, path, mode, extensions, max_results) — search file contents inside allowed roots; use empty strings for default path/mode/extensions/max_results\n"
			. "- fetch_url(url)    — fetch a public HTTP/HTTPS URL for reference; private/internal addresses are blocked\n"
			. "- list_plugins()    — list all installed plugins with their activation status and file paths\n"
			. "- run_query(sql, reason) — run a SQL query via wpdb; opens an approval UI for the user\n"
			. "- write_file(path, content, reason) — write a file; opens an approval UI for the user\n"
			. "- edit(filePath, oldString, newString, replaceAll, reason) — exact-string edit of an existing file; opens an approval UI for the user\n"
			. "- delete_file(path, reason) — delete a file; opens an approval UI for the user; a backup is created automatically\n"
			. "- move_file(src, dest, reason) — move or rename a file; opens an approval UI for the user; a backup of src is created automatically\n"
			. "- copy_file(src, dest, reason) — copy a file; opens an approval UI for the user; dest is backed up if it already exists\n"
			. "- delete_dir(path, reason) — recursively delete a directory; opens an approval UI for the user; all files are backed up; root directories cannot be deleted\n"
			. "- install_plugin(slug, reason) — install a plugin from WordPress.org by slug; opens an approval UI for the user\n"
			. '- activate_plugin(plugin, reason) — activate an installed plugin by its file path (e.g. "woocommerce/woocommerce.php"); opens an approval UI for the user' . "\n"
			. "- deactivate_plugin(plugin, reason) — deactivate an active plugin; opens an approval UI for the user\n"
			. "- run_php(code, reason) — execute a PHP snippet in the WordPress context; opens an approval UI for the user; output is captured\n"
			. "- list_backups(path?) — list backup files created by this plugin; optionally filter by original file path; always call this tool when asked about backups — never guess\n"
			. '- restore_backup(backup_file, original_path, reason) — restore a file from a specific backup; opens an approval UI for the user; call list_backups first to get the backup_file name';

		$approval = "HOW APPROVAL WORKS:\n"
			. "The \"approval UI\" for write/edit/run/delete/install/activate/deactivate tools is triggered by the tool call itself, not by your text. When you decide to take an action, invoke the tool in the SAME turn — describing the action in plain text without invoking the tool does nothing and leaves the user staring at a stalled chat. Never say \"I'll run X\" or \"I propose to run X\" without actually calling X in the same response.\n"
			. "\nDO NOT ASK FOR PERMISSION BEFORE INVOKING A TOOL. The user has already given consent by asking you to do the task; the dedicated approval UI (with Approve / Decline buttons and the full parameters visible) is the only consent gate that matters. Phrases like \"shall I proceed?\", \"let me know if you'd like me to continue\", \"I'll go ahead and...\" (without an actual tool call), or \"do you want me to run this?\" are all forbidden. They cause the chat to stall because the user expected the approval UI, not another text turn. Just call the tool — the approval UI handles the rest.\n"
			. "\nEXAMPLE OF CORRECT BEHAVIOR:\n"
			. "  User: \"create a test page\"\n"
			. "  Wrong response (stalls the chat — DO NOT do this):\n"
			. "    \"I'll create a published page titled 'Test Page' using wp_insert_post(). Let me know if you'd like me to proceed.\"\n"
			. "  Correct response (one assistant turn, text + tool call together):\n"
			. "    \"Creating a published page titled 'Test Page' via wp_insert_post().\"\n"
			. '    [tool_call: run_php(code="wp_insert_post([\'post_title\'=>\'Test Page\',\'post_status\'=>\'publish\',\'post_content\'=>\'Placeholder.\']);", reason="Create the test page the user asked for.")]' . "\n"
			. '  The user then sees the Proposed PHP Execution panel with Approve / Decline. That panel is the confirmation. Your text just narrates what the tool call is about to do.';

		$rules = "RULES:\n"
			. "1. ALWAYS call the tool in the same turn as any action you describe. Text alone never triggers anything. Never write \"I'll do X\" or \"I will create X\" without calling the tool in that same response.\n"
			. "2. Never access files outside the allowed directories above.\n"
			. "3. Never suggest changes to WordPress core, wp-config.php, .htaccess, or any dotfile.\n"
			. "4. Use edit for small changes to existing files. oldString must be copied exactly from the current file content and should identify one location unless replaceAll is true.\n"
			. "5. When calling write_file, include every line of the new file content (not a diff). Use write_file for new files or intentional full-file replacement. You must write the complete content in a single write_file call. Only one write_file call per turn is accepted; subsequent calls are discarded.\n"
			. "6. When calling run_query, use wpdb table-name conventions (e.g. {$db_prefix}my_table).\n"
			. "7. Briefly state what an action does and why in your text, then invoke the tool in the same response. Do not stop and wait, do not ask \"shall I proceed?\", do not request confirmation in any form, **just GO** — the approval UI is the only confirmation needed and it is shown by the tool call itself.\n"
			. "8. Use fetch_url to read documentation or understand an existing site before building something new.\n"
			. "9. Call list_plugins before install_plugin or activate_plugin to check what is already installed and active.\n"
			. "10. Use search_files before reading many files manually when you need to find hooks, functions, classes, shortcodes, option names, text strings, or other code references.\n"
			. "11. Treat existing installed plugins that were not created specifically for this customization as third-party dependencies. Do not write, edit, delete, move, copy, or directly patch their files; use WordPress hooks, documented APIs, settings, template overrides, or site-owned integration code instead.\n"
			. "12. If no supported hook, API, setting, template override, or customization path exists for a third-party plugin change, do not take action. Tell the user the change is not possible within these limitations.\n"
			. "13. Be conservative: if you are unsure, ask the user instead of guessing.\n"
			. "14. Do not reveal any API keys, secrets, or credentials you may encounter in files.\n"
			. '15. ' . $rule_10;

		return 'You are a capable WordPress assistant running inside WP-Admin. You can manage files, run SQL, install/activate plugins, and execute PHP — all with explicit human approval for actions.'
			. "\n\nALLOWED DIRECTORIES (for file operations only):\n" . $list
			. "\n\n" . $tools
			. "\n\n" . $approval
			. "\n\nLINKING TO FILES:\n" . $linking_block
			. "\n\nGENERATED PLUGIN VISIBILITY:\n" . $visibility_block
			. "\n\nTHIRD-PARTY PLUGIN CUSTOMIZATION:\n" . $third_party_block
			. "\n\n" . $rules
			. $jetpack_block;
	}

	/**
	 * Describe the default rollout behavior for generated public-facing plugins.
	 */
	private function build_plugin_visibility_section(): string {
		return implode(
			"\n",
			array(
				'When generating or changing a plugin that affects front-end visitors, default to an admin-only preview first unless the user explicitly asks for immediate public release.',
				'Use WordPress capabilities such as current_user_can( \'manage_options\' ) to show new public-facing UI, shortcodes, blocks, widgets, banners, notices, forms, or behavior only to admins while it is being tested.',
				'After the change is applied, tell the admin that the feature is currently admin-only and ask them to verify it before you remove the gate or make it visible to all visitors.',
				'Do not hide backend-only tools, safety fixes, or maintenance changes behind this preview gate unless they also affect the visitor-facing experience.',
			)
		);
	}

	/**
	 * Describe how to customize installed third-party plugins safely.
	 */
	private function build_third_party_plugin_section(): string {
		return implode(
			"\n",
			array(
				'Treat existing installed plugins that were not created specifically for the requested customization as third-party dependencies. This includes payment, SEO, commerce, security, form, caching, analytics, and other public plugins.',
				'You may inspect third-party plugin code to understand behavior and discover action hooks, filter hooks, settings, template overrides, or documented APIs, but do not write, edit, delete, move, copy, or directly patch files inside those plugin directories.',
				'Implement customizations in site-owned code: a new small custom plugin, an existing site-specific plugin, a child theme, or another user-owned integration layer.',
				'Do not use run_php, run_query, or file tools to mutate third-party plugin source files or private internals as a workaround. If no supported hook, API, setting, template override, or customization path can satisfy the request, take no action and tell the user the change is not possible within these limitations.',
			)
		);
	}

	/**
	 * Describe how the AI should link to files in chat.
	 *
	 * The chat renders two link schemes:
	 *  - /wp-admin/plugin-editor.php and /wp-admin/theme-editor.php — opens
	 *    WordPress core's editor in a new tab. Only useful when the editor
	 *    is actually available (capability + DISALLOW_FILE_EDIT/MODS).
	 *  - wpc-view:<absolute-path> — expands a read-only inline viewer below
	 *    the message. Always available for any path inside the allowed roots.
	 */
	private function build_linking_section( bool $can_edit_plugins, bool $can_edit_themes ): string {
		$lines = array(
			'Whenever you mention a file the user might want to inspect or tweak, link to it with markdown: [label](url). The chat only renders links whose URL starts with /wp-admin/ or wpc-view: — other URLs are shown as escaped plain text.',
			'',
			'Use wpc-view:<absolute-path> to expand a read-only viewer inline under the message — always works for any file inside the allowed roots:',
			'  - Example: [hello.php](wpc-view:/var/www/html/wp-content/plugins/hello/hello.php)',
		);

		if ( $can_edit_plugins ) {
			$lines[] = '';
			$lines[] = 'For plugin files the user can also edit, prefer the core plugin editor (opens in a new tab):';
			$lines[] = '  - /wp-admin/plugin-editor.php?file=<file>&plugin=<plugin>';
			$lines[] = '  - <plugin> is the plugin\'s main file (e.g. "hello.php" or "woocommerce/woocommerce.php").';
			$lines[] = '  - <file> is the file\'s path relative to wp-content/plugins.';
		}

		if ( $can_edit_themes ) {
			$lines[] = '';
			$lines[] = 'For theme files the user can also edit, prefer the core theme editor (opens in a new tab):';
			$lines[] = '  - /wp-admin/theme-editor.php?file=<file>&theme=<theme-slug>';
			$lines[] = '  - <theme-slug> is the theme directory name (e.g. "twentytwentyfive").';
			$lines[] = '  - <file> is the file\'s path relative to that theme directory.';
		}

		if ( ! $can_edit_plugins && ! $can_edit_themes ) {
			$lines[] = '';
			$lines[] = 'NOTE: This site has the WordPress plugin/theme file editors disabled (DISALLOW_FILE_EDIT, DISALLOW_FILE_MODS, or capability rules). Do not link to /wp-admin/plugin-editor.php or /wp-admin/theme-editor.php — they will fail. Use wpc-view: links for read-only viewing, and tell the user to apply edits via SFTP, WP-CLI, or their local IDE.';
		} elseif ( ! $can_edit_plugins ) {
			$lines[] = '';
			$lines[] = 'NOTE: The plugin file editor is disabled on this site. Do not link to /wp-admin/plugin-editor.php for plugin files — use wpc-view: instead.';
		} elseif ( ! $can_edit_themes ) {
			$lines[] = '';
			$lines[] = 'NOTE: The theme file editor is disabled on this site. Do not link to /wp-admin/theme-editor.php for theme files — use wpc-view: instead.';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Build rule 10, which tells the AI how to surface a file after a write.
	 */
	private function build_rule_10( bool $can_edit_plugins, bool $can_edit_themes ): string {
		if ( ! $can_edit_plugins && ! $can_edit_themes ) {
			return 'After a successful write/move/copy/delete, mention the affected file(s) by name and link them with wpc-view:<absolute-path> so the user can review the contents inline.';
		}
		return 'After a successful write/move/copy/delete, mention the affected file(s) by name and link them — prefer the matching plugin-editor.php / theme-editor.php URL when available for that file type, otherwise use wpc-view:<absolute-path>.';
	}

	/**
	 * Dispatch a read-only tool call to the appropriate tool class. Each tool
	 * returns a string ready to feed back to the AI as a tool_result.
	 */
	private function execute_read_tool( string $name, array $input ): string {
		switch ( $name ) {
			case 'list_files':
				return $this->file_tool->list_files_for_ai( $input['path'] ?? '' );
			case 'read_file':
				return $this->file_tool->read_file_for_ai( $input['path'] ?? '' );
			case 'search_files':
				return $this->file_tool->search_files_for_ai(
					$input['query'] ?? '',
					$input['path'] ?? '',
					$input['mode'] ?? '',
					$input['extensions'] ?? '',
					$input['max_results'] ?? ''
				);
			case 'fetch_url':
				return $this->url_tool->fetch_for_ai( $input['url'] ?? '' );
			case 'list_plugins':
				return $this->plugin_tool->list_plugins_for_ai();
			case 'list_backups':
				return $this->file_tool->list_backups_for_ai( $input['path'] ?? '' );
			default:
				return "Error: Unknown tool '{$name}'.";
		}
	}

	/**
	 * Build a safe, compact activity row for a read-only tool call.
	 *
	 * This is UI/debug metadata only. It intentionally avoids raw file contents,
	 * fetched page text, and search snippets.
	 */
	private function build_tool_activity( string $name, array $input, string $result ): ?array {
		if ( ! defined( 'HAYDI_SHOW_TOOL_ACTIVITY' ) || ! HAYDI_SHOW_TOOL_ACTIVITY ) {
			return null;
		}

		$is_error = str_starts_with( $result, 'Error: ' );
		$summary  = $is_error ? substr( $result, 7, 180 ) : '';
		$label    = $this->tool_activity_label( $name );

		if ( ! $is_error ) {
			$summary = match ( $name ) {
				'list_files'   => $this->summarize_list_files_activity( $input, $result ),
				'read_file'    => $this->summarize_read_file_activity( $input, $result ),
				'search_files' => $this->summarize_search_files_activity( $input, $result ),
				'fetch_url'    => $this->summarize_fetch_url_activity( $input, $result ),
				'list_plugins' => $this->summarize_list_plugins_activity( $result ),
				'list_backups' => $result,
				default        => 'Completed.',
			};
		}

		return array(
			'name'    => $name,
			'label'   => $label,
			'status'  => $is_error ? 'error' : 'ok',
			'summary' => $summary,
		);
	}

	private function tool_activity_label( string $name ): string {
		return match ( $name ) {
			'list_files'        => 'Listed files',
			'read_file'         => 'Read file',
			'search_files'      => 'Searched files',
			'fetch_url'         => 'Fetched URL',
			'list_plugins'      => 'Listed plugins',
			'run_query'         => 'Prepared query',
			'write_file'        => 'Prepared write',
			'edit'              => 'Prepared edit',
			'delete_file'       => 'Prepared delete',
			'move_file'         => 'Prepared move',
			'copy_file'         => 'Prepared copy',
			'delete_dir'        => 'Prepared folder delete',
			'install_plugin'    => 'Prepared install',
			'activate_plugin'   => 'Prepared activation',
			'deactivate_plugin' => 'Prepared deactivation',
			'run_php'           => 'Prepared PHP',
			'list_backups'      => 'Listed backups',
			'restore_backup'    => 'Prepared restore',
			default             => 'Used tool',
		};
	}

	private function summarize_tool_start_activity( string $name, array $input ): string {
		return match ( $name ) {
			'list_files'   => $this->display_path( (string) ( $input['path'] ?? '' ) ),
			'read_file'    => $this->display_path( (string) ( $input['path'] ?? '' ) ),
			'search_files' => sprintf(
				'"%s" in %s',
				$this->truncate_activity_text( (string) ( $input['query'] ?? '' ), 60 ),
				$this->display_path( (string) ( $input['path'] ?? '' ) )
			),
			'fetch_url'    => $this->truncate_activity_text( (string) ( $input['url'] ?? '' ), 120 ),
			'list_plugins' => 'Installed plugin inventory',
			'list_backups' => $input['path'] ? $this->display_path( (string) $input['path'] ) : 'All backups',
			default        => 'Running tool.',
		};
	}

	private function summarize_list_files_activity( array $input, string $result ): string {
		$data = json_decode( $result, true );
		if ( ! is_array( $data ) ) {
			return 'Completed.';
		}

		$files = 0;
		$dirs  = 0;
		foreach ( $data as $item ) {
			if ( 'directory' === ( $item['type'] ?? '' ) ) {
				++$dirs;
			} elseif ( 'file' === ( $item['type'] ?? '' ) ) {
				++$files;
			}
		}

		return sprintf(
			'%s · %d files · %d folders',
			$this->display_path( (string) ( $input['path'] ?? '' ) ),
			$files,
			$dirs
		);
	}

	private function summarize_read_file_activity( array $input, string $result ): string {
		return sprintf(
			'%s · %s',
			$this->display_path( (string) ( $input['path'] ?? '' ) ),
			$this->format_activity_bytes( strlen( $result ) )
		);
	}

	private function summarize_search_files_activity( array $input, string $result ): string {
		$data = json_decode( $result, true );
		if ( ! is_array( $data ) ) {
			return 'Completed.';
		}

		$query        = $this->truncate_activity_text( (string) ( $input['query'] ?? '' ), 60 );
		$scope        = $this->display_path( (string) ( $input['path'] ?? '' ) );
		$extensions   = (string) ( $input['extensions'] ?? '' );
		$ext_label    = '' !== trim( $extensions ) ? trim( $extensions ) : 'all allowed files';
		$result_count = (int) ( $data['count'] ?? 0 );
		$truncated    = ! empty( $data['truncated'] ) ? sprintf( ' · Showing first %d results', $result_count ) : '';

		return sprintf(
			'"%s" in %s · %s · Checked %d files · Found %d results%s',
			$query,
			$scope,
			$ext_label,
			(int) ( $data['scanned_files'] ?? 0 ),
			$result_count,
			$truncated
		);
	}

	private function summarize_fetch_url_activity( array $input, string $result ): string {
		return sprintf(
			'%s · %s text',
			$this->truncate_activity_text( (string) ( $input['url'] ?? '' ), 120 ),
			$this->format_activity_bytes( strlen( $result ) )
		);
	}

	private function summarize_list_plugins_activity( string $result ): string {
		$data = json_decode( $result, true );
		if ( ! is_array( $data ) ) {
			return 'Completed.';
		}

		$active = 0;
		foreach ( $data as $plugin ) {
			if ( ! empty( $plugin['active'] ) ) {
				++$active;
			}
		}

		return sprintf( '%d installed · %d active', count( $data ), $active );
	}

	private function display_path( string $path ): string {
		if ( '' === $path ) {
			return 'allowed roots';
		}

		$path = str_replace( '\\', '/', $path );
		foreach ( array( WP_CONTENT_DIR, ABSPATH ) as $base ) {
			$base = str_replace( '\\', '/', $base );
			if ( str_starts_with( $path, rtrim( $base, '/' ) . '/' ) ) {
				return ltrim( substr( $path, strlen( rtrim( $base, '/' ) ) ), '/' );
			}
		}

		return basename( $path );
	}

	private function format_activity_bytes( int $bytes ): string {
		if ( $bytes < 1024 ) {
			return $bytes . ' B';
		}
		if ( $bytes < 1048576 ) {
			return round( $bytes / 1024, 1 ) . ' KB';
		}
		return round( $bytes / 1048576, 1 ) . ' MB';
	}

	private function truncate_activity_text( string $text, int $max ): string {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) ?? $text );
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		return substr( $text, 0, $max - 3 ) . '...';
	}
}
