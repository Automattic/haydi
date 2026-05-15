<?php
/**
 * Main admin page template.
 *
 * Single-panel layout: the AI chat fills the workspace. A proposal panel
 * slides in from the top only while a write/delete/query/etc. is awaiting
 * approval. File browsing and editing happen in WordPress core's
 * plugin-editor.php / theme-editor.php — the AI links to those directly.
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template included inside Haydi_Plugin::render_main_page(); variables are method-scoped, never global.
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to view this page.', 'haydi' ) );
}

$guard = new Haydi_Filesystem_Guard();
$roots = $guard->get_allowed_roots();

$connector_ready    = Haydi_Plugin::has_provider();
$current_max_tokens = (int) get_option( 'haydi_max_tokens', Haydi_AI_Client::DEFAULT_MAX_TOKENS );
$playground_enabled = (bool) get_option( 'haydi_playground_preflight_enabled', false );
$tracks_enabled     = (bool) get_option( 'haydi_enable_tracks', false );
?>
<div class="wrap wpc-wrap">
	<h1 class="screen-reader-text"><?php esc_html_e( 'Haydi', 'haydi' ); ?></h1>
	<div class="wpc-page-header">
		<div class="wpc-page-header__title"><?php esc_html_e( 'Haydi', 'haydi' ); ?> <span class="wpc-page-header__subtitle"><?php esc_html_e( 'Your AI Autopilot', 'haydi' ); ?></span></div>
		<div class="wpc-page-header__actions">
			<button id="wpc-btn-new-chat" class="wpc-header-btn wpc-header-btn--primary" title="<?php esc_attr_e( 'New chat', 'haydi' ); ?>">
				<span class="dashicons dashicons-plus-alt"></span>
				<?php esc_html_e( 'New chat', 'haydi' ); ?>
			</button>
		</div>
	</div>

	<?php if ( empty( $roots ) ) : ?>
	<div class="notice notice-info">
		<p><?php esc_html_e( 'No allowed directories are configured. Activate a child theme or set a custom plugin slug in Settings.', 'haydi' ); ?></p>
	</div>
	<?php endif; ?>

	<!-- ── Single-panel workspace ─────────────────────────────────────── -->
	<div class="wpc-workspace">

		<div class="wpc-main">

		<?php
		$jetpack_notice_dismissed = get_user_meta( get_current_user_id(), 'haydi_jetpack_notice_dismissed', true );
		$jetpack_connected        = Haydi_Jetpack_Context::is_connected();
		$jetpack_available        = Haydi_Jetpack_Context::is_available();
		if ( ! $jetpack_connected && ! $jetpack_notice_dismissed ) :
			if ( $jetpack_available ) :
				// Jetpack is installed but not yet connected to WordPress.com.
				$jetpack_cta_label = __( 'Connect Jetpack', 'haydi' );
				$jetpack_cta_url   = admin_url( 'admin.php?page=jetpack#/dashboard' );
				$jetpack_cta_type  = 'connect';
				$jetpack_heading   = __( 'Connect Jetpack to unlock smarter AI suggestions', 'haydi' );
				$jetpack_subtext   = __( 'Jetpack is installed but not connected. Link it to WordPress.com and the AI will gain real context about your site.', 'haydi' );
			else :
				// Jetpack is not installed at all.
				$jetpack_cta_label = __( 'Install Jetpack', 'haydi' );
				$jetpack_cta_url   = admin_url( 'plugin-install.php?s=jetpack&tab=search&type=term' );
				$jetpack_cta_type  = 'install';
				$jetpack_heading   = __( 'Unlock smarter AI suggestions with Jetpack', 'haydi' );
				$jetpack_subtext   = __( 'When Jetpack is connected, the AI gains real context about your site — no more generic answers.', 'haydi' );
			endif;
			?>
		<div class="wpc-jetpack-notice" id="wpc-jetpack-notice">
			<div class="wpc-jetpack-notice__icon" aria-hidden="true">&#x1F680;</div>
			<div class="wpc-jetpack-notice__body">
				<p><strong><?php echo esc_html( $jetpack_heading ); ?></strong></p>
				<p><?php echo esc_html( $jetpack_subtext ); ?></p>
				<ul>
					<li><?php esc_html_e( 'Traffic stats — AI knows your top posts, visitor count, and where your audience comes from', 'haydi' ); ?></li>
					<li><?php esc_html_e( 'Search terms — AI learns what your visitors are actually looking for on your site', 'haydi' ); ?></li>
					<li><?php esc_html_e( 'Active modules — AI won\'t suggest building something Jetpack already provides', 'haydi' ); ?></li>
					<li><?php esc_html_e( 'Plan & features — AI scopes its recommendations to what\'s available on your plan', 'haydi' ); ?></li>
					<li><?php esc_html_e( 'Speed scores — AI can target real performance improvements via Jetpack Boost', 'haydi' ); ?></li>
					<li><?php esc_html_e( 'Security — AI knows your brute-force exposure and can suggest hardening measures', 'haydi' ); ?></li>
				</ul>
				<a href="<?php echo esc_url( $jetpack_cta_url ); ?>" class="button button-primary wpc-track-jetpack-cta" data-cta-type="<?php echo esc_attr( $jetpack_cta_type ); ?>">
					<?php echo esc_html( $jetpack_cta_label ); ?>
				</a>
			</div>
			<button type="button" class="wpc-jetpack-notice__dismiss" id="wpc-jetpack-notice-dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'haydi' ); ?>">&#x2715;</button>
		</div>
		<?php endif; ?>

		<!-- Proposal panel — hidden until the AI proposes an action. -->
		<div class="wpc-panel wpc-panel--editor wpc-hidden" id="wpc-editor-panel">
			<div class="wpc-panel__header">
				<span class="wpc-proposal-shell-title">
					<span class="dashicons dashicons-shield" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Approval required', 'haydi' ); ?></strong>
				</span>
			</div>
			<div class="wpc-panel__body">
				<!-- Proposed query (hidden until the AI proposes a run_query) -->
				<div id="wpc-query-section" class="wpc-hidden">
					<div class="wpc-proposal-header">
						<span class="dashicons dashicons-database"></span>
						<strong><?php esc_html_e( 'Review database change', 'haydi' ); ?></strong>
					</div>
					<p id="wpc-query-reason" class="wpc-proposal-reason"></p>
					<details class="wpc-technical-details">
						<summary><?php esc_html_e( 'Technical details', 'haydi' ); ?></summary>
						<pre id="wpc-query-sql" class="wpc-diff wpc-diff--after" style="max-height:200px;"></pre>
					</details>
					<div class="wpc-proposal-actions">
						<button id="wpc-btn-execute-query" class="button button-primary">
							<?php esc_html_e( 'Execute Query', 'haydi' ); ?>
						</button>
						<button id="wpc-btn-cancel-query" class="button">
							<?php esc_html_e( 'Cancel', 'haydi' ); ?>
						</button>
						<span id="wpc-query-status" class="wpc-apply-status"></span>
					</div>
				</div>

				<!-- Proposed file deletion (hidden until the AI proposes a delete) -->
				<div id="wpc-delete-section" class="wpc-hidden">
					<div class="wpc-proposal-header wpc-delete-header">
						<span class="dashicons dashicons-trash"></span>
						<strong><?php esc_html_e( 'Delete file', 'haydi' ); ?></strong>
					</div>
					<p id="wpc-delete-path" class="wpc-proposal-path"></p>
					<p id="wpc-delete-reason" class="wpc-proposal-reason"></p>
					<div class="wpc-proposal-actions">
						<button id="wpc-btn-confirm-delete" class="button button-primary wpc-btn-danger">
							<?php esc_html_e( 'Delete File', 'haydi' ); ?>
						</button>
						<button id="wpc-btn-cancel-delete" class="button">
							<?php esc_html_e( 'Cancel', 'haydi' ); ?>
						</button>
						<span id="wpc-delete-status" class="wpc-apply-status"></span>
					</div>
				</div>

				<!-- Proposed file move (hidden until the AI proposes a move) -->
				<div id="wpc-move-section" class="wpc-hidden">
					<div class="wpc-proposal-header">
						<span class="dashicons dashicons-randomize"></span>
						<strong><?php esc_html_e( 'Move file', 'haydi' ); ?></strong>
					</div>
					<p id="wpc-move-reason" class="wpc-proposal-reason"></p>
					<div class="wpc-diff-wrap">
						<div class="wpc-diff-col">
							<p class="wpc-diff-label"><?php esc_html_e( 'From', 'haydi' ); ?></p>
							<pre id="wpc-move-src" class="wpc-diff wpc-diff--before"></pre>
						</div>
						<div class="wpc-diff-col">
							<p class="wpc-diff-label"><?php esc_html_e( 'To', 'haydi' ); ?></p>
							<pre id="wpc-move-dest" class="wpc-diff wpc-diff--after"></pre>
						</div>
					</div>
					<div class="wpc-proposal-actions">
						<button id="wpc-btn-confirm-move" class="button button-primary">
							<?php esc_html_e( 'Move File', 'haydi' ); ?>
						</button>
						<button id="wpc-btn-cancel-move" class="button">
							<?php esc_html_e( 'Cancel', 'haydi' ); ?>
						</button>
						<span id="wpc-move-status" class="wpc-apply-status"></span>
					</div>
				</div>

				<!-- Proposed file copy (hidden until the AI proposes a copy) -->
				<div id="wpc-copy-section" class="wpc-hidden">
					<div class="wpc-proposal-header">
						<span class="dashicons dashicons-admin-page"></span>
						<strong><?php esc_html_e( 'Copy file', 'haydi' ); ?></strong>
					</div>
					<p id="wpc-copy-reason" class="wpc-proposal-reason"></p>
					<div class="wpc-diff-wrap">
						<div class="wpc-diff-col">
							<p class="wpc-diff-label"><?php esc_html_e( 'From', 'haydi' ); ?></p>
							<pre id="wpc-copy-src" class="wpc-diff wpc-diff--before"></pre>
						</div>
						<div class="wpc-diff-col">
							<p class="wpc-diff-label"><?php esc_html_e( 'To', 'haydi' ); ?></p>
							<pre id="wpc-copy-dest" class="wpc-diff wpc-diff--after"></pre>
						</div>
					</div>
					<div class="wpc-proposal-actions">
						<button id="wpc-btn-confirm-copy" class="button button-primary">
							<?php esc_html_e( 'Copy File', 'haydi' ); ?>
						</button>
						<button id="wpc-btn-cancel-copy" class="button">
							<?php esc_html_e( 'Cancel', 'haydi' ); ?>
						</button>
						<span id="wpc-copy-status" class="wpc-apply-status"></span>
					</div>
				</div>

				<!-- Proposed directory deletion (hidden until the AI proposes delete_dir) -->
				<div id="wpc-rmdir-section" class="wpc-hidden">
					<div class="wpc-proposal-header wpc-delete-header">
						<span class="dashicons dashicons-trash"></span>
						<strong><?php esc_html_e( 'Delete directory', 'haydi' ); ?></strong>
					</div>
					<p id="wpc-rmdir-path" class="wpc-proposal-path"></p>
					<p id="wpc-rmdir-reason" class="wpc-proposal-reason"></p>
					<div class="wpc-proposal-actions">
						<button id="wpc-btn-confirm-rmdir" class="button button-primary wpc-btn-danger">
							<?php esc_html_e( 'Delete Directory', 'haydi' ); ?>
						</button>
						<button id="wpc-btn-cancel-rmdir" class="button">
							<?php esc_html_e( 'Cancel', 'haydi' ); ?>
						</button>
						<span id="wpc-rmdir-status" class="wpc-apply-status"></span>
					</div>
				</div>

				<!-- Proposed plugin install (hidden until the AI proposes install_plugin) -->
				<div id="wpc-install-section" class="wpc-hidden">
					<div class="wpc-proposal-header">
						<span class="dashicons dashicons-download"></span>
						<strong><?php esc_html_e( 'Install plugin', 'haydi' ); ?></strong>
					</div>
					<p id="wpc-install-slug" class="wpc-proposal-path"></p>
					<p id="wpc-install-reason" class="wpc-proposal-reason"></p>
					<div class="wpc-proposal-actions">
						<button id="wpc-btn-confirm-install" class="button button-primary">
							<?php esc_html_e( 'Install Plugin', 'haydi' ); ?>
						</button>
						<button id="wpc-btn-cancel-install" class="button">
							<?php esc_html_e( 'Cancel', 'haydi' ); ?>
						</button>
						<span id="wpc-install-status" class="wpc-apply-status"></span>
					</div>
				</div>

				<!-- Proposed plugin activation (hidden until the AI proposes activate_plugin) -->
				<div id="wpc-activate-section" class="wpc-hidden">
					<div class="wpc-proposal-header">
						<span class="dashicons dashicons-yes-alt"></span>
						<strong><?php esc_html_e( 'Activate plugin', 'haydi' ); ?></strong>
					</div>
					<p id="wpc-activate-plugin" class="wpc-proposal-path"></p>
					<p id="wpc-activate-reason" class="wpc-proposal-reason"></p>
					<div class="wpc-proposal-actions">
						<button id="wpc-btn-confirm-activate" class="button button-primary">
							<?php esc_html_e( 'Activate Plugin', 'haydi' ); ?>
						</button>
						<button id="wpc-btn-cancel-activate" class="button">
							<?php esc_html_e( 'Cancel', 'haydi' ); ?>
						</button>
						<span id="wpc-activate-status" class="wpc-apply-status"></span>
					</div>
				</div>

				<!-- Proposed plugin deactivation (hidden until the AI proposes deactivate_plugin) -->
				<div id="wpc-deactivate-section" class="wpc-hidden">
					<div class="wpc-proposal-header wpc-delete-header">
						<span class="dashicons dashicons-dismiss"></span>
						<strong><?php esc_html_e( 'Deactivate plugin', 'haydi' ); ?></strong>
					</div>
					<p id="wpc-deactivate-plugin" class="wpc-proposal-path"></p>
					<p id="wpc-deactivate-reason" class="wpc-proposal-reason"></p>
					<div class="wpc-proposal-actions">
						<button id="wpc-btn-confirm-deactivate" class="button button-primary wpc-btn-danger">
							<?php esc_html_e( 'Deactivate Plugin', 'haydi' ); ?>
						</button>
						<button id="wpc-btn-cancel-deactivate" class="button">
							<?php esc_html_e( 'Cancel', 'haydi' ); ?>
						</button>
						<span id="wpc-deactivate-status" class="wpc-apply-status"></span>
					</div>
				</div>

				<!-- Proposed PHP execution (hidden until the AI proposes run_php) -->
				<div id="wpc-php-section" class="wpc-hidden">
					<div class="wpc-proposal-header">
						<span class="dashicons dashicons-editor-code"></span>
						<strong><?php esc_html_e( 'Run PHP', 'haydi' ); ?></strong>
					</div>
					<p id="wpc-php-reason" class="wpc-proposal-reason"></p>
					<pre id="wpc-php-code" class="wpc-diff wpc-diff--after" style="max-height:200px;"></pre>
					<div class="wpc-proposal-actions">
						<button id="wpc-btn-confirm-php" class="button button-primary">
							<?php esc_html_e( 'Execute PHP', 'haydi' ); ?>
						</button>
						<button id="wpc-btn-cancel-php" class="button">
							<?php esc_html_e( 'Cancel', 'haydi' ); ?>
						</button>
						<span id="wpc-php-status" class="wpc-apply-status"></span>
					</div>
				</div>

				<!-- Proposed backup restore (hidden until the AI proposes restore_backup) -->
				<div id="wpc-restore-section" class="wpc-hidden">
					<div class="wpc-proposal-header">
						<span class="dashicons dashicons-backup"></span>
						<strong><?php esc_html_e( 'Restore from backup', 'haydi' ); ?></strong>
					</div>
					<p id="wpc-restore-path" class="wpc-proposal-path"></p>
					<p id="wpc-restore-backup-info" class="wpc-proposal-reason"></p>
					<p id="wpc-restore-reason" class="wpc-proposal-reason"></p>
					<div class="wpc-proposal-actions">
						<button id="wpc-btn-confirm-restore" class="button button-primary">
							<?php esc_html_e( 'Restore', 'haydi' ); ?>
						</button>
						<button id="wpc-btn-cancel-restore" class="button">
							<?php esc_html_e( 'Cancel', 'haydi' ); ?>
						</button>
						<span id="wpc-restore-status" class="wpc-apply-status"></span>
					</div>
				</div>

				<!-- Proposed file change (hidden until the AI proposes a write) -->
				<div id="wpc-proposal-section" class="wpc-hidden">
					<div class="wpc-proposal-file-row">
						<code id="wpc-proposal-filename" class="wpc-proposal-filename"></code>
						<span id="wpc-proposal-badge" class="wpc-proposal-badge"><?php esc_html_e( 'File change', 'haydi' ); ?></span>
					</div>
					<p id="wpc-proposal-dir" class="wpc-proposal-dir"></p>
					<p id="wpc-proposal-reason" class="wpc-proposal-reason"></p>

					<pre id="wpc-diff-unified" class="wpc-diff-unified"></pre>

					<div class="wpc-proposal-actions">
						<button id="wpc-btn-apply" class="button button-primary">
							<?php esc_html_e( 'Apply Change', 'haydi' ); ?>
						</button>
						<button id="wpc-btn-reject" class="button">
							<?php esc_html_e( 'Reject', 'haydi' ); ?>
						</button>
						<span id="wpc-apply-status" class="wpc-apply-status"></span>
					</div>
				</div>
			</div>
		</div>

		<!-- AI chat panel — full-screen by default. -->
		<div class="wpc-panel wpc-panel--chat">
			<div class="wpc-chat-messages" id="wpc-chat-messages">
				<div class="wpc-message wpc-message--assistant"><?php echo wp_kses_post( __( 'Howdy! 👋 I\'m your WordPress assistant. I can help you with things like:<ul><li>📂 Browsing, reading, editing, moving, or deleting plugin/theme files</li><li>🔌 Installing, activating, or deactivating plugins</li><li>🗄️ Reviewing or updating site data</li><li>⚙️ Executing PHP snippets in the WordPress context</li><li>🌐 Fetching public web pages for reference (docs, examples)</li><li>🔍 Inspecting your site\'s setup</li></ul>For fast prototyping only — review AI output carefully. Consider <a href="https://developer.wordpress.com/studio/" target="_blank" class="wpc-track-studio">WordPress Studio</a> for a more reliable solution.<br><br>What would you like to work on today?', 'haydi' ) ); ?></div>
			</div>
			<div id="wpc-auto-accept-warning" class="wpc-auto-accept-warning wpc-hidden">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<?php esc_html_e( 'Auto-accept is on — all AI changes will be applied without confirmation.', 'haydi' ); ?>
			</div>
			<div class="wpc-chat-input-wrap">
				<div id="wpc-attachments" class="wpc-attachments wpc-hidden"></div>
				<div class="wpc-composer">
					<textarea id="wpc-chat-input" class="wpc-chat-input"
						placeholder="<?php echo $connector_ready ? esc_attr__( 'Ask the AI something…', 'haydi' ) : esc_attr__( 'Configure an AI connector under Settings → Connectors to start chatting.', 'haydi' ); ?>"
						rows="3"<?php disabled( ! $connector_ready ); ?>></textarea>
					<div class="wpc-composer__bar">
						<div class="wpc-composer__bar-left">
							<button id="wpc-btn-attach" type="button" class="wpc-icon-btn"
								title="<?php esc_attr_e( 'Attach a text file', 'haydi' ); ?>"<?php disabled( ! $connector_ready ); ?>>
								<span class="dashicons dashicons-paperclip"></span>
							</button>
							<input id="wpc-attach-input" type="file" class="wpc-hidden"
								accept=".txt,.md,.php,.js,.css,.json,.html,.xml,.csv,.ts,.yaml,.yml,.sh,.sql">
						</div>
						<button id="wpc-btn-send" class="wpc-send-btn" title="<?php esc_attr_e( 'Send message', 'haydi' ); ?>"<?php disabled( ! $connector_ready ); ?>>
							<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
								<path d="M8 13V3M8 3L4 7M8 3L12 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
						</button>
					</div>
				</div>
			</div>
			<div class="wpc-chat-meta">
				<div class="wpc-model-menu">
					<button id="wpc-btn-model-menu" class="wpc-model-menu__button"
						aria-haspopup="true" aria-expanded="false"
						title="<?php esc_attr_e( 'Model preference. Choose the provider and model used for Haydi requests.', 'haydi' ); ?>">
						<span class="dashicons dashicons-superhero-alt"></span>
						<span id="wpc-current-model-label"><?php esc_html_e( 'Model', 'haydi' ); ?></span>
						<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
					</button>
					<div id="wpc-model-menu" class="wpc-model-menu__popover wpc-hidden" role="menu"></div>
				</div>
				<label class="wpc-auto-accept">
					<input type="checkbox" id="wpc-auto-accept">
					<span class="wpc-toggle" aria-hidden="true"></span>
					<?php esc_html_e( 'Auto-accept', 'haydi' ); ?>
					<span class="wpc-tip" tabindex="0"
						data-tip="<?php esc_attr_e( 'When on, AI changes are applied automatically without asking for your approval. Turns off when you reload the page.', 'haydi' ); ?>"
						aria-label="<?php esc_attr_e( 'When on, AI changes are applied automatically without asking for your approval. Turns off when you reload the page.', 'haydi' ); ?>"></span>
				</label>
			</div>
		</div>

		</div><!-- /.wpc-main -->

		<!-- ── Sidebar ─────────────────────────────────────────────────── -->
		<div class="wpc-sidebar" id="wpc-sidebar">

			<!-- Session stats card -->
			<div class="wpc-sidebar-card">
				<div class="wpc-sidebar-card__header">
					<span class="dashicons dashicons-chart-bar"></span>
					<strong><?php esc_html_e( 'This session', 'haydi' ); ?></strong>
				</div>
				<div class="wpc-sidebar-card__body wpc-session-stats">
					<div class="wpc-stat-row">
						<span class="wpc-stat-row__label">
							<?php esc_html_e( 'Changes applied', 'haydi' ); ?>
							<span class="wpc-tip" tabindex="0"
								data-tip="<?php esc_attr_e( 'How many file edits the AI has made to your site during this session.', 'haydi' ); ?>"
								aria-label="<?php esc_attr_e( 'How many file edits the AI has made to your site during this session.', 'haydi' ); ?>"></span>
						</span>
						<span class="wpc-stat-row__value" id="wpc-metric-session-applies">0</span>
					</div>
					<div class="wpc-stat-row" id="wpc-session-tokens-badge">
						<span class="wpc-stat-row__label">
							<?php esc_html_e( 'Tokens used', 'haydi' ); ?>
							<span class="wpc-tip" tabindex="0"
								data-tip="<?php esc_attr_e( 'The total amount of text the AI has processed in this chat. Tokens are small chunks of text — roughly one word each.', 'haydi' ); ?>"
								aria-label="<?php esc_attr_e( 'The total amount of text the AI has processed in this chat. Tokens are small chunks of text — roughly one word each.', 'haydi' ); ?>"></span>
						</span>
						<span class="wpc-stat-row__value" id="wpc-metric-session-tokens">0</span>
					</div>
					<div class="wpc-stat-row" id="wpc-context-badge">
						<span class="wpc-stat-row__label">
							<?php esc_html_e( 'Context window', 'haydi' ); ?>
							<span class="wpc-tip" tabindex="0"
								data-tip="<?php esc_attr_e( 'How much of the AI\'s short-term memory is in use. Above 80%, the AI may start forgetting earlier parts of the chat.', 'haydi' ); ?>"
								aria-label="<?php esc_attr_e( 'How much of the AI\'s short-term memory is in use. Above 80%, the AI may start forgetting earlier parts of the chat.', 'haydi' ); ?>"></span>
						</span>
						<span class="wpc-stat-row__value" id="wpc-metric-context">--</span>
					</div>
					<details class="wpc-advanced-settings">
						<summary><?php esc_html_e( 'Advanced settings', 'haydi' ); ?></summary>
						<div class="wpc-stat-row wpc-stat-row--tokens">
							<label for="wpc-max-tokens" class="wpc-stat-row__label">
								<?php esc_html_e( 'Max response length', 'haydi' ); ?>
								<span class="wpc-tip" tabindex="0"
									data-tip="<?php esc_attr_e( 'The longest response the AI can write at once. Increase this if replies feel incomplete or cut off.', 'haydi' ); ?>"
									aria-label="<?php esc_attr_e( 'The longest response the AI can write at once. Increase this if replies feel incomplete or cut off.', 'haydi' ); ?>"></span>
							</label>
							<input type="number" id="wpc-max-tokens" class="wpc-max-tokens-input" min="1024" max="32768" step="1024"
								value="<?php echo esc_attr( $current_max_tokens ); ?>">
							<span id="wpc-tokens-status" class="wpc-muted"></span>
						</div>
						<label class="wpc-stat-row wpc-stat-row--toggle">
							<span class="wpc-stat-row__label">
								<?php esc_html_e( 'Playground preflight', 'haydi' ); ?>
								<span class="wpc-tip" tabindex="0"
									data-tip="<?php esc_attr_e( 'Run approved PHP writes in WordPress Playground before writing them to disk. Catches errors before they affect your live site, but slows down each write.', 'haydi' ); ?>"
									aria-label="<?php esc_attr_e( 'Run approved PHP writes in WordPress Playground before writing them to disk. Catches errors before they affect your live site, but slows down each write.', 'haydi' ); ?>"></span>
							</span>
							<input type="checkbox" id="wpc-playground-preflight" <?php checked( $playground_enabled ); ?>>
							<span id="wpc-playground-status" class="wpc-muted"></span>
						</label>
						<label class="wpc-stat-row wpc-stat-row--toggle">
							<span class="wpc-stat-row__label">
								<?php esc_html_e( 'Usage analytics', 'haydi' ); ?>
								<span class="wpc-tip" tabindex="0"
									data-tip="<?php esc_attr_e( 'Send anonymous usage data to Automattic (Tracks). Opt in to help us improve the plugin. No personal data is shared.', 'haydi' ); ?>"
									aria-label="<?php esc_attr_e( 'Send anonymous usage data to Automattic (Tracks). Opt in to help us improve the plugin. No personal data is shared.', 'haydi' ); ?>"></span>
							</span>
							<input type="checkbox" id="wpc-enable-tracks" <?php checked( $tracks_enabled ); ?>>
							<span class="wpc-muted"></span>
						</label>
					</details>
					<details class="wpc-advanced-settings">
						<summary><?php esc_html_e( 'Remote access', 'haydi' ); ?></summary>
						<div class="wpc-remote-access">
							<p class="wpc-muted wpc-remote-access__desc"><?php esc_html_e( 'Generate API tokens to connect local AI tools (Claude Code, Codex) to this site via MCP.', 'haydi' ); ?></p>
							<div id="wpc-tokens-list"></div>
							<div class="wpc-remote-access__form">
								<input type="text" id="wpc-token-label" class="wpc-remote-access__input"
									placeholder="<?php esc_attr_e( 'Label (optional)', 'haydi' ); ?>">
								<button type="button" id="wpc-btn-generate-token" class="button button-secondary wpc-remote-access__btn">
									<?php esc_html_e( 'Generate', 'haydi' ); ?>
								</button>
							</div>
							<div id="wpc-new-token-display" class="wpc-hidden wpc-remote-access__token-display">
								<p class="wpc-remote-access__once-notice"><?php esc_html_e( 'Copy now — not shown again.', 'haydi' ); ?></p>
								<code id="wpc-new-token-value" class="wpc-remote-access__token-value"></code>
								<button type="button" id="wpc-btn-copy-token" class="button button-secondary wpc-remote-access__copy-btn">
									<?php esc_html_e( 'Copy to clipboard', 'haydi' ); ?>
								</button>
								<details class="wpc-remote-access__details">
									<summary><?php esc_html_e( 'Connect with Claude Code', 'haydi' ); ?></summary>
									<p class="wpc-remote-access__snippet-label"><?php esc_html_e( 'Add to ~/.claude/claude_code_config.json:', 'haydi' ); ?></p>
									<pre id="wpc-mcp-config-snippet" class="wpc-remote-access__snippet"
										data-mcp-url="<?php echo esc_attr( rest_url( 'haydi/v1/mcp' ) ); ?>"></pre>
								</details>
							</div>
						</div>
					</details>
					<div class="wpc-session-actions">
						<button type="button" id="wpc-btn-compact" class="wpc-session-action-btn">
							<span class="dashicons dashicons-editor-contract"></span>
							<?php esc_html_e( 'Compact', 'haydi' ); ?>
						</button>
						<button type="button" id="wpc-btn-download" class="wpc-session-action-btn">
							<span class="dashicons dashicons-download"></span>
							<?php esc_html_e( 'Download .txt', 'haydi' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- History card -->
			<div class="wpc-sidebar-card">
				<div class="wpc-sidebar-card__header">
					<span class="dashicons dashicons-list-view"></span>
					<strong><?php esc_html_e( 'My chats', 'haydi' ); ?></strong>
				</div>
				<div class="wpc-sidebar-card__body" id="wpc-sidebar-history-list">
					<p class="wpc-muted wpc-sidebar-empty"><?php esc_html_e( 'Loading…', 'haydi' ); ?></p>
				</div>
			</div>

			<!-- Audit trail card -->
			<div class="wpc-sidebar-card">
				<div class="wpc-sidebar-card__header">
					<span class="dashicons dashicons-shield"></span>
					<strong><?php esc_html_e( 'Audit Trail', 'haydi' ); ?></strong>
					<a href="<?php echo esc_url( admin_url( 'tools.php?page=haydi-audit-log' ) ); ?>" class="wpc-sidebar-card__header-link">
						<?php esc_html_e( 'View all', 'haydi' ); ?>
					</a>
				</div>
				<div class="wpc-sidebar-card__body" id="wpc-sidebar-audit-list">
					<p class="wpc-muted wpc-sidebar-empty"><?php esc_html_e( 'No activity yet.', 'haydi' ); ?></p>
				</div>
			</div>


		</div><!-- /.wpc-sidebar -->

	</div><!-- /.wpc-workspace -->
</div><!-- /.wrap -->

<?php if ( ! get_option( 'haydi_onboarded', false ) ) : ?>
<div class="wpc-welcome-overlay" id="wpc-welcome-overlay" role="dialog" aria-modal="true" aria-labelledby="wpc-welcome-title">
	<div class="wpc-welcome-modal">
		<div class="wpc-welcome-modal__header">
			<h2 id="wpc-welcome-title"><?php esc_html_e( 'Welcome to Haydi', 'haydi' ); ?></h2>
			<p class="wpc-welcome-modal__subtitle"><?php esc_html_e( 'Your AI Autopilot — manage your site directly from WP-Admin using any AI provider.', 'haydi' ); ?></p>
		</div>
		<div class="wpc-welcome-modal__body">
			<label class="wpc-welcome-tracks-label">
				<input type="checkbox" id="wpc-welcome-enable-tracks">
				<span>
					<strong><?php esc_html_e( 'Help improve Haydi with usage analytics', 'haydi' ); ?></strong><br>
					<span class="wpc-muted"><?php esc_html_e( 'Send anonymous usage data to Automattic (Tracks). No personal data is shared. You can change this later in Advanced settings.', 'haydi' ); ?></span>
				</span>
			</label>
		</div>
		<div class="wpc-welcome-modal__footer">
			<button type="button" id="wpc-welcome-confirm" class="button button-primary">
				<?php esc_html_e( 'Get started', 'haydi' ); ?>
			</button>
		</div>
	</div>
</div>
<?php endif; ?>
