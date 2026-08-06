<?php
/**
 * AJAX dispatcher — runs the agentic chat loop, exposes the single browser
 * approval Adapter, and wires the remaining wp_ajax_* endpoints.
 *
 * Security model:
 *  - Every action verifies current_user_can('manage_options') AND a nonce
 *    (handled by Haydi_Ajax_Tool_Base::verify).
 *  - All filesystem paths are re-validated by Haydi_Filesystem_Guard.
 *  - Approval-gated tools (file writes, SQL, PHP, …) are NEVER executed
 *    automatically; the loop pauses and surfaces a "pending_action"
 *    payload that the human must explicitly approve.
 *  - The agentic loop runs server-side so list_files / read_file / fetch_url
 *    / list_plugins results flow back to the AI without a browser round-trip.
 */

defined( 'ABSPATH' ) || exit;

class Haydi_Ajax_Handlers extends Haydi_Ajax_Tool_Base {

	/** Maximum AI→Tool→AI iterations per user request. */
	const MAX_LOOP = 10;

	/** @var Haydi_Filesystem_Guard Filesystem access guard. */
	private Haydi_Filesystem_Guard $guard;
	/** @var Haydi_AI_Client AI client. */
	private Haydi_AI_Client $client;
	/** @var Haydi_Tool_Catalog|null Canonical declarations and dispatch. */
	private ?Haydi_Tool_Catalog $tool_catalog = null;
	/** @var Haydi_Api_Token_Manager API token manager. */
	private Haydi_Api_Token_Manager $token_manager;

	public function __construct( ?Haydi_Tool_Catalog $tool_catalog = null ) {
		parent::__construct( new Haydi_Audit_Logger() );

		$this->guard         = new Haydi_Filesystem_Guard();
		$this->client        = new Haydi_AI_Client( $tool_catalog );
		$this->tool_catalog  = $tool_catalog;
		$this->token_manager = new Haydi_Api_Token_Manager();

		$file_tool  = new Haydi_File_Tool( $this->logger, $this->guard );
		$chat_store = new Haydi_Chat_Store( $this->logger, $this->client );

		$file_tool->register();
		$chat_store->register();
		$this->register();
	}

	private function tool_catalog(): Haydi_Tool_Catalog {
		if ( ! isset( $this->tool_catalog ) || null === $this->tool_catalog ) {
			$this->tool_catalog = haydi_get_tool_catalog();
		}
		return $this->tool_catalog;
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
			'haydi_execute_approved_tool'  => 'handle_execute_approved_tool',
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

	/**
	 * Execute one catalog-native Action Proposal after explicit browser approval.
	 *
	 * Authentication remains in the browser Adapter; Tool resolution, policy,
	 * argument normalization, and execution remain in the Tool Catalog.
	 */
	public function handle_execute_approved_tool(): void {
		$this->verify();

		$tool_name = $this->post_param( 'tool_name' );
		if ( '' === $tool_name ) {
			wp_send_json_error( array( 'message' => 'tool_name is required.' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified above; exact Tool arguments are decoded then normalized by the catalog
		$raw_arguments   = isset( $_POST['arguments'] ) ? wp_unslash( $_POST['arguments'] ) : '';
		$argument_object = is_string( $raw_arguments ) ? json_decode( $raw_arguments ) : null;
		if ( ! $argument_object instanceof stdClass || JSON_ERROR_NONE !== json_last_error() ) {
			wp_send_json_error( array( 'message' => 'arguments must be a JSON object.' ) );
		}
		$arguments = (array) $argument_object;

		$outcome = $this->tool_catalog()->execute_approved( $tool_name, $arguments );
		if ( is_wp_error( $outcome ) ) {
			$error = array( 'message' => $outcome->get_error_message() );
			$data  = $outcome->get_error_data();
			if ( is_array( $data ) && isset( $data['output'] ) ) {
				$error['output'] = (string) $data['output'];
			}
			wp_send_json_error( $error );
		}

		wp_send_json_success(
			array(
				'tool_name' => $tool_name,
				'result'    => $outcome['result'],
			)
		);
	}

	// -------------------------------------------------------------------------
	// Chat / agentic loop
	// -------------------------------------------------------------------------

	/**
	 * Run the AI agentic loop server-side.
	 *
	 * Read tools (list_files / read_file / fetch_url / list_plugins) execute
	 * automatically. Approval-gated tools stop the loop and surface a
	 * pending_action Action Proposal for human approval.
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

				$tool_started = false;
				$tool_label   = 'Used tool';
				$outcome      = $this->tool_catalog()->dispatch(
					Haydi_Tool_Catalog::CHAT,
					$tool_name,
					$input,
					function ( array $tool_activity ) use ( $emit, $tool_name, $input, &$tool_started, &$tool_label ): void {
						$tool_started = true;
						$tool_label   = $tool_activity['label'];
						$this->emit_chat_event(
							$emit,
							'tool_start',
							array(
								'name'    => $tool_name,
								'label'   => $tool_label,
								'summary' => 'approval' === $tool_activity['effect']
									? 'Preparing approval request.'
									: $this->summarize_tool_start_activity( $tool_name, $input ),
							)
						);
					}
				);

				$is_error = is_wp_error( $outcome );
				if ( $is_error ) {
					$result = 'Error: ' . $outcome->get_error_message();
				} elseif ( 'action_proposal' === $outcome['kind'] ) {
					if ( null !== $pending_payload ) {
						$tool_results[] = array(
							'type'        => 'tool_result',
							'tool_use_id' => $tool_id,
							'name'        => $tool_name,
							'content'     => 'Deferred: only one action can be approved at a time. Propose this again after the current action is approved.',
						);
						continue;
					}

					$audit = $outcome['audit'];
					$this->logger->log( $audit['action'], $audit['path'], $audit['reason'] );

					$pending_payload                = $outcome['payload'];
					$pending_payload['tool_use_id'] = $tool_id;
					continue;
				} else {
					$result     = $outcome['result'];
					$tool_label = $outcome['activity']['label'];
				}

				if ( ! $tool_started ) {
					$this->emit_chat_event(
						$emit,
						'tool_start',
						array(
							'name'    => $tool_name,
							'label'   => $tool_label,
							'summary' => $this->summarize_tool_start_activity( $tool_name, $input ),
						)
					);
				}

				$activity_item = $this->build_tool_activity( $tool_name, $tool_label, $input, $result, $is_error );
				if ( null !== $activity_item ) {
					$activity[] = $activity_item;
				}
				$this->emit_chat_event(
					$emit,
					'tool_done',
					array(
						'name'     => $tool_name,
						'activity' => $activity_item,
						'status'   => $is_error ? 'error' : 'ok',
					)
				);
				$tool_results[] = array(
					'type'        => 'tool_result',
					'tool_use_id' => $tool_id,
					'name'        => $tool_name,
					'content'     => $result,
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
					'text'           => $all_text,
					'messages'       => $messages,
					'usage'          => $usage,
					'activity'       => $activity,
					'pending_action' => $pending_payload,
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

		$action_proposals = haydi_get_action_proposals();
		$legacy_tools     = array();
		foreach ( haydi_get_legacy_action_proposals() as $name => $proposal ) {
			// Omit a legacy description when a catalog-native approval Tool owns
			// the same name in the effective proposal registry.
			if ( ! isset( $action_proposals[ $name ] ) || $action_proposals[ $name ] !== $proposal ) {
				continue;
			}
			if ( ! empty( $proposal['tool_description'] ) ) {
				$legacy_tools[] = '- ' . $proposal['tool_description'];
			}
		}
		$legacy_tools_block = empty( $legacy_tools )
			? ''
			: "\n\nADDITIONAL APPROVAL TOOLS:\n" . implode( "\n", $legacy_tools );

		$approval = "HOW APPROVAL WORKS:\n"
			. $this->build_approval_workflow_section();

		$rules = "RULES:\n"
			. "1. Never access files outside the allowed directories above.\n"
			. "2. Never suggest changes to WordPress core, wp-config.php, .htaccess, or any dotfile.\n"
			. "3. Call list_plugins before install_plugin or activate_plugin to check what is already installed and active.\n"
			. "4. Use search_files before manually reading many files to locate hooks, functions, classes, shortcodes, option names, or text strings.\n"
			. "5. Be conservative: if you are unsure, ask the user instead of guessing.\n"
			. "6. Do not reveal any API keys, secrets, or credentials you may encounter in files.\n"
			. '7. ' . $rule_10;

		$prompt = 'You are a WordPress assistant running inside WP-Admin. Mutating actions require human approval.'
			. "\n\nALLOWED DIRECTORIES (for file operations only):\n" . $list
			. "\n\n" . $approval
			. $legacy_tools_block
			. "\n\nLINKING TO FILES:\n" . $linking_block
			. "\n\nGENERATED PLUGIN VISIBILITY:\n" . $visibility_block
			. "\n\nTHIRD-PARTY PLUGIN CUSTOMIZATION:\n" . $third_party_block
			. "\n\n" . $rules
			. $jetpack_block;

		return (string) apply_filters(
			'haydi_system_prompt',
			$prompt,
			array(
				'allowed_roots'    => $roots,
				'action_proposals' => $action_proposals,
				'can_edit_plugins' => $can_edit_plugins,
				'can_edit_themes'  => $can_edit_themes,
				'db_prefix'        => $db_prefix,
			)
		);
	}

	/**
	 * Explain the approval-tool contract in concrete terms for models that are
	 * prone to narrating the next action instead of calling the function.
	 */
	private function build_approval_workflow_section(): string {
		return implode(
			"\n",
			array(
				'An approval tool call is an approval request, not direct execution. It only shows the user an Approve/Decline UI with the full parameters.',
				'If the user asks for an action that has a tool, call the tool. Text such as "I can do that", "shall I proceed?", "approve this", or "let me know if you want me to continue" does nothing and is a failed response.',
				'Use at most one approval tool call per assistant response. If more work remains, wait for the tool_result from the approval UI, then continue with the next tool call.',
				'Plugin workflow: call list_plugins before install_plugin or activate_plugin. If the user asks to install and activate a plugin, first call install_plugin. After the approval result returns a plugin_file, call activate_plugin with that exact plugin_file. If list_plugins shows the plugin is already installed but inactive, skip install_plugin and call activate_plugin with its file path.',
			)
		);
	}

	/**
	 * Describe the default rollout behavior for generated public-facing plugins.
	 */
	private function build_plugin_visibility_section(): string {
		return implode(
			"\n",
			array(
				"For plugins affecting front-end visitors, default to admin-only preview — gate with current_user_can( 'manage_options' ) unless the user explicitly asks for immediate public release. Skip for backend tools, safety fixes, or maintenance-only changes.",
				'After applying, tell the admin to verify before making it visible to all visitors.',
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
				'Treat third-party plugins as read-only dependencies. Inspect their code for action hooks, filter hooks, APIs, settings, or template overrides, but never modify their files or private internals — not via file writes, run_query, or run_php.',
				'Implement customizations in site-owned code (custom plugin, child theme, or integration layer).',
				'If no supported hook, API, setting, or template override satisfies the request, take no action and tell the user it is not possible within these limitations.',
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
			'Link files with markdown [label](url). Only /wp-admin/ and wpc-view: URLs render as links.',
			'',
			'- wpc-view:<absolute-path> — read-only inline viewer for any file in allowed roots.',
			'  Example: [hello.php](wpc-view:/var/www/html/wp-content/plugins/hello/hello.php)',
		);

		if ( $can_edit_plugins || $can_edit_themes ) {
			$lines[] = '';
			$lines[] = 'For files the user can edit, prefer the core editor:';
		}

		if ( $can_edit_plugins ) {
			$lines[] = '- /wp-admin/plugin-editor.php?file=<file>&plugin=<plugin-main-file>';
			$lines[] = '  e.g. plugin=hello.php or plugin=woocommerce/woocommerce.php; file is relative to wp-content/plugins';
		}

		if ( $can_edit_themes ) {
			$lines[] = '- /wp-admin/theme-editor.php?file=<file>&theme=<theme-slug>';
			$lines[] = '  theme is the directory name (e.g. twentytwentyfive); file is relative to that theme directory';
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
	 * Build a safe, compact activity row for a read-only tool call.
	 *
	 * This is UI/debug metadata only. It intentionally avoids raw file contents,
	 * fetched page text, and search snippets.
	 */
	private function build_tool_activity( string $name, string $label, array $input, mixed $result, bool $is_error = false ): ?array {
		if ( ! defined( 'HAYDI_SHOW_TOOL_ACTIVITY' ) || ! HAYDI_SHOW_TOOL_ACTIVITY ) {
			return null;
		}

		$is_error = $is_error || ( is_string( $result ) && str_starts_with( $result, 'Error: ' ) );
		$summary  = $is_error && is_string( $result ) ? substr( $result, 7, 180 ) : '';
		if ( ! $is_error ) {
			$summary = match ( $name ) {
				'list_files'   => $this->summarize_list_files_activity( $input, $result ),
				'read_file'    => $this->summarize_read_file_activity( $input, $result ),
				'search_files' => $this->summarize_search_files_activity( $input, $result ),
				'fetch_url'    => $this->summarize_fetch_url_activity( $input, $result ),
				'list_plugins' => $this->summarize_list_plugins_activity( $result ),
				'list_backups' => 'Listed ' . count( (array) ( is_array( $result ) ? ( $result['backups'] ?? array() ) : array() ) ) . ' backups.',
				'list_posts'   => 'Listed ' . count( (array) ( is_array( $result ) ? ( $result['posts'] ?? array() ) : array() ) ) . ' posts.',
				'list_users'   => 'Listed ' . count( (array) ( is_array( $result ) ? ( $result['users'] ?? array() ) : array() ) ) . ' users.',
				'list_options' => 'Listed ' . count( (array) ( is_array( $result ) ? ( $result['options'] ?? array() ) : array() ) ) . ' options.',
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
			'list_posts'   => 'Posts inventory',
			'list_users'   => 'Users inventory',
			'list_options' => $input['search'] ? '"' . $this->truncate_activity_text( (string) $input['search'], 60 ) . '"' : 'Autoloaded options',
			'list_backups' => $input['path'] ? $this->display_path( (string) $input['path'] ) : 'All backups',
			default        => 'Running tool.',
		};
	}

	private function summarize_list_files_activity( array $input, mixed $result ): string {
		$data = is_array( $result ) ? ( $result['files'] ?? null ) : null;
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

	private function summarize_read_file_activity( array $input, mixed $result ): string {
		$content = is_array( $result ) ? (string) ( $result['content'] ?? '' ) : (string) $result;
		return sprintf(
			'%s · %s',
			$this->display_path( (string) ( $input['path'] ?? '' ) ),
			$this->format_activity_bytes( strlen( $content ) )
		);
	}

	private function summarize_search_files_activity( array $input, mixed $result ): string {
		$data = $result;
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

	private function summarize_fetch_url_activity( array $input, mixed $result ): string {
		$content = is_array( $result ) ? (string) ( $result['content'] ?? '' ) : (string) $result;
		return sprintf(
			'%s · %s text',
			$this->truncate_activity_text( (string) ( $input['url'] ?? '' ), 120 ),
			$this->format_activity_bytes( strlen( $content ) )
		);
	}

	private function summarize_list_plugins_activity( mixed $result ): string {
		$data = is_array( $result ) ? ( $result['plugins'] ?? null ) : null;
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
