// Shared helpers for the Dot.Press end to end tests.
// Needs a running app (E2E_BASE, default http://127.0.0.1:8850) seeded by PressDemoSeeder, and the sqlite file it uses (E2E_DB).
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import zlib from 'node:zlib';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8850';
export const APP = process.env.E2E_APP || path.resolve(HERE, '../../..');
export const DB = process.env.E2E_DB || `/tmp/press-demo-${path.basename(APP)}.sqlite`;
export const SHOTS = process.env.E2E_SHOTS || path.join(os.tmpdir(), 'press-e2e-shots');
fs.mkdirSync(SHOTS, { recursive: true });
const req = createRequire(path.join(HERE, 'noop.js'));

export async function launch() {
    const mod = await import(req.resolve('playwright-core'));
    const chromium = mod.chromium || mod.default.chromium;
    const args = ['--no-first-run', '--disable-features=Translate'];
    const exes = [
        process.env.CHROME_BIN,
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        path.join(os.homedir(), 'Library/Caches/ms-playwright/chromium-1148/chrome-mac/Chromium.app/Contents/MacOS/Chromium'),
        '/usr/bin/google-chrome', '/usr/bin/chromium',
    ].filter(Boolean);
    for (const exe of exes) {
        if (fs.existsSync(exe)) { try { return await chromium.launch({ executablePath: exe, args }); } catch (e) { /* next */ } }
    }
    return chromium.launch({ channel: 'chrome', args });
}

/** Runs a small PHP snippet inside the app with the test database. */
export function sqlite(sql) {
    return execFileSync('sqlite3', ['-json', DB, sql], { encoding: 'utf8' }).trim();
}
export function rows(sql) { const out = sqlite(sql); return out ? JSON.parse(out) : []; }

export async function login(page, email = 'owner@demo.test', password = 'password') {
    await page.goto(BASE + '/login');
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', password);
    await Promise.all([page.waitForURL((u) => !/\/login/.test(u.pathname)), page.click('button[type=submit]')]);
}

export async function newContext(browser, email, opts = {}) {
    // Sign-in is throttled (5 a minute), so a signed in session is kept on disk per account and reused until it stops working.
    const stateFile = path.join(SHOTS, `state-${email.replace(/[^a-z0-9]/gi, '_')}.json`);
    const make = async (state) => {
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ...opts, ...(state ? { storageState: state } : {}) });
        const page = await ctx.newPage();
        page.setDefaultTimeout(+(process.env.E2E_TIMEOUT || 8000));
        const errors = [];
        page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
        page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
        page.errors = errors;
        return { ctx, page };
    };
    if (fs.existsSync(stateFile) && Date.now() - fs.statSync(stateFile).mtimeMs < 50 * 60 * 1000) {
        const c = await make(stateFile);
        await c.page.goto(BASE + '/dashboard');
        if (!/\/login/.test(c.page.url())) return c;
        await c.ctx.close();
    }
    const c = await make(null);
    await login(c.page, email);
    await c.ctx.storageState({ path: stateFile });
    return c;
}

/** Tiny PNG writer (no dependencies): a w x h gradient with a visible border, so crops and masks can be seen. */
export function makePng(file, w = 640, h = 400) {
    const crc = (buf) => { let c, crcv = 0xffffffff; for (let n = 0; n < buf.length; n++) { c = (crcv ^ buf[n]) & 0xff; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; crcv = (crcv >>> 8) ^ c; } return (crcv ^ 0xffffffff) >>> 0; };
    const chunk = (type, data) => { const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const td = Buffer.concat([Buffer.from(type), data]); const cr = Buffer.alloc(4); cr.writeUInt32BE(crc(td)); return Buffer.concat([len, td, cr]); };
    const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
    const raw = Buffer.alloc((w * 3 + 1) * h);
    for (let y = 0; y < h; y++) {
        raw[y * (w * 3 + 1)] = 0;
        for (let x = 0; x < w; x++) {
            const o = y * (w * 3 + 1) + 1 + x * 3;
            const border = x < 6 || y < 6 || x >= w - 6 || y >= h - 6;
            raw[o] = border ? 255 : Math.round((x / w) * 255);
            raw[o + 1] = border ? 255 : Math.round((y / h) * 200);
            raw[o + 2] = border ? 255 : 160;
        }
    }
    fs.writeFileSync(file, Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]));
    return file;
}

// A tiny test runner: each step is recorded with PASS or FAIL and the run continues, so one broken check never hides the rest.
export function suite(name) {
    const results = [];
    const api = {
        results,
        async step(id, title, fn) {
            const t = Date.now();
            try { await fn(); if (api.page) { const sx = await api.page.evaluate(() => document.querySelector('.ed')?.scrollLeft || 0).catch(() => 0); if (sx) throw new Error('the editor shell was scrolled sideways by ' + sx + 'px'); } results.push({ id, title, ok: true }); console.log(`PASS  ${id}  ${title}  (${Date.now() - t} ms)`); }
            catch (e) { if (api.page) await shot(api.page, `${name}-FAIL-${id}`).catch(() => {}); results.push({ id, title, ok: false, err: e.message.split('\n')[0] }); console.log(`FAIL  ${id}  ${title}\n      ${e.message.split('\n').slice(0, 3).join('\n      ')}`); }
        },
        summary() {
            const bad = results.filter((r) => !r.ok);
            console.log(`\n${name}: ${results.length - bad.length}/${results.length} steps passed`);
            fs.writeFileSync(path.join(SHOTS, `${name}.json`), JSON.stringify(results, null, 1));
            return bad.length;
        },
    };
    return api;
}
export function ok(cond, msg) { if (!cond) throw new Error(msg || 'assertion failed'); }
export function eq(a, b, msg) { if (JSON.stringify(a) !== JSON.stringify(b)) throw new Error(`${msg || 'not equal'}: got ${JSON.stringify(a)} expected ${JSON.stringify(b)}`); }
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
export async function shot(page, name) { const f = path.join(SHOTS, name + '.png'); await page.screenshot({ path: f }); return f; }

/** The saved document of a deck, read through the API as the signed in user. */
export async function savedDoc(page, id) {
    return page.evaluate(async (deckId) => {
        const r = await fetch(`/api/decks/${deckId}/document`, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        return r.json();
    }, id);
}
export async function apiGet(page, url) {
    return page.evaluate(async (u) => { const r = await fetch(u, { credentials: 'same-origin', headers: { Accept: 'application/json' } }); return { status: r.status, body: await r.json().catch(() => null) }; }, url);
}
export async function openDeck(page, id, qs = '') {
    await page.goto(`${BASE}/decks/${id}${qs}`);
    await page.waitForSelector('.es-viewport');
    await page.waitForFunction(() => document.fonts?.status !== 'loading');
}
/** Waits until the save bead says Saved and the server has everything (pending is empty). */
export async function saved(page, timeout = 15000) {
    await page.waitForFunction(() => document.querySelector('.tb-save')?.classList.contains('is-saved'), null, { timeout });
}
export const slideEls = (d, i = 0) => d.slides[i].els;
/** Screen centre of an element of the current slide, from its rendered node. */
export async function elBox(page, id) {
    const b = await page.locator(`.es-sheet-in [data-eid="${id}"]`).boundingBox();
    if (!b) throw new Error('element not on screen: ' + id);
    return b;
}
export const MOD = process.platform === 'darwin' ? 'Meta' : 'Control';

export async function center(page, id, at = [0.5, 0.5]) {
    const b = await elBox(page, id);
    return { x: b.x + b.width * at[0], y: b.y + b.height * at[1], b };
}
/** A real click on an element of the stage (the stage owns pointer events, so the pointer goes to its screen position). */
export async function clickEl(page, id, opts = {}) {
    const c = await center(page, id, opts.at);
    const mods = opts.modifiers || [];
    for (const m of mods) await page.keyboard.down(m);
    await page.mouse.click(c.x, c.y, { button: opts.button, clickCount: opts.clickCount });
    for (const m of mods.slice().reverse()) await page.keyboard.up(m);
    return c;
}
/** Drag an element by (dx, dy) screen pixels with real pointer events. */
export async function dragEl(page, id, dx, dy, { steps = 10, from = null, at } = {}) {
    const c = from || await center(page, id, at);
    await page.mouse.move(c.x, c.y);
    await page.mouse.down();
    await page.mouse.move(c.x + dx / 2, c.y + dy / 2, { steps });
    await page.mouse.move(c.x + dx, c.y + dy, { steps });
    await page.mouse.up();
}

/** POST/PATCH/DELETE JSON as the signed in user (the XSRF cookie Laravel set is echoed back, like axios does). */
export async function apiSend(page, method, url, body) {
    return page.evaluate(async ({ method, url, body }) => {
        const tok = decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '');
        const r = await fetch(url, { method, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': tok, 'X-Requested-With': 'XMLHttpRequest' }, body: body === undefined ? undefined : JSON.stringify(body) });
        return { status: r.status, body: await r.json().catch(() => null) };
    }, { method, url, body });
}
export async function createDeck(page, title, extra = {}) {
    await page.goto(BASE + '/dashboard');
    const r = await apiSend(page, 'POST', '/api/decks', { title, ...extra });
    if (r.status >= 300) throw new Error('create deck failed ' + JSON.stringify(r.body));
    return r.body.id;
}
/** Send ops straight to the API (set up state fast; the assertions still go through the UI). */
export async function sendOps(page, deckId, ops) {
    const r = await apiSend(page, 'POST', `/api/decks/${deckId}/ops`, { client: 'e2e-setup', key: crypto.randomUUID?.() || String(Math.random()), ops });
    if (r.status >= 300) throw new Error('ops failed ' + JSON.stringify(r.body));
    return r.body;
}
