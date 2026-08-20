# Haydi — Developer Guide

→ **[SETUP.md](SETUP.md)** for local dev quickstart (wp-env, PHP, Node versions).

### Machine-specific wp-env overrides

`.wp-env.override.json` is gitignored and merged on top of `.wp-env.json` by wp-env automatically. Use it for settings that only apply to your machine.

Example — if your machine routes Docker traffic through a local proxy (common with corporate VPNs or certain Docker Desktop configurations), WordPress's HTTP API won't be able to reach the internet from inside the container. Route it through the Docker Desktop proxy:

```json
{
  "config": {
    "WP_PROXY_HOST": "host.docker.internal",
    "WP_PROXY_PORT": "3128"
  }
}
```

---

## Testing

Three suites run independently — no shared state between PHP unit tests, JS unit tests, and integration tests.

### PHP unit tests (no running WordPress required)

```bash
vendor/bin/phpunit
# or: composer test:php
```

| Test file | What it covers |
|---|---|
| `IsPrivateIpTest` | `Haydi_Fetch_Url_Tool::is_private_ip()` — IPv4 RFC-1918, loopback, link-local; IPv6 loopback, ULA, IPv4-mapped, link-local; public IPs allowed |
| `CheckHostForSsrfTest` | `Haydi_Fetch_Url_Tool::resolve_and_validate_host()` — bare private/public IPs, bracketed IPv6, unresolvable `.invalid` TLD |
| `FetchUrlTest` | `Haydi_Fetch_Url_Tool::fetch()` pre-request validation — malformed URLs, non-http/https scheme, SSRF chain for RFC-1918/loopback/metadata IPs |
| `FilesystemGuardValidateTest` | `validate_path()` + `validate_new_path()` — root acceptance, all allowed extensions, nonexistent paths, disallowed extension, dotfile rejection, paths outside roots |
| `FilesystemGuardRestoreBackupTest` | `restore_latest_backup()` — newest-first selection, restore from backup, delete-on-no-backup, error paths |
| `AuditLoggerTest` | `log()` field structure, prepend order, `MAX_ENTRIES=200` trimming; `get_log()` happy path + corrupted-option fallback; `clear_log()` |
| `JetpackContextTest` | `Jetpack_Context` with stubbed Jetpack classes — `is_available`, `is_connected`, plan/modules/stats/posts/referrers/search/security/speed/sync collectors, prompt-section formatting |
| `JetpackContextUnavailableTest` | Same class with no Jetpack present — every method degrades to null/empty without errors (runs in a separate process) |
| `AccessControlTest` | the non-lowerable `edit_plugins` code-execution floor plus the additional filtered capability |
| `AjaxHandlersPluginActionsTest` | `Haydi_Plugin_Tool::list_plugins_for_ai` structured result shape and active-flag accuracy; `haydi_php_execute()` output capture, no-output placeholder, exception/Error reporting, output-before-throw; plugin Tool Implementation validation and activation auto-revert behavior |
| `AjaxApprovalHandlerTest` | the single browser Approval Pipeline Adapter: capability/nonce checks, direct `run_php` denial below the hard floor, token-mint denial, argument validation, catalog delegation, and result envelopes |
| `AjaxHandlersChatTest` | `Haydi_Chat_Store::handle_save_chat` / `handle_load_chat` — token-usage persistence, per-message timestamps, display-log round-trip |
| `AjaxHandlersApplyWriteTest` | `haydi_file_execute_write()` — `token_get_all` PHP lint gate, post-write loopback health check via `Health_Check`, automatic restore-from-backup on health failure |
| `AjaxHandlersFileMoveTest` | `haydi_file_execute_move()` two-step undo — destination is removed and source is restored from backup when the post-move health check fails; healthy moves leave the destination intact and skip restore |
| `AjaxHandlersQueryTest` | `haydi_query_execute()` — SELECT/SHOW skip the loopback health check, UPDATE-style queries run it, unhealthy site after a write returns the recovery-mode warning |
| `HealthCheckTest` | `Haydi_Health_Check` — `verify()` against healthy/empty/critical-error/`WP_Error` responses; `verify_or_revert()` skip-on-healthy + undo invocation + restore-success vs restore-failed message formatting; `verify_or_warn()` recovery-mode message |
| `AjaxHandlersLinkingSectionTest` | `Haydi_Ajax_Handlers::build_linking_section` / `build_rule_10` — system-prompt linking guidance across the four `edit_plugins` / `edit_themes` cap combinations (both, plugins-only, themes-only, neither) |
| `ApiTokenManagerTest` | covers generate/validate/list/revoke token lifecycle; issuer binding and live authorization; legacy, wrong-length, and unrecognized token rejection; independent storage of multiple tokens |
| `RestApiMcpTest` | covers MCP JSON-RPC routing in `handle_mcp()` — parse error, invalid request, initialize response, ping, tools/list shape, notifications (204), unknown method; Bearer-token permission including issuer capability revocation |
| `ToolCatalogTest` | canonical chat/MCP inventory, projection aliases, schema validation, approval policy, dispatch, and additive legacy compatibility |

### JS unit tests (no browser required)

```bash
npm run test:unit
```

| Test file | What it covers |
|---|---|
| `utils.test.js` | `esc()` XSS escaping and type coercion, `chatTitle()` (default title, 60-char truncation, non-string content skipping), `renderAssistantMarkdown()` link allowlist — `/wp-admin/` URLs render as `<a>`, `wpc-view:<path>` URLs render as toggle buttons that expand an inline read-only viewer; everything else stays escaped |
| `sanitize-messages.test.js` | `sanitizeMessages()` — strips trailing assistant `tool_use` blocks with no matching `tool_result`, injects synthetic results for mid-history orphans, preserves resolved tool-use chains |
| `usage-and-time.test.js` | `formatTokens()` thousands/millions formatting, `formatTimestamp()` same-day vs cross-day rendering |
| `suggestions.test.js` | `pickSuggestionChips()` — one chip per non-empty category, deterministic with a seeded rng, defensive against null/non-array buckets and empty pools |

### Integration tests (requires `npm run env:start`)

```bash
npm test
```

Playwright tests against a live wp-env instance on `http://localhost:9888`. Covers the shared approval endpoint and the remaining AJAX actions, including:

- `read_file` with security-rejection cases (paths outside roots)
- approved `run_query` Tool Executions, including 200-row truncation and non-SELECT writes
- `save_settings` out-of-range value handling
- `verify()` — missing nonce and invalid nonce both return 403
- Chat CRUD: save, list, load, update-in-place, delete
- `MAX_CHATS=50` eviction (saving 51 chats drops the oldest)
- Missing required parameters for move, copy, load, delete, and save chat
- approved `run_php` Tool Executions — output capture, no-output placeholder, exception reporting, empty/whitespace rejection, WordPress context access
- approved plugin Tool Executions — install slug validation, activation path/file validation, and deactivation input validation
- the generic approval Adapter rejects unknown, automatic, malformed, non-object, and unauthenticated requests; edit's string `"false"` cannot widen a single-match proposal into replace-all
- `chat-pending.spec.js` — one approval card and transcript lifecycle across success, error, decline, cancellation, legacy compatibility, and in-flight race protection
- Token generation, REST API file/status operations with Bearer token auth
- MCP initialize handshake, tools/list, tools/call, token revocation

### What is not yet covered by automated tests

- **`Haydi_AI_Client`** — requires stubbing WordPress 7.0 AI Connector DTOs (`ModelMessage`, `UserMessage`, etc.) that are only available inside a running WP 7.0 instance.
- **`FilesystemGuard` write/delete/move/copy/`backup_dir_recursive`** — require `wp_mkdir_p` and `WP_Filesystem` stubs; the happy paths are exercised by the integration tests instead. (`restore_latest_backup` is unit-tested.)
- **`handle_chat` rate-limit (429), `MAX_LOOP` exhaustion, `MAX_MESSAGES_BYTES` trim** — require a live AI connector to exercise the agentic loop.
- **`Haydi_Fetch_Url_Tool::fetch()` cURL-pin (`CURLOPT_RESOLVE`) and `redirection => 0` behaviour** — require a live HTTP transport; pre-request validation is unit-tested.
- **Built-in write operations** (`write_file`, `edit_file`, `run_php`, etc.) via token auth are covered by integration tests; MCP tool execution for write tools is not yet unit-tested (requires filesystem + health-check stubs).
- **`list_posts`, `list_users`, `list_options`** — new core read tools; covered by the integration test suite but not yet by dedicated unit tests.

---

## Internals worth knowing

A few non-obvious conventions that make the code easier to extend:

- **Access control.** Browser pages and AJAX handlers use `haydi_current_user_can_access()`. It requires WordPress's mapped `edit_plugins` capability as a hard code-execution floor and the filtered Haydi capability (`manage_options` by default) as an additional restriction. `edit_plugins` folds in multisite Super Admin checks plus `DISALLOW_FILE_EDIT` / file-modification policy, and `haydi_access_capability` cannot lower it. REST/MCP requests require a Bearer token unconditionally (`haydi_is_authorized_api_request()`); token validation rechecks the stored issuer through the same policy, while an authenticated request session is never sufficient on its own.

- **Editor model policy.** `Haydi_Settings_Page` registers the `haydi_editor_model` option and an administrator-only **Settings → Haydi** page through the native Settings API. `Haydi_Model_Policy` resolves that stored provider/model pair against current connector choices and localizes the resulting browser policy. For a non-administrator who independently has code-editing authority, `assets/admin.js` selects and disables the configured model; Administrators retain the normal picker. It is deliberately a UI policy, not server-side model authorization.

- **Per-tool layout.** Built-in Tool Implementations live under `includes/tools/`. Read-only file operations are in `class-file-tool.php`; WordPress content inventory is in `class-content-tool.php`; file mutations are in `class-file-actions.php`; plugin management, URL fetching, SQL, and PHP each have their own module. Each module registers its Tool Declarations and Tool Implementations once with `Haydi_Tool_Catalog`; class-based tools expose `register_tools($catalog)`, while the standalone mutation modules expose `haydi_register_*_tool()` functions. Direct REST Adapters remain beside the Implementations they invoke, while browser approval crosses one Adapter in `Haydi_Ajax_Handlers`. That class also owns the chat agentic loop, system-prompt builder, and non-tool endpoints. `Haydi_Chat_Store` owns list/save/load/delete/record_apply and `trim_messages_to_fit`.

- **Tool Catalog.** `Haydi_Tool_Catalog` is the canonical registry for Tool names, JSON Schemas, automatic-versus-approval policy, availability, chat/MCP projections, activity labels, and dispatch. `declarations()` gives the AI and MCP Adapters their provider-independent Tool Declarations; `dispatch()` normalizes projected names and arguments, returns a Tool Execution for automatic chat Tools, returns an Action Proposal for mutating chat Tools, and executes either kind for an already-authorized MCP request. After browser authorization, `execute_approved()` resolves the exact chat projection, rechecks approval policy and availability, then invokes the same registered Tool Implementation. A successful Tool Execution has one JSON-compatible `result`; it stays structured through chat and is serialized only by an Adapter whose transport requires text. The catalog does not contain filesystem, SQL, plugin, PHP, or provider serialization algorithms; those stay in their focused Implementations and Adapters.

- **Provider Tool selection.** Haydi supplies the same provider-independent Tool Declarations to every connector and leaves Tool selection at the connector/provider default. Do not send a custom `tool_choice`: accepted shapes differ between provider transports, while Anthropic, Google, and OpenAI all default to automatic selection when Tools are present.

- **Health check + auto-revert.** `Haydi_Health_Check` (`includes/class-health-check.php`) is injected into every mutating handler — the standalone execute functions receive it as a parameter; `Plugin_Tool` holds it directly. Read-only tools and `Chat_Store` do not receive it. After a successful mutation, the handler calls either `verify_or_revert($undo, $context)` (file ops, plugin activation — `$undo` is the per-op rollback closure) or `verify_or_warn($context)` (directory delete, SQL, PHP, plugin install — anything without a clean undo). Both probe the dedicated `haydi_health` AJAX endpoint via a loopback HTTP request pinned to `127.0.0.1`. The endpoint itself is registered only when `DOING_AJAX` and refuses non-loopback `REMOTE_ADDR`s. The move undo is the only multi-step one (delete dest + restore src); the rest delegate to `restore_latest_backup()` or `deactivate_plugins()`.

- **Approval Pipeline.** Every catalog-native approval Tool produces the same `pending_action` envelope: `{tool_name, label, arguments}`. The agent loop permits one Action Proposal at a time. The browser renders one approval card and posts approved work to `haydi_execute_approved_tool`; that authenticated Adapter delegates policy, normalization, and execution back to the Tool Catalog and returns `{tool_name, result}`. The browser uses that same structured `result` for UI presentation and to resolve the exact provider Tool call—there is no second pre-serialized copy. `APPROVAL_PRESENTATIONS` contains only Tool-specific labels, icons, warnings, confirmations, and success copy. Decline resolves the transcript without Tool Execution.

- **Adding Tools.** The preferred extension Seam is the `haydi_register_tools` action. Register a callback during plugin load and call `$catalog->register($definition, $implementation)` from it; the default catalog fires the action when it is built and then freezes, so late registration is rejected. A definition must provide one canonical name, description, object JSON Schema, `automatic` or `approval` effect, and at least one chat/MCP projection. Canonical and projected Tool names use the portable provider subset `^[A-Za-z_][A-Za-z0-9_-]{0,63}$`; malformed schemas and projection collisions are rejected during registration. A chat approval Tool adds proposal label and audit metadata, but does not add a browser endpoint: its registered Implementation automatically crosses the shared Approval Pipeline. Use projection names and input aliases only for compatibility—the Tool Implementation always receives canonical arguments.

- **Legacy Tool hooks.** Existing additive host integrations continue to work: `haydi_tool_schemas` and `haydi_execute_read_tool` are adapted onto chat declaration/execution, while `haydi_mcp_tools` and `haydi_mcp_execute_tool` can add MCP declarations/executors. Catalog MCP declarations are restored after the legacy filter, so legacy callbacks cannot remove or mutate built-ins and colliding additions are ignored. Historical `haydi_register_action_proposal()` registrations still render through the one approval card, then an explicit compatibility Adapter calls their declared AJAX action. Catalog-native approval Tools win collisions, while a legacy proposal may still approval-gate an automatic Tool as it did before. New integrations should use `haydi_register_tools` so a Tool Declaration, Tool Implementation, approval policy, and MCP exposure cannot drift apart.

- **Test halt sentinel.** Production `wp_send_json_success/error` call `wp_die()` and never return. The handler test fixtures stub them to capture the response then `throw new \HaydiTestHaltException` (declared in `tests/unit/bootstrap.php`); the call helpers in each test catch it. This stops a downstream code path from masking the first error a handler emitted.

- **Backup filenames.** Each backup filename includes a 16-char random hex token (`<basename>.<timestamp>.<token>.bak`) so the `wp-content/uploads/haydi-backups/` directory is not enumerable on web servers that ignore `.htaccess`. `restore_latest_backup()` still picks the newest by lexicographic timestamp sort.

- **Jetpack prompt-section cache.** `Haydi_Jetpack_Context::to_prompt_section()` is wrapped in a 5-minute transient (`PROMPT_CACHE_KEY`). Empty results are cached too, so disconnected installs do not re-probe Jetpack on every chat turn.

- **Backup restore tools.** `list_backups(path?)` scans `wp-content/uploads/haydi-backups/` and returns backup filenames with timestamps. `restore_backup(backup_file, original_path, reason)` is an approval-gated action implemented by `haydi_file_execute_restore_backup()` in `includes/tools/class-file-actions.php`; it validates the backup is within the backup directory, calls `FilesystemGuard::restore_specific_backup()` (which creates a new backup of the current file first), then runs the health check. If the site breaks after restore, `verify_or_revert()` rolls back to the pre-restore backup.

- **Two link schemes in chat.** `renderAssistantMarkdown()` only resolves `[label](url)` markdown when the URL begins with `/wp-admin/` (renders as `<a>` to core's plugin/theme editor) or `wpc-view:<absolute-path>` (renders as a button that lazily fetches via `haydi_read_file` and expands an inline read-only `<pre>` below the message). The system prompt is built per-request from `current_user_can('edit_plugins')` / `current_user_can('edit_themes')` — those caps already fold in `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, and multisite super-admin rules, so the AI is told to use `wpc-view:` whenever a core editor would 404 or fail capability checks.

- **Suggestion chip pool.** `Haydi_Plugin::get_suggestion_pool()` returns a category-keyed array (`file`, `db`, `plugin`, `php`) of i18n prompts that is localised under `haydi.suggestions`. `pickSuggestionChips()` in `assets/admin.js` selects one entry per non-empty category for each fresh chat, so adding a prompt to one bucket only changes that bucket's rotation. Chips are removed from the DOM as soon as the user sends a message or loads an existing chat; they don't appear in `displayLog` and are never persisted.

- **Remote Access architecture.**
  - *Token manager:* plaintext shown once at generation, SHA-256 hash and issuer user ID stored in `wp_options`; 64-char hex token (32 random bytes); prefix stored for UI display. Validation rechecks the issuer's live `edit_plugins` floor and configured Haydi capability, so demotion revokes access and ownerless legacy tokens are rejected. `Haydi_Api_Token_Manager` handles generate/validate/list/revoke independently of any HTTP layer. The UI lives inside a "Remote access" `<details>` row inside the existing **Advanced settings** collapsible in the sidebar — not a separate card.
  - *REST API:* `Haydi_Rest_Api` is instantiated at the bottom of `haydi.php` alongside `Haydi_Ajax_Handlers`; all routes share one `check_permission()` callback, which requires a valid Bearer token unconditionally — a logged-in WordPress session is never sufficient by itself. The file-action, query, and PHP modules add their routes via `rest_api_init`.
  - *MCP endpoint:* `POST /wp-json/haydi/v1/mcp` speaks the MCP Streamable HTTP transport (JSON-RPC 2.0); handles `initialize`, `ping`, `tools/list`, `tools/call`, and `notifications/*`. `tools/list` and `tools/call` delegate to the Tool Catalog's MCP projection and dispatch. The `haydi_mcp_tools` and `haydi_mcp_execute_tool` filters remain compatibility Adapters for existing integrations.

---

## Manual smoke testing

`tests/run-prompt.js` opens a headed Chromium browser, logs into wp-admin, sends a prompt, and auto-approves every proposal until the chat ends:

```bash
node tests/run-prompt.js "create a contact-form plugin with three files"
```

The browser stays open after the run so you can inspect the result. Requires wp-env to be running (`npm run env:start`).

---

## Building a distribution zip

```bash
npm run dist
```

Produces `dist/haydi.zip`, a complete install containing every built-in tool. The zip root folder is `haydi/` so WordPress can install it directly.

The zip includes the plugin's GPL license and bundled-library notices. It does
not contain tests, dev tooling, or vendor code.

---

## Releasing a new version

**1. Bump the version and build locally:**

```bash
npm run release -- <version>
# e.g. npm run release -- 1.1.0
```

This updates the `Version:` header in `haydi.php`, the `Stable tag:` in `readme.txt`, prepends a changelog entry, builds `dist/haydi.zip`, commits the changes, and creates a local `v<version>` git tag.

**2. Push the commit and publish the GitHub release:**

```bash
git push
npm run gh:release
```

`gh:release` pushes the tag and creates a GitHub release with `dist/haydi.zip` attached.

**Re-releasing the current version** (e.g. if the tag or GitHub release is missing but `haydi.php` already has the right version):

```bash
npm run gh:release
```

The script reads the version from `haydi.php` directly, so it works even when `npm run release` was skipped or partially run.

---

## File structure

```
haydi/
├── haydi.php                         # plugin bootstrap
├── includes/
│   ├── functions.php                 # legacy action registry + API authorization helpers
│   ├── class-filesystem-guard.php
│   ├── class-health-check.php        # loopback probe + verify_or_revert / verify_or_warn
│   ├── class-audit-logger.php
│   ├── class-jetpack-context.php
│   ├── class-tool-catalog.php        # canonical declarations, projections, policy, dispatch
│   ├── class-ai-client.php           # AI Connector DTO Adapter for catalog declarations
│   ├── class-chat-store.php          # list/save/load/delete + trim_messages_to_fit
│   ├── class-ajax-handlers.php       # chat loop + single browser approval Adapter
│   ├── class-api-token-manager.php   # generate/validate/list/revoke long-lived API tokens
│   ├── class-rest-api.php            # REST routes + MCP Streamable HTTP catalog Adapter
│   └── tools/
│       ├── class-ajax-tool-base.php  # shared capability, nonce, and request helpers
│       ├── class-file-tool.php       # list/read/search/list_backups + haydi_read_file AJAX
│       ├── class-content-tool.php    # list_posts/list_users/list_options shared execution
│       ├── class-file-actions.php    # write/edit/delete/move/copy/delete_dir/restore_backup
│       ├── class-plugin-tool.php     # install/activate/deactivate + list_plugins
│       ├── class-query-tool.php      # SQL registration, REST Adapter, and execution
│       ├── class-php-tool.php        # PHP registration, REST Adapter, and execution
│       └── class-fetch-url-tool.php  # fetch_url + SSRF guard (no AJAX endpoint)
├── admin/
│   ├── main-page.php
│   └── audit-log-page.php
├── assets/
│   ├── admin.css
│   ├── admin.js                      # command-palette entry inlined via wp_add_inline_script
│   └── marked.min.js                 # markdown renderer (vendored)
├── tests/
│   ├── handlers.spec.js         # Playwright: shared approval + remaining AJAX actions
│   ├── chat-pending.spec.js     # Playwright: pending-proposal cancel-on-new-message flow
│   ├── run-prompt.js            # Manual headed-browser smoke test
│   └── unit/
│       ├── bootstrap.php        # PHPUnit bootstrap (WP stubs, constants, halt sentinel)
│       ├── IsPrivateIpTest.php
│       ├── CheckHostForSsrfTest.php
│       ├── FetchUrlTest.php
│       ├── FilesystemGuardValidateTest.php
│       ├── FilesystemGuardRestoreBackupTest.php
│       ├── AuditLoggerTest.php
│       ├── JetpackContextTest.php
│       ├── JetpackContextUnavailableTest.php
│       ├── AjaxHandlersPluginActionsTest.php
│       ├── AjaxHandlersChatTest.php
│       ├── AjaxHandlersApplyWriteTest.php
│       ├── AjaxHandlersFileMoveTest.php
│       ├── AjaxHandlersQueryTest.php
│       ├── HealthCheckTest.php
│       ├── AjaxHandlersLinkingSectionTest.php
│       ├── ApiTokenManagerTest.php
│       ├── RestApiMcpTest.php
│       ├── ToolCatalogTest.php
│       ├── stubs/
│       │   └── JetpackStubs.php  # Stand-in Jetpack classes for the unit tests
│       └── js/
│           ├── utils.test.js
│           ├── sanitize-messages.test.js
│           ├── usage-and-time.test.js
│           └── suggestions.test.js
├── phpunit.xml.dist
├── jest.config.js
├── playwright.config.js
├── eslint.config.js
├── phpcs.xml
├── .wp-env.json
├── package.json
├── composer.json
├── SETUP.md
├── DEVELOPER.md
└── README.md
```
