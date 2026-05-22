// @ts-check
/**
 * Issue #12 — UI integration test for the orphan tool_use scenario.
 *
 * Repro from the bug report: AI proposes a run_query (an extension tool), the
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
        // without an AI provider.  First call → returns a pending_extension.
        // Second call → captures the messages array and ends the turn.
        let chatCalls   = 0;
        let secondMsgs  = null;
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
                        { type: 'tool_use', id: TOOL_USE_ID, name: 'run_query',
                          input: { sql: 'SELECT 1', reason: 'inspect categories' } },
                    ],
                }]);
                return fulfillChat(route, action, {
                    text:     'I will list categories first.',
                    messages: echoed,
                    pending_extension: {
                        tool_use_id:  TOOL_USE_ID,
                        tool_name:    'run_query',
                        label:        'Run SQL Query',
                        ajax_action:  'haydi_execute_query',
                        payload_keys: ['sql', 'reason'],
                        sql:          'SELECT 1',
                        reason:       'inspect categories',
                        pre_results:  [],
                    },
                });
            }

            // Second call: capture and respond with a no-op end_turn.
            secondMsgs = reqMessages;
            return fulfillChat(route, action, {
                text:     'OK',
                messages: reqMessages.concat([{ role: 'assistant', content: 'OK' }]),
            });
        });

        // First user message — triggers the proposal.
        await page.fill('#wpc-chat-input', 'install woocommerce please');
        await page.click('#wpc-btn-send');
        await expect(page.locator('#wpc-extension-section')).toBeVisible();
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

        // The proposal panel must be hidden — sendMessage clears it.
        await expect(page.locator('#wpc-extension-section')).toBeHidden();

        // The captured payload's last user message must carry a tool_result
        // matching the previous turn's tool_use id, and the new text.
        const last = secondMsgs[secondMsgs.length - 1];
        expect(last.role).toBe('user');
        expect(Array.isArray(last.content)).toBe(true);

        const toolResultIds = last.content
            .filter((b) => b.type === 'tool_result')
            .map((b) => b.tool_use_id);
        expect(toolResultIds).toContain(TOOL_USE_ID);

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
                        pending_extension: {
                            tool_use_id:  TOOL_USE_ID,
                            tool_name:    'run_php',
                            label:        'Run PHP',
                            ajax_action:  'haydi_run_php',
                            payload_keys: ['code', 'reason'],
                            code:         'bad_fn();',
                            reason:       'test',
                            pre_results:  [],
                        },
                    });
                }

                secondMsgs = reqMessages;
                return fulfillChat(route, action, {
                    text:     'I will fix the snippet.',
                    messages: reqMessages.concat([{ role: 'assistant', content: 'I will fix the snippet.' }]),
                });
            }

            if (action === 'haydi_run_php') {
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
        await expect(page.locator('#wpc-extension-section')).toBeVisible();

        await page.click('#wpc-btn-confirm-extension');

        // The error must appear in the chat transcript so the user can see it.
        await expect(page.locator('.wpc-message--error').filter({ hasText: 'PHP error' })).toBeVisible({ timeout: 5_000 });

        // The proposal panel is hidden after the error is surfaced.
        await expect(page.locator('#wpc-extension-section')).toBeHidden();

        // A second chat request must have fired.
        await expect.poll(() => chatCalls, { timeout: 5_000 }).toBeGreaterThanOrEqual(2);

        // The second request must carry a tool_result with the error message.
        const last = secondMsgs[secondMsgs.length - 1];
        expect(last.role).toBe('user');
        const toolResults = last.content.filter((b) => b.type === 'tool_result');
        expect(toolResults).toHaveLength(1);
        expect(toolResults[0].tool_use_id).toBe(TOOL_USE_ID);
        expect(toolResults[0].content).toMatch(/PHP error/);

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
                        pending_extension: {
                            tool_use_id:  TOOL_USE_ID,
                            tool_name:    'run_query',
                            label:        'Run SQL Query',
                            ajax_action:  'haydi_execute_query',
                            payload_keys: ['sql', 'reason'],
                            sql:          'SELECT 1',
                            reason:       'r',
                            pre_results:  [],
                        },
                    });
                }
                return fulfillChat(route, action, {
                    text:     'done',
                    messages: reqMessages.concat([{ role: 'assistant', content: 'done' }]),
                });
            }

            if (action === 'haydi_execute_query') {
                executeQueryHit++;
                // Hold the response open until the test releases it.
                await new Promise((res) => { resolveQuery = res; });
                return route.fulfill({
                    status:      200,
                    contentType: 'application/json',
                    body:        JSON.stringify({
                        success: true,
                        data:    { type: 'select', rows: [], count: 0, truncated: false, result: '[]' },
                    }),
                });
            }

            return route.continue();
        });

        // Trigger the proposal.
        await page.fill('#wpc-chat-input', 'do a query');
        await page.click('#wpc-btn-send');
        await expect(page.locator('#wpc-extension-section')).toBeVisible();

        // Approve — but the route holds the response, so the AJAX is mid-flight.
        await page.click('#wpc-btn-confirm-extension');
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
