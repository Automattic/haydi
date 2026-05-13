// @ts-check
/**
 * Integration tests for Haydi_Ajax_Handlers.
 *
 * Runs against a live wp-env WordPress instance (http://localhost:9888).
 * All tests share one authenticated browser page to avoid repeated logins.
 * Tests run in serial order because several build on state created by earlier ones.
 */
const { test, expect } = require('@playwright/test');

const BASE       = 'http://localhost:9888';
const LOGIN_URL  = `${BASE}/wp-login.php`;
const PLUGIN_URL = `${BASE}/wp-admin/tools.php?page=haydi`;

// Shared state populated in beforeAll. The wp-env container always mounts
// wp-content at /var/www/html/wp-content, so the plugins root is fixed.
const pluginsDir = '/var/www/html/wp-content/plugins';
const testDir    = `${pluginsDir}/_test-haydi`;
const testFile   = `${testDir}/test.php`;

let page;
let ajaxUrl;
let nonce;

test.describe('Haydi_Ajax_Handlers', () => {
    test.describe.configure({ mode: 'serial' });

    // -------------------------------------------------------------------------
    // Setup / teardown
    // -------------------------------------------------------------------------

    test.beforeAll(async ({ browser }) => {
        const ctx = await browser.newContext();
        page = await ctx.newPage();

        // Log in as admin.
        await page.goto(LOGIN_URL);
        await page.fill('#user_login', 'admin');
        await page.fill('#user_pass', 'password');
        await page.click('#wp-submit');
        await page.waitForURL('**/wp-admin/**');

        // Load the plugin page to extract the nonce and ajax URL.
        await page.goto(PLUGIN_URL);
        ajaxUrl = await page.evaluate(() => window.haydi.ajaxUrl); // eslint-disable-line no-undef
        nonce   = await page.evaluate(() => window.haydi.nonce);   // eslint-disable-line no-undef

        // Seed the test directory with a file.
        await post({
            action:  'haydi_apply_write',
            path:    testFile,
            content: '<?php // haydi test fixture\n',
        });
    });

    test.afterAll(async () => {
        // Best-effort cleanup — delete the whole test directory.
        try {
            await post({ action: 'haydi_delete_dir', path: testDir });
        } catch { /* ignore */ }
        await page.context().close();
    });

    /** Helper: POST to admin-ajax.php with the shared nonce. */
    function post(data) {
        return page.request
            .post(ajaxUrl, { form: { nonce, ...data } })
            .then(r => r.json());
    }

    // -------------------------------------------------------------------------
    // haydi_read_file
    // -------------------------------------------------------------------------

    test('haydi_read_file — reads test file content', async () => {
        const res = await post({ action: 'haydi_read_file', path: testFile });
        expect(res.success).toBe(true);
        expect(res.data.content).toContain('<?php');
        expect(res.data.path).toBe(testFile);
    });

    test('haydi_read_file — rejects path outside allowed roots', async () => {
        const res = await post({ action: 'haydi_read_file', path: '/etc/passwd' });
        expect(res.success).toBe(false);
    });

    // -------------------------------------------------------------------------
    // haydi_apply_write
    // -------------------------------------------------------------------------

    test('haydi_apply_write — creates a new file', async () => {
        const path = `${testDir}/write-test.php`;
        const res = await post({ action: 'haydi_apply_write', path, content: '<?php // write test\n' });
        expect(res.success).toBe(true);
        expect(res.data.path).toBe(path);
        // Verify content was written.
        const read = await post({ action: 'haydi_read_file', path });
        expect(read.data.content).toContain('write test');
        // Cleanup.
        await post({ action: 'haydi_delete_file', path });
    });

    test('haydi_apply_write — rejects path outside allowed roots', async () => {
        const res = await post({ action: 'haydi_apply_write', path: '/tmp/evil.php', content: '' });
        expect(res.success).toBe(false);
    });

    // -------------------------------------------------------------------------
    // haydi_copy_file
    // -------------------------------------------------------------------------

    test('haydi_copy_file — copies a file to a new path', async () => {
        const dest = `${testDir}/test-copy.php`;
        const res = await post({ action: 'haydi_copy_file', src: testFile, dest, reason: 'test copy' });
        expect(res.success).toBe(true);
        expect(res.data.dest).toBe(dest);
        // Verify the copy exists and has the right content.
        const read = await post({ action: 'haydi_read_file', path: dest });
        expect(read.success).toBe(true);
        expect(read.data.content).toContain('<?php');
        // Cleanup.
        await post({ action: 'haydi_delete_file', path: dest });
    });

    test('haydi_copy_file — rejects source outside allowed roots', async () => {
        const res = await post({ action: 'haydi_copy_file', src: '/etc/passwd', dest: `${testDir}/bad.php`, reason: 'test' });
        expect(res.success).toBe(false);
    });

    // -------------------------------------------------------------------------
    // haydi_move_file
    // -------------------------------------------------------------------------

    test('haydi_move_file — moves a file', async () => {
        const src  = `${testDir}/move-src.php`;
        const dest = `${testDir}/move-dest.php`;
        await post({ action: 'haydi_apply_write', path: src, content: '<?php // move source\n' });

        const res = await post({ action: 'haydi_move_file', src, dest, reason: 'test move' });
        expect(res.success).toBe(true);
        expect(res.data.src).toBe(src);
        expect(res.data.dest).toBe(dest);

        // Source should be gone.
        const readSrc = await post({ action: 'haydi_read_file', path: src });
        expect(readSrc.success).toBe(false);

        // Destination should exist.
        const readDest = await post({ action: 'haydi_read_file', path: dest });
        expect(readDest.success).toBe(true);
        // Cleanup.
        await post({ action: 'haydi_delete_file', path: dest });
    });

    // -------------------------------------------------------------------------
    // haydi_delete_file
    // -------------------------------------------------------------------------

    test('haydi_delete_file — deletes an existing file', async () => {
        const path = `${testDir}/to-delete.php`;
        await post({ action: 'haydi_apply_write', path, content: '<?php\n' });
        const res = await post({ action: 'haydi_delete_file', path });
        expect(res.success).toBe(true);
        // File should no longer be readable.
        const read = await post({ action: 'haydi_read_file', path });
        expect(read.success).toBe(false);
    });

    test('haydi_delete_file — rejects non-existent file', async () => {
        const res = await post({ action: 'haydi_delete_file', path: `${testDir}/no-such-file.php` });
        expect(res.success).toBe(false);
    });

    // -------------------------------------------------------------------------
    // haydi_delete_dir
    // -------------------------------------------------------------------------

    test('haydi_delete_dir — recursively deletes a subdirectory', async () => {
        const subDir  = `${testDir}/subdir`;
        const subFile = `${subDir}/file.php`;
        await post({ action: 'haydi_apply_write', path: subFile, content: '<?php\n' });

        const res = await post({ action: 'haydi_delete_dir', path: subDir });
        expect(res.success).toBe(true);

        // The seed file should no longer be readable inside the deleted dir.
        const read = await post({ action: 'haydi_read_file', path: subFile });
        expect(read.success).toBe(false);
    });

    test('haydi_delete_dir — rejects deletion of an allowed root directory', async () => {
        const res = await post({ action: 'haydi_delete_dir', path: pluginsDir });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/root/i);
    });

    // -------------------------------------------------------------------------
    // haydi_execute_query
    // -------------------------------------------------------------------------

    test('haydi_execute_query — runs a SELECT query and returns rows', async () => {
        const res = await post({ action: 'haydi_execute_query', sql: 'SELECT 1 AS n', reason: 'test' });
        expect(res.success).toBe(true);
        expect(res.data.type).toBe('select');
        expect(res.data.rows[0].n).toBe('1');
    });

    test('haydi_execute_query — rejects empty sql', async () => {
        const res = await post({ action: 'haydi_execute_query', sql: '', reason: 'test' });
        expect(res.success).toBe(false);
    });

    // -------------------------------------------------------------------------
    // haydi_save_settings
    // -------------------------------------------------------------------------

    test('haydi_save_settings — saves max_tokens', async () => {
        const res = await post({ action: 'haydi_save_settings', max_tokens: '8192' });
        expect(res.success).toBe(true);
    });

    // -------------------------------------------------------------------------
    // haydi_clear_log
    // -------------------------------------------------------------------------

    test('haydi_clear_log — clears the audit log', async () => {
        const res = await post({ action: 'haydi_clear_log' });
        expect(res.success).toBe(true);
    });

    // -------------------------------------------------------------------------
    // haydi_chat (validation only — no API key needed)
    // -------------------------------------------------------------------------

    test('haydi_chat — rejects an empty messages array', async () => {
        const res = await post({ action: 'haydi_chat', messages: '[]' });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/messages/i);
    });

    test('haydi_chat — rejects malformed messages JSON', async () => {
        const res = await post({ action: 'haydi_chat', messages: 'not-json' });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/messages/i);
    });

    // -------------------------------------------------------------------------
    // haydi_execute_query — edge cases
    // -------------------------------------------------------------------------

    test('haydi_execute_query — SELECT truncates results beyond MAX_QUERY_ROWS (200)', async () => {
        // information_schema.columns always has hundreds of rows in any MySQL install.
        const res = await post({
            action: 'haydi_execute_query',
            sql:    'SELECT table_name FROM information_schema.columns LIMIT 205',
            reason: 'test truncation',
        });
        expect(res.success).toBe(true);
        expect(res.data.type).toBe('select');
        expect(res.data.truncated).toBe(true);
        expect(res.data.rows.length).toBe(200);
    });

    test('haydi_execute_query — non-SELECT returns affected-row count', async () => {
        // Use a no-op UPDATE that matches zero rows — safe, leaves no data behind.
        const res = await post({
            action: 'haydi_execute_query',
            sql:    "UPDATE wp_options SET autoload = autoload WHERE option_name = 'haydi_nonexistent_option_test'",
            reason: 'test non-select',
        });
        expect(res.success).toBe(true);
        expect(res.data.type).toBe('write');
        expect(res.data).toHaveProperty('rows');
    });

    // -------------------------------------------------------------------------
    // haydi_save_settings — out-of-range values
    // -------------------------------------------------------------------------

    test('haydi_save_settings — rejects out-of-range max_tokens with a clear message', async () => {
        const tooSmall = await post({ action: 'haydi_save_settings', max_tokens: '1' });
        expect(tooSmall.success).toBe(false);
        expect(tooSmall.data.message).toMatch(/1024.*32768/);

        const tooBig = await post({ action: 'haydi_save_settings', max_tokens: '99999' });
        expect(tooBig.success).toBe(false);
        expect(tooBig.data.message).toMatch(/1024.*32768/);
    });

    // -------------------------------------------------------------------------
    // verify() — authentication / nonce checks
    // -------------------------------------------------------------------------

    test('verify() — rejects request with no nonce', async () => {
        const res = await page.request
            .post(ajaxUrl, { form: { action: 'haydi_read_file', path: testFile } })
            .then(r => r.json());
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/nonce/i);
    });

    test('verify() — rejects request with invalid nonce', async () => {
        const res = await page.request
            .post(ajaxUrl, { form: { action: 'haydi_read_file', path: testFile, nonce: 'bad-nonce-value' } })
            .then(r => r.json());
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/nonce/i);
    });

    // -------------------------------------------------------------------------
    // Missing required parameters
    // -------------------------------------------------------------------------

    test('haydi_move_file — rejects missing src / dest', async () => {
        const res = await post({ action: 'haydi_move_file', src: '', dest: '', reason: 'test' });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/required/i);
    });

    test('haydi_copy_file — rejects missing src / dest', async () => {
        const res = await post({ action: 'haydi_copy_file', src: '', dest: '', reason: 'test' });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/required/i);
    });

    test('haydi_load_chat — rejects missing id', async () => {
        const res = await post({ action: 'haydi_load_chat', id: '' });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/id/i);
    });

    test('haydi_delete_chat — rejects missing id', async () => {
        const res = await post({ action: 'haydi_delete_chat', id: '' });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/id/i);
    });

    test('haydi_save_chat — rejects empty messages array', async () => {
        const res = await post({
            action:   'haydi_save_chat',
            id:       '',
            title:    'test',
            messages: '[]',
        });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/messages/i);
    });

    // -------------------------------------------------------------------------
    // Chat management
    // -------------------------------------------------------------------------

    // Shared chat id populated by the save test, consumed by load/delete.
    let savedChatId;

    test('haydi_save_chat — creates a new chat and returns an id', async () => {
        const messages = JSON.stringify([{ role: 'user', content: 'Hello' }]);
        const res = await post({
            action:   'haydi_save_chat',
            id:       '',
            title:    'Test chat',
            messages,
        });
        expect(res.success).toBe(true);
        expect(typeof res.data.id).toBe('string');
        expect(res.data.id).toMatch(/^chat_/);
        savedChatId = res.data.id;
    });

    test('haydi_list_chats — includes the saved chat', async () => {
        const res = await post({ action: 'haydi_list_chats' });
        expect(res.success).toBe(true);
        const ids = res.data.chats.map(c => c.id);
        expect(ids).toContain(savedChatId);
    });

    test('haydi_load_chat — returns the saved messages', async () => {
        const res = await post({ action: 'haydi_load_chat', id: savedChatId });
        expect(res.success).toBe(true);
        expect(Array.isArray(res.data.messages)).toBe(true);
        expect(res.data.messages[0].role).toBe('user');
        expect(res.data.messages[0].content).toBe('Hello');
    });

    test('haydi_load_chat — returns error for unknown id', async () => {
        const res = await post({ action: 'haydi_load_chat', id: 'chat_does_not_exist' });
        expect(res.success).toBe(false);
    });

    test('haydi_save_chat — updates an existing chat in place', async () => {
        const messages = JSON.stringify([
            { role: 'user',      content: 'Hello' },
            { role: 'assistant', content: 'Hi there!' },
        ]);
        const res = await post({
            action:   'haydi_save_chat',
            id:       savedChatId,
            title:    'Updated title',
            messages,
        });
        expect(res.success).toBe(true);
        expect(res.data.id).toBe(savedChatId);

        // Verify the list still has only one entry for this id.
        const list = await post({ action: 'haydi_list_chats' });
        const matches = list.data.chats.filter(c => c.id === savedChatId);
        expect(matches.length).toBe(1);
        expect(matches[0].title).toBe('Updated title');
    });

    test('haydi_save_chat — round-trips the display log verbatim', async () => {
        const messages = JSON.stringify([{ role: 'user', content: 'Hi' }]);
        const display  = JSON.stringify([
            { role: 'user',      text: 'Hi' },
            { role: 'assistant', text: 'Hello!' },
            { role: 'error',     text: 'Error: rate limited' },
        ]);
        const save = await post({
            action:   'haydi_save_chat',
            id:       '',
            title:    'with display log',
            messages,
            display,
        });
        expect(save.success).toBe(true);
        const id = save.data.id;

        const load = await post({ action: 'haydi_load_chat', id });
        expect(load.success).toBe(true);
        expect(load.data.display).toEqual([
            { role: 'user',      text: 'Hi' },
            { role: 'assistant', text: 'Hello!' },
            { role: 'error',     text: 'Error: rate limited' },
        ]);

        // Cleanup so the test doesn't leak into other suites.
        await post({ action: 'haydi_delete_chat', id });
    });

    test('haydi_load_chat — returns empty display for legacy chats', async () => {
        const save = await post({
            action:   'haydi_save_chat',
            id:       '',
            title:    'no display field',
            messages: JSON.stringify([{ role: 'user', content: 'legacy' }]),
            // Note: no `display` field sent — simulates pre-feature client.
        });
        const id = save.data.id;

        const load = await post({ action: 'haydi_load_chat', id });
        expect(load.success).toBe(true);
        expect(load.data.display).toEqual([]);

        await post({ action: 'haydi_delete_chat', id });
    });

    test('haydi_delete_chat — removes the chat', async () => {
        const res = await post({ action: 'haydi_delete_chat', id: savedChatId });
        expect(res.success).toBe(true);

        // It must no longer appear in the list.
        const list = await post({ action: 'haydi_list_chats' });
        const ids  = list.data.chats.map(c => c.id);
        expect(ids).not.toContain(savedChatId);
    });

    // -------------------------------------------------------------------------
    // haydi_run_php
    // -------------------------------------------------------------------------

    test('haydi_run_php — executes code and returns captured output', async () => {
        const res = await post({
            action: 'haydi_run_php',
            code:   "echo 'hello from php';",
            reason: 'integration test',
        });
        expect(res.success).toBe(true);
        expect(res.data.output).toBe('hello from php');
    });

    test('haydi_run_php — returns no-output placeholder for silent code', async () => {
        const res = await post({
            action: 'haydi_run_php',
            code:   '$x = 1 + 1;',
            reason: 'silent computation',
        });
        expect(res.success).toBe(true);
        expect(res.data.output).toBe('(no output)');
    });

    test('haydi_run_php — returns error for thrown exception', async () => {
        const res = await post({
            action: 'haydi_run_php',
            code:   "throw new \\RuntimeException('test error');",
            reason: 'exception test',
        });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/test error/);
    });

    test('haydi_run_php — rejects empty code', async () => {
        const res = await post({ action: 'haydi_run_php', code: '', reason: 'test' });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/required/i);
    });

    test('haydi_run_php — rejects whitespace-only code', async () => {
        const res = await post({ action: 'haydi_run_php', code: '   ', reason: 'test' });
        expect(res.success).toBe(false);
    });

    test('haydi_run_php — can access WordPress globals', async () => {
        // Verify the snippet runs in WP context by reading a known option.
        const res = await post({
            action: 'haydi_run_php',
            code:   "echo get_option('blogname');",
            reason: 'test wp context',
        });
        expect(res.success).toBe(true);
        // output should be the site name string, not empty/error.
        expect(typeof res.data.output).toBe('string');
        expect(res.data.output.length).toBeGreaterThan(0);
    });

    // -------------------------------------------------------------------------
    // haydi_install_plugin — validation only (no real network requests)
    // -------------------------------------------------------------------------

    test('haydi_install_plugin — rejects empty slug', async () => {
        const res = await post({ action: 'haydi_install_plugin', slug: '', reason: 'test' });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/required/i);
    });

    test('haydi_install_plugin — rejects slug with uppercase letters', async () => {
        const res = await post({ action: 'haydi_install_plugin', slug: 'MyPlugin', reason: 'test' });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/slug/i);
    });

    test('haydi_install_plugin — rejects slug with path traversal', async () => {
        const res = await post({ action: 'haydi_install_plugin', slug: '../evil', reason: 'test' });
        expect(res.success).toBe(false);
    });

    test('haydi_install_plugin — rejects slug with slash', async () => {
        const res = await post({ action: 'haydi_install_plugin', slug: 'some/thing', reason: 'test' });
        expect(res.success).toBe(false);
    });

    // -------------------------------------------------------------------------
    // haydi_activate_plugin — validation
    // -------------------------------------------------------------------------

    test('haydi_activate_plugin — rejects empty plugin', async () => {
        const res = await post({ action: 'haydi_activate_plugin', plugin: '', reason: 'test' });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/required/i);
    });

    test('haydi_activate_plugin — rejects path traversal', async () => {
        const res = await post({
            action: 'haydi_activate_plugin',
            plugin: '../../../wp-config.php',
            reason: 'test',
        });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/invalid/i);
    });

    test('haydi_activate_plugin — rejects non-PHP extension', async () => {
        const res = await post({
            action: 'haydi_activate_plugin',
            plugin: 'woocommerce/woocommerce.js',
            reason: 'test',
        });
        expect(res.success).toBe(false);
    });

    test('haydi_activate_plugin — rejects plugin file that does not exist', async () => {
        const res = await post({
            action: 'haydi_activate_plugin',
            plugin: 'definitely-not-installed/plugin.php',
            reason: 'test',
        });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/not found/i);
    });

    // -------------------------------------------------------------------------
    // haydi_deactivate_plugin — validation
    // -------------------------------------------------------------------------

    test('haydi_deactivate_plugin — rejects empty plugin', async () => {
        const res = await post({ action: 'haydi_deactivate_plugin', plugin: '', reason: 'test' });
        expect(res.success).toBe(false);
        expect(res.data.message).toMatch(/required/i);
    });

    test('haydi_save_chat — MAX_CHATS cap (100) evicts oldest entries', async () => {
        // Start from a clean slate for this user: delete all existing chats.
        const before = await post({ action: 'haydi_list_chats' });
        for (const c of before.data.chats) {
            await post({ action: 'haydi_delete_chat', id: c.id });
        }

        // Save 101 chats — one beyond the cap.
        const msgs = JSON.stringify([{ role: 'user', content: 'x' }]);
        const ids  = [];
        for (let i = 0; i < 101; i++) {
            const r = await post({
                action:   'haydi_save_chat',
                id:       '',
                title:    `Chat ${i}`,
                messages: msgs,
            });
            ids.push(r.data.id);
        }

        const after = await post({ action: 'haydi_list_chats' });
        expect(after.data.chats.length).toBe(100);

        // The very first chat saved (ids[0]) must have been evicted.
        const remaining = after.data.chats.map(c => c.id);
        expect(remaining).not.toContain(ids[0]);

        // Cleanup.
        for (const id of remaining) {
            await post({ action: 'haydi_delete_chat', id });
        }
    });
});
