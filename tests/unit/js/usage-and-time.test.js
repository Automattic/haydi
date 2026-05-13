// @ts-check
/**
 * Unit tests for the formatting helpers added to assets/admin.js for token
 * monitoring (formatTokens) and per-message timestamps (formatTimestamp).
 *
 * Same convention as utils.test.js: the helpers live inside an IIFE in
 * admin.js and cannot be imported, so they're reproduced verbatim here.
 * Any change to the originals must be mirrored in the function definitions
 * below.
 */

'use strict';

// ---------------------------------------------------------------------------
// Functions copied verbatim from assets/admin.js
// ---------------------------------------------------------------------------

function formatTokens(n) {
    n = Number(n) || 0;
    if (n < 1000)    return String(n);
    if (n < 1000000) return (n / 1000).toFixed(n < 10000 ? 1 : 0) + 'k';
    return (n / 1000000).toFixed(1) + 'M';
}

function formatTimestamp(ms) {
    if (!ms) return '';
    var d   = new Date(Number(ms));
    if (isNaN(d.getTime())) return '';
    var now = new Date();
    var sameDay = d.getFullYear() === now.getFullYear()
               && d.getMonth()    === now.getMonth()
               && d.getDate()     === now.getDate();
    var time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    if (sameDay) return time;
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + time;
}

// Adapted from updateContextBadge() to keep the token-budget math testable
// without booting the WordPress admin UI.
function contextUsageMetric(usage, maxInput, reservedOutput) {
    var peak = Number(usage.prompt_peak) || 0;
    var last = Number(usage.prompt_last) || 0;
    var current = last || peak;
    var usableInput = maxInput ? Math.max(1, maxInput - reservedOutput) : 0;

    if (!current || !usableInput) {
        return null;
    }

    return {
        current,
        peak,
        usableInput,
        pct: Math.min(999, Math.round((current / usableInput) * 100)),
    };
}

function currentContextPressure(usage, maxInput, reservedOutput) {
    var current = Number(usage.prompt_last) || Number(usage.prompt_peak) || 0;
    var usableInput = maxInput ? Math.max(1, maxInput - reservedOutput) : 0;

    if (!current || !usableInput) { return 0; }
    return current / usableInput;
}

function shouldAutoCompactBeforeSend(state, usage, maxInput, reservedOutput) {
    if (state.applyInFlight || state.busy || state.pending || !state.messages.length) { return false; }
    return currentContextPressure(usage, maxInput, reservedOutput) >= 0.90;
}

// ---------------------------------------------------------------------------
// formatTokens()
// ---------------------------------------------------------------------------

test('formatTokens: sub-thousand values render as raw integers', () => {
    expect(formatTokens(0)).toBe('0');
    expect(formatTokens(1)).toBe('1');
    expect(formatTokens(999)).toBe('999');
});

test('formatTokens: thousands below 10k render with one decimal place', () => {
    expect(formatTokens(1000)).toBe('1.0k');
    expect(formatTokens(1500)).toBe('1.5k');
    expect(formatTokens(9999)).toBe('10.0k'); // 9.999 rounds up to 10.0
});

test('formatTokens: thousands at 10k and above drop the decimal', () => {
    expect(formatTokens(10000)).toBe('10k');
    expect(formatTokens(12345)).toBe('12k');
    expect(formatTokens(999999)).toBe('1000k');
});

test('formatTokens: millions render with one decimal place and M suffix', () => {
    expect(formatTokens(1000000)).toBe('1.0M');
    expect(formatTokens(2500000)).toBe('2.5M');
});

test('formatTokens: non-numeric or falsy inputs render as 0', () => {
    expect(formatTokens(null)).toBe('0');
    expect(formatTokens(undefined)).toBe('0');
    expect(formatTokens('not-a-number')).toBe('0');
    expect(formatTokens('')).toBe('0');
});

test('formatTokens: numeric strings are coerced through Number()', () => {
    expect(formatTokens('1500')).toBe('1.5k');
    expect(formatTokens('42')).toBe('42');
});

// ---------------------------------------------------------------------------
// formatTimestamp()
// ---------------------------------------------------------------------------

test('formatTimestamp: returns empty string for missing or zero input', () => {
    expect(formatTimestamp(0)).toBe('');
    expect(formatTimestamp(null)).toBe('');
    expect(formatTimestamp(undefined)).toBe('');
});

test('formatTimestamp: returns empty string for invalid numeric input', () => {
    // Number('abc') is NaN → new Date(NaN) is Invalid Date.
    expect(formatTimestamp('abc')).toBe('');
});

test('formatTimestamp: same-day timestamps render as HH:MM only', () => {
    // Build a timestamp known to share the current day.
    const now    = new Date();
    const sample = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 14, 23, 0).getTime();

    const out = formatTimestamp(sample);

    // HH:MM in the local locale — exact format is locale-dependent, but the
    // string must NOT contain a date component (no month name, no slash, no comma).
    expect(out).not.toMatch(/[A-Za-z]{3,}|\//);
    // It must contain at least the minute we set.
    expect(out).toMatch(/23/);
});

test('formatTimestamp: different-day timestamps include an abbreviated date', () => {
    // 30 days before today is reliably outside today's calendar date.
    const past = Date.now() - 30 * 24 * 60 * 60 * 1000;

    const out = formatTimestamp(past);

    // Must contain at least one alphabetic run (the abbreviated month name).
    expect(out).toMatch(/[A-Za-z]{3,}/);
    // And a numeric day-of-month somewhere.
    expect(out).toMatch(/\d/);
});

test('formatTimestamp: numeric strings are accepted via Number() coercion', () => {
    const now    = new Date();
    const sample = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 9, 5, 0).getTime();

    expect(formatTimestamp(String(sample))).not.toBe('');
});

// ---------------------------------------------------------------------------
// contextUsageMetric()
// ---------------------------------------------------------------------------

test('contextUsageMetric: latest prompt drives the visible percentage', () => {
    const metric = contextUsageMetric(
        { prompt_peak: 90000, prompt_last: 12000 },
        100000,
        10000
    );

    expect(metric.current).toBe(12000);
    expect(metric.peak).toBe(90000);
    expect(metric.usableInput).toBe(90000);
    expect(metric.pct).toBe(13);
});

test('contextUsageMetric: falls back to peak for legacy usage without prompt_last', () => {
    const metric = contextUsageMetric(
        { prompt_peak: 45000, prompt_last: 0 },
        100000,
        10000
    );

    expect(metric.current).toBe(45000);
    expect(metric.pct).toBe(50);
});

test('contextUsageMetric: reserves configured output tokens from input budget', () => {
    const metric = contextUsageMetric(
        { prompt_peak: 75000, prompt_last: 75000 },
        100000,
        25000
    );

    expect(metric.usableInput).toBe(75000);
    expect(metric.pct).toBe(100);
});

// ---------------------------------------------------------------------------
// auto compaction threshold
// ---------------------------------------------------------------------------

test('shouldAutoCompactBeforeSend: triggers at 90 percent of usable input', () => {
    expect(shouldAutoCompactBeforeSend(
        { applyInFlight: false, busy: false, pending: null, messages: [{ role: 'user', content: 'Earlier task.' }] },
        { prompt_peak: 1000, prompt_last: 81000 },
        100000,
        10000
    )).toBe(true);
});

test('shouldAutoCompactBeforeSend: waits below 90 percent of usable input', () => {
    expect(shouldAutoCompactBeforeSend(
        { applyInFlight: false, busy: false, pending: null, messages: [{ role: 'user', content: 'Earlier task.' }] },
        { prompt_peak: 1000, prompt_last: 80999 },
        100000,
        10000
    )).toBe(false);
});

test('shouldAutoCompactBeforeSend: does not run while a proposal is pending', () => {
    expect(shouldAutoCompactBeforeSend(
        { applyInFlight: false, busy: false, pending: { kind: 'write' }, messages: [{ role: 'user', content: 'Earlier task.' }] },
        { prompt_peak: 81000, prompt_last: 81000 },
        100000,
        10000
    )).toBe(false);
});
