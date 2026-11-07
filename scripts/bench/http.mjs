// HTTP benchmark against a running Dot.Press server: cost of one request, and the load of N simultaneous editors.
//
//   php artisan press:bench --seed-only --use-current-db --sizes=10,100,300      prints deck ids and a bearer token
//   /path/to/serve   (PHP_CLI_SERVER_WORKERS=4 php -d opcache.enable_cli=1 -S 127.0.0.1:8853 ... or php artisan serve)
//   node scripts/bench/http.mjs --base http://127.0.0.1:8853 --token <token> --decks 10:3,100:4,300:5 [--editors 20] [--seconds 40] [--mode old|new] [--tokens storage/app/bench/tokens.txt]
//   (php artisan press:bench --seed-only --use-current-db --tokens=20 writes one token per virtual editor, so each editor
//   is a different user with its own rate limit, as in real use)
//
// Part 1: sequential latency of the hot endpoints, one request at a time. On a one-process server that latency is the CPU
//         cost of the request, so the load of N editors is cost x requests per minute x N / 60.
// Part 2: N virtual editors running the real client cadence for --seconds, counting requests, errors and server CPU.
//         --mode old   presence every 3 s plus a changes poll every 3 s (the client before R-40 performance work)
//         --mode new   the cadence of resources/js/press/editor/core/cadence.js (presence only; changes when its seq moves)
import { execSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { args, recorder, median, percentile } from './lib.mjs';
import { cadence } from '../../resources/js/press/editor/core/cadence.js';

const a = args();
const BASE = String(a.base || 'http://127.0.0.1:8853');
const TOKEN = String(a.token || process.env.PRESS_TOKEN || '');
if (!TOKEN) { console.error('Pass --token (printed by press:bench --seed-only).'); process.exit(1); }
const decks = Object.fromEntries(String(a.decks || '10:3,100:4,300:5').split(',').map((p) => p.split(':')));
const rec = recorder(`Dot.Press HTTP benchmark (${BASE})`);
const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json', 'Content-Type': 'application/json' };
const tokenFile = a.tokens && a.tokens !== true ? a.tokens : 'storage/app/bench/tokens.txt';
const editorTokens = existsSync(tokenFile) ? readFileSync(tokenFile, 'utf8').split('\n').filter(Boolean) : [];

async function call(method, path, body, token) {
    const t = performance.now();
    const res = await fetch(`${BASE}${path}`, { method, headers: token ? { ...headers, Authorization: `Bearer ${token}` } : headers, body: body ? JSON.stringify(body) : undefined });
    const text = await res.text();
    return { ms: performance.now() - t, status: res.status, bytes: text.length, encoding: res.headers.get('content-encoding'), json: res.headers.get('content-type')?.includes('json') ? JSON.parse(text || 'null') : null };
}

function serverPids() {
    try { return execSync(`pgrep -f "${new URL(BASE).host}"`).toString().split('\n').filter(Boolean).map(Number); } catch { return []; }
}
function cpuSeconds() {
    let total = 0;
    for (const pid of serverPids()) {
        try {
            const t = execSync(`ps -o cputime= -p ${pid}`).toString().trim(); // [[dd-]hh:]mm:ss.cc on macOS: mm:ss.cc
            const parts = t.split(':').map(Number);
            total += parts.reduce((s, x) => s * 60 + x, 0);
        } catch { /* process gone */ }
    }
    return total;
}

async function sequential(label, n, fn) {
    const t = [];
    let last;
    await fn(); // warm
    for (let i = 0; i < n; i++) { last = await fn(); t.push(last.ms); }
    rec.put('request_cost', `${label}.p50_ms`, median(t));
    rec.put('request_cost', `${label}.p95_ms`, percentile(t, 0.95));
    rec.put('request_cost', `${label}.bytes`, last.bytes);
    if (last.status >= 400) rec.put('request_cost', `${label}.status`, last.status);
    return median(t);
}

const RUN = Date.now().toString(36);
const callAs = call;
const costs = {};
for (const [size, id] of Object.entries(decks)) {
    costs[`document_${size}`] = await sequential(`get_document_${size}_slides`, size > 100 ? 10 : 20, () => call('GET', `/api/decks/${id}/document`));
}
const deckId = decks[100] || Object.values(decks)[0];
const doc = (await call('GET', `/api/decks/${deckId}/document`)).json;
const slideId = doc.slides[0].id;
const elId = doc.slides[0].els[0].id;
let seq = doc.seq;
costs.presence = await sequential('post_presence', 60, () => call('POST', `/api/decks/${deckId}/presence`, { client: 'bench-seq', slide: slideId, selection: [elId], cursor: { x: 10, y: 20 } }));
costs.changes = await sequential('get_changes_nothing_new', 60, async () => { const r = await call('GET', `/api/decks/${deckId}/changes?after=${seq}`); return r; });
let n = 0;
costs.ops = await sequential('post_ops_single_el_set', 30, async () => { const r = await call('POST', `/api/decks/${deckId}/ops`, { client: 'bench-seq', key: `h-${Date.now()}-${++n}`, ops: [{ op: 'el.set', slide: slideId, id: elId, p: { x: 100 + (n % 50) } }] }); if (r.json?.seq) seq = r.json.seq; return r; });
costs.page = await sequential('get_editor_page_html', 5, async () => { const t = performance.now(); const res = await fetch(`${BASE}/decks/${deckId}`, { headers: { Authorization: `Bearer ${TOKEN}` } }); const text = await res.text(); return { ms: performance.now() - t, status: res.status, bytes: text.length }; });
const withEncoding = await call('GET', `/api/decks/${deckId}/document`);
rec.put('request_cost', 'get_document_100_slides.content_encoding', withEncoding.encoding || 'none');

// ---- N editors -------------------------------------------------------------------------------------------------------
const editors = Number(a.editors || 20);
const seconds = Number(a.seconds || 40);
const mode = String(a.mode || 'new');
const lat = [];
let errors = 0;
let throttled = 0;
const counts = { presence: 0, changes: 0, ops: 0 };
const end = Date.now() + seconds * 1000;
const cpu0 = cpuSeconds();

async function editor(i) {
    const client = `bench-${RUN}-${i}`;
    const tok = editorTokens[i % Math.max(1, editorTokens.length)];
    const call = (m, p, b) => callAs(m, p, b, tok);
    let mySeq = seq;
    let idleSince = Date.now();
    const track = (r, kind) => { counts[kind]++; lat.push(r.ms); if (r.status === 429) throttled++; else if (r.status >= 400) errors++; return r; };
    const loops = [];
    const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
    await sleep((i * 3000) / editors); // spread the starts, as real tabs are
    const presenceLoop = async () => {
        while (Date.now() < end) {
            const r = track(await call('POST', `/api/decks/${deckId}/presence`, { client, slide: slideId, selection: [elId], cursor: { x: i, y: i } }), 'presence');
            const others = r.json?.others?.length || 0;
            if (mode === 'new' && r.json?.seq > mySeq) {
                const c = track(await call('GET', `/api/decks/${deckId}/changes?after=${mySeq}`), 'changes');
                mySeq = c.json?.seq ?? mySeq;
            }
            const idleMs = Date.now() - idleSince;
            await sleep(mode === 'new' ? cadence.presenceMs({ focused: true, hidden: false, idleMs, others }) : 3000);
        }
    };
    loops.push(presenceLoop());
    if (mode === 'old') {
        loops.push((async () => {
            while (Date.now() < end) {
                const c = track(await call('GET', `/api/decks/${deckId}/changes?after=${mySeq}`), 'changes');
                mySeq = c.json?.seq ?? mySeq;
                await sleep(3000);
            }
        })());
    }
    // One editor in five is actively changing the deck: a single el.set every 6 seconds.
    if (i % 5 === 0) {
        loops.push((async () => {
            let k = 0;
            while (Date.now() < end) {
                await sleep(6000);
                idleSince = Date.now();
                const r = track(await call('POST', `/api/decks/${deckId}/ops`, { client, key: `ed-${i}-${Date.now()}-${++k}`, ops: [{ op: 'el.set', slide: slideId, id: elId, p: { y: 50 + k } }] }), 'ops');
                if (r.json?.seq) mySeq = Math.max(mySeq, r.json.seq);
            }
        })());
    }
    await Promise.all(loops);
}
await Promise.all(Array.from({ length: editors }, (_, i) => editor(i)));
const cpu = cpuSeconds() - cpu0;
const total = counts.presence + counts.changes + counts.ops;
const key = `${editors}_editors_${mode}`;
rec.put('load', `${key}.seconds`, seconds);
rec.put('load', `${key}.requests_total`, total);
rec.put('load', `${key}.requests_per_minute_per_editor`, (total / editors) / (seconds / 60));
rec.put('load', `${key}.requests_per_second_all`, total / seconds);
rec.put('load', `${key}.presence`, counts.presence);
rec.put('load', `${key}.changes`, counts.changes);
rec.put('load', `${key}.ops`, counts.ops);
rec.put('load', `${key}.latency_p50_ms`, median(lat));
rec.put('load', `${key}.latency_p95_ms`, percentile(lat, 0.95));
rec.put('load', `${key}.latency_max_ms`, Math.max(...lat));
rec.put('load', `${key}.errors_5xx_or_4xx`, errors);
rec.put('load', `${key}.throttled_429`, throttled);
if (cpu > 0) {
    rec.put('load', `${key}.server_cpu_seconds`, cpu);
    rec.put('load', `${key}.server_cpu_core_fraction`, cpu / seconds);
}

rec.print();
rec.save(a.json);
