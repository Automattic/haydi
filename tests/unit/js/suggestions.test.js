// @ts-check
/**
 * Unit tests for pickSuggestionChips() — picks one prompt per category from
 * the suggestion pool to seed the greeting's chip row.
 *
 * The function lives inside an IIFE in admin.js so it cannot be imported.
 * It is reproduced verbatim here — any change to the original must be
 * reflected in the function definition below.
 */

'use strict';

// ---------------------------------------------------------------------------
// Function copied verbatim from assets/admin.js
// ---------------------------------------------------------------------------

function pickSuggestionChips(pool, rng) {
    rng = rng || Math.random;
    var picks = [];
    Object.keys(pool || {}).forEach(function (key) {
        var bucket = pool[key];
        if (!Array.isArray(bucket) || bucket.length === 0) { return; }
        var idx = Math.floor(rng() * bucket.length) % bucket.length;
        picks.push(bucket[idx]);
    });
    return picks;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

// Returns a deterministic RNG that yields the supplied values in order, then
// loops. Lets tests force which bucket index is chosen for each category.
function seq(values) {
    var i = 0;
    return function () {
        var v = values[i % values.length];
        i++;
        return v;
    };
}

const POOL = {
    file:   ['F1', 'F2', 'F3'],
    db:     ['D1', 'D2'],
    plugin: ['P1', 'P2', 'P3'],
    php:    ['X1', 'X2'],
};

// ---------------------------------------------------------------------------
// Core behaviour
// ---------------------------------------------------------------------------

test('returns one chip per non-empty category, in key order', () => {
    // rng=0 always picks index 0 → first entry of each bucket.
    const picks = pickSuggestionChips(POOL, () => 0);
    expect(picks).toEqual(['F1', 'D1', 'P1', 'X1']);
});

test('picks the entry indexed by floor(rng * bucket.length)', () => {
    // 0.99 against bucket of length N picks the last entry of that bucket.
    const picks = pickSuggestionChips(POOL, () => 0.99);
    expect(picks).toEqual(['F3', 'D2', 'P3', 'X2']);
});

test('drives a different chip per category from a sequenced rng', () => {
    // file: 0/3 → F1, db: 0.5/2 → D1, plugin: 0.5/3 → P1 (0.5*3=1.5→1 wait)
    // 0.5 * 3 = 1.5 → floor → 1 → P2.  Recompute below explicitly.
    // File   bucket=3, rng=0    → idx 0 → F1
    // Db     bucket=2, rng=0.6  → 1.2 → 1 → D2
    // Plugin bucket=3, rng=0.5  → 1.5 → 1 → P2
    // Php    bucket=2, rng=0.99 → 1.98 → 1 → X2
    const picks = pickSuggestionChips(POOL, seq([0, 0.6, 0.5, 0.99]));
    expect(picks).toEqual(['F1', 'D2', 'P2', 'X2']);
});

test('returns a chip count equal to the number of populated categories', () => {
    expect(pickSuggestionChips(POOL).length).toBe(4);
});

// ---------------------------------------------------------------------------
// Defensive / edge cases
// ---------------------------------------------------------------------------

test('skips categories whose bucket is empty', () => {
    const pool = { file: ['F1'], db: [], plugin: ['P1'] };
    const picks = pickSuggestionChips(pool, () => 0);
    expect(picks).toEqual(['F1', 'P1']);
});

test('skips categories whose value is not an array', () => {
    const pool = { file: ['F1'], db: 'oops', plugin: ['P1'] };
    const picks = pickSuggestionChips(pool, () => 0);
    expect(picks).toEqual(['F1', 'P1']);
});

test('returns empty array for null/undefined pool', () => {
    expect(pickSuggestionChips(null)).toEqual([]);
    expect(pickSuggestionChips(undefined)).toEqual([]);
});

test('returns empty array when every bucket is empty', () => {
    expect(pickSuggestionChips({ a: [], b: [] }, () => 0)).toEqual([]);
});

test('defaults to Math.random when no rng is supplied', () => {
    // Repeatedly invoking with no rng must still return one entry per
    // populated category and every entry must come from its bucket.
    for (let i = 0; i < 25; i++) {
        const picks = pickSuggestionChips(POOL);
        expect(picks).toHaveLength(4);
        expect(POOL.file).toContain(picks[0]);
        expect(POOL.db).toContain(picks[1]);
        expect(POOL.plugin).toContain(picks[2]);
        expect(POOL.php).toContain(picks[3]);
    }
});
