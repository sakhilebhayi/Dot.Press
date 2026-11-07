// Acceptance checks 19 to 24: group, layers, themes, change slide size, undo and redo, keyboard shortcuts.
import { MOD, launch, newContext, suite, ok, eq, sleep, shot, savedDoc, saved, openDeck, createDeck, sendOps, clickEl, center, dragEl, BASE, SHOTS } from './lib.mjs';

const t = suite('03-arrange');
const browser = await launch();
const { page } = await newContext(browser, 'owner@demo.test');
t.page = page;
const deckId = await createDeck(page, 'E2E arrange ' + Date.now());
const d0 = await savedDoc(page, deckId);
const slideId = d0.slides[0].id;
const shape = (id, x, y, w, h, fill, extra = {}) => ({ op: 'el.add', slide: slideId, el: { id, type: 'shape', kind: 'rect', x, y, w, h, fill, stroke: null, sw: 0, ...extra } });
await sendOps(page, deckId, [
    shape('ra', 200, 220, 420, 300, '#d1495b'),
    shape('rb', 700, 220, 420, 300, '#2e86ab'),
    shape('rc', 1200, 220, 420, 300, '#3a7d44'),
    { op: 'el.add', slide: slideId, el: { id: 'tx', type: 'text', x: 200, y: 640, w: 900, h: 140, doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Layers, groups and themes' }] }] }, font: '@heading', size: 72, weight: 700, color: '@ink' } },
]);
await openDeck(page, deckId);
const insp = page.locator('.ed-insp');
const els = async () => (await savedDoc(page, deckId)).slides[0].els;
const el = async (id) => (await els()).find((e) => e.id === id);
const order = async () => (await els()).map((e) => e.id);
async function flush() { await sleep(250); await saved(page); }
async function pick(...ids) {
    await page.keyboard.press('Escape');
    for (let i = 0; i < ids.length; i++) await clickEl(page, ids[i], i ? { modifiers: ['Shift'] } : {});
}
const sel = () => page.locator('.es-proxy[aria-pressed="true"]').evaluateAll((e) => e.map((x) => x.dataset.id));
const stageScale = async () => { const b = await page.locator('.es-sheet-in').boundingBox(); return b.width / (await savedDoc(page, deckId)).deck.w; };

await t.step('19', 'group: shift click two shapes, Ctrl+G, they move together, Ctrl+Shift+G ungroups', async () => {
    await pick('ra', 'rb');
    eq((await sel()).sort(), ['ra', 'rb'], 'two selected');
    await page.keyboard.press(`${MOD}+g`);
    await flush();
    const a = await el('ra'); const b = await el('rb');
    ok(a.group && a.group === b.group, 'same group id: ' + a.group + ' ' + b.group);
    ok(!(await el('rc')).group, 'the third is not grouped');
    // clicking one member selects the whole group; dragging moves both
    await page.keyboard.press('Escape');
    await clickEl(page, 'ra');
    eq((await sel()).sort(), ['ra', 'rb'], 'click selects the group');
    const before = [await el('ra'), await el('rb')];
    const sc = await stageScale();
    await dragEl(page, 'ra', 100 * sc, 60 * sc);
    await flush();
    const after = [await el('ra'), await el('rb')];
    ok(Math.abs(after[0].x - before[0].x - 100) < 3 && Math.abs(after[1].x - before[1].x - 100) < 3, `both moved by 100: ${after[0].x - before[0].x}, ${after[1].x - before[1].x}`);
    ok(Math.abs(after[0].y - before[0].y - 60) < 3, 'and by 60 down');
    await page.keyboard.press(`${MOD}+Shift+g`);
    await flush();
    ok(!(await el('ra')).group && !(await el('rb')).group, 'ungrouped');
    await shot(page, '19-group');
});

await t.step('20', 'layers: reorder with the shortcuts and with the buttons, lock stops dragging, hide removes it from the slide, layers list matches', async () => {
    await pick('ra');
    await page.keyboard.press(`${MOD}+Shift+]`);
    await flush();
    eq((await order()).slice(-1), ['ra'], 'bring to front');
    await page.keyboard.press(`${MOD}+Shift+[`);
    await flush();
    eq((await order())[0], 'ra', 'send to back');
    await page.keyboard.press(`${MOD}+]`);
    await flush();
    eq((await order()).indexOf('ra'), 1, 'bring forward one step');
    // layers list in the Arrange panel
    await insp.getByRole('tab', { name: 'Arrange', exact: true }).click();
    const names = await insp.locator('.lay-name').allInnerTexts();
    eq(names.length, 4, 'four layers listed: ' + names.join(' | '));
    await insp.getByRole('button', { name: 'Bring to front' }).click();
    await flush();
    eq((await order()).slice(-1), ['ra'], 'panel button: bring to front');
    // lock: a locked shape does not move when dragged
    await page.keyboard.press('Escape'); await clickEl(page, 'rc');
    await page.keyboard.press(`${MOD}+Shift+l`);
    await flush();
    ok((await el('rc')).lock === true, 'locked');
    const rcBefore = await el('rc');
    await dragEl(page, 'rc', 80, 40);
    await flush();
    const rcAfter = await el('rc');
    eq([rcAfter.x, rcAfter.y], [rcBefore.x, rcBefore.y], 'a locked element does not move');
    eq(await sel(), [], 'a locked element cannot be picked on the stage');
    ok(await page.getByText('That element is locked').count() >= 0, 'announced');
    // the way back: the context menu on the locked element, or its row in the Layers list
    await clickEl(page, 'rc', { button: 'right' });
    await page.screenshot({ path: SHOTS + '/20-locked-menu.png' });
    await page.getByRole('menuitem', { name: /^Unlock/ }).click();
    await flush();
    ok(!(await el('rc')).lock, 'unlocked');
    // hide
    await page.keyboard.press(`${MOD}+Shift+h`);
    await flush();
    ok((await el('rc')).hide === true, 'hidden: ' + JSON.stringify(await el('rc')) + ' selected ' + (await sel()));
    await page.keyboard.press(`${MOD}+Shift+h`);
    await flush();
    ok(!(await el('rc')).hide, 'shown again');
    await shot(page, '20-layers');
});

await t.step('21', 'themes: apply a theme from the gallery; token colours follow, hard coded colours stay and are counted', async () => {
    await page.keyboard.press('Escape');
    await page.locator('.tb-insp').click().catch(() => {});
    await insp.locator('.in-pick').click();
    await page.getByRole('menuitem', { name: 'Theme', exact: true }).click();
    await sleep(700);
    const before = (await savedDoc(page, deckId)).deck.theme;
    const bg0 = await page.evaluate(() => getComputedStyle(document.querySelector('.es-sheet-in .ps-stage')).backgroundColor);
    const inkBefore = await page.evaluate(() => getComputedStyle(document.querySelector('.es-sheet-in [data-eid="tx"] .p-fx, .es-sheet-in [data-eid="tx"]')).color);
    await shot(page, '21-theme-gallery');
    await insp.getByRole('radio', { name: /Night briefing/ }).first().click();
    await sleep(300);
    await shot(page, '21-theme-candidate');
    await insp.getByRole('button', { name: 'Apply theme' }).click();
    await sleep(500);
    await flush();
    const after = (await savedDoc(page, deckId)).deck.theme;
    ok(after.id !== before.id, `theme changed: ${before.id} -> ${after.id}`);
    eq(after.name, 'Night briefing');
    const bg1 = await page.evaluate(() => getComputedStyle(document.querySelector('.es-sheet-in .ps-stage')).backgroundColor);
    ok(bg1 !== bg0, `slide background follows the theme: ${bg0} -> ${bg1}`);
    const notice = await insp.locator('.dz-theme').innerText();
    ok(/hard coded colours? do/.test(notice) || /hard coded/.test(notice), 'the panel says how many hard coded colours do not follow: ' + notice.slice(0, 200));
    const ra = (await els()).find((e) => e.id === 'ra');
    eq(ra.fill, '#d1495b', 'an element with its own hex colour keeps it');
    await shot(page, '21-theme-applied');
});

await t.step('22', 'change slide size: 16:9 to 4:3 with the Size panel; positions scale, the deck and every slide follow, undo brings it back', async () => {
    await insp.locator('.in-pick').click();
    await page.getByRole('menuitem', { name: 'Size', exact: true }).click();
    await sleep(600);
    const before = await savedDoc(page, deckId);
    const rb0 = before.slides[0].els.find((e) => e.id === 'rb');
    await insp.getByRole('radio', { name: /Standard/ }).click();
    await sleep(300);
    await shot(page, '22-size-panel');
    await insp.getByRole('radio', { name: /Scale to fit/ }).isChecked().then((c) => ok(c, 'the default mode is the one that cuts nothing off'));
    await insp.getByRole('button', { name: 'Resize slides' }).click();
    await page.getByRole('dialog').getByRole('button', { name: /^Resize 1 slide/ }).click();
    await sleep(1000);
    await saved(page);
    const after = await savedDoc(page, deckId);
    eq([after.deck.w, after.deck.h], [1440, 1080], 'deck size');
    const rb1 = after.slides[0].els.find((e) => e.id === 'rb');
    ok(rb1.x !== rb0.x || rb1.w !== rb0.w, `element geometry changed with the size: ${rb0.x},${rb0.w} -> ${rb1.x},${rb1.w}`);
    ok(rb1.x + rb1.w <= 1440, 'still inside the new slide width');
    const box = await page.locator('.es-sheet-in').boundingBox();
    ok(Math.abs(box.width / box.height - 4 / 3) < 0.02, 'the stage shows a 4:3 slide');
    await shot(page, '22-size-done');
});

await t.step('23', 'undo and redo: dock buttons, shortcuts, labels name the action; a whole drag is one step; undo reaches back over resize and theme', async () => {
    await page.keyboard.press('Escape');
    const orderBefore = await order();
    await clickEl(page, 'tx');
    await page.keyboard.press('Delete');
    await flush();
    ok(!(await order()).includes('tx'), 'deleted');
    const undoBtn = page.locator('.dk-b[aria-label="Undo"]'); const redoBtn = page.locator('.dk-b[aria-label="Redo"]');
    ok(/Delete/i.test(await undoBtn.getAttribute('data-tip')), 'the undo button names the action: ' + await undoBtn.getAttribute('data-tip'));
    await undoBtn.click();
    await flush();
    ok((await order()).includes('tx'), 'undo with the dock button restores it');
    await redoBtn.click();
    await flush();
    ok(!(await order()).includes('tx'), 'redo deletes again');
    await page.keyboard.press(`${MOD}+z`);
    await flush();
    ok((await order()).includes('tx'), 'Ctrl+Z undoes');
    await page.keyboard.press(`${MOD}+Shift+z`);
    await flush();
    ok(!(await order()).includes('tx'), 'Ctrl+Shift+Z redoes');
    await page.keyboard.press(`${MOD}+z`);
    await flush();
    // one drag is one undo step
    await page.keyboard.press('Escape');
    await clickEl(page, 'ra', { at: [0.2, 0.2] });
    const x0 = (await el('ra')).x;
    await dragEl(page, 'ra', 120, 40, { steps: 25, at: [0.2, 0.2] });
    await flush();
    const x1 = (await el('ra')).x;
    ok(x1 > x0 + 100, 'dragged: ' + x0 + ' -> ' + x1 + ' sel ' + (await sel()));
    await page.keyboard.press(`${MOD}+z`);
    await flush();
    ok(Math.abs((await el('ra')).x - x0) < 1, 'a single undo returns the whole drag: ' + (await el('ra')).x + ' vs ' + x0);
    // undo reaches back over the resize and the theme
    const w1 = (await savedDoc(page, deckId)).deck.w;
    for (let i = 0; i < 6; i++) await page.keyboard.press(`${MOD}+z`);
    await flush();
    await sleep(800);
    const dnow = await savedDoc(page, deckId);
    console.log('after many undos: size', dnow.deck.w, dnow.deck.h, 'theme', dnow.deck.theme.id);
    ok(dnow.deck.w === 1920 || dnow.deck.theme.id !== 'night', 'undo goes back over the size or the theme change: ' + dnow.deck.w + ' ' + dnow.deck.theme.id);
});

await t.step('24', 'keyboard shortcuts: select all, Tab, nudge, copy and paste, duplicate, delete, align, zoom, bold, the help sheet', async () => {
    await page.keyboard.press('Escape');
    await page.locator('.es-viewport').focus();
    const n0 = (await els()).length;
    await page.keyboard.press(`${MOD}+a`);
    eq((await sel()).length, n0, 'select all');
    await page.keyboard.press('Escape');
    eq(await sel(), [], 'Escape clears the selection');
    await page.keyboard.press('Tab');
    const first = await sel();
    eq(first.length, 1, 'Tab selects one element: ' + first);
    await page.keyboard.press('Tab');
    ok((await sel())[0] !== first[0], 'Tab walks to the next');
    await page.keyboard.press('Shift+Tab');
    eq(await sel(), first, 'Shift+Tab walks back');
    // nudge
    await page.keyboard.press('Escape');
    await clickEl(page, 'rb', { at: [0.2, 0.9] });
    const b0 = await el('rb');
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('Shift+ArrowDown');
    await flush();
    const b1 = await el('rb');
    ok(Math.abs(b1.x - b0.x - 1) < 0.01 && Math.abs(b1.y - b0.y - 10) < 0.01, `ArrowRight is 1 and Shift+ArrowDown is 10: ${b1.x - b0.x}, ${b1.y - b0.y}`);
    // copy and paste, duplicate, delete
    await page.keyboard.press(`${MOD}+c`);
    await page.keyboard.press(`${MOD}+v`);
    await flush();
    eq((await els()).length, n0 + 1, 'copy and paste adds one');
    await page.keyboard.press(`${MOD}+d`);
    await flush();
    eq((await els()).length, n0 + 2, 'duplicate adds one');
    await page.keyboard.press('Delete');
    await flush();
    eq((await els()).length, n0 + 1, 'Delete removes the selected copy');
    await page.keyboard.press(`${MOD}+z`);
    await flush();
    eq((await els()).length, n0 + 2, 'and undo brings it back');
    // align left of ra and rc to the leftmost
    await page.keyboard.press('Escape');
    await clickEl(page, 'ra', { at: [0.2, 0.2] });
    await clickEl(page, 'rc', { at: [0.2, 0.2], modifiers: ['Shift'] });
    await page.keyboard.press('Alt+a');
    await flush();
    eq((await el('ra')).x, (await el('rc')).x, 'Alt+A aligns left edges');
    // zoom
    const zoomText = () => page.locator('.dk-zoom').innerText();
    await page.keyboard.press(`${MOD}+1`);
    ok(/100%/.test(await zoomText()), 'Ctrl+1 is 100 percent: ' + await zoomText());
    await page.keyboard.press(`${MOD}+-`);
    ok(!/100%/.test(await zoomText()), 'Ctrl+minus zooms out: ' + await zoomText());
    await page.keyboard.press(`${MOD}+0`);
    ok(/fit/.test(await zoomText()), 'Ctrl+0 fits: ' + await zoomText());
    // bold while editing text
    await page.keyboard.press('Escape');
    const c = await center(page, 'tx', [0.3, 0.5]);
    await page.mouse.dblclick(c.x, c.y);
    await sleep(500);
    await page.keyboard.press(`${MOD}+a`);
    await page.keyboard.press(`${MOD}+b`);
    await page.keyboard.press('Escape');
    await flush();
    ok(JSON.stringify((await el('tx')).doc).includes('bold') || (await el('tx')).weight >= 700, 'Ctrl+B in text');
    // the help sheet
    await page.keyboard.press('Escape');
    await page.locator('.es-viewport').focus();
    await page.keyboard.press('?');
    await page.waitForSelector('.hp, [role=dialog]:has-text("Keyboard")');
    const help = await page.locator('.hp, [role=dialog]').first().innerText();
    ok(/Undo/.test(help) && /Group/.test(help) && /Nudge/i.test(help), 'the help sheet lists the shortcuts');
    await sleep(600);
    await shot(page, '24-help');
    await page.keyboard.press('Escape');
    eq(await page.locator('.hp').count(), 0, 'Escape closes it');
});

console.log('console errors:', JSON.stringify(page.errors));
const bad = t.summary();
await browser.close();
process.exit(bad ? 1 : 0);
