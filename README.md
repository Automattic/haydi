# Haydi — Your AI Autopilot

Use any AI provider (via WordPress 7.0 Connectors) to inspect and modify
WordPress files directly from WP-Admin — within strict guardrails. No shell,
no SSH.

Inspired by [Matt Mullenweg](https://ma.tt) to build this. Props to [Cem Ünalan](https://blog.cemunalan.com.tr/) for the original idea of a plugin-generating plugin.

**[Try it in WordPress Playground →](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/automattic/haydi/trunk/blueprint.json)**

### UI

The page is a single full-screen chat. There is no built-in file browser or editor — file references in the AI's replies are clickable and adapt to what your site actually allows:

- If WordPress core's `plugin-editor.php` / `theme-editor.php` are available for the file type, the AI links straight to them so you can hand-edit in a new tab.
- Otherwise (e.g. on managed hosts that set `DISALLOW_FILE_EDIT` or `DISALLOW_FILE_MODS`, or for files outside plugin/theme dirs), the link expands a read-only inline viewer below the message and the AI is told to point you at SFTP / WP-CLI / your local IDE for edits.

A fresh chat greets you with a row of suggestion chips spanning the assistant's capabilities (one prompt each from file-ops, database, plugins, and PHP). Click a chip to drop the prompt into the input. The chips disappear once you send your first message, and a different set is rolled the next time you start a new chat.

When the AI proposes a file change a panel slides in above the chat showing a unified diff against the live on-disk content (so the diff is always current, not whatever you happen to have open in another tab). The panel disappears as soon as you approve, reject, or move on.

---

## Configuration

The AI assistant always has access to `wp-content/plugins/` and `wp-content/themes/`.
Nothing else is ever accessible (not core, not `wp-config.php`, not `.htaccess`).

Optionally create `wp-content/uploads/ai-edits/` as a scratch directory.

If [Jetpack](https://jetpack.com/) is installed and connected, the plugin enriches the system prompt with site-specific context (stats, top posts, referrers, search terms, active modules, plan tier, speed scores, social connections, security data) so suggestions are tailored to the site rather than generic. Without Jetpack, a dismissable banner appears at the top of the chat panel pointing to install/connect; everything else still works.

You can also launch the assistant from anywhere in WP-Admin via the command palette (Cmd/Ctrl+K → "Interact with AI").

### Access control

Haydi requires WordPress's mapped `edit_plugins` capability as a hard floor,
plus `manage_options` by default. On a normal single site this admits trusted
Administrators. On Multisite, core denies `edit_plugins` to ordinary site
Administrators, so only Super Admins can use Haydi. Core also denies this
capability when file editing or file modifications are disabled.

Haydi includes tools that can modify files, run SQL and PHP, and change
plugin state — `run_php` in particular can be used to grant the calling user
any capability, including `manage_options` itself, so a lower floor is not a
meaningful boundary. Mutating actions still require approval in the browser,
but that approval comes from the same user who already has full access.

The additional capability is filterable when a site needs to restrict access
further:

```php
add_filter( 'haydi_access_capability', static fn() => 'manage_network_options' );
```

This filter cannot lower the `edit_plugins` floor. Use **Settings → Haydi** to
restrict which model non-administrators with code-editing authority can pick.
This is a browser-interface policy only and does not add model authorization
to REST or MCP requests.

### Remote Access (MCP / REST API)

Generate an API token under **Advanced settings** in the Haydi sidebar (Tools → Haydi) to connect local AI tools directly to your site.

**Claude Code** (and any MCP-compatible tool): add to `~/.claude/claude_code_config.json`:

```json
{
  "mcpServers": {
    "haydi": {
      "url": "https://yoursite.com/wp-json/haydi/v1/mcp",
      "headers": { "Authorization": "Bearer <your-token>" }
    }
  }
}
```

Claude Code can then use all Haydi tools (`haydi_list_files`, `haydi_list_posts`, `haydi_write_file`, `haydi_run_query`, etc.) as MCP tools — no browser needed.

The same token also works against the REST API directly: `GET /wp-json/haydi/v1/files`, `GET /wp-json/haydi/v1/file`, etc. Write operations execute immediately when authenticated via token; the token is the approval gate. Tokens are bound to the user who generated them, and that user's Haydi access is rechecked on every request. Tokens created before issuer binding was introduced are invalid and must be regenerated.

`GET /wp-json/haydi/v1/status` returns site info plus an `allowed_roots` array — the same paths exposed by the `haydi_get_allowed_roots` MCP tool — so clients can discover valid write targets without guessing.

---

## How it works

```
Browser                   PHP (this plugin)           AI Connector (WP Connectors)
  │                             │                              │
  │── user message ────────────►│                              │
  │                             │── messages + tools ─────────►│
  │                             │◄── tool_use (list / read)    │
  │                             │   [execute locally]          │
  │                             │── tool_result ──────────────►│
  │                             │  (loop until done or approval needed)
  │                             │◄── tool_use (fetch / write / query …)
  │                             │   [NOT executed yet]         │
  │◄── Action Proposal ─────────│                              │
  │   [one approval card: URL, diff, SQL, PHP, …]              │
  │── "Approve" ────────────────►│                              │
  │                             │   [backup + write / execute] │
  │◄── success ─────────────────│                              │
```

`list_files`, `read_file`, `search_files`, `list_plugins`, `list_posts`, `list_users`, and `list_options` run automatically. In browser chat, `fetch_url` pauses so the user can inspect and approve the exact outbound URL before any request is sent. Everything that mutates the filesystem, database, or plugin state also pauses for human approval.

Token-authenticated MCP and REST requests execute `fetch_url` immediately; the token is the approval gate for those remote transports.

---

## Tools

### Built-in tools

| Tool | Auto? | What it does |
|---|---|---|
| `fetch_url(url)` | **No (browser)** | Shows the exact outbound URL for approval, then fetches it, strips HTML, and truncates at 100 KB. Private IPs are blocked. Token-authenticated MCP/REST calls execute immediately. |
| `get_allowed_roots()` | Yes | Returns the list of absolute directory paths Haydi is allowed to read/write. Call this before writing files to pick a valid target path. |
| `list_files(path)` | Yes | Lists files/dirs inside an allowed root. |
| `read_file(path)` | Yes | Reads a file (max 512 KB). |
| `search_files(query, path, mode, extensions, max_results)` | Yes | Searches allowed file contents using PHP (no shell grep). Empty optional fields use safe defaults. |
| `list_plugins()` | Yes | Lists all installed plugins with name, version, file path, and active status. |
| `list_posts(status?, type?, limit?)` | Yes | Lists posts/pages with ID, title, status, type, date, and content. Defaults: any status, post+page types, 50 most recently modified. |
| `list_users(role?, limit?)` | Yes | Lists users with ID, login, email, display name, and roles. Defaults: all roles, 50 most recently registered. |
| `list_options(search?)` | Yes | Lists WordPress options. Without a search term returns autoloaded options; with a search term filters `option_name` by substring. Capped at 100 rows. |
| `list_backups(path?)` | Yes | Lists backup files created by the plugin. Optionally filter by original file path. |
| `install_plugin(slug, reason)` | **No** | Proposes installing a plugin from WordPress.org by slug. |
| `activate_plugin(plugin, reason)` | **No** | Proposes activating an installed plugin by file path. |
| `deactivate_plugin(plugin, reason)` | **No** | Proposes deactivating an active plugin. |
| `write_file(path, content, reason)` | **No** | Proposes a file write. You see the full content before applying. |
| `edit(filePath, oldString, newString, replaceAll, reason)` | **No** | Proposes an exact-string edit to an existing file. `oldString` must match once unless `replaceAll` is true. |
| `delete_file(path, reason)` | **No** | Proposes deleting a file. A backup is created automatically. |
| `move_file(src, dest, reason)` | **No** | Proposes moving/renaming a file. Source is backed up first. |
| `copy_file(src, dest, reason)` | **No** | Proposes copying a file. Destination is backed up if it already exists. |
| `delete_dir(path, reason)` | **No** | Proposes recursively deleting a directory. All files are backed up first. Root directories cannot be deleted. |
| `restore_backup(backup_file, original_path, reason)` | **No** | Proposes restoring a file from a specific backup. Use `list_backups` first to find the backup filename. A new backup of the current file is created before restoring. |
| `run_query(sql, reason)` | **No** | Proposes SQL via `$wpdb`. You see the full query before it runs. |
| `run_php(code, reason)` | **No** | Proposes executing a PHP snippet in the WordPress context. Output is captured and returned. |

---

## Workflows

### 1. See a site → build a plugin

```
Fetch https://example.com/pricing and create a WordPress plugin that renders
the same pricing table as a [pricing_table] shortcode.
```

The AI proposes the URL fetch first. After you approve the exact URL, it reads the markup and proposes plugin files one at a time. You review each proposal and click Apply.

### 2. Modify an existing plugin

Open a file in the browser panel, then describe the change:

```
Add a $limit parameter (default 10) to list_posts() and pass it to WP_Query.
```

The AI reads the file, proposes the updated version, you diff and apply.

### 3. Reorganise plugin files

```
Rename class-old-name.php to class-new-name.php and update the class name inside it.
```

The AI reads the file, proposes a `move_file` to rename it, then a `write_file` with the updated class name. Each step requires your approval.

### 4. Create and query a database table

```
Create a table called wmp_feedback (id, post_id, rating, comment, created_at)
using the WordPress table prefix, then insert a test row.
```

Each `CREATE`, `INSERT`, or `SELECT` is shown to you before it runs.
Results are fed back to the AI so it can continue automatically.

### 5. Install and configure a plugin

```
Install and activate WooCommerce, then configure it for selling digital products.
```

The AI checks what is already installed (`list_plugins`), proposes installing WooCommerce, activating it, and then uses `run_php` or `run_query` to apply configuration — one approved step at a time.

### 6. Restore a file from backup

```
My functions.php is broken after the last edit — restore it to the previous version.
```

The AI calls `list_backups` to find available backups for that file, presents what it found, and proposes `restore_backup`. A new backup of the current (broken) file is created first so the restore is itself reversible.

---

## Security

| Control | Detail |
|---|---|
| Auth | WordPress's mapped `edit_plugins` hard floor + configured Haydi capability (`manage_options` by default) + nonce on every browser request |
| Path isolation | `realpath()` + allowlist on every read/write; nothing above ABSPATH |
| Extension allowlist | `.php .css .js .json .txt .md .html` only |
| File size cap | 512 KB reads/writes |
| Dotfiles | Always skipped |
| Backup | Timestamped `.bak` created before any write, delete, or move |
| Backup dir | Protected with `.htaccess Deny from all`; filenames carry a random token so URLs are not enumerable on web servers that ignore `.htaccess` (Nginx, LiteSpeed) |
| Post-mutation health check | After every approved write/delete/move/copy/activate/run_query/run_php, the plugin fires a loopback request to a dedicated health endpoint pinned to `127.0.0.1`. If the site is no longer responding, file ops auto-restore from backup and `activate_plugin` auto-deactivates. `delete_dir`, `run_query`, and `run_php` are detect-only (no automatic revert) and surface a recovery-mode message instead. PHP writes are also pre-validated with `token_get_all()` so syntax errors are caught before the file ever hits disk. |
| Writes/deletes/moves/queries/installs | Require a human click by default. A session-only **Auto-accept** toggle can opt every proposal in until the page is reloaded. |
| Audit log | All operations logged to `wp_options` |
| API key | Managed via Settings → Connectors; never sent to browser |
| `fetch_url` approval + SSRF | Browser chat shows the exact URL and requires approval before sending a request; token-authenticated MCP/REST calls execute immediately. Scheme must be `http`/`https`; every A and AAAA record is resolved and validated against private/loopback/link-local ranges; WordPress Requests is forced to cURL, which is pinned to those IPs, and fails closed if the pinned transport is unavailable or an HTTP proxy would resolve the origin (defeats DNS rebinding); redirects are disabled (would re-resolve DNS); response body capped at 200 KB |
| `run_query` | Full SQL shown before execution; SELECT results capped at 200 rows |
| `install_plugin` | Slug validated against `^[a-z0-9][a-z0-9-]*$`; downloads only from WordPress.org API |
| `run_php` | Full code shown before execution; output captured and returned |
| Remote Access tokens | SHA-256 hash and issuer ID stored; plaintext shown once; issuer access rechecked on every request; revocable from Advanced settings; Bearer token auth on all REST / MCP routes |
| REST API write ops | Execute immediately when token-authenticated; same guard, health-check, backup, and audit-log machinery as browser-initiated changes |
