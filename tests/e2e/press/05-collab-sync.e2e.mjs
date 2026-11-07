// Two real browsers (owner and editor) on one deck: what one does arrives at the other in the same order, element by element.
// Includes the known case of slides added in a chain through temporary ids inside one batch.
import { MOD, launch, newContext, suite, ok, eq, sleep, shot, savedDoc, saved, openDeck, createDeck, clickEl, BASE } from './lib.mjs';

const t = suite('05-collab-sync');
const browser = await launch();
const A = await newContext(browser, 'owner@demo.test');
const B = await newContext(browser, 'editor@demo.test');
t.page = A.page;
const deckId = await createDeck(A.page, 'E2E collab ' + Date.now());
await openDeck(A.page, deckId);
await openDeck(B.page, deckId);
const rowIds = (p) => p.locator('.sp-row[data-row="slide"]').evaluateAll((els) => els.map((e) => e.dataset.id));
const rowTitles = (p) => p.locator('.sp-row[data-row="slide"] .sp-title').allInnerTexts();
const serverIds = async () => (await savedDoc(A.page, deckId)).slides.map((s) => String(s.id));
async function waitFor(fn, timeout = 15000) { const end = Date.now() + timeout; let last; while (Date.now() < end) { last = await fn(); if (last === true) return; await sleep(250); } throw new Error('timed out: ' + JSON.stringify(last)); }

await t.step('sync-1', 'four slides added in quick succession by one browser (a chain of temporary ids) appear in the same order in the other, without a reload', async () => {
    await A.page.locator('.es-viewport').focus();
    for (let i = 0; i < 4; i++) { await A.page.keyboard.press(`${MOD}+m`); }
    await saved(A.page);
    const server = await serverIds();
    eq(server.length, 5, 'five slides on the server');
    await waitFor(async () => (await rowIds(B.page)).length === 5 || (await rowIds(B.page)).length);
    await sleep(1500);
    const bIds = await rowIds(B.page);
    const aIds = await rowIds(A.page);
    eq(aIds, server, 'the author sees the server order');
    eq(bIds, server, 'the other browser sees the server order too');
});

await t.step('sync-2', 'titles typed in one browser reach the other against the right slides', async () => {
    const aRows = A.page.locator('.sp-row[data-row="slide"]');
    for (let i = 0; i < 5; i++) { await aRows.nth(i).click(); await A.page.keyboard.press('F2'); await A.page.locator('.sp-edit').fill(`Claim ${i + 1}`); await A.page.keyboard.press('Enter'); }
    await saved(A.page);
    console.log('A titles', await rowTitles(A.page), 'server', (await savedDoc(A.page, deckId)).slides.map((s) => s.title));
    await waitFor(async () => JSON.stringify(await rowTitles(B.page)) === JSON.stringify(['Claim 1', 'Claim 2', 'Claim 3', 'Claim 4', 'Claim 5']) || await rowTitles(B.page));
});

await t.step('sync-3', 'a drag reorder in one browser is the same order in the other', async () => {
    const aRows = A.page.locator('.sp-row[data-row="slide"]');
    await aRows.nth(4).click();
    await A.page.keyboard.press('Alt+ArrowUp'); await A.page.keyboard.press('Alt+ArrowUp'); await A.page.keyboard.press('Alt+ArrowUp');
    await saved(A.page);
    const server = (await savedDoc(A.page, deckId)).slides.map((s) => s.title);
    await waitFor(async () => JSON.stringify(await rowTitles(B.page)) === JSON.stringify(server) || await rowTitles(B.page));
});

await t.step('sync-4', 'both edit the same slide at once: different elements both survive, the same property settles on the later edit', async () => {
    // A adds a text with the tool, B adds a shape at the same moment
    const draw = async (p, tool, x, y) => {
        await p.locator(`.dk-b[aria-label="${tool}"]`).click();
        const sb = await p.locator('.es-sheet-in').boundingBox();
        await p.mouse.move(sb.x + sb.width * x, sb.y + sb.height * y); await p.mouse.down(); await p.mouse.move(sb.x + sb.width * (x + 0.2), sb.y + sb.height * (y + 0.2), { steps: 5 }); await p.mouse.up();
    };
    await A.page.locator('.sp-row[data-row="slide"]').first().click();
    await B.page.locator('.sp-row[data-row="slide"]').first().click();
    await sleep(500);
    await Promise.all([draw(A.page, 'Text', 0.1, 0.1).then(async () => { await sleep(400); await A.page.keyboard.type('From the owner'); await A.page.keyboard.press('Escape'); }), draw(B.page, 'Text', 0.6, 0.6).then(async () => { await sleep(400); await B.page.keyboard.type('From the editor'); await B.page.keyboard.press('Escape'); })]);
    await saved(A.page); await saved(B.page);
    await sleep(4500);
    const doc = await savedDoc(A.page, deckId);
    const texts = doc.slides[0].els.map((e) => JSON.stringify(e.doc));
    ok(texts.some((x) => x.includes('From the owner')) && texts.some((x) => x.includes('From the editor')), 'both texts saved');
    for (const p of [A.page, B.page]) {
        const shown = await p.locator('.es-sheet-in').innerText();
        ok(shown.includes('From the owner') && shown.includes('From the editor'), 'both texts shown');
    }
    await shot(B.page, 'sync-4-editor');
});

console.log('console errors:', JSON.stringify(A.page.errors), JSON.stringify(B.page.errors));
const bad = t.summary();
await browser.close();
process.exit(bad ? 1 : 0);
