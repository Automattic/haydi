=== Haydi ===
Contributors: automattic, bor0, raicem
Tags: ai, automation, assistant, site-management, mcp
Requires at least: 7.0
Tested up to: 7.0
Stable tag: 1.1.0
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your AI Autopilot — manage your site directly from WP-Admin using any AI provider.

== Description ==

Haydi lets an AI agent manage your WordPress site from WP-Admin. Tell it what you want; it reads your site, proposes changes, and requires your approval before anything is modified.

**What it can do:**

* Browse, read, and search plugin and theme files
* List posts, pages, users, and site options
* Install, activate, and deactivate plugins
* Fetch public web pages for reference (docs, examples)
* Inspect your site setup and Jetpack stats
* Edit, write, move, copy, and delete files — with a live diff before applying
* Run database queries
* Execute PHP snippets in the WordPress context
* Restore files from automatic backups

**How it works:**

Read-only operations (listing files, reading files, fetching URLs, listing posts and users) run automatically. Every mutating action — plugin installs and any write/delete/SQL/PHP operation — pauses for human approval. You see a full diff or preview before clicking Apply.

Before any PHP file is written to disk, it is validated with `token_get_all()` to catch syntax errors. If the "Playground preflight" option is enabled, it is also tested inside a WordPress Playground sandbox first.

A post-write health check fires a loopback request after every approved change. If your site stops responding, file changes are automatically restored from backup. Plugins that break the site after activation are auto-deactivated.

All operations are recorded in an audit log accessible from within the chat interface, so you have a full history of what the AI did.

**Jetpack integration:** when Jetpack is connected, the AI receives site-specific context — stats, top posts, referrers, active modules, plan tier, speed scores, and security data — so suggestions are tailored to your site rather than generic.

**Remote access via MCP:** generate an API token under Advanced settings in the Haydi sidebar and connect local AI tools (Claude Code, Codex, and any MCP-compatible client) directly to your site — no browser required. The built-in MCP endpoint (`/wp-json/haydi/v1/mcp`) speaks the Model Context Protocol over HTTP and exposes the complete Haydi toolset.

**Command palette:** open Haydi from anywhere in WP-Admin with Cmd/Ctrl+K → "Interact with AI".

**Requires WordPress 7.0** (uses the WordPress Connectors API to connect to AI providers). Configure your AI provider under Settings → Connectors.

== Installation ==

1. Ensure WordPress 7.0 or later is installed and at least one AI provider connector is active under Settings → Connectors.
2. Upload the `haydi` folder to `/wp-content/plugins/`.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Go to **Tools → Haydi** to start using the assistant.

== Frequently Asked Questions ==

= Which AI providers are supported? =

Any provider available through the WordPress Connectors API (WordPress 7.0+). This includes Anthropic Claude, OpenAI, and Gemini, among others. Configure your provider under Settings → Connectors.

= Who can use Haydi? =

By default, Editors and Administrators can use Haydi. Authors, Contributors, and Subscribers cannot. Developers can change the required capability with the `haydi_access_capability` filter; its default is `edit_others_posts`.

Haydi can modify files, run SQL and PHP, and change plugin state. Only grant access to trusted users, even though browser mutations require explicit approval by default.

= Can administrators restrict which model Editors use? =

Yes. Go to Settings → Haydi and select an Editor model. The default allows Editors to choose any configured model; selecting a model fixes and disables their picker while Administrators retain the full picker. This controls the browser interface only and does not enforce a model restriction on REST or MCP requests.

= Does it work without Jetpack? =

Yes. Jetpack is optional. Without it, everything works normally but AI suggestions are generic rather than tailored to your specific site's content and stats. A dismissable banner appears pointing to install/connect Jetpack if it is not active.

= Is my data sent to external servers? =

Yes. When you send a message, your prompt and relevant site context (file contents, query results, etc.) are forwarded to the AI provider you have configured via WordPress Connectors. See the "External Services" section below for details.

= Can the AI make changes without my approval? =

By default, no. Every action that modifies your site — plugin installs and any write/delete/SQL/PHP operation — requires an explicit click to approve. Read-only operations (browsing files, listing posts and users, fetching URLs) run automatically.

An **Auto-accept** toggle is available in the interface for users who want to let the AI apply a series of changes without pausing for each one. This setting is session-only and resets when the page is reloaded.

When using the MCP or REST API with a token, write operations execute immediately — the API token itself is the approval gate, equivalent to a human clicking Apply. Tokens can be revoked at any time from Advanced settings in the Haydi sidebar.

= How do I connect Claude Code or another AI tool via MCP? =

1. Go to **Tools → Haydi** and open **Advanced settings** in the sidebar (expand the "Remote access" row).
2. Enter an optional label and click **Generate**.
3. Copy the token (shown once) and expand "Connect with Claude Code" to get the config snippet.
4. Paste the snippet into `~/.claude/claude_code_config.json` (or the equivalent config for your MCP client).
5. Restart your AI tool — it will appear as an MCP server named `haydi`.

Tokens can be revoked from the same card at any time.

= What happens if a change breaks my site? =

A health check runs after every approved change. If your site stops responding, file changes are automatically reverted from a timestamped backup. For database queries and PHP execution, a recovery-mode message is shown instead.

= Does the plugin store my conversations? =

Chat history is stored in user meta in your own database. Nothing is sent to Automattic beyond anonymised usage events (opt-in).

== External Services ==

This plugin sends data to third-party services. By using Haydi you agree to the terms and privacy policies of the AI provider you have configured.

**AI Providers (via WordPress Connectors)**

When you submit a message, your prompt and any site content retrieved during the session (file contents, database results, fetched URLs) are sent to the AI provider configured under Settings → Connectors. The specific provider depends on your configuration — common examples include:

* Anthropic Claude — https://www.anthropic.com/
  * Terms of Service: https://www.anthropic.com/legal/consumer-terms
  * Privacy Policy: https://www.anthropic.com/legal/privacy

* OpenAI — https://openai.com/
  * Terms of Service: https://openai.com/policies/terms-of-use
  * Privacy Policy: https://openai.com/policies/privacy-policy

* Google Gemini — https://gemini.google.com/
  * Terms of Service: https://policies.google.com/terms
  * Privacy Policy: https://policies.google.com/privacy

No data is sent to these services unless you actively submit a message in the Haydi interface.

**WordPress Playground (optional)**

If the "Playground preflight" setting is enabled, proposed PHP file writes are validated inside a WordPress Playground sandbox before being written to disk. This sends the proposed file content to:

* WordPress Playground — https://playground.wordpress.net/
  * Privacy Policy: https://automattic.com/privacy/

**Automattic Tracks (optional, opt-in)**

If you opt in to usage analytics, anonymised event data (actions taken, errors encountered) is sent to Automattic Tracks. No personal data or site content is included. This is disabled by default and can be toggled under the plugin settings.

* Automattic Privacy Policy: https://automattic.com/privacy/

== Bundled Libraries ==

`assets/marked.min.js` is a minified build of [marked](https://github.com/markedjs/marked) (v18), a Markdown parser licensed under MIT. The unminified source is available at https://github.com/markedjs/marked.

No WordPress-bundled libraries (jQuery, Backbone, lodash, etc.) are duplicated by this plugin.

== Changelog ==
= 1.1.0 =
* Released 2026-08-07.
* Bundled file editing, database query, and PHP execution tools directly in Haydi, replacing separate extension downloads with one complete plugin package.
* Unified chat and MCP tool declarations and execution through one validated Tool Catalog.
* Consolidated browser mutations into one authenticated approval pipeline while preserving explicit human confirmation.
* Preserved structured tool results until transport boundaries for consistent OpenAI, Anthropic, and Google behavior.
* Simplified distribution to a single haydi.zip package.

= 1.0.1 =
* Released 2026-07-06.

= 1.0.0 =
* Initial release.
