'use strict';

/**
 * ESLint config for Haydi.
 *
 * Targets the WordPress JavaScript Coding Standards:
 * https://developer.wordpress.org/coding-standards/wordpress-coding-standards/javascript/
 *
 * Two environments:
 *  - assets/   browser + jQuery admin scripts
 *  - tests/    Node.js test scripts (Playwright)
 */
module.exports = [
    // Vendored third-party files — not linted.
    { ignores: [ 'assets/marked.min.js' ] },

    // ----- Admin / browser scripts -----
    {
        files: [ 'assets/**/*.js' ],
        languageOptions: {
            ecmaVersion: 2020,
            sourceType:  'script',
            globals: {
                window:    'readonly',
                document:  'readonly',
                navigator: 'readonly',
                jQuery:    'readonly',
                $:         'readonly',
                wp:                  'readonly',
                haydi:         'readonly',
                haydiCommands: 'readonly',
                alert:    'readonly',
                confirm:  'readonly',
                console:  'readonly',
                URLSearchParams: 'readonly',
                Blob:       'readonly',
                URL:        'readonly',
                FileReader: 'readonly',
                marked:     'readonly',
            },
        },
        rules: {
            // Correctness
            'eqeqeq':          [ 'error', 'always' ],
            'no-eval':         'error',
            'no-implied-eval': 'error',
            'no-unused-vars':  [ 'warn', { vars: 'all', args: 'none', caughtErrorsIgnorePattern: '^_' } ],
            'no-undef':        'error',
            'no-console':      'warn',

            // Style (WordPress JS Coding Standards)
            'quotes': [ 'error', 'single', { avoidEscape: true } ],
            'semi':   [ 'error', 'always' ],
            'yoda':   [ 'error', 'never' ],
        },
    },

    // ----- Test / Node scripts (Playwright) -----
    {
        files: [ 'tests/**/*.js', 'playwright.config.js', 'eslint.config.js' ],
        ignores: [ 'tests/unit/js/**' ],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType:  'commonjs',
            globals: {
                require:  'readonly',
                module:   'writable',
                exports:  'writable',
                process:  'readonly',
                __dirname: 'readonly',
                console:  'readonly',
                Promise:  'readonly',
                URL:      'readonly',
                URLSearchParams: 'readonly',
            },
        },
        rules: {
            'eqeqeq':         [ 'error', 'always' ],
            'no-eval':        'error',
            'no-unused-vars': [ 'warn', { vars: 'all', args: 'none' } ],
            'no-undef':       'error',
            'quotes':         [ 'error', 'single', { avoidEscape: true } ],
            'semi':           [ 'error', 'always' ],
        },
    },

    // ----- Jest unit tests -----
    {
        files: [ 'tests/unit/js/**/*.test.js' ],
        languageOptions: {
            ecmaVersion: 2022,
            sourceType:  'script',
            globals: {
                // Jest globals
                test:        'readonly',
                expect:      'readonly',
                describe:    'readonly',
                it:          'readonly',
                beforeAll:   'readonly',
                afterAll:    'readonly',
                beforeEach:  'readonly',
                afterEach:   'readonly',
                // Node
                require:     'readonly',
                module:      'writable',
                console:     'readonly',
            },
        },
        rules: {
            'eqeqeq':         [ 'error', 'always' ],
            'no-eval':        'error',
            'no-unused-vars': [ 'warn', { vars: 'all', args: 'none' } ],
            'no-undef':       'error',
            'quotes':         [ 'error', 'single', { avoidEscape: true } ],
            'semi':           [ 'error', 'always' ],
        },
    },
];
