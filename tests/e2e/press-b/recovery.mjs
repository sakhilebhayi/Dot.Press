// Check 40/41 (export failure recovery): make the export storage unwritable, export through the UI, see an honest failure with
// a Retry, the deck untouched, then fix the storage and Retry. Also a queued export that fails once and recovers by itself.
import fs from 'node:fs';
import path from 'node:path';
import { launch, context, login, api, expect, shot, summary, sleep, until, php, phpJson, APP, OUT } from './lib.mjs';
import { buildAcceptanceDeck, uid } from './fixture.mjs';

const store = path.join(APP, 'storage/app/private/press');
const exportsDir = path.join(store, 'exports');
fs.mkdirSync(exportsDir, { recursive: true });
const lock = () => { fs.chmodSync(exportsDir, 0o555); fs.chmodSync(store, 0o555); };
const unlock = () => { fs.chmodSync(store, 0o755); fs.chmodSync(exportsDir, 0o755); };
process.on('exit', () => { try { unlock(); } catch (e) { /* best effort */ } });

const browser = await launch();
const oc = await context(browser, { acceptDownloads: true });
const page = await oc.newPage();
await login(page);
const small = await buildAcceptanceDeck(page, { withMedia: false, title: 'Recovery small ' + Date.now() });
const hashOf = (id) => phpJson(`echo json_encode(['h' => App\\Services\\Press\\DeckHash::compute(App\\Models\\Deck::withoutGlobalScopes()->find(${id}))]);`).h;
const h0 = hashOf(small.id);

await page.goto(`/decks/${small.id}`); await page.waitForSelector('.es-viewport'); await sleep(1000);
lock();
await page.getByRole('button', { name: 'Export', exact: true }).click();
await page.getByRole('menuitem', { name: 'PDF', exact: true }).click();
await page.getByRole('button', { name: /Check before exporting/ }).click();
await page.locator('.xp-pf').waitFor();
await page.getByRole('button', { name: /^Export PDF/ }).click();
await page.getByText('The export did not finish').waitFor({ timeout: 60000 });
const msg = await page.locator('.xp-fail').innerText();
expect(/could not be written|could not be finished|Try again/i.test(msg) && !/Exception|\/Users\/|stack/i.test(msg), 'check 41: a failed export says so in plain words, no internals: ' + msg.replace(/\s+/g, ' '));
expect(await page.getByRole('button', { name: 'Retry' }).isVisible(), 'check 41: the failure offers Retry');
await shot(page, 'recovery-failed');
const row = phpJson(`$e = App\\Models\\DeckExport::where('deck_id', ${small.id})->latest('id')->first(); echo json_encode(['status' => $e->status, 'error' => $e->error, 'path' => $e->path, 'size' => (int) $e->size]);`);
expect(row.status === 'failed' && row.error && row.path === null, 'check 41: the export row records the failure and no half file path: ' + JSON.stringify(row));
expect(hashOf(small.id) === h0, 'check 41: the deck is exactly as it was after the failed export (no silent damage)');
expect(fs.readdirSync(exportsDir).filter((f) => f.endsWith('.part')).length === 0, 'check 41: no partial file was left behind');
// other formats are not affected by the broken storage
const png = await api(page, 'GET', `/api/decks/${small.id}/document`);
expect(png.status === 200, 'check 41: the editor keeps working while exports fail');
unlock();
await page.getByRole('button', { name: 'Retry' }).click();
await page.getByRole('button', { name: 'Download', exact: true }).waitFor({ timeout: 60000 });
const [d] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download', exact: true }).click()]);
const f = path.join(OUT, 'downloads', 'recovered.pdf'); await d.saveAs(f);
expect(fs.readFileSync(f).subarray(0, 5).toString() === '%PDF-', 'check 41: after the storage is back, Retry produces a real PDF');
await shot(page, 'recovery-retried');

// ---- queued path: the deck has video, so it runs on the queue worker. It fails once, then recovers on its own retry.
const big = await buildAcceptanceDeck(page, { title: 'Recovery queued ' + Date.now() });
lock();
const r = await api(page, 'POST', `/api/decks/${big.id}/exports`, { format: 'pdf', options: {} });
expect(r.status === 202 && r.json.status === 'queued', 'check 41: a deck with video is queued (202)');
const t0 = Date.now();
await until(async () => { const e = (await api(page, 'GET', `/api/exports/${r.json.id}`)).json; return e.status === 'queued' && !!e.error; }, { timeout: 60000, what: 'first attempt to fail and re-queue' });
const mid = (await api(page, 'GET', `/api/exports/${r.json.id}`)).json;
expect(mid.status === 'queued' && /could not be written/.test(mid.error), 'check 41: after the first failed attempt the page sees "queued" (a retry is coming) with the reason: ' + mid.error);
unlock();
const fin = await until(async () => { const e = (await api(page, 'GET', `/api/exports/${r.json.id}`)).json; return ['done', 'failed'].includes(e.status) ? e : null; }, { timeout: 120000, step: 2000, what: 'retry to finish' });
expect(fin.status === 'done', `check 41: the retry succeeded once the storage was back (${Math.round((Date.now() - t0) / 1000)} s after the request): ${fin.status} ${fin.error || ''}`);

// ---- permanent failure on the queue ends as failed with a message, then the person retries
lock();
const r2 = await api(page, 'POST', `/api/decks/${big.id}/exports`, { format: 'pptx', options: {} });
const end2 = await until(async () => { const e = (await api(page, 'GET', `/api/exports/${r2.json.id}`)).json; return ['done', 'failed'].includes(e.status) ? e : null; }, { timeout: 170000, step: 3000, what: 'permanent failure' });
unlock();
expect(end2.status === 'failed' && !!end2.error && !end2.download_url, 'check 41: when every attempt fails the export ends as failed with a message: ' + end2.error);
const again = await api(page, 'POST', `/api/decks/${big.id}/exports`, { format: 'pptx', options: {} });
const ok2 = await until(async () => { const e = (await api(page, 'GET', `/api/exports/${again.json.id}`)).json; return ['done', 'failed'].includes(e.status) ? e : null; }, { timeout: 120000, step: 2000, what: 'fresh export' });
expect(ok2.status === 'done', 'check 41: a fresh export after the failure works');
console.log('console errors', oc._errors.slice(0, 4));
summary('recovery');
await browser.close();
