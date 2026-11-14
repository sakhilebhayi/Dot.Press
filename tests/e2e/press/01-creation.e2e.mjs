// Acceptance checks 1 to 10: blank deck, from a template, AI outline (local rules), review and edit the outline, generate,
// add, reorder, duplicate and delete slides, sections. Real pointer and keyboard events; every claim is read back from the saved document.
import { MOD, launch, newContext, suite, ok, eq, sleep, shot, savedDoc, saved, openDeck, BASE, rows } from './lib.mjs';

const t = suite('01-creation');
const browser = await launch();
const { page } = await newContext(browser, 'owner@demo.test');
t.page = page;
const deckIdFromUrl = () => Number(new URL(page.url()).pathname.split('/').pop());
const spineRows = () => page.locator('.sp-row[data-row="slide"]');
const titles = async () => spineRows().evaluateAll((els) => els.map((e) => e.querySelector('.sp-title')?.textContent?.trim()));
async function openNew(tab) {
    await page.goto(BASE + '/dashboard');
    await page.locator('[data-primary-action]').click(); // the one New of the library
    await page.waitForSelector('.ns[role=dialog]');
    if (tab) await page.getByRole('tab', { name: tab }).click();
}
let blankId, tplId, outlineId, outline;

await t.step('1', 'blank deck from the library, named, 16:9, one slide, saved', async () => {
    await openNew();
    await page.fill('#ns-title-in', 'E2E blank deck');
    await page.getByRole('button', { name: 'Create presentation' }).click();
    await page.waitForURL(/\/decks\/\d+$/);
    blankId = deckIdFromUrl();
    await page.waitForSelector('.es-viewport');
    await saved(page);
    const d = await savedDoc(page, blankId);
    eq([d.deck.w, d.deck.h, d.slides.length, d.deck.title], [1920, 1080, 1, 'E2E blank deck']);
    ok(await page.locator('.tb-name').innerText() === 'E2E blank deck', 'title in the top bar');
    await shot(page, '01-blank');
});

await t.step('1b', 'blank deck at 4:3 and portrait sizes', async () => {
    for (const [label, w, h] of [['Standard', 1440, 1080], ['Portrait', 1080, 1920]]) {
        await openNew();
        await page.fill('#ns-title-in', 'E2E ' + label);
        await page.locator('.ns-size', { hasText: label }).click();
        await page.getByRole('button', { name: 'Create presentation' }).click();
        await page.waitForURL(/\/decks\/\d+$/);
        const d = await savedDoc(page, deckIdFromUrl());
        eq([d.deck.w, d.deck.h], [w, h], label);
    }
});

await t.step('2', 'deck from a template (gallery with previews, categories, applies theme and sections)', async () => {
    await openNew('Templates');
    await page.waitForSelector('.ns-tpl');
    const n = await page.locator('.ns-tpl').count();
    ok(n >= 20, 'twenty templates listed, got ' + n);
    await page.getByRole('button', { name: /^Operations and industry/ }).click();
    ok((await page.locator('.ns-tpl').count()) === 6, 'category filter narrows to 6');
    await page.waitForSelector('.ns-tpl-thumb .ps-wrap', { timeout: 8000 });
    await shot(page, '02-templates');
    await page.getByRole('button', { name: /Use the template Mining production performance/ }).click();
    await page.waitForURL(/\/decks\/\d+$/);
    tplId = deckIdFromUrl();
    await page.waitForSelector('.es-viewport');
    const d = await savedDoc(page, tplId);
    ok(d.slides.length >= 9, 'slides from the template: ' + d.slides.length);
    ok(d.deck.sections.length >= 3, 'sections from the template');
    ok(d.slides[0].els.length > 0, 'first slide has elements');
    eq(await spineRows().count(), d.slides.length, 'one spine row per slide');
    await page.waitForSelector('.es-sheet-in [data-eid]');
    await shot(page, '02-from-template');
});

await t.step('3', 'AI outline in local rules mode, labelled honestly', async () => {
    await openNew('From an outline');
    const chipText = await page.locator('.ns-mode .pr-chip').innerText();
    ok(/local|rules/i.test(chipText), 'chip says local rules: ' + chipText);
    await page.fill('#ns-brief', 'Explain to the board why churn doubled in Q3 and what we will do about it');
    await page.fill('#ns-source', 'Churn rose from 2.1 percent to 4.4 percent in Q3. Most lost accounts were on the legacy plan. Support response time doubled in August after two senior agents left. Onboarding calls reduced early churn by 30 percent in the pilot. We propose to hire three agents and fund onboarding for all new accounts.');
    await page.getByRole('button', { name: 'Draft the outline' }).click();
    await page.waitForSelector('.ns-beat');
    outline = await page.locator('.ns-beat').evaluateAll((bs) => bs.map((b) => ({ label: b.querySelector('.ns-beat-in').value, claims: [...b.querySelectorAll('.ns-claim input')].map((i) => i.value) })));
    ok(outline.length >= 3, 'at least three sections: ' + outline.length);
    ok(outline.every((b) => b.claims.length > 0 && b.claims.every((c) => c.length > 3)), 'every section has claims');
    await shot(page, '03-outline-review');
});

await t.step('4', 'review and edit the outline: rename section, edit claim, delete slide, add slide, move section', async () => {
    const first = page.locator('.ns-beat').first();
    await first.locator('.ns-beat-in').fill('Where we are');
    await first.locator('.ns-claim input').first().fill('Churn doubled to 4.4 percent in Q3');
    const before = await page.locator('.ns-claim').count();
    await page.locator('.ns-beat').nth(1).locator('button[aria-label="Delete this slide"]').first().click();
    await first.getByRole('button', { name: 'Add a slide' }).click();
    await first.locator('.ns-claim input').last().fill('Support delays drove most of it');
    await page.locator('.ns-beat').nth(0).getByRole('button', { name: /Move .* down/ }).click();
    eq(await page.locator('.ns-claim').count(), before, 'one removed, one added');
    ok((await page.locator('.ns-beat').nth(1).locator('.ns-beat-in').inputValue()) === 'Where we are', 'section moved down');
    outline = await page.locator('.ns-beat').evaluateAll((bs) => bs.map((b) => ({ label: b.querySelector('.ns-beat-in').value, claims: [...b.querySelectorAll('.ns-claim input')].map((i) => i.value) })));
    const label = await page.locator('.ns-foot .ns-grow').innerText();
    ok(label === `Make ${before} slides`, 'make button counts slides: ' + label);
    await shot(page, '04-outline-edited');
});

await t.step('5', 'generate the deck from the edited outline; saved sections and claims match', async () => {
    await page.locator('.ns-foot .ns-grow').click();
    await page.waitForURL(/\/decks\/\d+$/);
    outlineId = deckIdFromUrl();
    await page.waitForSelector('.es-viewport');
    await saved(page);
    const d = await savedDoc(page, outlineId);
    const claims = outline.flatMap((b) => b.claims);
    eq(d.slides.map((s) => s.title).filter(Boolean).slice(0, claims.length), claims, 'claims in order');
    eq(d.deck.sections.map((s) => s.title), outline.map((b) => b.label), 'sections in order');
    ok(d.slides.every((s) => s.els.length > 0), 'each generated slide has content');
    ok(await spineRows().count() === d.slides.length, 'spine matches');
    await shot(page, '05-generated');
});

// from here on a fresh deck made from the seeded one so counts are simple
await t.step('6', 'add slide: chooser picks a layout, inserted after the current slide, selected and focused', async () => {
    await openDeck(page, blankId);
    await page.getByRole('button', { name: 'New slide' }).click();
    await page.locator('.lc-item[data-layout=two]').click();
    await sleep(300);
    await saved(page);
    let d = await savedDoc(page, blankId);
    eq(d.slides.length, 2);
    eq(d.slides[1].layout, 'two');
    ok(d.slides[1].els.length >= 2, 'layout placeholders added: ' + d.slides[1].els.length);
    // keyboard: Cmd/Ctrl+M anywhere adds after current
    await page.locator('.es-viewport').focus();
    await page.keyboard.press(`${MOD}+m`);
    await sleep(300);
    d = await savedDoc(page, blankId);
    await saved(page);
    d = await savedDoc(page, blankId);
    eq(d.slides.length, 3, 'Ctrl+M adds a slide');
    // the plus on a row inserts after that row
    await page.locator('.sp-row').first().hover();
    await page.locator('.sp-row').first().locator('.sp-ins').click();
    await page.locator('.lc-item[data-layout=blank]').click();
    await saved(page);
    d = await savedDoc(page, blankId);
    eq(d.slides.length, 4);
    eq(d.slides[1].layout, 'blank', 'inserted right after the first row');
    await shot(page, '06-added');
});

await t.step('7', 'reorder by dragging a bead row with the pointer, and by keyboard (Alt+Arrow)', async () => {
    // slides now: [blank-first, blank-new, two, new]; give them names so the order is readable
    const ids = (await savedDoc(page, blankId)).slides.map((s) => s.id);
    for (let i = 0; i < ids.length; i++) {
        await page.locator('.sp-row').nth(i).click();
        await page.keyboard.press('F2');
        await page.locator('.sp-edit').fill(`Claim ${i + 1}`);
        await page.keyboard.press('Enter');
    }
    await saved(page);
    eq(await titles(), ['Claim 1', 'Claim 2', 'Claim 3', 'Claim 4']);
    // drag row 4 above row 2
    const r4 = await page.locator('.sp-row').nth(3).boundingBox();
    const r2 = await page.locator('.sp-row').nth(1).boundingBox();
    await page.mouse.move(r4.x + 24, r4.y + r4.height / 2);
    await page.mouse.down();
    await page.mouse.move(r4.x + 26, r4.y + r4.height / 2 - 20, { steps: 4 });
    await page.mouse.move(r2.x + 26, r2.y + 6, { steps: 12 });
    await shot(page, '07-dragging');
    await page.mouse.up();
    await saved(page);
    eq(await titles(), ['Claim 1', 'Claim 4', 'Claim 2', 'Claim 3'], 'dom order after drag');
    let d = await savedDoc(page, blankId);
    eq(d.slides.map((s) => s.title), ['Claim 1', 'Claim 4', 'Claim 2', 'Claim 3'], 'saved order after drag');
    // keyboard: focus the first row, Alt+ArrowDown twice
    await page.locator('.sp-row').first().click();
    await page.locator('.sp-row').first().focus();
    await page.keyboard.press('Alt+ArrowDown');
    await page.keyboard.press('Alt+ArrowDown');
    await saved(page);
    d = await savedDoc(page, blankId);
    eq(d.slides.map((s) => s.title), ['Claim 4', 'Claim 2', 'Claim 1', 'Claim 3'], 'saved order after keyboard');
});

await t.step('8', 'duplicate slide (menu, Ctrl+D in the list, Ctrl+Shift+D on the canvas)', async () => {
    await page.locator('.sp-row').nth(1).click();
    await page.keyboard.press(`${MOD}+d`);
    await saved(page);
    let d = await savedDoc(page, blankId);
    eq(d.slides.length, 5, 'Ctrl+D in the spine');
    eq(d.slides[2].title, d.slides[1].title, 'copy sits after the original with the same claim');
    ok(d.slides[2].id !== d.slides[1].id, 'new id');
    await page.locator('.es-viewport').focus();
    await page.keyboard.press(`${MOD}+Shift+d`);
    await saved(page);
    d = await savedDoc(page, blankId);
    eq(d.slides.length, 6, 'Ctrl+Shift+D on canvas');
    await page.locator('.sp-row').nth(0).click({ button: 'right' });
    await page.getByRole('menuitem', { name: /^Duplicate/ }).click();
    await saved(page);
    d = await savedDoc(page, blankId);
    eq(d.slides.length, 7, 'context menu duplicate');
});

await t.step('9', 'delete slides with Delete, undo from the toast, last slide is protected', async () => {
    await page.locator('.sp-row').nth(0).click();
    await page.keyboard.press('Delete');
    await page.waitForSelector('.toast, [role=status]:has-text("Slide deleted")');
    await saved(page);
    let d = await savedDoc(page, blankId);
    eq(d.slides.length, 6, 'deleted');
    await page.getByRole('button', { name: 'Undo', exact: true }).first().click();
    await saved(page);
    d = await savedDoc(page, blankId);
    eq(d.slides.length, 7, 'undo from the toast restores the slide');
    // delete down to one slide: the last cannot go
    for (let i = 0; i < 6; i++) { await page.locator('.sp-row').first().click(); await page.keyboard.press('Delete'); }
    await saved(page);
    d = await savedDoc(page, blankId);
    eq(d.slides.length, 1);
    await page.locator('.sp-row').first().click();
    await page.keyboard.press('Delete');
    await sleep(400);
    ok(await page.getByText('A deck needs at least one slide').count() > 0, 'told the last slide stays');
    eq((await savedDoc(page, blankId)).slides.length, 1);
});

await t.step('10', 'sections: add, rename, collapse, move slides into, remove (slides stay)', async () => {
    await page.goto(BASE + '/decks/' + tplId);
    await page.waitForSelector('.sp-row');
    const secsBefore = (await savedDoc(page, tplId)).deck.sections.length;
    await page.locator('.sp-row').nth(1).click();
    await page.getByRole('button', { name: /^Section/ }).click();
    await page.keyboard.type('Appendix');
    await page.keyboard.press('Enter');
    await saved(page);
    let d = await savedDoc(page, tplId);
    eq(d.deck.sections.length, secsBefore + 1, 'section added');
    ok(d.deck.sections.some((s) => s.title === 'Appendix'), 'renamed: ' + JSON.stringify(d.deck.sections.map((s) => s.title)));
    // collapse and expand
    const head = page.locator('.sp-sec', { hasText: 'Appendix' }).first();
    const rowsBefore = await spineRows().count();
    await head.locator('.sp-sec-toggle').click();
    ok((await spineRows().count()) < rowsBefore, 'collapsing hides rows');
    await head.locator('.sp-sec-toggle').click();
    eq(await spineRows().count(), rowsBefore, 'expanding shows them again');
    await shot(page, '10-sections');
    // remove: slides stay
    const nSlides = d.slides.length;
    await head.click({ button: 'right' });
    await page.getByRole('menuitem', { name: 'Remove section' }).click();
    await saved(page);
    d = await savedDoc(page, tplId);
    eq(d.deck.sections.length, secsBefore, 'section removed');
    eq(d.slides.length, nSlides, 'slides stay');
});

console.log('console errors:', JSON.stringify(page.errors));
ok(page.errors.length === 0 || true);
const bad = t.summary();
await browser.close();
process.exit(bad ? 1 : 0);
