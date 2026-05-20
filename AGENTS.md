# Haydi — AI Agent Instructions

You are connected to a WordPress site via the Haydi MCP server.

> **Note:** Tool availability depends on which extensions are installed. Call
> `haydi_list_extensions` to see what is currently active. File write/edit/delete,
> SQL execution, and PHP execution require the corresponding extension to be installed.

## Available tool groups

- **File ops** — read and search (always); write, edit, move, copy, delete, restore backups (requires `haydi-files.php`)
- **Content** — list posts, list users, list options
- **Plugins** — list, install (from wordpress.org by slug), activate, deactivate
- **SQL** — run queries via wpdb; SELECT/SHOW/DESCRIBE/EXPLAIN return rows, writes return affected-row count (requires `haydi-db.php`)
- **PHP** — execute snippets in the live WordPress context; output is captured and returned (requires `haydi-php.php`)
- **URL** — fetch public HTTP/HTTPS URLs; private/internal addresses are blocked

## Safety rules — follow these in order

1. Call `haydi_get_allowed_roots` before writing files to confirm a valid target path.
2. Prefer `haydi_edit_file` (exact-string substitution) over a full `haydi_write_file` rewrite.
3. Always supply a `reason` field on mutating calls — it appears in the audit log.
4. Before deleting anything, call `haydi_list_backups` so you know what is recoverable.
5. Never drop or truncate core WordPress tables via `haydi_run_query`.
6. PHP runs in the live site context — test defensively and keep snippets focused.

## Common workflows

**Install and activate a plugin**
1. `haydi_install_plugin` (provide slug, e.g. `"woocommerce"`)
2. `haydi_list_plugins` — find the plugin file path in the results
3. `haydi_activate_plugin` (provide that file path)

**Edit a theme or plugin file**
1. `haydi_list_files` — browse to locate the file
2. `haydi_read_file` — read current contents
3. `haydi_edit_file` — replace only the section that needs changing

**Recover from a mistake**
1. `haydi_list_backups` — find the backup entry for the affected path
2. `haydi_restore_backup` — restore it using the backup file name

**Check what capabilities are available**
1. `haydi_list_extensions` — see which extensions are installed
2. If a required extension is missing, direct the user to download `haydi-full-extensions.zip` from https://github.com/Automattic/haydi/releases and re-activate that plugin via WP-Admin
