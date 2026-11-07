// Shared helpers for the Dot.Press acceptance scripts (checks 26 to 42).
// Needs a running app: E2E_BASE (default http://127.0.0.1:8851), E2E_DB (the sqlite file the app uses), E2E_APP (repo root).
// The scripts drive the real UI in Chrome through playwright-core and assert on the DOM, the network and the database.
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { chromium, devices } from 'playwright-core';

export { devices };
export const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8851';
export const APP = process.env.E2E_APP || path.resolve(path.dirname(new URL(import.meta.url).pathname), '../../..');
export const DB = process.env.E2E_DB || '/tmp/press-demo-pw-e2eb.sqlite';
export const OUT = process.env.E2E_OUT || '/tmp/press-b-out';
export const FIX = path.join(OUT, 'fixtures');
export const PY = process.env.PRESS_PY || '/tmp/press-venv/bin/python';
export const FFMPEG = process.env.FFMPEG || '/Users/sakhilebhayi/.homebrew/bin/ffmpeg';
fs.mkdirSync(FIX, { recursive: true });

export async function launch(extra = {}) {
    const args = ['--autoplay-policy=no-user-gesture-required', '--use-fake-ui-for-media-stream'];
    const opts = { args, ...extra };
    const exe = process.env.CHROME_PATH;
    if (exe) return chromium.launch({ ...opts, executablePath: exe });
    return chromium.launch({ ...opts, channel: 'chrome' });
}

/** A new browser context with console and page error capture. Returns {ctx, errors}. */
export async function context(browser, opts = {}) {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, baseURL: BASE, ...opts });
    const errors = [];
    ctx.on('page', (p) => watch(p, errors));
    ctx._errors = errors;
    return ctx;
}
export function watch(p, errors) {
    p.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
    p.on('console', (m) => {
        if (m.type() === 'error') {
            const t = m.text();
            if (/Failed to load resource.*(404|403|401|419)/.test(t) && p.__expectFail) return;
            errors.push('console: ' + t);
        }
    });
}

/** Run PHP against the app database. The code runs inside artisan tinker, so models and services are available. */
export function php(code) {
    const f = path.join(OUT, 'snippet-' + process.pid + '-' + Math.random().toString(36).slice(2) + '.php');
    fs.writeFileSync(f, '<?php\n' + code + '\n');
    try {
        const out = execFileSync('php', ['artisan', 'tinker', '--execute', `require '${f}';`], { cwd: APP, env: { ...process.env, DB_DATABASE: DB, APP_ENV: 'local' }, encoding: 'utf8', maxBuffer: 1 << 26 });
        return out.trim();
    } finally { fs.rmSync(f, { force: true }); }
}
/** Run PHP and parse the last line as JSON. The snippet must `echo json_encode(...)` as its last output. */
export function phpJson(code) {
    const out = php(code);
    const lines = out.split('\n').filter((l) => l.trim().length);
    return JSON.parse(lines[lines.length - 1]);
}

export async function login(page, email = 'owner@demo.test', password = 'password') {
    await page.goto('/login');
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', password);
    await Promise.all([page.waitForURL(/dashboard|teams|decks/), page.click('button[type=submit]')]);
}

/** Call the JSON API as the signed in page (cookie session plus the XSRF header). */
export async function api(page, method, url, body, { raw = false } = {}) {
    return page.evaluate(async ({ method, url, body, raw }) => {
        const xsrf = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) || '').split('=')[1] || '');
        const isForm = body instanceof FormData;
        const res = await fetch(url, {
            method, credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, 'X-Requested-With': 'XMLHttpRequest', ...(body && !isForm ? { 'Content-Type': 'application/json' } : {}) },
            body: body ? (isForm ? body : JSON.stringify(body)) : undefined,
        });
        const text = await res.text();
        let json = null;
        try { json = JSON.parse(text); } catch (e) { /* not json */ }
        return { status: res.status, json, text: raw ? text : text.slice(0, 2000) };
    }, { method, url, body: body instanceof Object && !(body instanceof FormData) ? body : body, raw });
}

/** Upload a local file to the asset library through the real endpoint; returns the asset resource. */
export async function uploadAsset(page, file, mime) {
    const data = fs.readFileSync(file).toString('base64');
    const res = await page.evaluate(async ({ data, name, mime }) => {
        const xsrf = decodeURIComponent((document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN=')) || '').split('=')[1] || '');
        const bin = Uint8Array.from(atob(data), (c) => c.charCodeAt(0));
        const fd = new FormData();
        fd.append('file', new File([bin], name, { type: mime }));
        const r = await fetch('/api/assets', { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf }, body: fd });
        return { status: r.status, json: await r.json().catch(() => null) };
    }, { data, name: path.basename(file), mime });
    return res;
}

export function assert(cond, msg) {
    if (!cond) { results.push({ ok: false, msg }); throw new Error('ASSERT FAILED: ' + msg); }
    results.push({ ok: true, msg });
    console.log('  ok  ' + msg);
}
/** Record a soft failure (the script keeps going; the run exits non-zero at the end). */
export function expect(cond, msg) {
    if (cond) { results.push({ ok: true, msg }); console.log('  ok  ' + msg); return true; }
    results.push({ ok: false, msg }); console.log('  FAIL ' + msg); process.exitCode = 1; return false;
}
export const results = [];

export async function shot(page, name) {
    const f = path.join(OUT, name + '.png');
    await page.screenshot({ path: f });
    console.log('  shot ' + f);
    return f;
}

export function sleep(ms) { return new Promise((r) => setTimeout(r, ms)); }

/** Poll until fn returns truthy. */
export async function until(fn, { timeout = 15000, step = 200, what = 'condition' } = {}) {
    const t0 = Date.now();
    for (;;) {
        const v = await fn();
        if (v) return v;
        if (Date.now() - t0 > timeout) throw new Error('Timed out waiting for ' + what);
        await sleep(step);
    }
}

export function sh(cmd, args, opts = {}) { return execFileSync(cmd, args, { encoding: 'utf8', maxBuffer: 1 << 26, ...opts }); }
export function py(script, ...args) { return execFileSync(PY, ['-I', script, ...args], { encoding: 'utf8', maxBuffer: 1 << 26 }); }

/** Small real media files made with ffmpeg and Pillow. Cached under OUT/fixtures. */
export function makeMedia() {
    const f = (n) => path.join(FIX, n);
    if (!fs.existsSync(f('clip.mp4'))) {
        sh(FFMPEG, ['-y', '-f', 'lavfi', '-i', 'testsrc=duration=4:size=640x360:rate=25', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=4', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', '-movflags', '+faststart', f('clip.mp4')], { stdio: 'ignore' });
    }
    if (!fs.existsSync(f('tone.mp3'))) {
        sh(FFMPEG, ['-y', '-f', 'lavfi', '-i', 'sine=frequency=660:duration=3', '-c:a', 'libmp3lame', f('tone.mp3')], { stdio: 'ignore' });
    }
    if (!fs.existsSync(f('photo.png'))) {
        sh(FFMPEG, ['-y', '-f', 'lavfi', '-i', 'testsrc2=size=800x450:rate=1', '-frames:v', '1', f('photo.png')], { stdio: 'ignore' });
    }
    return { video: f('clip.mp4'), audio: f('tone.mp3'), image: f('photo.png') };
}

export function summary(name) {
    const bad = results.filter((r) => !r.ok);
    console.log(`\n${name}: ${results.length - bad.length} passed, ${bad.length} failed`);
    if (bad.length) { console.log(bad.map((b) => ' - ' + b.msg).join('\n')); process.exitCode = 1; }
}
