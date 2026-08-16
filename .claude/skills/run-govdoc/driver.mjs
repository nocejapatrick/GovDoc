// REPL driver for GovDoc (Laravel + Inertia + Vue, served by Herd at
// http://govdoc.test). Run under a modern Node (see SKILL.md — the
// system default `node` on this machine is v14 and will not even
// parse this file's dependencies correctly).
//
// Designed for agents: run it directly for a scripted sequence (pipe
// commands via stdin), or wrap in tmux with send-keys/capture-pane for
// interactive iteration.
import { chromium } from 'playwright';
import * as readline from 'node:readline';
import * as fs from 'node:fs';
import * as path from 'node:path';

const BASE = process.env.GOVDOC_BASE_URL || 'http://govdoc.test';
const SHOT_DIR = process.env.SCREENSHOT_DIR || '/tmp/govdoc-shots';
fs.mkdirSync(SHOT_DIR, { recursive: true });

let browser = null;
let page = null;
const consoleErrors = [];

const COMMANDS = {
    async launch() {
        if (browser) return console.log('already launched');
        browser = await chromium.launch();
        page = await browser.newPage();
        page.on('console', (msg) => {
            if (msg.type() === 'error') consoleErrors.push(msg.text());
        });
        page.on('pageerror', (err) => consoleErrors.push('pageerror: ' + err.message));
        console.log('launched, base url:', BASE);
    },

    // Logs in as the seeded admin (database/seeders/AdminSeeder.php).
    // For a non-admin session, use `nav /login` + `fill`/`click` directly.
    async login(arg) {
        if (!page) return console.log('ERROR: launch first');
        const [email, password] = (arg || 'admin@gmail.com admin').split(' ');
        await page.goto(`${BASE}/login`, { waitUntil: 'networkidle' });
        await page.fill('#email', email);
        await page.fill('#password', password);
        // Plain sequential click + waitForLoadState('networkidle') is racy here —
        // Inertia's redirect can resolve before networkidle fires. Race the
        // click against an explicit waitForURL instead.
        await Promise.all([
            page.waitForURL(`${BASE}/dashboard`, { timeout: 10_000 }),
            page.click('button[type="submit"]'),
        ]);
        console.log('logged in as', email, '→', page.url());
    },

    async nav(urlPath) {
        if (!page) return console.log('ERROR: launch first');
        await page.goto(`${BASE}${urlPath.startsWith('/') ? '' : '/'}${urlPath}`, { waitUntil: 'networkidle' });
        console.log('at', page.url());
    },

    async ss(name) {
        if (!page) return console.log('ERROR: launch first');
        const f = path.join(SHOT_DIR, (name || `ss-${Date.now()}`) + '.png');
        await page.screenshot({ path: f, fullPage: true });
        console.log('screenshot:', f);
    },

    async click(sel) {
        if (!page) return console.log('ERROR: launch first');
        try {
            await page.click(sel, { timeout: 10_000 });
            console.log('clicked', sel);
        } catch (e) {
            console.log('ERROR:', e.message.split('\n')[0]);
        }
    },

    async 'click-text'(text) {
        if (!page) return console.log('ERROR: launch first');
        const r = await page.evaluate((t) => {
            const els = [...document.querySelectorAll('a, button, [role="button"]')];
            const el = els.find((e) => e.textContent?.trim() === t) ?? els.find((e) => e.textContent?.includes(t));
            if (!el) return 'NOT_FOUND';
            el.click();
            return 'OK: ' + el.tagName;
        }, text);
        console.log('click-text', JSON.stringify(text), '→', r);
    },

    async fill(arg) {
        if (!page) return console.log('ERROR: launch first');
        const [sel, ...rest] = arg.split(' ');
        await page.fill(sel, rest.join(' '));
        console.log('filled', sel);
    },

    async wait(sel) {
        if (!page) return console.log('ERROR: launch first');
        try {
            await page.waitForSelector(sel, { timeout: 10_000 });
            console.log('found:', sel);
        } catch {
            console.log('TIMEOUT:', sel);
        }
    },

    async eval(expr) {
        if (!page) return console.log('ERROR: launch first');
        try {
            console.log(JSON.stringify(await page.evaluate(expr)));
        } catch (e) {
            console.log('ERROR:', e.message);
        }
    },

    async text(sel) {
        if (!page) return console.log('ERROR: launch first');
        console.log(
            await page.evaluate((s) => (s ? document.querySelector(s) : document.body)?.innerText ?? '(null)', sel || null),
        );
    },

    console() {
        console.log(consoleErrors.length ? consoleErrors.join('\n') : '(no console errors so far)');
    },

    async quit() {
        if (browser) await browser.close().catch(() => {});
        browser = null;
        page = null;
    },

    help() {
        console.log('commands:', Object.keys(COMMANDS).join(', '));
    },
};

// (No Electron here, so plain process.stdin is fine — unlike the Electron
// driver pattern this was adapted from, nothing else is competing for stdin.)
const rl = readline.createInterface({ input: process.stdin, output: process.stdout, prompt: 'driver> ' });

// Piped/heredoc input delivers 'line' events faster than each async command
// resolves (e.g. `launch` is still spawning the browser when `login` fires) —
// a bare `rl.on('line', async ...)` does NOT serialize these. Queue them.
let queue = Promise.resolve();
rl.on('line', (line) => {
    queue = queue.then(async () => {
        const [cmd, ...rest] = line.trim().split(/\s+/);
        if (!cmd) return rl.prompt();
        const fn = COMMANDS[cmd];
        if (!fn) {
            console.log('unknown:', cmd, '— try: help');
            return rl.prompt();
        }
        try {
            await fn(rest.join(' '));
        } catch (e) {
            console.log('ERROR:', e.message);
        }
        if (cmd === 'quit') {
            rl.close();
            process.exit(0);
            return;
        }
        rl.prompt();
    });
});
rl.on('close', async () => {
    // Piped/heredoc input hits EOF (→ this 'close' event) as soon as all lines
    // are delivered, which can be before the queued commands above have
    // actually run — await the queue first or a fast pipe kills the browser
    // mid-launch.
    await queue.catch(() => {});
    await COMMANDS.quit();
    process.exit(0);
});

console.log('GovDoc driver — "help" for commands, "launch" to start');
rl.prompt();
