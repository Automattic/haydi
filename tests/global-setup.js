// @ts-check
const { chromium } = require('@playwright/test');
const path = require('path');

const BASE     = 'http://localhost:9888';
const AUTH_FILE = path.join(__dirname, 'playwright-auth.json');

/**
 * Logs in once as admin and saves the session cookies to playwright-auth.json.
 * Each test then calls browser.newContext({ storageState: AUTH_FILE }) so no
 * test has to log in from scratch — avoiding race conditions when multiple
 * Playwright workers hit wp-login.php concurrently for the same account.
 */
module.exports = async () => {
    const browser = await chromium.launch();
    const page    = await browser.newPage();

    await page.goto(`${BASE}/wp-login.php`);
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**');

    await page.context().storageState({ path: AUTH_FILE });
    await browser.close();
};
