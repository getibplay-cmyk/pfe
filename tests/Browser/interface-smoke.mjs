// Browser regression checks against the real Blade components and production
// assets, with synthetic presentation data. Business integration stays in the
// PostgreSQL PHPUnit suite. Uses the runner's Chrome, no extra npm dependency.
import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const output = path.resolve(process.env.UI_EVIDENCE_DIR ?? path.join(root, 'storage/framework/testing/ui-evidence'));
await fs.mkdir(output, { recursive: true });
const fixtures = path.join(output, 'fixtures');
const render = spawnSync(process.env.PHP_BINARY || 'php', ['tests/Browser/render-ui-fixtures.php', fixtures], { cwd: root, encoding: 'utf8' });
assert.equal(render.status, 0, render.stdout + render.stderr);
const pages = ['dashboard', 'dashboard-ar', 'login', 'vehicle', 'components', 'components-ar', 'error'];
const server = http.createServer(async (req, res) => {
    try {
        const url = new URL(req.url, 'http://127.0.0.1:8765');
        if (req.method === 'POST') {
            let data = '';
            for await (const chunk of req) data += chunk;
            assert.equal(url.pathname, '/fixture-submit');
            const form = new URLSearchParams(data);
            assert.equal(form.get('record'), 'fixture');
            assert.equal(form.get('action'), 'archive');
            assert.ok(form.get('_token'));
            res.setHeader('Content-Type', 'text/html; charset=utf-8');
            res.end('<h1>Action de démonstration confirmée</h1>');
            return;
        }
        if (url.pathname === '/sample.svg') {
            res.setHeader('Content-Type', 'image/svg+xml');
            res.end('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="360"><rect width="600" height="360" fill="#eff6ff"/><path d="M100 235v-45l60-65h220l70 65h40v45Z" fill="#1d4ed8"/><circle cx="175" cy="240" r="32" fill="#111827"/><circle cx="410" cy="240" r="32" fill="#111827"/></svg>');
            return;
        }
        const name = url.pathname.slice(1);
        let content;
        if (pages.includes(name)) {
            content = await fs.readFile(path.join(fixtures, `${name}.html`));
            res.setHeader('Content-Type', 'text/html; charset=utf-8');
        } else if (/^\/build\/assets\/[a-zA-Z0-9_.-]+\.(css|js)$/.test(url.pathname)) {
            content = await fs.readFile(path.join(root, 'public', url.pathname));
            res.setHeader('Content-Type', url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript');
        } else {
            res.writeHead(404).end();
            return;
        }
        res.setHeader('Cache-Control', 'no-store');
        res.end(content);
    } catch (error) {
        res.writeHead(500).end(String(error));
    }
});
await new Promise(resolve => server.listen(8765, '127.0.0.1', resolve));
const profile = await fs.mkdtemp(path.join(os.tmpdir(), 'sanad-ui-chrome-'));
const chrome = spawn(process.env.CHROME_PATH || 'google-chrome', [
    '--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--no-first-run',
    '--remote-debugging-port=0', '--remote-debugging-address=127.0.0.1',
    `--user-data-dir=${profile}`, 'about:blank',
], { stdio: ['ignore', 'ignore', 'pipe'] });
let chromeError = '';
chrome.on('error', error => { chromeError = error.message; });
chrome.stderr.on('data', chunk => { chromeError = (chromeError + chunk).slice(-3000); });
let socket;
const checks = [];
try {
    let port;
    for (let i = 0; i < 100; i++) {
        try { port = (await fs.readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]; break; } catch {}
        if (chrome.exitCode !== null) throw new Error(chromeError);
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    assert.ok(port, `Chrome unavailable: ${chromeError}`);
    const targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
    socket = new WebSocket(targets.find(target => target.type === 'page').webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    let sequence = 0;
    const pending = new Map();
    let errors = [];
    socket.onmessage = ({ data }) => {
        const message = JSON.parse(data);
        if (message.id) {
            const callback = pending.get(message.id);
            pending.delete(message.id);
            if (message.error) callback?.reject(new Error(JSON.stringify(message.error)));
            else callback?.resolve(message.result);
        }
        if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description ?? message.params.exceptionDetails.text);
    };
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++sequence;
        const timeout = setTimeout(() => { pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, 15000);
        pending.set(id, { resolve: value => { clearTimeout(timeout); resolve(value); }, reject: error => { clearTimeout(timeout); reject(error); } });
        socket.send(JSON.stringify({ id, method, params }));
    });
    const evaluate = async expression => {
        const result = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
        assert.ok(!result.exceptionDetails, JSON.stringify(result.exceptionDetails));
        return result.result.value;
    };
    const waitFor = async expression => {
        for (let i = 0; i < 100; i++) {
            if (await evaluate(expression)) return;
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        throw new Error(`UI timeout: ${expression}`);
    };
    const go = async name => {
        errors = [];
        await send('Page.navigate', { url: `http://127.0.0.1:8765/${name}` });
        await waitFor(`location.pathname === '/${name}' && document.readyState === 'complete'`);
        if (name !== 'error') await waitFor("!!window.Alpine && !document.querySelector('[x-cloak]')");
        assert.deepEqual(errors, [], `JavaScript errors on ${name}`);
    };
    const capture = async name => {
        const { data } = await send('Page.captureScreenshot', { format: 'png' });
        await fs.writeFile(path.join(output, `${name}.png`), Buffer.from(data, 'base64'));
    };
    const viewport = width => send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');
    await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
    for (const width of [320, 390, 768, 1024, 1440]) {
        await viewport(width);
        for (const name of pages) {
            await go(name);
            const state = await evaluate(`(() => {
                const box = document.querySelector('.rf-action-card-header');
                const mark = document.querySelector('[data-brand-mark] path');
                return {
                    overflow: document.documentElement.scrollWidth > innerWidth + 1,
                    headings: document.querySelectorAll('h1').length,
                    alert: [...document.querySelectorAll('[role="alertdialog"]')].some(e => e.getClientRects().length > 0),
                    cardPadding: box ? parseFloat(getComputedStyle(box).paddingLeft) : null,
                    markStroke: mark ? getComputedStyle(mark).stroke : null,
                    colors: getComputedStyle(document.body).backgroundColor,
                };
            })()`);
            assert.equal(state.overflow, false, `Horizontal page overflow: ${name} ${width}`);
            assert.equal(state.headings, 1, `Main heading: ${name}`);
            assert.equal(state.alert, false, `Spontaneous confirmation: ${name}`);
            if (state.cardPadding !== null) assert.ok(state.cardPadding >= 16, `Missing card styles: ${name}`);
            assert.notEqual(state.markStroke, 'none', `Missing logo: ${name}`);
            checks.push({ page: name, width, ...state });
            if ([390, 1440].includes(width)) await capture(`${name}-${width}`);
        }
    }
    await viewport(320);
    await go('dashboard');
    await evaluate('document.querySelector("[x-ref=notificationButton]").click()');
    await waitFor('document.querySelector("#apercu-notifications").getClientRects().length > 0');
    assert.ok(await evaluate('(() => { const r = document.querySelector("#apercu-notifications").getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; })()'), 'Notifications must stay inside the mobile viewport');
    await capture('notifications-320');
    await go('components');
    await evaluate('document.querySelector("form[x-sanad-pilot-confirm] button").click()');
    await waitFor('document.querySelector("#sanad-pilot-confirm-dialog").open');
    assert.equal(await evaluate('document.activeElement.textContent.trim()'), 'Annuler');
    assert.ok(await evaluate('document.querySelector("#sanad-pilot-confirm-dialog").matches(":modal")'));
    assert.equal(await evaluate('document.querySelector("#sanad-pilot-confirm-dialog-title").textContent.trim()'), 'Archiver le document');
    await capture('confirmation-320');
    await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await waitFor('!document.querySelector("#sanad-pilot-confirm-dialog").open');
    assert.equal(await evaluate('document.activeElement.textContent.trim()'), 'Archiver le document');
    await evaluate('document.querySelector("form[x-sanad-pilot-confirm] button").click()');
    await waitFor('document.querySelector("#sanad-pilot-confirm-dialog").open');
    await evaluate('document.querySelector("#sanad-pilot-confirm-dialog [x-ref=cancel]").click()');
    await waitFor('!document.querySelector("#sanad-pilot-confirm-dialog").open');
    assert.equal(await evaluate('location.pathname'), '/components');
    await evaluate('document.querySelector("form[x-sanad-pilot-confirm] button").click()');
    await waitFor('document.querySelector("#sanad-pilot-confirm-dialog").open');
    await evaluate('document.querySelector("#sanad-pilot-confirm-dialog .rf-button-danger").click()');
    await waitFor('document.querySelector("h1")?.textContent === "Action de démonstration confirmée"');
    await go('components');
    await evaluate('document.querySelector("button[aria-label^=\"Agrandir\"]").click()');
    await waitFor('document.querySelector("[aria-label=\"Fermer l’aperçu\"]")?.getClientRects().length > 0');
    assert.deepEqual(errors, [], 'Photo gallery errors');
    await capture('photo-gallery-320');
    // Missing JavaScript must never expose a blank confirmation at page load.
    await send('Network.setBlockedURLs', { urls: ['*.js'] });
    await send('Page.navigate', { url: 'http://127.0.0.1:8765/dashboard' });
    await waitFor("location.pathname === '/dashboard' && document.readyState === 'complete'");
    assert.equal(await evaluate('document.querySelector("[role=alertdialog]").getClientRects().length'), 0);
    await capture('dashboard-without-js-320');
    await fs.writeFile(path.join(output, 'results.json'), JSON.stringify({ checks, interactions: ['notifications-mobile', 'confirmation-closed-on-load', 'confirmation-cancel-escape-focus', 'confirmation-original-post', 'photo-gallery', 'missing-javascript-closed-dialog'] }, null, 2));
    console.log(`UI browser checks passed: ${checks.length} page/viewport combinations and 6 interaction scenarios.`);
} finally {
    socket?.close();
    chrome.kill('SIGTERM');
    server.close();
    // Only a freshly created, dedicated temporary browser profile is removed.
    await new Promise(resolve => chrome.exitCode !== null ? resolve() : chrome.once('exit', resolve));
    await fs.rm(profile, { recursive: true, force: true });
}
