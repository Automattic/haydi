// @ts-check
/**
 * Issue #12 — UI integration test for the orphan tool_use scenario.
 *
 * Repro from the bug report: AI proposes a run_query action, the
 * user types a new instruction instead of clicking Approve / Decline, and the
 * next chat request 400s with "tool_use ids were found without tool_result
 * blocks immediately after".
 *
 * The connector / AI provider is intercepted with route fulfillment so this
 * test does not require a live AI endpoint.
 */
const { test, expect } = require('@playwright/test');
const path = require('path');

const BASE       = 'http://localhost:9888';
const PLUGIN_URL = `${BASE}/wp-admin/tools.php?page=haydi`;
const TOOL_USE_ID = 'toolu_test_pending_query_42';
const CONTINUATION_HANDLE = 'a'.repeat(64);
// Session cookies saved by global-setup.js — reused so no per-test login is needed.
const AUTH_FILE = path.join(__dirname, 'playwright-auth.json');

function isChatAction(action) {
    return action === 'haydi_chat' || action === 'haydi_chat_stream';
}

async function setupPage(page) {
    const overlay = page.locator('#wpc-welcome-overlay');
    // count() checks DOM presence without a layout/visibility constraint, so it
    // is reliable even before the browser finishes first paint.  isVisible() can
    // return false in that window, leaving aria-modal="true" active and making
    // everything outside the dialog inert — causing page.fill() to hang.
    if (await overlay.count() > 0) {
        await overlay.waitFor({ state: 'visible' });
        await page.click('#wpc-welcome-confirm');
        await overlay.waitFor({ state: 'detached' });
    }
    // The test environment has no connector configured so the inputs are
    // disabled. Remove that gate since the AI is mocked via route interception.
    await page.evaluate(() => {
        /* global document */
        ['#wpc-chat-input', '#wpc-btn-send', '#wpc-btn-attach'].forEach(function (sel) {
            var el = document.querySelector(sel);
            if (el) { el.removeAttribute('disabled'); }
        });
    });
}

function sse(event, data) {
    return `event: ${event}\ndata: ${JSON.stringify(data || {})}\n\n`;
}

function fulfillChat(route, action, data) {
    if (action === 'haydi_chat_stream') {
        return route.fulfill({
            status:      200,
            contentType: 'text/event-stream',
            body:        sse('final', data) + sse('done', {}),
        });
    }

    return route.fulfill({
        status:      200,
        contentType: 'application/json',
        body:        JSON.stringify({
            success: true,
            data,
        }),
    });
}

test.describe('Chat — pending proposal handling', () => {
    test('streaming tool activity stays collapsed with a single progress spinner', async ({ browser }) => {
        const ctx  = await browser.newContext({ storageState: AUTH_FILE });
        const page = await ctx.newPage();

        await page.goto(PLUGIN_URL);
        await setupPage(page);

        await page.evaluate(() => {
            const host = globalThis;
            const originalFetch = host.fetch.bind(host);

            host.__haydiFinishStream = null;
            host.fetch = (input, init) => {
                const body = init && init.body ? String(init.body) : '';
                if (body.indexOf('action=haydi_chat_stream') === -1) {
                    return originalFetch(input, init);
                }

                const encoder = new host.TextEncoder();
                const stream = new host.ReadableStream({
                    start(controller) {
                        controller.enqueue(encoder.encode('event: status\ndata: {"message":"Thinking..."}\n\n'));
                        controller.enqueue(encoder.encode('event: tool_start\ndata: {"label":"Read file","summary":"plugins/hello.php"}\n\n'));
                        controller.enqueue(encoder.encode('event: tool_done\ndata: {"activity":{"label":"Read file","summary":"plugins/hello.php · 2.6 KB","status":"ok"},"status":"ok"}\n\n'));
                        host.__haydiFinishStream = () => {
                            controller.enqueue(encoder.encode('event: final\ndata: {"text":"Done","messages":[{"role":"assistant","content":"Done"}],"activity":[{"label":"Read file","summary":"plugins/hello.php · 2.6 KB","status":"ok"}]}\n\n'));
                            controller.enqueue(encoder.encode('event: done\ndata: {}\n\n'));
                            controller.close();
                        };
                    },
                });

                return Promise.resolve(new host.Response(stream, {
                    status:  200,
                    headers: { 'Content-Type': 'text/event-stream' },
                }));
            };
        });

        await page.fill('#wpc-chat-input', 'stream a status update');
        await page.click('#wpc-btn-send');

        await expect(page.locator('.wpc-stream-progress')).toBeVisible();
        await expect(page.locator('.wpc-stream-progress__status-text')).toHaveText('Thinking...');
        await expect(page.locator('.wpc-stream-progress .wpc-spinner')).toHaveText('');
        await expect(page.locator('.wpc-stream-progress .wpc-spinner')).toHaveCount(1);
        await expect(page.locator('.wpc-stream-dock')).toHaveCount(0);
        await expect(page.locator('.wpc-stream-progress .wpc-tool-activity')).toBeVisible();
        await expect(page.locator('.wpc-stream-progress .wpc-tool-activity')).toHaveJSProperty('open', false);
        await expect(page.locator('.wpc-stream-progress .wpc-tool-activity summary')).toContainText('Read file');
        await expect(page.locator('.wpc-stream-progress .wpc-tool-activity__item')).toHaveCount(1);
        await expect(page.locator('.wpc-stream-progress .wpc-tool-activity__detail')).toHaveText('plugins/hello.php · 2.6 KB');

        await page.evaluate(() => globalThis.__haydiFinishStream());
        await expect(page.locator('.wpc-stream-progress')).toBeHidden();
        await expect(page.locator('.wpc-stream-dock')).toHaveCount(0);
        await expect(page.locator('.wpc-tool-activity')).toBeVisible();
        await expect(page.locator('.wpc-tool-activity')).toHaveJSProperty('open', false);
        await expect(page.locator('.wpc-message--assistant').filter({ hasText: 'Done' })).toBeVisible();

        await ctx.close();
    });

    test('typing a new message while a proposal is pending sends a well-formed follow-up', async ({ browser }) => {
        const ctx  = await browser.newContext({ storageState: AUTH_FILE });
        const page = await ctx.newPage();

        await page.goto(PLUGIN_URL);
        await setupPage(page);

        // Intercept the chat endpoint so we can drive the chat
        // without an AI provider.  First call → returns a pending_action.
        // Second call → captures the messages array and ends the turn.
        let chatCalls   = 0;
        let secondMsgs  = null;
        let secondContinuationHandle = null;
        await page.route('**/admin-ajax.php', async (route) => {
            const params = new URLSearchParams(route.request().postData() || '');
            const action = params.get('action');
            if (!isChatAction(action)) {
                return route.continue();
            }

            chatCalls++;
            const reqMessages = JSON.parse(params.get('messages') || '[]');

            if (chatCalls === 1) {
                const echoed = reqMessages.concat([{
                    role:    'assistant',
                    content: [
                        { type: 'text', text: 'I will list categories first.' },
                        { type: 'tool_use', id: 'toolu_pre_pending', name: 'read_status', input: {} },
                        { type: 'tool_use', id: TOOL_USE_ID, name: 'run_query',
                          input: { sql: 'SELECT 1', reason: 'inspect categories' } },
                        { type: 'tool_use', id: 'toolu_post_pending', name: 'read_status', input: {} },
                    ],
                }]);
                return fulfillChat(route, action, {
                    text:     'I will list categories first.',
                    messages: echoed,
                    pending_action: {
                        tool_use_id: TOOL_USE_ID,
                        tool_name:   'run_query',
                        label:       'Run SQL Query',
                        arguments:   { sql: 'SELECT 1', reason: 'inspect categories' },
                        pre_results: [{
                            type: 'tool_result', tool_use_id: 'toolu_pre_pending',
                            name: 'read_status', content: 'before',
                        }],
                        post_results: [{
                            type: 'tool_result', tool_use_id: 'toolu_post_pending',
                            name: 'read_status', content: 'after',
                        }],
                    },
                    continuation_handle: CONTINUATION_HANDLE,
                });
            }

            // Second call: capture and respond with a no-op end_turn.
            secondMsgs = reqMessages;
            secondContinuationHandle = params.get('continuation_handle');
            return fulfillChat(route, action, {
                text:     'OK',
                messages: reqMessages.concat([{ role: 'assistant', content: 'OK' }]),
            });
        });

        // First user message — triggers the proposal.
        await page.fill('#wpc-chat-input', 'install woocommerce please');
        await page.click('#wpc-btn-send');
        await expect(page.locator('#wpc-action-section')).toBeVisible();
        await expect(page.locator('.wpc-panel--chat > #wpc-editor-panel + .wpc-chat-input-wrap')).toBeVisible();

        const proposalBox = await page.locator('#wpc-editor-panel').boundingBox();
        const inputBox = await page.locator('#wpc-chat-input').boundingBox();
        expect(proposalBox.y + proposalBox.height).toBeLessThanOrEqual(inputBox.y + 1);

        // User types a NEW instruction instead of approving.  Pre-fix this
        // would push a bare-text user msg, leaving the assistant tool_use
        // orphaned and the next API call would 400.
        await page.fill('#wpc-chat-input', 'go ahead with the design');
        await page.click('#wpc-btn-send');

        // Wait for the second chat request to land.
        await expect.poll(() => chatCalls, { timeout: 5_000 }).toBeGreaterThanOrEqual(2);
        expect(secondContinuationHandle).toBe(CONTINUATION_HANDLE);

        // The proposal panel must be hidden — sendMessage clears it.
        await expect(page.locator('#wpc-action-section')).toBeHidden();

        // The captured payload's last user message must carry a tool_result
        // matching the previous turn's tool_use id, and the new text.
        const last = secondMsgs[secondMsgs.length - 1];
        expect(last.role).toBe('user');
        expect(Array.isArray(last.content)).toBe(true);

        const toolResultIds = last.content
            .filter((b) => b.type === 'tool_result')
            .map((b) => b.tool_use_id);
        expect(toolResultIds).toEqual(['toolu_pre_pending', TOOL_USE_ID, 'toolu_post_pending']);
        expect(last.content.find((b) => b.tool_use_id === TOOL_USE_ID).name).toBe('run_query');

        const textBlocks = last.content
            .filter((b) => b.type === 'text')
            .map((b) => b.text);
        expect(textBlocks).toContain('go ahead with the design');

        // Sanity: the assistant turn that proposed the query is still in
        // history exactly once — sendMessage does not double-push it.
        const assistantTurnsWithToolUse = secondMsgs.filter((m) =>
            m.role === 'assistant' && Array.isArray(m.content) &&
            m.content.some((b) => b.type === 'tool_use' && b.id === TOOL_USE_ID)
        );
        expect(assistantTurnsWithToolUse).toHaveLength(1);

        await ctx.close();
    });

    test('issue #7: approving a PHP snippet that errors should propagate the error back to the agent', async ({ browser }) => {
        const ctx  = await browser.newContext({ storageState: AUTH_FILE });
        const page = await ctx.newPage();

        await page.goto(PLUGIN_URL);
        await setupPage(page);

        let chatCalls  = 0;
        let secondMsgs = null;

        await page.route('**/admin-ajax.php', async (route) => {
            const params = new URLSearchParams(route.request().postData() || '');
            const action = params.get('action');

            if (isChatAction(action)) {
                chatCalls++;
                const reqMessages = JSON.parse(params.get('messages') || '[]');

                if (chatCalls === 1) {
                    const echoed = reqMessages.concat([{
                        role:    'assistant',
                        content: [
                            { type: 'tool_use', id: TOOL_USE_ID, name: 'run_php',
                              input: { code: 'bad_fn();', reason: 'test' } },
                        ],
                    }]);
                    return fulfillChat(route, action, {
                        text:     'Running PHP.',
                        messages: echoed,
                        pending_action: {
                            tool_use_id: TOOL_USE_ID,
                            tool_name:   'run_php',
                            label:       'Run PHP',
                            arguments:   { code: 'bad_fn();', reason: 'test' },
                            pre_results: [],
                        },
                    });
                }

                secondMsgs = reqMessages;
                return fulfillChat(route, action, {
                    text:     'I will fix the snippet.',
                    messages: reqMessages.concat([{ role: 'assistant', content: 'I will fix the snippet.' }]),
                });
            }

            if (action === 'haydi_execute_approved_tool') {
                expect(params.get('tool_name')).toBe('run_php');
                expect(JSON.parse(params.get('arguments') || '{}')).toEqual({ code: 'bad_fn();', reason: 'test' });
                return route.fulfill({
                    status:      200,
                    contentType: 'application/json',
                    body:        JSON.stringify({
                        success: false,
                        data:    { message: 'PHP error — Error: Call to undefined function bad_fn()', output: '' },
                    }),
                });
            }

            return route.continue();
        });

        await page.fill('#wpc-chat-input', 'run some php');
        await page.click('#wpc-btn-send');
        await expect(page.locator('#wpc-action-section')).toBeVisible();

        await page.click('#wpc-btn-confirm-action');

        // The error must appear in the chat transcript so the user can see it.
        await expect(page.locator('.wpc-message--error').filter({ hasText: 'PHP error' })).toBeVisible({ timeout: 5_000 });

        // The proposal panel is hidden after the error is surfaced.
        await expect(page.locator('#wpc-action-section')).toBeHidden();

        // A second chat request must have fired.
        await expect.poll(() => chatCalls, { timeout: 5_000 }).toBeGreaterThanOrEqual(2);

        // The second request must carry a tool_result with the error message.
        const last = secondMsgs[secondMsgs.length - 1];
        expect(last.role).toBe('user');
        const toolResults = last.content.filter((b) => b.type === 'tool_result');
        expect(toolResults).toHaveLength(1);
        expect(toolResults[0].tool_use_id).toBe(TOOL_USE_ID);
        expect(toolResults[0].name).toBe('run_php');
        expect(toolResults[0].content).toMatch(/PHP error/);

        await ctx.close();
    });

    test('catalog approval uses the generic endpoint and preserves provider Tool identity', async ({ browser }) => {
        const ctx  = await browser.newContext({ storageState: AUTH_FILE });
        const page = await ctx.newPage();

        await page.goto(PLUGIN_URL);
        await setupPage(page);
        page.on('dialog', (dialog) => dialog.accept());

        let chatCalls  = 0;
        let secondMsgs = null;
        let applyCalls = 0;

        await page.route('**/admin-ajax.php', async (route) => {
            const params = new URLSearchParams(route.request().postData() || '');
            const action = params.get('action');

            if (isChatAction(action)) {
                chatCalls++;
                const reqMessages = JSON.parse(params.get('messages') || '[]');
                if (chatCalls === 1) {
                    return fulfillChat(route, action, {
                        text:     'I can install that after approval.',
                        messages: reqMessages.concat([{
                            role:    'assistant',
                            content: [{
                                type:  'tool_use',
                                id:    TOOL_USE_ID,
                                name:  'install_plugin',
                                input: {
                                    slug:           'contact-form-7',
                                    reason:         'Add a contact form.',
                                    legacy_adapter: true,
                                    ajax_action:    'haydi_should_not_run',
                                },
                            }],
                        }]),
                        pending_action: {
                            tool_use_id: TOOL_USE_ID,
                            tool_name:   'install_plugin',
                            label:       'Install Plugin',
                            arguments: {
                                slug:           'contact-form-7',
                                reason:         'Add a contact form.',
                                legacy_adapter: true,
                                ajax_action:    'haydi_should_not_run',
                            },
                            pre_results: [],
                        },
                    });
                }

                secondMsgs = reqMessages;
                return fulfillChat(route, action, {
                    text:     'Installed.',
                    messages: reqMessages.concat([{ role: 'assistant', content: 'Installed.' }]),
                });
            }

            if (action === 'haydi_execute_approved_tool') {
                applyCalls++;
                expect(params.get('tool_name')).toBe('install_plugin');
                expect(JSON.parse(params.get('arguments') || '{}')).toEqual({
                    slug:           'contact-form-7',
                    reason:         'Add a contact form.',
                    legacy_adapter: true,
                    ajax_action:    'haydi_should_not_run',
                });
                return route.fulfill({
                    status:      200,
                    contentType: 'application/json',
                    body:        JSON.stringify({
                        success: true,
                        data:    {
                            tool_name: 'install_plugin',
                            result: {
                                slug:        'contact-form-7',
                                plugin_file: 'contact-form-7/wp-contact-form-7.php',
                                installation: {
                                    status:       'installed',
                                    capabilities: ['forms', 'mail'],
                                },
                            },
                        },
                    }),
                });
            }

            return route.continue();
        });

        await page.fill('#wpc-chat-input', 'install a contact form plugin');
        await page.click('#wpc-btn-send');

        await expect(page.locator('#wpc-action-section')).toBeVisible();
        await expect(page.locator('#wpc-action-label')).toHaveText('Install Plugin');
        await expect(page.locator('#wpc-action-target')).toHaveText('contact-form-7');
        await expect(page.locator('#wpc-action-details')).toBeHidden();
        await expect(page.locator('#wpc-btn-confirm-action-label')).toHaveText('Install Plugin');

        await page.click('#wpc-btn-confirm-action');
        await expect.poll(() => applyCalls).toBe(1);
        await expect.poll(() => chatCalls).toBe(2);
        await expect(page.locator('.wpc-message--assistant').filter({ hasText: 'contact-form-7/wp-contact-form-7.php' })).toBeVisible();

        const last = secondMsgs[secondMsgs.length - 1];
        const result = last.content.find((block) => block.type === 'tool_result' && block.tool_use_id === TOOL_USE_ID);
        expect(result.name).toBe('install_plugin');
        expect(result.content).toEqual({
            slug:        'contact-form-7',
            plugin_file: 'contact-form-7/wp-contact-form-7.php',
            installation: {
                status:       'installed',
                capabilities: ['forms', 'mail'],
            },
        });

        await ctx.close();
    });

    test('file mutation and fetch approvals show their authoritative targets', async ({ browser }) => {
        const ctx = await browser.newContext({ storageState: AUTH_FILE });
        const cases = [
            {
                toolName: 'write_file',
                label:    'Write File',
                args:     {
                    path:    '/var/www/html/wp-content/plugins/example/write.php',
                    content: '<?php echo "write";',
                    reason:  'Write the fixture.',
                },
                target: 'Target: /var/www/html/wp-content/plugins/example/write.php',
            },
            {
                toolName: 'edit',
                label:    'Edit File',
                args:     {
                    filePath:  '/var/www/html/wp-content/themes/example/functions.php',
                    oldString: 'old value',
                    newString: 'new value',
                    reason:    'Edit the fixture.',
                },
                target: 'Target: /var/www/html/wp-content/themes/example/functions.php',
            },
            {
                toolName: 'delete_file',
                label:    'Delete File',
                args:     {
                    path:   '/var/www/html/wp-content/plugins/example/delete.php',
                    reason: 'Delete the fixture.',
                },
                target: 'Target: /var/www/html/wp-content/plugins/example/delete.php',
            },
            {
                toolName: 'delete_dir',
                label:    'Delete Directory',
                args:     {
                    path:   '/var/www/html/wp-content/plugins/example/obsolete',
                    reason: 'Delete the fixture directory.',
                },
                target: 'Target: /var/www/html/wp-content/plugins/example/obsolete',
            },
            {
                toolName: 'move_file',
                label:    'Move File',
                args:     {
                    src:    '/var/www/html/wp-content/plugins/example/source.php',
                    dest:   '/var/www/html/wp-content/plugins/example/moved.php',
                    reason: 'Move the fixture.',
                },
                target: 'Source: /var/www/html/wp-content/plugins/example/source.php\n'
                    + 'Destination: /var/www/html/wp-content/plugins/example/moved.php',
            },
            {
                toolName: 'copy_file',
                label:    'Copy File',
                args:     {
                    src:    '/var/www/html/wp-content/themes/example/source.php',
                    dest:   '/var/www/html/wp-content/themes/example/copied.php',
                    reason: 'Copy the fixture.',
                },
                target: 'Source: /var/www/html/wp-content/themes/example/source.php\n'
                    + 'Destination: /var/www/html/wp-content/themes/example/copied.php',
            },
            {
                toolName: 'restore_backup',
                label:    'Restore Backup',
                args:     {
                    backup_file:   'functions.php.2026-08-20_12-00-00.token.bak',
                    original_path: '/var/www/html/wp-content/themes/example/functions.php',
                    reason:        'Restore the fixture.',
                },
                target: 'Backup: functions.php.2026-08-20_12-00-00.token.bak\n'
                    + 'Restore to: /var/www/html/wp-content/themes/example/functions.php',
            },
            {
                toolName: 'fetch_url',
                label:    'Fetch URL',
                args:     {
                    url: 'https://example.test/reference?item=42',
                },
                target: 'https://example.test/reference?item=42',
            },
        ];

        for (const item of cases) {
            const page = await ctx.newPage();
            await page.goto(PLUGIN_URL);
            await setupPage(page);

            await page.route('**/admin-ajax.php', async (route) => {
                const params = new URLSearchParams(route.request().postData() || '');
                const action = params.get('action');

                if (isChatAction(action)) {
                    const messages = JSON.parse(params.get('messages') || '[]');
                    return fulfillChat(route, action, {
                        text:     `Prepared ${item.label}.`,
                        messages: messages.concat([{
                            role:    'assistant',
                            content: [{
                                type:  'tool_use',
                                id:    `${TOOL_USE_ID}_${item.toolName}`,
                                name:  item.toolName,
                                input: item.args,
                            }],
                        }]),
                        pending_action: {
                            tool_use_id: `${TOOL_USE_ID}_${item.toolName}`,
                            tool_name:   item.toolName,
                            label:       item.label,
                            arguments:   item.args,
                            pre_results: [],
                        },
                    });
                }

                if (action === 'haydi_read_file') {
                    return route.fulfill({
                        status:      200,
                        contentType: 'application/json',
                        body:        JSON.stringify({ success: true, data: { content: 'old value' } }),
                    });
                }

                return route.continue();
            });

            await page.fill('#wpc-chat-input', `prepare ${item.toolName}`);
            await page.click('#wpc-btn-send');

            const target = page.locator('#wpc-action-target');
            await expect(target).toBeVisible();
            await expect.poll(() => target.textContent()).toBe(item.target);
            await page.close();
        }

        await ctx.close();
    });

    test('declining the generic card resolves the exact provider Tool call without executing it', async ({ browser }) => {
        const ctx  = await browser.newContext({ storageState: AUTH_FILE });
        const page = await ctx.newPage();

        await page.goto(PLUGIN_URL);
        await setupPage(page);

        let chatCalls  = 0;
        let applyCalls = 0;
        let secondMsgs = null;

        await page.route('**/admin-ajax.php', async (route) => {
            const params = new URLSearchParams(route.request().postData() || '');
            const action = params.get('action');

            if (isChatAction(action)) {
                chatCalls++;
                const reqMessages = JSON.parse(params.get('messages') || '[]');
                if (chatCalls === 1) {
                    return fulfillChat(route, action, {
                        text:     '',
                        messages: reqMessages.concat([{
                            role:    'assistant',
                            content: [{
                                type:  'tool_use',
                                id:    TOOL_USE_ID,
                                name:  'deactivate_plugin',
                                input: { plugin: 'demo/demo.php', reason: 'No longer needed.' },
                            }],
                        }]),
                        pending_action: {
                            tool_use_id: TOOL_USE_ID,
                            tool_name:   'deactivate_plugin',
                            label:       'Deactivate Plugin',
                            arguments:   { plugin: 'demo/demo.php', reason: 'No longer needed.' },
                            pre_results: [],
                        },
                    });
                }

                secondMsgs = reqMessages;
                return fulfillChat(route, action, {
                    text:     'Understood.',
                    messages: reqMessages.concat([{ role: 'assistant', content: 'Understood.' }]),
                });
            }

            if (action === 'haydi_execute_approved_tool') {
                applyCalls++;
                return route.fulfill({
                    status:      500,
                    contentType: 'application/json',
                    body:        JSON.stringify({ success: false, data: { message: 'Decline must not execute.' } }),
                });
            }
            return route.continue();
        });

        await page.fill('#wpc-chat-input', 'deactivate demo');
        await page.click('#wpc-btn-send');
        await expect(page.locator('#wpc-action-section')).toBeVisible();
        await expect(page.locator('#wpc-action-section')).toHaveClass(/is-danger/);
        await page.click('#wpc-btn-cancel-action');

        await expect.poll(() => chatCalls).toBe(2);
        expect(applyCalls).toBe(0);
        await expect(page.locator('#wpc-action-section')).toBeHidden();

        const last = secondMsgs[secondMsgs.length - 1];
        const result = last.content.find((block) => block.type === 'tool_result');
        expect(result.tool_use_id).toBe(TOOL_USE_ID);
        expect(result.name).toBe('deactivate_plugin');
        expect(result.content).toContain('cancelled this deactivate plugin');

        await ctx.close();
    });

    test('legacy pending_action uses its declared AJAX Adapter with flattened arguments', async ({ browser }) => {
        const ctx  = await browser.newContext({ storageState: AUTH_FILE });
        const page = await ctx.newPage();

        await page.goto(PLUGIN_URL);
        await setupPage(page);

        let chatCalls  = 0;
        let legacyHits = 0;
        let secondMsgs = null;

        await page.route('**/admin-ajax.php', async (route) => {
            const params = new URLSearchParams(route.request().postData() || '');
            const action = params.get('action');

            if (isChatAction(action)) {
                chatCalls++;
                const reqMessages = JSON.parse(params.get('messages') || '[]');
                if (chatCalls === 1) {
                    return fulfillChat(route, action, {
                        text:     '',
                        messages: reqMessages.concat([{
                            role:    'assistant',
                            content: [{
                                type:  'tool_use',
                                id:    TOOL_USE_ID,
                                name:  'legacy_clear_cache',
                                input: { cache: 'pages', reason: 'Refresh stale pages.' },
                            }],
                        }]),
                        pending_action: {
                            tool_use_id:  TOOL_USE_ID,
                            tool_name:    'legacy_clear_cache',
                            label:        'Clear Cache',
                            legacy_adapter: true,
                            ajax_action:  'haydi_legacy_clear_cache',
                            payload_keys: ['cache', 'reason'],
                            cache:        'pages',
                            reason:       'Refresh stale pages.',
                            pre_results:  [{
                                type:        'tool_result',
                                tool_use_id: 'read_before_approval',
                                name:        'read_status',
                                content:     'warm',
                            }],
                        },
                    });
                }

                secondMsgs = reqMessages;
                return fulfillChat(route, action, {
                    text:     'Cache cleared.',
                    messages: reqMessages.concat([{ role: 'assistant', content: 'Cache cleared.' }]),
                });
            }

            if (action === 'haydi_legacy_clear_cache') {
                legacyHits++;
                expect(params.get('cache')).toBe('pages');
                expect(params.get('reason')).toBe('Refresh stale pages.');
                expect(params.get('tool_name')).toBeNull();
                expect(params.get('arguments')).toBeNull();
                return route.fulfill({
                    status:      200,
                    contentType: 'application/json',
                    body:        JSON.stringify({
                        success: true,
                        data:    { result: 'Cache cleared.' },
                    }),
                });
            }

            return route.continue();
        });

        await page.fill('#wpc-chat-input', 'clear stale cache');
        await page.click('#wpc-btn-send');
        await expect(page.locator('#wpc-action-section')).toBeVisible();
        await page.click('#wpc-btn-confirm-action');

        await expect.poll(() => legacyHits).toBe(1);
        await expect.poll(() => chatCalls).toBe(2);

        const last = secondMsgs[secondMsgs.length - 1];
        const results = last.content.filter((block) => block.type === 'tool_result');
        expect(results.map((block) => block.tool_use_id)).toEqual(['read_before_approval', TOOL_USE_ID]);
        expect(results[1].name).toBe('legacy_clear_cache');
        expect(typeof results[1].content).toBe('string');
        expect(results[1].content).toContain('Cache cleared.');

        await ctx.close();
    });

    test('clicking Send while an approval AJAX is in flight is a no-op (race guard)', async ({ browser }) => {
        const ctx  = await browser.newContext({ storageState: AUTH_FILE });
        const page = await ctx.newPage();

        await page.goto(PLUGIN_URL);
        await setupPage(page);

        let chatCalls       = 0;
        let executeQueryHit = 0;
        let resolveQuery    = null;

        await page.route('**/admin-ajax.php', async (route) => {
            const params = new URLSearchParams(route.request().postData() || '');
            const action = params.get('action');

            if (isChatAction(action)) {
                chatCalls++;
                const reqMessages = JSON.parse(params.get('messages') || '[]');
                if (chatCalls === 1) {
                    const echoed = reqMessages.concat([{
                        role:    'assistant',
                        content: [
                            { type: 'tool_use', id: TOOL_USE_ID, name: 'run_query',
                              input: { sql: 'SELECT 1', reason: 'r' } },
                        ],
                    }]);
                    return fulfillChat(route, action, {
                        text:     '',
                        messages: echoed,
                        pending_action: {
                            tool_use_id: TOOL_USE_ID,
                            tool_name:   'run_query',
                            label:       'Run SQL Query',
                            arguments:   { sql: 'SELECT 1', reason: 'r' },
                            pre_results: [],
                        },
                    });
                }
                return fulfillChat(route, action, {
                    text:     'done',
                    messages: reqMessages.concat([{ role: 'assistant', content: 'done' }]),
                });
            }

            if (action === 'haydi_execute_approved_tool') {
                executeQueryHit++;
                expect(params.get('tool_name')).toBe('run_query');
                expect(JSON.parse(params.get('arguments') || '{}')).toEqual({ sql: 'SELECT 1', reason: 'r' });
                // Hold the response open until the test releases it.
                await new Promise((res) => { resolveQuery = res; });
                return route.fulfill({
                    status:      200,
                    contentType: 'application/json',
                    body:        JSON.stringify({
                        success: true,
                        data:    {
                            tool_name: 'run_query',
                            result: {
                                type:      'select',
                                rows:      [{ n: '1' }],
                                count:     1,
                                truncated: false,
                            },
                        },
                    }),
                });
            }

            return route.continue();
        });

        // Trigger the proposal.
        await page.fill('#wpc-chat-input', 'do a query');
        await page.click('#wpc-btn-send');
        await expect(page.locator('#wpc-action-section')).toBeVisible();

        // Approve — but the route holds the response, so the AJAX is mid-flight.
        await page.click('#wpc-btn-confirm-action');
        await expect.poll(() => executeQueryHit).toBe(1);

        // While the approval is mid-flight, type a message and click Send.
        // The race guard must reject this — chatCalls must stay at 1.
        await page.fill('#wpc-chat-input', 'never mind');
        await page.click('#wpc-btn-send');

        // Give the click a moment to either no-op (correct) or fire a chat (bug).
        await page.waitForTimeout(250);
        expect(chatCalls).toBe(1);

        // Release the held approval response.  Now the success callback can
        // push the real tool_result and call runChatRequest exactly once.
        resolveQuery();
        await expect.poll(() => chatCalls).toBe(2);

        await ctx.close();
    });
});
