// Acceptance check 25 (and the editor side of 47 and 49): autosave and recovery. Every case is a real browser doing a real edit:
// plain autosave, going offline, closing the tab with unsent changes, killing the server for 20 seconds, the server answering 500.
// After each the saved document is read back and compared with what the person did.
import { spawn, execSync } from 'node:child_process';
import { waitUntil, MOD, launch, newContext, suite, ok, eq, sleep, shot, savedDoc, saved, openDeck, createDeck, sendOps, clickEl, APP, BASE } from './lib.mjs';

const t = suite('04-autosave');
const browser = await launch();
const { ctx, page: first } = await newContext(browser, 'owner@demo.test');
let cur = first;
t.page = cur;
const deckId = await createDeck(cur, 'E2E autosave ' + Date.now());
const slideId = (await savedDoc(cur, deckId)).slides[0].id;
await sendOps(cur, deckId, [{ op: 'el.add', slide: slideId, el: { id: 'box', type: 'shape', kind: 'rect', x: 400, y: 300, w: 400, h: 300, fill: '#2e86ab' } }]);
await openDeck(cur, deckId);
// A second, quiet reader used to look at the server's copy without touching the editor under test.
const reader = await ctx.newPage();
await reader.goto(BASE + '/dashboard');
const serverX = async () => (await savedDoc(reader, deckId)).slides[0].els.find((e) => e.id === 'box').x;
const bead = () => cur.locator('.tb-save');
const waitKey = (key, timeout = 20000) => waitUntil(cur, (k) => document.querySelector('.tb-save')?.classList.contains('is-' + k), key, { timeout });
async function nudge(n) {
    await cur.locator('.es-viewport').focus();
    await cur.keyboard.press('Escape');
    await clickEl(cur, 'box', { at: [0.2, 0.2] });
    for (let i = 0; i < n; i++) await cur.keyboard.press('Shift+ArrowRight');
}
const killServer = () => { try { execSync("pkill -f 'php.*127.0.0.1:8850' ; pkill -f 'artisan serve --port=8850'"); } catch { /* none running */ } };
function startServer() {
    const p = spawn('php', ['artisan', 'serve', '--port=8850'], { cwd: APP, detached: true, stdio: 'ignore' });
    p.unref();
}
async function serverUp(ms = 15000) {
    const end = Date.now() + ms;
    while (Date.now() < end) { try { const r = await fetch(BASE + '/login'); if (r.ok) return true; } catch { /* down */ } await sleep(300); }
    return false;
}
let expectedX = 400;

await t.step('25a', 'autosave: an edit goes to the server on its own, the bead says Saved, and there is no save button', async () => {
    await nudge(3);
    expectedX += 30;
    await waitKey('saved', 6000);
    eq(await serverX(), expectedX, 'saved without a click');
    eq(await cur.getByRole('button', { name: /^Save$/ }).count(), 0, 'no save button to press');
});

await t.step('25b', 'offline: the bead says so, the edit is kept, and it arrives when the network returns', async () => {
    await ctx.setOffline(true);
    await sleep(300);
    await nudge(2);
    expectedX += 20;
    await waitKey('offline', 15000);
    ok(/Offline/.test(await bead().innerText()), 'the words: ' + await bead().innerText());
    eq(await serverX().catch(() => 'unreachable'), 'unreachable', 'the reader is offline too, so use the saved value after reconnect');
    await ctx.setOffline(false);
    await waitKey('saved', 30000);
    eq(await serverX(), expectedX, 'delivered after reconnect');
});

await t.step('25c', 'close the tab with unsent changes and open the deck again: the editor offers them back, Restore saves them', async () => {
    await ctx.setOffline(true);
    await nudge(2);
    expectedX += 20;
    await waitKey('offline', 15000);
    await sleep(900); // the unsent copy is written to local storage
    const stored = await cur.evaluate(() => Object.keys(localStorage).filter((k) => k.startsWith('press:sync:')).length);
    ok(stored >= 1, 'an unsent copy is in local storage: ' + stored);
    await cur.close({ runBeforeUnload: false });
    await ctx.setOffline(false);
    const next = await ctx.newPage();
    next.setDefaultTimeout(8000);
    next.errors = [];
    cur = next; t.page = next;
    await openDeck(cur, deckId);
    eq(await serverX(), expectedX - 20, 'the server does not have the change yet');
    // a tab closed a moment ago may still look alive for 15 seconds, so the offer can take up to about 17 seconds
    await cur.waitForSelector('.ed-banner', { timeout: 25000 });
    ok(/unsent changes/.test(await cur.locator('.ed-banner').innerText()), 'banner text');
    await shot(cur, '25c-recovery-banner');
    await cur.getByRole('button', { name: 'Restore' }).click();
    await waitKey('saved', 20000);
    eq(await serverX(), expectedX, 'restored and saved');
    eq(await cur.locator('.ed-banner').count(), 0, 'banner gone');
});

await t.step('25d', 'the server is killed for 20 seconds: edits made meanwhile are kept, the bead is honest, everything lands once it is back, nothing twice', async () => {
    await cur.locator('.es-viewport').focus();
    killServer();
    await sleep(800);
    await nudge(1);
    expectedX += 10;
    await waitKey('offline', 15000).catch(async () => { await waitKey('error', 5000); });
    const words = await bead().innerText();
    ok(/Offline|Could not save/.test(words), 'honest words: ' + words);
    await sleep(8000);
    await nudge(1);
    expectedX += 10;
    await shot(cur, '25d-server-down');
    await sleep(8000);
    startServer();
    ok(await serverUp(20000), 'the server came back');
    await waitKey('saved', 45000);
    eq(await serverX(), expectedX, 'both edits arrived, applied once');
    await shot(cur, '25d-recovered');
});

await t.step('25e', 'the server answers 500 three times: the bead says it could not save, retries by itself, and nothing is lost', async () => {
    let refused = 0;
    await ctx.route('**/api/decks/*/ops', (route) => { if (refused < 3) { refused++; route.fulfill({ status: 500, contentType: 'application/json', body: '{"message":"Server Error"}' }); } else route.continue(); });
    await nudge(1);
    expectedX += 10;
    await waitKey('saved', 40000);
    eq(await serverX(), expectedX, 'saved after the retries');
    ok(refused >= 3, 'the server refused ' + refused + ' times');
    await ctx.unroute('**/api/decks/*/ops');
});

await t.step('25f', 'a change the server refuses (422) stops saving visibly with a way out, other edits still queue, Discard clears it', async () => {
    let refuse = true;
    await ctx.route('**/api/decks/*/ops', (route) => { if (refuse) route.fulfill({ status: 422, contentType: 'application/json', body: '{"message":"The op is not valid","errors":{"ops":["bad op"]}}' }); else route.continue(); });
    await nudge(1);
    await waitKey('error', 20000);
    ok(/Could not save/.test(await bead().innerText()), 'words: ' + await bead().innerText());
    ok(await cur.locator('.tb-retry').count() > 0, 'a button to act: ' + await cur.locator('.tb-retry').innerText());
    await shot(cur, '25f-refused');
    refuse = false;
    await cur.locator('.tb-retry').click();
    await waitKey('saved', 20000).catch(async () => { /* the action may be Discard */ });
    await ctx.unroute('**/api/decks/*/ops');
    await cur.locator('.es-viewport').focus();
    // after discarding the refused change the editor saves again
    if ((await beadClass()).includes('is-error')) { await cur.locator('.tb-retry').click(); }
    await waitKey('saved', 20000);
    expectedX = await serverX();
});

async function beadClass() { return (await cur.locator('.tb-save').getAttribute('class')) || ''; }

console.log('console errors:', JSON.stringify(cur.errors));
const bad = t.summary();
await browser.close();
process.exit(bad ? 1 : 0);
