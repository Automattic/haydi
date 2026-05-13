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
| `AjaxHandlersPluginActionsTest` | `Haydi_Plugin_Tool::list_plugins_for_ai` JSON shape and active-flag accuracy; `Haydi_PHP_Tool::handle_run_php` output capture, no-output placeholder, exception/Error reporting, output-before-throw; `Plugin_Tool::handle_install_plugin` slug validation; `Plugin_Tool::handle_activate_plugin` path validation, file-not-found, and auto-deactivation when the post-activation health check fails; `Plugin_Tool::handle_deactivate_plugin` empty-input rejection and success path |
| `AjaxHandlersChatTest` | `Haydi_Chat_Store::handle_save_chat` / `handle_load_chat` — token-usage persistence, per-message timestamps, display-log round-trip |
| `AjaxHandlersApplyWriteTest` | `Haydi_File_Tool::handle_apply_write` — `token_get_all` PHP lint gate, post-write loopback health check via `Health_Check`, automatic restore-from-backup on health failure |
| `AjaxHandlersFileMoveTest` | `Haydi_File_Tool::handle_move_file` two-step undo — destination is removed and source is restored from backup when the post-move health check fails; healthy moves leave the destination intact and skip restore |
| `AjaxHandlersQueryTest` | `Haydi_Query_Tool::handle_execute_query` — SELECT/SHOW skip the loopback health check, UPDATE-style queries run it, unhealthy site after a write returns the recovery-mode warning |
| `HealthCheckTest` | `Haydi_Health_Check` — `verify()` against healthy/empty/critical-error/`WP_Error` responses; `verify_or_revert()` skip-on-healthy + undo invocation + restore-success vs restore-failed message formatting; `verify_or_warn()` recovery-mode message |
| `AjaxHandlersLinkingSectionTest` | `Haydi_Ajax_Handlers::build_linking_section` / `build_rule_10` — system-prompt linking guidance across the four `edit_plugins` / `edit_themes` cap combinations (both, plugins-only, themes-only, neither) |

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

Playwright tests against a live wp-env instance on `http://localhost:9888`. Covers all AJAX actions including:

- `read_file` with security-rejection cases (paths outside roots)
- `execute_query` 200-row truncation and non-SELECT write queries
- `save_settings` out-of-range value handling
- `verify()` — missing nonce and invalid nonce both return 403
- Chat CRUD: save, list, load, update-in-place, delete
- `MAX_CHATS=50` eviction (saving 51 chats drops the oldest)
- Missing required parameters for move, copy, load, delete, and save chat
- `run_php` — output capture, no-output placeholder, exception reporting, empty/whitespace rejection, WordPress context access
- `install_plugin` — invalid slug rejection (uppercase, path traversal, slash)
- `activate_plugin` — path traversal, non-PHP extension, file-not-found rejection
- `deactivate_plugin` — empty plugin rejection
- `chat-pending.spec.js` — typing a new message while a pending proposal is outstanding cancels the proposal cleanly without orphaning a `tool_use` block

### What is not yet covered by automated tests

- **`Haydi_AI_Client`** — requires stubbing WordPress 7.0 AI Connector DTOs (`ModelMessage`, `UserMessage`, etc.) that are only available inside a running WP 7.0 instance.
- **`FilesystemGuard` write/delete/move/copy/`backup_dir_recursive`** — require `wp_mkdir_p` and `WP_Filesystem` stubs; the happy paths are exercised by the integration tests instead. (`restore_latest_backup` is unit-tested.)
- **`handle_chat` rate-limit (429), `MAX_LOOP` exhaustion, `MAX_MESSAGES_BYTES` trim** — require a live AI connector to exercise the agentic loop.
- **`Haydi_Fetch_Url_Tool::fetch()` cURL-pin (`CURLOPT_RESOLVE`) and `redirection => 0` behaviour** — require a live HTTP transport; pre-request validation is unit-tested.

---

## Internals worth knowing

A few non-obvious conventions that make the code easier to extend:

- **Per-tool class layout.** AJAX handlers are split by capability area under `includes/tools/`: `class-file-tool.php` (read/write/delete/move/copy/delete_dir), `class-plugin-tool.php` (install/activate/deactivate + `list_plugins`), `class-query-tool.php`, `class-php-tool.php`, and `class-fetch-url-tool.php`. Each AJAX-exposed tool extends `Haydi_Ajax_Tool_Base` (which owns `verify`, `post_param`, `require_param`, `dispatch_guard_result`) and implements `register()` to add its own `wp_ajax_*` hooks. `Haydi_Ajax_Handlers` constructs every tool, calls `register()` on each, and only owns the chat agentic loop, system-prompt builder, and a few non-tool endpoints (settings, audit clear, jetpack notice). `Haydi_Chat_Store` (`includes/class-chat-store.php`) owns list/save/load/delete/record_apply and the `trim_messages_to_fit` helper. `Haydi_Fetch_Url_Tool` does not extend the base because it has no AJAX endpoint of its own — the chat dispatcher invokes `fetch_for_ai()` directly during the read-tool loop.

- **Health check + auto-revert.** `Haydi_Health_Check` (`includes/class-health-check.php`) is injected into every mutating tool (`File_Tool`, `Plugin_Tool`, `Query_Tool`, `PHP_Tool`) — read-only tools and `Chat_Store` do not receive it. After a successful mutation, the handler calls either `verify_or_revert($undo, $context)` (file ops, plugin activation — `$undo` is the per-op rollback closure) or `verify_or_warn($context)` (`delete_dir`, `run_query`, `run_php`, `install_plugin` — anything without a clean undo). Both probe the dedicated `haydi_health` AJAX endpoint via a loopback HTTP request pinned to `127.0.0.1` (the path is reused from `admin_url()` so Docker's host-mapped ports don't matter). The endpoint itself is registered only when `DOING_AJAX` and refuses non-loopback `REMOTE_ADDR`s so it can't be probed externally. `move_file`'s undo is the only multi-step one (delete dest + restore src); the rest delegate to `restore_latest_backup()` or `deactivate_plugins()`.

- **Approval-flow data tables.** All 11 approval-required tools (`write_file`, `edit`, `delete_file`, `move_file`, `copy_file`, `delete_dir`, `run_query`, `install_plugin`, `activate_plugin`, `deactivate_plugin`, `run_php`) are described once in two places:
  - **PHP** — `Haydi_Ajax_Handlers::APPROVAL_TOOLS` maps each tool name to its response key, fields, and audit-log verb. `handle_chat()` reads this to package a pending proposal.
  - **JS** — `PROPOSALS` in `assets/admin.js` carries the section/button/status DOM IDs, AJAX action, payload keys, label, confirm prompt, and per-state messages. `setupProposal()` wires the show/hide/confirm/cancel handlers from each entry.

  Adding a new approvable tool means adding one entry on each side (plus the matching PHP `handle_*` method on the appropriate tool class and the HTML section) — no need to add another switch case or copy a click-handler block.

- **AI tool schemas.** `Haydi_AI_Client::TOOL_SCHEMAS` is a single constant listing every tool's name, description, and field descriptions. `get_function_declarations()` expands it into provider-agnostic `FunctionDeclaration` objects.

- **Test halt sentinel.** Production `wp_send_json_success/error` call `wp_die()` and never return. The handler test fixtures stub them to capture the response then `throw new \HaydiTestHaltException` (declared in `tests/unit/bootstrap.php`); the call helpers in each test catch it. This stops a downstream code path from masking the first error a handler emitted.

- **Backup filenames.** Each backup filename includes a 16-char random hex token (`<basename>.<timestamp>.<token>.bak`) so the `wp-content/uploads/haydi-backups/` directory is not enumerable on web servers that ignore `.htaccess`. `restore_latest_backup()` still picks the newest by lexicographic timestamp sort.

- **Jetpack prompt-section cache.** `Haydi_Jetpack_Context::to_prompt_section()` is wrapped in a 5-minute transient (`PROMPT_CACHE_KEY`). Empty results are cached too, so disconnected installs do not re-probe Jetpack on every chat turn.

- **Backup restore tools.** `list_backups(path?)` is a read-only auto-executing tool that scans `wp-content/uploads/haydi-backups/` and returns backup filenames with timestamps. `restore_backup(backup_file, original_path, reason)` is an approval tool handled by `Haydi_File_Tool::handle_restore_backup()` — it validates the backup is within the backup directory, calls `FilesystemGuard::restore_specific_backup()` (which creates a new backup of the current file first), then runs the health check. If the site breaks after restore, `verify_or_revert()` rolls back to the pre-restore backup.

- **Two link schemes in chat.** `renderAssistantMarkdown()` only resolves `[label](url)` markdown when the URL begins with `/wp-admin/` (renders as `<a>` to core's plugin/theme editor) or `wpc-view:<absolute-path>` (renders as a button that lazily fetches via `haydi_read_file` and expands an inline read-only `<pre>` below the message). The system prompt is built per-request from `current_user_can('edit_plugins')` / `current_user_can('edit_themes')` — those caps already fold in `DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, and multisite super-admin rules, so the AI is told to use `wpc-view:` whenever a core editor would 404 or fail capability checks.

- **Suggestion chip pool.** `Haydi_Plugin::get_suggestion_pool()` returns a category-keyed array (`file`, `db`, `plugin`, `php`) of i18n prompts that is localised under `haydi.suggestions`. `pickSuggestionChips()` in `assets/admin.js` selects one entry per non-empty category for each fresh chat, so adding a prompt to one bucket only changes that bucket's rotation. Chips are removed from the DOM as soon as the user sends a message or loads an existing chat; they don't appear in `displayLog` and are never persisted.

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

Produces `dist/haydi.zip` containing only the installable plugin files — no tests, no dev tooling, no vendor code.

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
├── haydi.php
├── includes/
│   ├── class-filesystem-guard.php
│   ├── class-health-check.php        # loopback probe + verify_or_revert / verify_or_warn
│   ├── class-audit-logger.php
│   ├── class-jetpack-context.php
│   ├── class-ai-client.php
│   ├── class-chat-store.php  # list/save/load/delete + trim_messages_to_fit
│   ├── class-ajax-handlers.php       # chat loop, system prompt, dispatch
│   └── tools/
│       ├── class-ajax-tool-base.php  # verify/post_param/require_param/dispatch_guard_result
│       ├── class-file-tool.php       # read/write/delete/move/copy/delete_dir
│       ├── class-plugin-tool.php     # install/activate/deactivate + list_plugins
│       ├── class-query-tool.php      # run_query
│       ├── class-php-tool.php        # run_php
│       └── class-fetch-url-tool.php  # fetch_url + SSRF guard (no AJAX endpoint)
├── admin/
│   ├── main-page.php
│   └── audit-log-page.php
├── assets/
│   ├── admin.css
│   ├── admin.js                      # command-palette entry inlined via wp_add_inline_script
│   └── marked.min.js                 # markdown renderer (vendored)
├── tests/
│   ├── handlers.spec.js         # Playwright integration tests (all AJAX actions)
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
