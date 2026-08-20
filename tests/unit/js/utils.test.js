// @ts-check
/**
 * Unit tests for pure utility functions used in assets/admin.js.
 *
 * The functions live inside an IIFE in admin.js so they cannot be imported.
 * They are reproduced verbatim here — any change to the originals must be
 * reflected in the function definitions below.
 *
 * Functions under test: esc, chatTitle, styleVariationRefreshHint,
 * ajaxFailureMessage, approvalTargetText.
 */

'use strict';

// ---------------------------------------------------------------------------
// Functions copied verbatim from assets/admin.js
// ---------------------------------------------------------------------------

function esc(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function ajaxResponseMessage(jqXHR) {
    var data;
    var text;

    if (!jqXHR) { return ''; }

    data = jqXHR.responseJSON && jqXHR.responseJSON.data;
    if (data && data.message) {
        return String(data.message);
    }

    text = jqXHR.responseText;
    if (!text) { return ''; }

    text = String(text)
        .replace(/<script[\s\S]*?<\/script>/gi, ' ')
        .replace(/<style[\s\S]*?<\/style>/gi, ' ')
        .replace(/<[^>]+>/g, ' ')
        .replace(/&nbsp;/g, ' ')
        .replace(/&#039;/g, '\'')
        .replace(/&quot;/g, '"')
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>')
        .replace(/&amp;/g, '&')
        .replace(/\s+/g, ' ')
        .trim();

    if (text.length > 240) {
        text = text.substring(0, 237) + '...';
    }

    return text;
}

function ajaxFailureMessage(jqXHR, fallback) {
    var details = [];
    var responseMessage;

    if (jqXHR && jqXHR.status) {
        details.push('HTTP ' + jqXHR.status);
    }

    responseMessage = ajaxResponseMessage(jqXHR);
    if (responseMessage) {
        details.push(responseMessage);
    }

    if (!details.length) {
        return fallback;
    }

    return fallback + ' (' + details.join(': ') + ')';
}

// fallbackChatTitle() reads from the closed-over `state.messages` in admin.js.
// Adapted here to accept messages as a parameter to make it pure/testable.
function fallbackChatTitle(messages) {
    var title = 'Chat';
    for (var i = 0; i < messages.length; i++) {
        if (messages[i].role === 'user') {
            var c = messages[i].content;
            if (typeof c === 'string' && c) { title = c; break; }
        }
    }
    return title.length > 60 ? title.substring(0, 57) + '\u2026' : title;
}

function chatTitle(messages, savedTitle) {
    return savedTitle || fallbackChatTitle(messages);
}

function approvalTargetText(args, presentation) {
    if (Array.isArray(presentation.targetFields)) {
        return presentation.targetFields.map(function (target) {
            var value = args[target.field];
            return value === undefined || value === null || value === ''
                ? ''
                : target.label + ': ' + String(value);
        }).filter(Boolean).join('\n');
    }

    return presentation.targetField ? String(args[presentation.targetField] || '') : '';
}

function isThemeStyleVariationPath(path) {
    return /\/wp-content\/themes\/[^/]+\/styles\/[^/]+\.json$/i.test(String(path || ''));
}

function styleVariationRefreshHint(path) {
    return isThemeStyleVariationPath(path)
        ? ' Refresh the Site Editor to see the new style variation.'
        : '';
}

function humanizeIdentifier(str) {
    return String(str || '')
        .replace(/[-_]+/g, ' ')
        .replace(/\b\w/g, function (ch) { return ch.toUpperCase(); });
}

function getModelChoiceGroups(haydiConfig) {
    var choices = haydiConfig.modelChoices || {};
    var groups = [];
    Object.keys(choices).forEach(function (providerId) {
        var provider = choices[providerId] || {};
        var models = Array.isArray(provider.models) ? provider.models : [];
        if (!models.length) { return; }
        groups.push({
            id:     provider.id || providerId,
            name:   provider.name || humanizeIdentifier(providerId),
            models: models,
        });
    });

    if (groups.length) { return groups; }

    var limits = haydiConfig.modelLimits || {};
    Object.keys(limits).forEach(function (providerId) {
        var provider = limits[providerId] || {};
        var models = provider.models || {};
        var modelIds = Object.keys(models);
        if (!modelIds.length) { return; }
        groups.push({
            id:     provider.provider || providerId,
            name:   provider.name || humanizeIdentifier(provider.provider || providerId),
            models: modelIds.map(function (modelId) {
                return {
                    id:   modelId,
                    name: (models[modelId] && models[modelId].name) || modelId,
                };
            }),
        });
    });

    return groups;
}

function findModelChoice(haydiConfig, providerId, modelId) {
    var groups = getModelChoiceGroups(haydiConfig);
    for (var i = 0; i < groups.length; i++) {
        if (groups[i].id !== providerId) { continue; }
        for (var j = 0; j < groups[i].models.length; j++) {
            if (groups[i].models[j].id === modelId) {
                return {
                    provider:      providerId,
                    providerLabel: groups[i].name,
                    model:         modelId,
                    label:         groups[i].models[j].name || modelId,
                };
            }
        }
    }
    return null;
}

function defaultModelChoice(haydiConfig) {
    var groups = getModelChoiceGroups(haydiConfig);
    if (!groups.length || !groups[0].models.length) { return null; }
    return findModelChoice(haydiConfig, groups[0].id, groups[0].models[0].id);
}

function restrictedModelChoice(haydiConfig) {
    var policy = haydiConfig.modelPolicy || {};
    if (!policy.restricted) { return null; }
    return findModelChoice(haydiConfig, String(policy.provider || ''), String(policy.model || ''));
}

// ---------------------------------------------------------------------------
// esc() — XSS-critical HTML escaping
// ---------------------------------------------------------------------------

test('esc: passes through plain text unchanged', () => {
    expect(esc('hello world')).toBe('hello world');
});

test('esc: escapes ampersands', () => {
    expect(esc('a & b')).toBe('a &amp; b');
});

test('esc: escapes less-than and greater-than', () => {
    expect(esc('<script>')).toBe('&lt;script&gt;');
});

test('esc: escapes double quotes', () => {
    expect(esc('"value"')).toBe('&quot;value&quot;');
});

test('esc: escapes all special characters in one pass', () => {
    expect(esc('<a href="x">link & text</a>'))
        .toBe('&lt;a href=&quot;x&quot;&gt;link &amp; text&lt;/a&gt;');
});

test('esc: coerces non-string inputs to string before escaping', () => {
    expect(esc(42)).toBe('42');
    expect(esc(null)).toBe('null');
    expect(esc(true)).toBe('true');
});

test('esc: returns empty string for empty input', () => {
    expect(esc('')).toBe('');
});

// ---------------------------------------------------------------------------
// ajaxFailureMessage()
// ---------------------------------------------------------------------------

test('ajaxFailureMessage: includes JSON error message from failed AJAX response', () => {
    expect(ajaxFailureMessage({
        status:       403,
        responseJSON: {
            data: {
                message: 'Nonce check failed.',
            },
        },
    }, 'Could not prepare Playground preflight.'))
        .toBe('Could not prepare Playground preflight. (HTTP 403: Nonce check failed.)');
});

test('ajaxFailureMessage: strips HTML response text and includes HTTP status', () => {
    expect(ajaxFailureMessage({
        status:       500,
        responseText: '<br><b>Parse error</b>: Invalid body indentation level in <b>/path/file.php</b> on line <b>788</b>',
    }, 'Could not prepare Playground preflight.'))
        .toBe('Could not prepare Playground preflight. (HTTP 500: Parse error : Invalid body indentation level in /path/file.php on line 788)');
});

test('ajaxFailureMessage: returns fallback when no AJAX details are available', () => {
    expect(ajaxFailureMessage(null, 'Could not prepare Playground preflight.'))
        .toBe('Could not prepare Playground preflight.');
});

// ---------------------------------------------------------------------------
// chatTitle()
// ---------------------------------------------------------------------------

test('chatTitle: returns default when messages list is empty', () => {
    expect(chatTitle([])).toBe('Chat');
});

test('chatTitle: returns default when no user message exists', () => {
    const messages = [{ role: 'assistant', content: 'Hi there!' }];
    expect(chatTitle(messages)).toBe('Chat');
});

test('chatTitle: uses first user message string as title', () => {
    const messages = [
        { role: 'user', content: 'Refactor the login page' },
        { role: 'assistant', content: 'Sure!' },
    ];
    expect(chatTitle(messages)).toBe('Refactor the login page');
});

test('chatTitle: prefers a saved generated title', () => {
    const messages = [
        { role: 'user', content: 'Can you create a form plugin with a shortcode and admin settings?' },
    ];
    expect(chatTitle(messages, 'Form Plugin Settings')).toBe('Form Plugin Settings');
});

test('chatTitle: skips non-string user content', () => {
    const messages = [
        { role: 'user', content: [{ type: 'text', text: 'tool result' }] }, // array, not string
        { role: 'user', content: 'Second user message' },
    ];
    expect(chatTitle(messages)).toBe('Second user message');
});

test('chatTitle: truncates titles longer than 60 characters to 57 + ellipsis', () => {
    const long = 'A'.repeat(61);
    const result = chatTitle([{ role: 'user', content: long }]);
    expect(result).toHaveLength(58); // 57 chars + '…' (1 char)
    expect(result.endsWith('\u2026')).toBe(true);
});

test('chatTitle: does not truncate titles of exactly 60 characters', () => {
    const exact = 'B'.repeat(60);
    const result = chatTitle([{ role: 'user', content: exact }]);
    expect(result).toBe(exact);
});

test('styleVariationRefreshHint: prompts for Site Editor refresh after theme style variation writes', () => {
    expect(styleVariationRefreshHint('/var/www/html/wp-content/themes/twentytwentyfive/styles/noir.json'))
        .toBe(' Refresh the Site Editor to see the new style variation.');
});

test('styleVariationRefreshHint: ignores non-style-variation files', () => {
    expect(styleVariationRefreshHint('/var/www/html/wp-content/themes/twentytwentyfive/theme.json')).toBe('');
    expect(styleVariationRefreshHint('/var/www/html/wp-content/plugins/example/styles/noir.json')).toBe('');
});

// ---------------------------------------------------------------------------
// Approval target presentation
// ---------------------------------------------------------------------------

test('approvalTargetText: renders the exact authoritative single target', () => {
    expect(approvalTargetText(
        { path: '/var/www/html/wp-content/plugins/example/main.php' },
        { targetFields: [{ label: 'Target', field: 'path' }] }
    )).toBe('Target: /var/www/html/wp-content/plugins/example/main.php');
});

test('approvalTargetText: labels both source and destination paths', () => {
    expect(approvalTargetText(
        {
            src:  '/var/www/html/wp-content/plugins/example/source.php',
            dest: '/var/www/html/wp-content/plugins/example/destination.php',
        },
        {
            targetFields: [
                { label: 'Source', field: 'src' },
                { label: 'Destination', field: 'dest' },
            ],
        }
    )).toBe(
        'Source: /var/www/html/wp-content/plugins/example/source.php\n'
        + 'Destination: /var/www/html/wp-content/plugins/example/destination.php'
    );
});

test('approvalTargetText: preserves legacy single-field targets such as fetch URLs', () => {
    expect(approvalTargetText(
        { url: 'https://example.test/reference?item=42' },
        { targetField: 'url' }
    )).toBe('https://example.test/reference?item=42');
});

test('approvalTargetText: omits missing target fields instead of showing placeholders', () => {
    expect(approvalTargetText(
        { original_path: '/var/www/html/wp-content/themes/example/functions.php' },
        {
            targetFields: [
                { label: 'Backup', field: 'backup_file' },
                { label: 'Restore to', field: 'original_path' },
            ],
        }
    )).toBe('Restore to: /var/www/html/wp-content/themes/example/functions.php');
});

// ---------------------------------------------------------------------------
// Model picker
// ---------------------------------------------------------------------------

test('defaultModelChoice: picks the first configured model instead of returning null', () => {
    const config = {
        modelChoices: {
            anthropic: {
                id:     'anthropic',
                name:   'Anthropic',
                models: [
                    { id: 'claude-opus-4-7', name: 'Claude Opus 4.7' },
                    { id: 'claude-sonnet-4-6', name: 'Claude Sonnet 4.6' },
                ],
            },
        },
        modelLimits: {},
    };

    expect(defaultModelChoice(config)).toEqual({
        provider:      'anthropic',
        providerLabel: 'Anthropic',
        model:         'claude-opus-4-7',
        label:         'Claude Opus 4.7',
    });
});

test('defaultModelChoice: returns null only when there are no configured models', () => {
    expect(defaultModelChoice({ modelChoices: {}, modelLimits: {} })).toBeNull();
});

test('restrictedModelChoice: returns the administrator-selected model for an Editor', () => {
    const config = {
        modelChoices: {
            anthropic: {
                id:     'anthropic',
                name:   'Anthropic',
                models: [
                    { id: 'claude-opus-5', name: 'Claude Opus 5' },
                    { id: 'claude-sonnet-4-6', name: 'Claude Sonnet 4.6' },
                ],
            },
        },
        modelLimits: {},
        modelPolicy: {
            restricted: true,
            provider:   'anthropic',
            model:      'claude-sonnet-4-6',
        },
    };

    expect(restrictedModelChoice(config)).toEqual({
        provider:      'anthropic',
        providerLabel: 'Anthropic',
        model:         'claude-sonnet-4-6',
        label:         'Claude Sonnet 4.6',
    });
});

test('restrictedModelChoice: returns null when the locked model is unavailable', () => {
    expect(restrictedModelChoice({
        modelChoices: {},
        modelLimits:  {},
        modelPolicy:  {
            restricted: true,
            provider:   'anthropic',
            model:      'claude-sonnet-4-6',
        },
    })).toBeNull();
});
