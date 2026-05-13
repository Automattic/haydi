#!/usr/bin/env node
/**
 * Open a headed browser, send a prompt to the AI File Assistant, and
 * auto-approve any file-write proposals that come back.
 *
 * Usage:
 *   node tests/run-prompt.js <prompt text>
 *   node tests/run-prompt.js "create a very basic plugin with three files"
 */

const { chromium } = require('@playwright/test');

const BASE   = 'http://localhost:9888';
const PROMPT = process.argv.slice(2).join(' ');

if (!PROMPT) {
	console.error('Usage: node tests/run-prompt.js <prompt text>');
	process.exit(1);
}

(async () => {
	const browser = await chromium.launch({ headless: false, slowMo: 50 });
	const page    = await browser.newPage({ viewport: { width: 1400, height: 900 } });

	// Accept all confirmation dialogs (write-approval confirms).
	page.on('dialog', d => d.accept());

	process.stdout.write('Logging in… ');
	await page.goto(`${BASE}/wp-login.php`);
	await page.fill('#user_login', 'admin');
	await page.fill('#user_pass', 'password');
	await page.click('#wp-submit');
	await page.waitForURL('**/wp-admin/**');
	console.log('OK');

	await page.goto(`${BASE}/wp-admin/tools.php?page=haydi`);
	await page.waitForSelector('#wpc-btn-send');

	console.log(`\nPrompt: "${PROMPT}"\n`);
	await page.fill('#wpc-chat-input', PROMPT);
	await page.click('#wpc-btn-send');

	// Loop: approve every write/delete proposal until none appear.
	let approved = 0;
	const ACTIONS = {
		'wpc-proposal-section': { reasonSel: '#wpc-proposal-reason', label: 'Write',   btn: '#wpc-btn-apply'          },
		'wpc-delete-section':   { reasonSel: '#wpc-delete-reason',   label: 'Delete',  btn: '#wpc-btn-confirm-delete'  },
		'wpc-move-section':     { reasonSel: '#wpc-move-reason',     label: 'Move',    btn: '#wpc-btn-confirm-move'    },
		'wpc-copy-section':     { reasonSel: '#wpc-copy-reason',     label: 'Copy',    btn: '#wpc-btn-confirm-copy'    },
		'wpc-rmdir-section':    { reasonSel: '#wpc-rmdir-reason',    label: 'Rmdir',   btn: '#wpc-btn-confirm-rmdir'   },
	};

	const PENDING = Object.keys(ACTIONS).map(id => `#${id}:not(.wpc-hidden)`).join(', ');

	while (true) {
		const el = await page.waitForSelector(PENDING, { timeout: 90_000 }).catch(() => null);
		if (!el) break;

		const sectionId = await el.evaluate(n => n.id);
		const action    = ACTIONS[sectionId];
		const reason    = (await page.textContent(action.reasonSel) || '').trim();
		approved++;
		console.log(`  [${approved}] ${action.label}: ${reason}`);
		await page.click(action.btn);
		await page.waitForSelector(`#${sectionId}.wpc-hidden`, { timeout: 10_000 }).catch(() => {});
	}

	console.log(`\nDone — ${approved} action(s) approved.`);
	console.log('Browser stays open for inspection. Press Ctrl+C to quit.\n');

	await new Promise(() => {}); // keep open
})();
