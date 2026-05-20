# Haydi Extensions

Extensions add write/execute capabilities to Haydi beyond what ships in the WordPress.org core.

## Available Extensions

Download the latest extension files from the [Haydi GitHub releases](https://github.com/Automattic/haydi/releases):

| File | Adds |
|------|------|
| `haydi-files.php` | `write_file`, `edit`, `delete_file`, `move_file`, `copy_file`, `delete_dir`, `restore_backup` |
| `haydi-db.php` | `run_query` |
| `haydi-php.php` | `run_php` |

## Installation

1. Download the extension PHP file(s) you want.
2. Upload them to `wp-content/plugins/haydi/extensions/` via SFTP or your hosting file manager.
3. Refresh the Haydi chat — the new tools are available immediately.

No activation step is needed. Haydi auto-loads any `.php` file placed in this folder.
