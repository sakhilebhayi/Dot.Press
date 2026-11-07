// Browser benchmark for the Dot.Press editor: first load, dragging, the Spine on a 300 slide deck, memory growth over 500
// operations, thumbnail cost and the sync request rate. Needs Chrome and playwright-core, neither of which is a project
// dependency; point at them with CHROME_PATH and PLAYWRIGHT_CORE if the defaults below do not match your machine.
//
//   php artisan press:bench --seed-only --use-current-db --sizes=10,100,300      decks "Bench 10/100/300", user bench@press.test
//   php artisan serve --port=8853                                                (or any server on the same database)
//   node scripts/bench/browser.mjs --base http://127.0.0.1:8853 [--sizes 10,100,300] [--cpu 4] [--only load,drag,spine,leak,thumbs,poll] [--json out.json]
//
// CPU throttling uses the DevTools protocol (Emulation.setCPUThrottlingRate): --cpu 4 is a 4x slower processor.
import { createRequire } from 'node:module';
import { existsSync } from 'node:fs';
import { args, recorder, median, percentile } from './lib.mjs';

const a = args();
const BASE = String(a.base || process.env.PRESS_BASE || 'http://127.0.0.1:8853');
const EMAIL = process.env.PRESS_EMAIL || 'bench@press.test';
const PASSWORD = process.env.PRESS_PASSWORD || 'password';
const sizes = String(a.sizes || '10,100,300').split(',').map(Number);
const cpuRates = String(a.cpu || '1,4').split(',').map(Number);
const only = new Set(String(a.only || 'load,drag,spine,thumbs,leak,poll,library').split(','));
const rec = recorder(`Dot.Press browser benchmark (${BASE})`);

function loadPlaywright() {
    const candidates = [process.env.PLAYWRIGHT_CORE, `${process.env.HOME}/.npm-global/lib/node_modules/@playwright/cli/`, `${process.cwd()}/`].filter(Boolean);
    for (const c of candidates) {
        try {
            const req = createRequire(c.endsWith('/') ? c : `${c}/`);
            return req('playwright-core');
        } catch { /* try the next */ }
    }
    throw new Error('playwright-core not found. Set PLAYWRIGHT_CORE to a folder that can resolve it (for example the global @playwright/cli install).');
}
const chromePath = process.env.CHROME_PATH || ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((p) => existsSync(p));
if (!chromePath) throw new Error('Chrome not found. Set CHROME_PATH.');
const { chromium } = loadPlaywright();
const browser = await chromium.launch({ executablePath: chromePath, headless: true, args: ['--js-flags=--expose-gc', '--enable-precise-memory-info', '--disable-background-timer-throttling'] });

let storageState = null; // one sign-in for the whole run: the login form is rate limited
async function newSession(cpu = 1) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, ...(storageState ? { storageState } : {}) });
    const page = await context.newPage();
    await page.addInitScript(() => {
        window.__long = [];
        try { new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__long.push([e.startTime, e.duration]); }).observe({ type: 'longtask', buffered: true }); } catch { /* unsupported */ }
    });
    const cdp = await context.newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Performance.enable');
    if (cpu > 1) await cdp.send('Emulation.setCPUThrottlingRate', { rate: cpu });
    const net = { bytes: 0, js: 0, css: 0, font: 0, requests: 0, api: [] };
    const types = new Map();
    cdp.on('Network.responseReceived', (e) => types.set(e.requestId, { type: e.type, url: e.response.url }));
    cdp.on('Network.loadingFinished', (e) => {
        const t = types.get(e.requestId);
        net.bytes += e.encodedDataLength;
        net.requests++;
        if (t?.type === 'Script') net.js += e.encodedDataLength;
        if (t?.type === 'Stylesheet') net.css += e.encodedDataLength;
        if (t?.type === 'Font') net.font += e.encodedDataLength;
    });
    page.on('request', (r) => { if (r.url().includes('/api/')) net.api.push([Date.now(), r.method(), new URL(r.url()).pathname.replace(/\/\d+/g, '/:id')]); });
    return { context, page, cdp, net };
}

async function login(page) {
    if (storageState) return;
    await page.goto(`${BASE}/login`);
    await page.fill('input[type=email]', EMAIL);
    await page.fill('input[type=password]', PASSWORD);
    await Promise.all([page.waitForURL('**/dashboard'), page.click('button[type=submit]')]);
}

async function deckIds(page) {
    const res = await page.evaluate(async () => (await fetch('/api/decks?per_page=60&q=Bench', { headers: { Accept: 'application/json' } })).json());
    const ids = {};
    for (const d of res.data) { const m = /^Bench (\d+)$/.exec(d.title); if (m) ids[m[1]] = d.id; }
    return ids;
}

async function metrics(cdp) {
    const { metrics: m } = await cdp.send('Performance.getMetrics');
    return Object.fromEntries(m.map((x) => [x.name, x.value]));
}

const longTasks = (page, from = 0) => page.evaluate((f) => {
    const t = window.__long.filter(([s]) => s >= f);
    return { count: t.length, blocking: t.reduce((sum, [, d]) => sum + Math.max(0, d - 50), 0), max: t.reduce((m, [, d]) => Math.max(m, d), 0) };
}, from);

/** Frame durations from requestAnimationFrame while `fn` runs in the page. */
const frames = (page, ms) => page.evaluate((duration) => new Promise((resolve) => {
    const d = [];
    let last = performance.now();
    const end = last + duration;
    const tick = (now) => { d.push(now - last); last = now; if (now < end) requestAnimationFrame(tick); else resolve(d); };
    requestAnimationFrame(tick);
}), ms);

function frameStats(section, key, d) {
    rec.put(section, `${key}.frames`, d.length);
    rec.put(section, `${key}.frame_p50_ms`, median(d));
    rec.put(section, `${key}.frame_p95_ms`, percentile(d, 0.95));
    rec.put(section, `${key}.frame_max_ms`, Math.max(...d));
    rec.put(section, `${key}.frames_over_50ms`, d.filter((x) => x > 50).length);
    rec.put(section, `${key}.fps_avg`, 1000 / (d.reduce((s, x) => s + x, 0) / d.length));
}

async function openEditor(page, id) {
    await page.goto(`${BASE}/decks/${id}`, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.es-sheet-in .p-el', { timeout: 120000 });
}

// ---------------------------------------------------------------------------------------------------------------

{
    const boot = await newSession(1);
    await login(boot.page);
    storageState = await boot.context.storageState();
    const ids = await deckIds(boot.page);
    await boot.context.close();
    if (!sizes.every((s) => ids[s])) console.warn('Missing decks:', sizes.filter((s) => !ids[s]).join(', '), 'found', JSON.stringify(ids));

    if (only.has('load')) {
        for (const cpu of cpuRates) {
            for (const size of sizes) {
                const key = `${size}_slides.cpu${cpu}x`;
                for (const cache of ['cold', 'warm']) {
                    const s = await newSession(cpu);
                    await login(s.page);
                    if (cache === 'warm') { await openEditor(s.page, ids[size]); await s.page.waitForTimeout(500); }
                    s.net.bytes = s.net.js = s.net.css = s.net.font = s.net.requests = 0;
                    await s.page.evaluate(() => { if (window.__long) window.__long.length = 0; });
                    const t0 = Date.now();
                    await s.page.goto(`${BASE}/decks/${ids[size]}`, { waitUntil: 'domcontentloaded' });
                    const tNav = Date.now() - t0;
                    await s.page.waitForSelector('.es-sheet-in .p-el', { timeout: 180000 }).catch(() => {});
                    const tReady = Date.now() - t0;
                    await s.page.waitForTimeout(1500);
                    const after = await metrics(s.cdp);
                    const lt = await longTasks(s.page);
                    const nav = await s.page.evaluate(() => { const n = performance.getEntriesByType('navigation')[0]; return { dcl: n.domContentLoadedEventEnd, load: n.loadEventEnd, ttfb: n.responseStart, transfer: n.transferSize, decoded: n.decodedBodySize }; });
                    const heap = await s.page.evaluate(() => performance.memory?.usedJSHeapSize / 1048576);
                    const nodes = await s.page.evaluate(() => document.querySelectorAll('*').length);
                    rec.put('load', `${key}.${cache}.html_ttfb_ms`, nav.ttfb);
                    rec.put('load', `${key}.${cache}.dom_content_loaded_ms`, nav.dcl);
                    rec.put('load', `${key}.${cache}.first_slide_drawn_ms`, tReady);
                    rec.put('load', `${key}.${cache}.long_tasks`, lt.count);
                    rec.put('load', `${key}.${cache}.blocking_ms`, lt.blocking);
                    rec.put('load', `${key}.${cache}.longest_task_ms`, lt.max);
                    // The counters restart with the new document, so the "after" values are the cost of this load.
                    rec.put('load', `${key}.${cache}.script_ms`, after.ScriptDuration * 1000);
                    rec.put('load', `${key}.${cache}.layout_style_ms`, (after.LayoutDuration + after.RecalcStyleDuration) * 1000);
                    rec.put('load', `${key}.${cache}.transfer_kb`, s.net.bytes / 1024);
                    rec.put('load', `${key}.${cache}.js_kb`, s.net.js / 1024);
                    rec.put('load', `${key}.${cache}.requests`, s.net.requests);
                    rec.put('load', `${key}.${cache}.dom_nodes`, nodes);
                    rec.put('load', `${key}.${cache}.js_heap_mb`, heap);
                    void tNav;
                    await s.context.close();
                }
            }
        }
    }

    const big = sizes.includes(300) ? 300 : Math.max(...sizes);
    const mid = sizes.includes(100) ? 100 : sizes[0];

    if (only.has('drag')) {
        for (const cpu of cpuRates) {
            const s = await newSession(cpu);
            await login(s.page);
            await openEditor(s.page, ids[mid]);
            await s.page.waitForTimeout(800);
            // Select every element, duplicate them (60 elements), select all again and drag one of them.
            await s.page.click('.es-slidespace', { position: { x: 5, y: 5 } }).catch(() => {});
            await s.page.keyboard.press('ControlOrMeta+a');
            await s.page.keyboard.press('ControlOrMeta+d');
            await s.page.waitForTimeout(500);
            await s.page.keyboard.press('ControlOrMeta+a');
            const count = await s.page.evaluate(() => document.querySelectorAll('.es-sheet-in .p-el').length);
            rec.put('drag', `cpu${cpu}x.elements_on_slide`, count);
            const box = await s.page.evaluate(() => {
                const el = [...document.querySelectorAll('.es-sheet-in .p-el[data-type="shape"]')][0] || document.querySelector('.es-sheet-in .p-el');
                const r = el.getBoundingClientRect();
                return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
            });
            const posBefore = await s.page.evaluate(() => [...document.querySelectorAll('.es-sheet-in .p-el')].slice(0, 3).map((e) => e.getBoundingClientRect().x));
            await s.page.evaluate(() => { if (window.__long) window.__long.length = 0; });
            const framesPromise = frames(s.page, 2500);
            await s.page.mouse.move(box.x, box.y);
            await s.page.mouse.down();
            for (let i = 1; i <= 60; i++) {
                await s.page.mouse.move(box.x + i * 4, box.y + i * 2);
                await s.page.waitForTimeout(16);
            }
            await s.page.mouse.up();
            const d = await framesPromise;
            const lt = await longTasks(s.page);
            const posAfter = await s.page.evaluate(() => [...document.querySelectorAll('.es-sheet-in .p-el')].slice(0, 3).map((e) => e.getBoundingClientRect().x));
            rec.put('drag', `cpu${cpu}x.moved_px`, posAfter[0] - posBefore[0]);
            frameStats('drag', `cpu${cpu}x.drag_all_selected`, d);
            rec.put('drag', `cpu${cpu}x.long_tasks`, lt.count);
            rec.put('drag', `cpu${cpu}x.blocking_ms`, lt.blocking);
            rec.put('drag', `cpu${cpu}x.longest_task_ms`, lt.max);
            await s.context.close();
        }
    }

    if (only.has('spine') || only.has('thumbs')) {
        for (const [label, size, view] of [['300', big, 'claims'], ['300', big, 'sheets'], ['100', mid, 'claims'], ['100', mid, 'sheets']]) {
            for (const cpu of cpuRates) {
                const s = await newSession(cpu);
                await login(s.page);
                await openEditor(s.page, ids[size]);
                await s.page.waitForSelector('.sp-scroll');
                if (view === 'sheets') await s.page.click('button:has(.pr-sr:text("Sheets view"))');
                await s.page.waitForTimeout(1000);
                const before = await metrics(s.cdp);
                const nodes0 = await s.page.evaluate(() => document.querySelectorAll('*').length);
                await s.page.evaluate(() => { if (window.__long) window.__long.length = 0; });
                // Scroll from the top to the bottom of the Spine over 4 seconds, 40 px per frame.
                const result = await s.page.evaluate(() => new Promise((resolve) => {
                    const sc = document.querySelector('.sp-scroll');
                    const d = [];
                    let last = performance.now();
                    const tick = (now) => {
                        d.push(now - last); last = now;
                        sc.scrollTop += 40;
                        if (sc.scrollTop + sc.clientHeight < sc.scrollHeight - 2) requestAnimationFrame(tick); else resolve({ d, height: sc.scrollHeight });
                    };
                    requestAnimationFrame(tick);
                }));
                await s.page.waitForTimeout(800);
                const after = await metrics(s.cdp);
                const lt = await longTasks(s.page);
                const stats = await s.page.evaluate(() => ({ nodes: document.querySelectorAll('*').length, thumbs: document.querySelectorAll('.lt .ps-wrap').length, heap: performance.memory?.usedJSHeapSize / 1048576 }));
                const key = `${label}_slides.${view}.cpu${cpu}x`;
                frameStats('spine', `${key}.scroll_full_list`, result.d);
                rec.put('spine', `${key}.long_tasks`, lt.count);
                rec.put('spine', `${key}.blocking_ms`, lt.blocking);
                rec.put('spine', `${key}.dom_nodes_before`, nodes0);
                rec.put('spine', `${key}.dom_nodes_after_scroll`, stats.nodes);
                rec.put('spine', `${key}.thumbnails_alive_after_scroll`, stats.thumbs);
                rec.put('spine', `${key}.js_heap_mb_after_scroll`, stats.heap);
                rec.put('thumbs', `${key}.scripting_ms_for_all_thumbs`, (after.ScriptDuration - before.ScriptDuration) * 1000);
                rec.put('thumbs', `${key}.layout_style_ms_for_all_thumbs`, (after.LayoutDuration - before.LayoutDuration + after.RecalcStyleDuration - before.RecalcStyleDuration) * 1000);
                await s.context.close();
            }
        }
    }

    if (only.has('leak')) {
        const s = await newSession(1);
        await login(s.page);
        await openEditor(s.page, ids[mid]);
        await s.page.waitForTimeout(1000);
        await s.page.click('.es-sheet-in .p-el[data-type="shape"]').catch(() => {});
        const gc = async () => { await s.page.evaluate(() => { window.gc?.(); }); await s.page.waitForTimeout(200); await s.page.evaluate(() => { window.gc?.(); }); };
        await gc();
        const heap0 = await s.page.evaluate(() => performance.memory.usedJSHeapSize / 1048576);
        const nodes0 = await s.page.evaluate(() => document.querySelectorAll('*').length);
        const listeners0 = (await s.cdp.send('Memory.getDOMCounters')).jsEventListeners;
        for (let i = 0; i < 500; i++) {
            await s.page.keyboard.press(i % 2 ? 'ArrowRight' : 'ArrowDown');
            if (i % 50 === 0) await s.page.waitForTimeout(30);
        }
        await s.page.waitForTimeout(3000);
        await gc();
        const heap1 = await s.page.evaluate(() => performance.memory.usedJSHeapSize / 1048576);
        const nodes1 = await s.page.evaluate(() => document.querySelectorAll('*').length);
        const listeners1 = (await s.cdp.send('Memory.getDOMCounters')).jsEventListeners;
        // Second round: if memory keeps climbing at the same rate, it leaks; if it flattens, it was the undo stack filling up.
        for (let i = 0; i < 500; i++) {
            await s.page.keyboard.press(i % 2 ? 'ArrowLeft' : 'ArrowUp');
            if (i % 50 === 0) await s.page.waitForTimeout(30);
        }
        await s.page.waitForTimeout(3000);
        await gc();
        const heap2 = await s.page.evaluate(() => performance.memory.usedJSHeapSize / 1048576);
        rec.put('leak', 'heap_start_mb', heap0);
        rec.put('leak', 'heap_after_500_ops_mb', heap1);
        rec.put('leak', 'heap_after_1000_ops_mb', heap2);
        rec.put('leak', 'growth_first_500_mb', heap1 - heap0);
        rec.put('leak', 'growth_second_500_mb', heap2 - heap1);
        rec.put('leak', 'dom_nodes_growth', nodes1 - nodes0);
        rec.put('leak', 'event_listeners_growth', listeners1 - listeners0);
        await s.context.close();
    }

    if (only.has('library')) {
        // The library with 200 decks (php artisan press:bench --only=library --use-current-db creates them).
        for (const cpu of cpuRates) {
            const s = await newSession(cpu);
            await login(s.page);
            const t0 = Date.now();
            await s.page.goto(`${BASE}/dashboard`, { waitUntil: 'domcontentloaded' });
            await s.page.waitForSelector('.lw-grid:not(.lw-grid--wait) > li', { timeout: 60000 }).catch(() => {});
            await s.page.waitForFunction(() => document.querySelectorAll('.lw-grid .ps-wrap').length >= 6, null, { timeout: 60000 }).catch(() => {});
            const ready = Date.now() - t0;
            await s.page.waitForTimeout(1500);
            const lt = await longTasks(s.page);
            const stats = await s.page.evaluate(() => ({ nodes: document.querySelectorAll('*').length, cards: document.querySelectorAll('.ps-wrap').length, heap: performance.memory?.usedJSHeapSize / 1048576 }));
            rec.put('library', `cpu${cpu}x.cards_drawn_ms`, ready);
            rec.put('library', `cpu${cpu}x.thumbnails_drawn`, stats.cards);
            rec.put('library', `cpu${cpu}x.dom_nodes`, stats.nodes);
            rec.put('library', `cpu${cpu}x.long_tasks`, lt.count);
            rec.put('library', `cpu${cpu}x.blocking_ms`, lt.blocking);
            rec.put('library', `cpu${cpu}x.transfer_kb`, s.net.bytes / 1024);
            rec.put('library', `cpu${cpu}x.js_heap_mb`, stats.heap);
            await s.context.close();
        }
    }

    if (only.has('poll')) {
        // Requests per minute of one open tab. A deck nobody else has touched in the last 20 seconds is used, so the tab
        // is alone (presence rows of earlier sessions would otherwise count as company). The idle state is measured over
        // the last 30 seconds of a 100 second wait, after the tab has been untouched for more than a minute.
        const quiet = ids[sizes[0]];
        await new Promise((r) => setTimeout(r, 25000));
        const states = [
            ['active_alone_with_pointer', 30, 30],
            ['idle_focused_after_a_minute', 100, 30],
            ['hidden', 65, 65],
        ];
        for (const [state, total, window] of states) {
            const s = await newSession(1);
            await login(s.page);
            await openEditor(s.page, quiet);
            await s.page.waitForTimeout(1000);
            if (state === 'hidden') {
                await s.page.evaluate(() => {
                    Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
                    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
                    document.dispatchEvent(new Event('visibilitychange'));
                    window.dispatchEvent(new Event('blur'));
                });
            }
            s.net.api.length = 0;
            const t0 = Date.now();
            if (state.startsWith('active')) {
                for (let i = 0; i < total * 2; i++) { await s.page.mouse.move(300 + (i % 40) * 10, 300 + (i % 20) * 5); await s.page.waitForTimeout(500); }
            } else {
                await s.page.waitForTimeout(total * 1000);
            }
            const from = t0 + (total - window) * 1000;
            const counted = s.net.api.filter(([at]) => at >= from);
            const mins = window / 60;
            const byPath = {};
            for (const [, method, path] of counted) byPath[`${method} ${path}`] = (byPath[`${method} ${path}`] || 0) + 1;
            rec.put('poll', `${state}.requests_per_minute`, counted.length / mins);
            for (const [k, n] of Object.entries(byPath)) rec.put('poll', `${state}.${k}.per_minute`, n / mins);
            await s.context.close();
        }
    }
}

await browser.close();
rec.print();
rec.save(a.json);
