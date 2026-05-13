// @ts-check
/**
 * Unit tests for sanitizeMessages() in assets/admin.js.
 *
 * The function lives inside an IIFE in admin.js so it cannot be imported.
 * It is reproduced verbatim here — any change to the original must be
 * reflected in the function definition below.
 */

'use strict';

// ---------------------------------------------------------------------------
// Function copied verbatim from assets/admin.js
// ---------------------------------------------------------------------------

function sanitizeMessages(msgs) {
    var fixed = [];
    for (var i = 0; i < msgs.length; i++) {
        var msg = msgs[i];
        fixed.push(msg);

        if (msg.role !== 'assistant' || !Array.isArray(msg.content)) { continue; }

        var toolUseIds = [];
        msg.content.forEach(function (block) {
            if (block.type === 'tool_use' && block.id) { toolUseIds.push(block.id); }
        });
        if (!toolUseIds.length) { continue; }

        if (i === msgs.length - 1) {
            fixed.pop();
            continue;
        }

        var next       = msgs[i + 1];
        var resolved   = [];
        var nextIsUserArr = next && next.role === 'user' && Array.isArray(next.content);
        if (nextIsUserArr) {
            next.content.forEach(function (block) {
                if (block.type === 'tool_result' && block.tool_use_id) {
                    resolved.push(block.tool_use_id);
                }
            });
        }

        var missing = toolUseIds.filter(function (id) { return resolved.indexOf(id) === -1; });
        if (!missing.length) { continue; }

        var synthetic = missing.map(function (id) {
            return {
                type:        'tool_result',
                tool_use_id: id,
                content:     'The user moved on without approving this action.',
            };
        });

        if (nextIsUserArr) {
            next.content = synthetic.concat(next.content);
        } else if (next.role === 'user' && typeof next.content === 'string') {
            next.content = synthetic.concat([{ type: 'text', text: next.content }]);
        } else {
            fixed.push({ role: 'user', content: synthetic });
        }
    }
    return fixed;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('returns empty list unchanged', () => {
    expect(sanitizeMessages([])).toEqual([]);
});

test('passes through a well-formed chat untouched', () => {
    const msgs = [
        { role: 'user', content: 'hi' },
        { role: 'assistant', content: 'hello' },
    ];
    expect(sanitizeMessages(msgs)).toEqual(msgs);
});

test('strips a trailing assistant message that ends in an unresolved tool_use', () => {
    const msgs = [
        { role: 'user', content: 'do it' },
        { role: 'assistant', content: [
            { type: 'text', text: 'sure' },
            { type: 'tool_use', id: 'tool_1', name: 'run_query', input: {} },
        ]},
    ];
    const result = sanitizeMessages(msgs);
    expect(result).toHaveLength(1);
    expect(result[0].role).toBe('user');
});

test('keeps a trailing assistant message whose tool_use blocks are all resolved', () => {
    const msgs = [
        { role: 'assistant', content: [
            { type: 'tool_use', id: 'tool_1', name: 'list_files', input: {} },
        ]},
        { role: 'user', content: [
            { type: 'tool_result', tool_use_id: 'tool_1', content: 'ok' },
        ]},
    ];
    expect(sanitizeMessages(msgs)).toEqual(msgs);
});

test('injects synthetic tool_result blocks for mid-history orphaned tool_use', () => {
    const msgs = [
        { role: 'assistant', content: [
            { type: 'tool_use', id: 'tool_1', name: 'run_query', input: {} },
        ]},
        { role: 'user', content: 'just go ahead with the next step' },
        { role: 'assistant', content: 'ok' },
    ];
    const result = sanitizeMessages(msgs);
    expect(result).toHaveLength(3);
    // The plain-string user message becomes an array containing the synthetic
    // tool_result followed by the original text block.
    expect(Array.isArray(result[1].content)).toBe(true);
    expect(result[1].content[0]).toMatchObject({
        type:        'tool_result',
        tool_use_id: 'tool_1',
    });
    expect(result[1].content[1]).toMatchObject({
        type: 'text',
        text: 'just go ahead with the next step',
    });
});

test('prepends synthetic tool_result blocks to an array-form user message that lacks them', () => {
    const msgs = [
        { role: 'assistant', content: [
            { type: 'tool_use', id: 'tool_1', name: 'run_query', input: {} },
            { type: 'tool_use', id: 'tool_2', name: 'read_file', input: {} },
        ]},
        { role: 'user', content: [
            { type: 'tool_result', tool_use_id: 'tool_2', content: 'file contents' },
        ]},
    ];
    const result = sanitizeMessages(msgs);
    const ids = result[1].content
        .filter((b) => b.type === 'tool_result')
        .map((b) => b.tool_use_id);
    expect(ids).toEqual(expect.arrayContaining(['tool_1', 'tool_2']));
});

// ---------------------------------------------------------------------------
// Issue #12 — "Weird occasional failures on long prompts"
// Reproduces the exact sequence from the bug report: a proposal (run_query)
// was outstanding when the user typed a new instruction instead of clicking
// Execute / Cancel, so the previous assistant turn's tool_use was orphaned.
// ---------------------------------------------------------------------------

test('issue #12: broken state — orphaned proposal followed by plain-text user msg is repaired', () => {
    // The chat as it would have been saved by the buggy code path:
    // assistant proposes a query, user replies in plain text.
    const msgs = [
        { role: 'user',      content: 'install woocommerce and import products' },
        { role: 'assistant', content: [
            { type: 'text', text: 'WooCommerce is installed and active...' },
            { type: 'tool_use', id: 'toolu_01H9thnKCPLdXv5T36pxP5Yo', name: 'run_query', input: {
                sql: 'SELECT t.term_id ...',
                reason: 'List existing product categories...',
            }},
        ]},
        { role: 'user', content: '7 products is ok just go ahead with the design this is the MOST IMPORTANT BIT in the world right now' },
    ];

    const result = sanitizeMessages(msgs);

    // Same length — sanitizeMessages repairs in place rather than dropping turns.
    expect(result).toHaveLength(3);
    // The orphaned user message is now an array carrying a synthetic
    // tool_result for the proposal, then the user's actual text.
    expect(Array.isArray(result[2].content)).toBe(true);
    expect(result[2].content[0]).toMatchObject({
        type:        'tool_result',
        tool_use_id: 'toolu_01H9thnKCPLdXv5T36pxP5Yo',
    });
    expect(result[2].content[1]).toMatchObject({
        type: 'text',
        text: '7 products is ok just go ahead with the design this is the MOST IMPORTANT BIT in the world right now',
    });
});

test('issue #12: well-formed state — sendMessage’s bundled tool_result + text passes through untouched', () => {
    // The chat as the FIXED sendMessage now constructs it: the new
    // user message already carries a cancel tool_result alongside the text,
    // so sanitizeMessages must not double-repair or otherwise mutate it.
    const msgs = [
        { role: 'assistant', content: [
            { type: 'tool_use', id: 'toolu_01H9thnKCPLdXv5T36pxP5Yo', name: 'run_query', input: {} },
        ]},
        { role: 'user', content: [
            { type: 'tool_result', tool_use_id: 'toolu_01H9thnKCPLdXv5T36pxP5Yo',
              content: 'The user did not approve the proposed query and sent a new instruction instead.' },
            { type: 'text', text: '7 products is ok just go ahead with the design' },
        ]},
    ];
    const before = JSON.parse(JSON.stringify(msgs));
    expect(sanitizeMessages(msgs)).toEqual(before);
});

test('inserts a fresh user message when the next message is assistant', () => {
    const msgs = [
        { role: 'assistant', content: [
            { type: 'tool_use', id: 'tool_1', name: 'run_query', input: {} },
        ]},
        { role: 'assistant', content: 'oops, no user response in between' },
    ];
    const result = sanitizeMessages(msgs);
    expect(result).toHaveLength(3);
    expect(result[1].role).toBe('user');
    expect(result[1].content[0]).toMatchObject({
        type:        'tool_result',
        tool_use_id: 'tool_1',
    });
    expect(result[2].role).toBe('assistant');
});
