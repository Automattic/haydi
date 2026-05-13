const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir:       './tests',
    testMatch:     '**/*.spec.js',
    timeout:       30_000,
    reporter:      'list',
    use: {
        baseURL: 'http://localhost:9888',
    },
});
