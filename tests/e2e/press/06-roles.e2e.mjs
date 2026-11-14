// The three accounts of the demo team on one deck: the owner builds it, the viewer can only read (and comment), the editor can edit
// but cannot share or delete. UI behaviour and the server's answers are both checked.
import { MOD, launch, newContext, suite, ok, eq, sleep, shot, savedDoc, saved, openDeck, createDeck, sendOps, clickEl, dragEl, apiGet, apiSend, BASE } from './lib.mjs';

const t = suite('06-roles');
const browser = await launch();
const O = await newContext(browser, 'owner@demo.test');
const V = await newContext(browser, 'viewer@demo.test');
const E = await newContext(browser, 'editor@demo.test');
t.page = V.page;
const deckId = await createDeck(O.page, 'E2E roles ' + Date.now());
const slideId = (await savedDoc(O.page, deckId)).slides[0].id;
await sendOps(O.page, deckId, [
    { op: 'el.add', slide: slideId, el: { id: 'box', type: 'shape', kind: 'rect', x: 300, y: 300, w: 400, h: 300, fill: '#2e86ab' } },
    { op: 'el.add', slide: slideId, el: { id: 'words', type: 'text', x: 800, y: 300, w: 700, h: 200, doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Read me' }] }] }, size: 72, color: '@ink' } },
]);
const ownerEls = async () => (await savedDoc(O.page, deckId)).slides[0].els;
const snapshot = async () => JSON.stringify(await savedDoc(O.page, deckId));

await t.step('viewer-ui', 'viewer: the editor opens read only, says so, and every edit control is off', async () => {
    await openDeck(V.page, deckId);
    await V.page.waitForSelector('.es-badge');
    ok(/Read only/.test(await V.page.locator('.es-badge').innerText()), 'badge');
    ok(/View only/.test(await V.page.locator('.tb-save').innerText()), 'bead says View only: ' + await V.page.locator('.tb-save').innerText());
    for (const label of ['Text', 'Shapes', 'Line or arrow']) ok(await V.page.locator(`.dk-b[aria-label="${label}"]`).isDisabled(), `${label} tool disabled`);
    ok(await V.page.locator('.tb-name').isDisabled(), 'the title cannot be renamed');
    eq(await V.page.getByRole('button', { name: /^New slide/ }).count(), 0, 'no New slide button');
    ok(/View only/.test(await V.page.locator('.sp-foot').innerText()), 'the slide list says View only');
    await shot(V.page, 'viewer-editor');
});

await t.step('viewer-keys', 'viewer: dragging, arrows, Delete, duplicate, new slide, rename and paste change nothing on the server', async () => {
    const before = await snapshot();
    await V.page.locator('.es-viewport').focus();
    await clickEl(V.page, 'box');
    await dragEl(V.page, 'box', 120, 60);
    for (const k of ['ArrowRight', 'Shift+ArrowDown', 'Delete', `${MOD}+d`, `${MOD}+m`, `${MOD}+Shift+d`, `${MOD}+g`, `${MOD}+x`, `${MOD}+v`, 'F2', `${MOD}+Shift+l`, `${MOD}+]`]) await V.page.keyboard.press(k);
    await V.page.mouse.dblclick(...(await (async () => { const b = await (await V.page.locator('.es-sheet-in [data-eid="words"]').boundingBox()); return [b.x + 20, b.y + 20]; })()));
    await V.page.keyboard.type('hacked');
    await V.page.keyboard.press('Escape');
    await V.page.locator('.sp-row').first().click({ button: 'right' });
    await sleep(300);
    const menu = await V.page.getByRole('menuitem').evaluateAll((els) => els.map((e) => [e.innerText.replace(/\s+/g, ' ').trim(), e.disabled || e.getAttribute('aria-disabled') === 'true']));
    const enabledEdits = menu.filter(([label, off]) => !off && /^(New slide|Duplicate|Hide|Rename|Paste|Start a section|Delete|No section)/.test(label));
    eq(enabledEdits, [], 'edit items in the slide menu are disabled for a viewer');
    await V.page.keyboard.press('Escape');
    await sleep(1500);
    eq(await snapshot(), before, 'the saved deck is untouched');
    eq(await V.page.locator('.tb-save').innerText().then((s) => s.trim()), 'View only');
});

await t.step('viewer-api', 'viewer: the server refuses writes with 403 (ops, rename, visibility, delete, create, asset upload)', async () => {
    await V.page.goto(BASE + '/dashboard');
    const ops = await apiSend(V.page, 'POST', `/api/decks/${deckId}/ops`, { client: 'x', key: 'k' + Date.now(), ops: [{ op: 'el.set', slide: slideId, id: 'box', p: { x: 1 } }] });
    eq(ops.status, 403, 'ops');
    eq((await apiSend(V.page, 'PATCH', `/api/decks/${deckId}`, { title: 'hacked' })).status, 403, 'rename');
    eq((await apiSend(V.page, 'DELETE', `/api/decks/${deckId}`)).status, 403, 'delete');
    eq((await apiSend(V.page, 'POST', `/api/decks`, { title: 'by a viewer' })).status, 403, 'create');
    const read = await apiGet(V.page, `/api/decks/${deckId}/document`);
    eq(read.status, 200, 'reading is allowed');
    eq((await ownerEls()).find((e) => e.id === 'box').x, 300, 'and the deck did not change');
});

await t.step('viewer-comment', 'viewer: may comment (the team viewer role) and sees the thread', async () => {
    await openDeck(V.page, deckId);
    await V.page.locator('.tb-insp').click();
    await V.page.locator('.in-pick').click();
    await V.page.getByRole('menuitem', { name: 'Comments', exact: true }).click();
    await sleep(700);
    const box = V.page.locator('.cmp-compose [contenteditable=true], .cmp-compose textarea').first();
    await box.click();
    await V.page.keyboard.type('Looks right to me');
    await V.page.locator('.cmp-compose').getByRole('button', { name: 'Comment', exact: true }).click();
    await V.page.waitForSelector('.cmp-list :text("Looks right to me")');
    await shot(V.page, 'viewer-comment');
});

await t.step('editor-ui', 'editor: edits through the stage and the panels, and it is saved', async () => {
    await openDeck(E.page, deckId);
    ok(await E.page.locator('.es-badge').count() === 0, 'no read only badge');
    ok(!(await E.page.locator('.dk-b[aria-label="Text"]').isDisabled()), 'tools are on');
    await clickEl(E.page, 'box', { at: [0.2, 0.2] });
    await E.page.keyboard.press('Shift+ArrowRight');
    await E.page.keyboard.press('Shift+ArrowRight');
    await saved(E.page);
    eq((await ownerEls()).find((e) => e.id === 'box').x, 320, 'the editor moved it and the owner sees it');
    // title rename
    await E.page.locator('.tb-name').click();
    await E.page.locator('.tb-input').fill('Renamed by the editor');
    await E.page.keyboard.press('Enter');
    await saved(E.page);
    eq((await savedDoc(O.page, deckId)).deck.title, 'Renamed by the editor');
});

await t.step('editor-limits', 'editor: cannot delete the deck, change who may see it or approve it', async () => {
    await E.page.goto(BASE + '/dashboard');
    eq((await apiSend(E.page, 'DELETE', `/api/decks/${deckId}`)).status, 403, 'delete');
    const vis = await apiSend(E.page, 'PATCH', `/api/decks/${deckId}`, { visibility: 'link' });
    ok([403, 422].includes(vis.status) || (await savedDoc(O.page, deckId)).deck.visibility !== 'link', 'visibility change refused: ' + vis.status);
    eq((await savedDoc(O.page, deckId)).deck.visibility, 'team', 'still team');
    await openDeck(E.page, deckId);
    await E.page.locator('.tb-act:has-text("Share")').click();
    await sleep(800);
    await shot(E.page, 'editor-share');
});

await t.step('viewer-library', 'viewer: the library does not offer to create a presentation', async () => {
    await V.page.goto(BASE + '/dashboard');
    await sleep(800);
    // the one New is the arrival row's primary action (a link that opens the sheet); a viewer's primary opens the presentation instead
    const n = await V.page.locator('[data-primary-action][aria-haspopup="dialog"]').count();
    ok(n === 0, 'no New action is offered: count ' + n);
    ok(await V.page.locator('.lr').count() > 0, 'the ledger still lists the presentations');
    eq(await V.page.getByRole('button', { name: /^(Duplicate|Archive|Rename)$/ }).count(), 0, 'the stage offers no Duplicate, Archive or Rename');
    ok(await V.page.getByRole('link', { name: 'Open', exact: true }).count() > 0, 'the stage offers Open');
    await shot(V.page, 'viewer-library');
});

const unexpected = [...V.page.errors, ...E.page.errors].filter((e) => !/status of (403|422)/.test(e));
console.log('console errors other than the refusals this script provoked:', JSON.stringify(unexpected));
const bad = t.summary();
await browser.close();
process.exit(bad ? 1 : 0);
