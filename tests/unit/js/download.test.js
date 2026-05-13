// @ts-check
/**
 * Unit tests for formatDisplayLogAsText in assets/admin.js.
 *
 * Same convention as utils.test.js: the function lives inside an IIFE in
 * admin.js and cannot be imported, so it's reproduced verbatim here.
 * Any change to the original must be mirrored in the definition below.
 */

'use strict';

// ---------------------------------------------------------------------------
// Function copied verbatim from assets/admin.js
// ---------------------------------------------------------------------------

function formatDisplayLogAsText(displayLog) {
    var roleLabels = { user: 'You', assistant: 'Assistant', 'compact-summary': 'Summary' };
    var lines = [];
    displayLog.forEach(function (entry) {
        if (entry.role !== 'user' && entry.role !== 'assistant' && entry.role !== 'compact-summary') { return; }
        var label = roleLabels[entry.role];
        var timestamp = entry.t ? new Date(entry.t).toLocaleString() : '';
        lines.push((timestamp ? '[' + timestamp + '] ' : '') + label + ':');
        lines.push(entry.text);
        lines.push('');
    });
    return lines;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test('includes user and assistant messages', () => {
    var lines = formatDisplayLogAsText([
        { role: 'user',      text: 'Hello',   t: null },
        { role: 'assistant', text: 'Hi there', t: null },
    ]);
    expect(lines).toContain('You:');
    expect(lines).toContain('Hello');
    expect(lines).toContain('Assistant:');
    expect(lines).toContain('Hi there');
});

test('includes compact-summary entries with Summary label', () => {
    var lines = formatDisplayLogAsText([
        { role: 'compact-summary', text: 'Goal: build a plugin.', t: null },
    ]);
    expect(lines).toContain('Summary:');
    expect(lines).toContain('Goal: build a plugin.');
});

test('excludes usage and activity entries', () => {
    var lines = formatDisplayLogAsText([
        { role: 'usage',    text: '100 tokens', t: null },
        { role: 'activity', text: 'ran tool',   t: null },
    ]);
    expect(lines).toHaveLength(0);
});

test('prepends timestamp when t is set', () => {
    var lines = formatDisplayLogAsText([
        { role: 'user', text: 'hi', t: 0 },
    ]);
    expect(lines[0]).toBe('You:');
});

test('omits timestamp bracket when t is null', () => {
    var lines = formatDisplayLogAsText([
        { role: 'user', text: 'hi', t: null },
    ]);
    expect(lines[0]).toBe('You:');
});

test('separates entries with a blank line', () => {
    var lines = formatDisplayLogAsText([
        { role: 'user',      text: 'a', t: null },
        { role: 'assistant', text: 'b', t: null },
    ]);
    expect(lines[2]).toBe('');
    expect(lines[5]).toBe('');
});
