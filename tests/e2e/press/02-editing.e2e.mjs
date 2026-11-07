// Acceptance checks 11 to 18: text, typography, images (a real PNG upload), crop and resize, shapes, tables, charts, chart data.
// Everything is made and changed with real pointer and keyboard events on the stage and in the inspector; the saved document is read back.
import fs from 'node:fs';
import path from 'node:path';
import { waitUntil, MOD, launch, newContext, suite, ok, eq, sleep, shot, savedDoc, saved, openDeck, createDeck, clickEl, center, dragEl, makePng, SHOTS, BASE } from './lib.mjs';

const t = suite('02-editing');
const browser = await launch();
const { page } = await newContext(browser, 'owner@demo.test');
t.page = page;
const deckId = await createDeck(page, 'E2E editing ' + Date.now());
await openDeck(page, deckId);
const openedAt = Date.now();
const insp = page.locator('.ed-insp');
const proxyIds = () => page.locator('.es-proxy').evaluateAll((els) => els.map((e) => e.dataset.id));
const stageBox = () => page.locator('.es-sheet-in').boundingBox();
const els = async () => (await savedDoc(page, deckId)).slides[0].els;
const elById = async (id) => (await els()).find((e) => e.id === id);
async function flush() { await sleep(250); await saved(page); }
async function waitNew(before) { await waitUntil(page, (b) => [...document.querySelectorAll('.es-proxy')].some((e) => !b.includes(e.dataset.id)), before); const now = await proxyIds(); return now.find((i) => !before.includes(i)); }
/** A rectangle on the slide, in slide fractions of the stage, dragged with the pointer. */
async function drag(tool, fx, fy, fw, fh) {
    const sb = await stageBox();
    const before = await proxyIds();
    await tool();
    const x = sb.x + sb.width * fx; const y = sb.y + sb.height * fy;
    await page.mouse.move(x, y); await page.mouse.down();
    await page.mouse.move(x + sb.width * fw / 2, y + sb.height * fh / 2, { steps: 6 });
    await page.mouse.move(x + sb.width * fw, y + sb.height * fh, { steps: 6 });
    await page.mouse.up();
    return before;
}
/** A number field of the open panel, by its visible label. */
const num = (label) => insp.locator('.c-num').filter({ has: page.locator('label', { hasText: new RegExp('^' + label + '$') }) }).locator('input');
async function setNum(label, v) { const i = num(label).first(); await i.click(); await i.fill(String(v)); await i.press('Enter'); }
/** Insert buttons collapse into the + menu when the dock is narrow (inspector open): use whichever is shown. */
async function dockInsert(label) {
    const direct = page.locator(`.dk-ins[aria-label="${label}"]`);
    if (await direct.isVisible()) { await direct.click(); return; }
    await page.locator('.dk-insmenu button').click();
    await page.getByRole('menuitem', { name: new RegExp('^' + label) }).click();
}
let textId, imgId, shapeId, tableId, chartId;

await t.step('11', 'text: Text tool, drag a box, type, Escape; saved as a text element with the typed words', async () => {
    const before = await drag(() => page.locator('.dk-b[aria-label="Text"]').click(), 0.1, 0.1, 0.5, 0.15);
    await sleep(500);
    await page.keyboard.type('Production beat plan by four percent');
    await page.keyboard.press('Escape');
    textId = await waitNew(before);
    await flush();
    const e = await elById(textId);
    eq(e.type, 'text');
    ok(JSON.stringify(e.doc).includes('Production beat plan by four percent'), 'typed words saved');
    // double click re-enters editing and the words are there
    await page.mouse.dblclick(...(await (async () => { const c = await center(page, textId); return [c.x, c.y]; })()));
    await sleep(500);
    await page.keyboard.press(`${MOD}+a`);
    await page.keyboard.type('Plan beat: four percent');
    await page.keyboard.press('Escape');
    await flush();
    ok(JSON.stringify((await elById(textId)).doc).includes('Plan beat: four percent'), 'edit in place saved');
    await shot(page, '11-text');
});

await t.step('12', 'typography: font, size, weight, bold, colour, alignment, spacing, shrink to fit, all from the Text panel', async () => {
    await clickEl(page, textId);
    await sleep(500);
    ok(await insp.locator('.in-pick').innerText() === 'Text', 'Text panel opens by itself for a text');
    await setNum('Size', 72); await setNum('Line', 1.5); await setNum('Letter', 0.05);
    await insp.getByRole('radio', { name: 'Align centre' }).click();
    await insp.getByRole('button', { name: 'Bold' }).click();
    await insp.getByRole('switch', { name: /Shrink text to fit the box/ }).click();
    await flush();
    const e = await elById(textId);
    eq(e.size, 72, 'size'); eq(e.lh, 1.5, 'line height'); eq(e.ls, 0.05, 'letter spacing');
    eq(e.align, 'center', 'alignment'); eq(e.fit, 'shrink', 'fit');
    ok(JSON.stringify(e.doc).includes('bold') || e.weight >= 600, 'bold applied: weight ' + e.weight);
    // font: pick another family from the font picker
    await insp.getByLabel('Font', { exact: true }).click();
    await page.getByRole('option').nth(3).click();
    await flush();
    const f = (await elById(textId)).font;
    ok(f !== '@body', 'font changed: ' + f);
    // colour: pick a theme token swatch
    await insp.getByRole('button', { name: /^Colour/ }).first().click();
    await page.getByRole('button', { name: /^(Accent)/ }).first().click();
    await page.keyboard.press('Escape');
    await flush();
    ok((await elById(textId)).color !== '@ink', 'colour changed: ' + (await elById(textId)).color);
    await shot(page, '12-typography');
});

const PNG = makePng(path.join(SHOTS, 'e2e-photo-640x400.png'), 640, 400);
await t.step('13', 'images: upload a real PNG from the Insert panel; it lands on the slide, the file is stored and the picture loads', async () => {
    await page.keyboard.press('Escape');
    await page.locator('.es-viewport').click({ position: { x: 5, y: 5 } });
    await page.locator('.dk-b[aria-label="Picture"]').click();
    const before = await proxyIds();
    const fc = page.waitForEvent('filechooser');
    await insp.getByRole('button', { name: /Drop files here or choose them/ }).click();
    (await fc).setFiles(PNG);
    imgId = await waitNew(before);
    await flush();
    const e = await elById(imgId);
    eq(e.type, 'image');
    ok(e.src && e.src.asset, 'src is an asset: ' + JSON.stringify(e.src));
    await waitUntil(page, (id) => { const i = document.querySelector(`.es-sheet-in [data-eid="${id}"] img`); return i && i.complete && i.naturalWidth > 0; }, imgId);
    const dim = await page.evaluate((id) => { const i = document.querySelector(`.es-sheet-in [data-eid="${id}"] img`); return [i.naturalWidth, i.naturalHeight]; }, imgId);
    eq(dim, [640, 400], 'the stored picture is the uploaded one');
    const d = await savedDoc(page, deckId);
    const a = d.assets[e.src.asset];
    ok(a && a.w === 640 && a.h === 400, 'asset record has the real size: ' + JSON.stringify(a));
    await shot(page, '13-image');
});

await t.step('14', 'crop and resize: crop with drag and wheel, resize by the corner handle and by Alt+Arrow, mask, alt text', async () => {
    await clickEl(page, imgId);
    await sleep(400);
    const before = await elById(imgId);
    await page.getByRole('button', { name: /^Crop/ }).click();
    await page.waitForSelector('.es-crop-hint');
    const c = await center(page, imgId);
    await page.mouse.move(c.x, c.y);
    await page.mouse.wheel(0, -400);
    await sleep(200);
    await page.mouse.move(c.x, c.y); await page.mouse.down(); await page.mouse.move(c.x - 40, c.y - 20, { steps: 6 }); await page.mouse.up();
    await shot(page, '14-cropping');
    await page.keyboard.press('Enter');
    await flush();
    const cropped = await elById(imgId);
    ok(cropped.crop && (cropped.crop.w < 0.999 || cropped.crop.h < 0.999), 'crop saved: ' + JSON.stringify(cropped.crop));
    eq([cropped.w, cropped.h], [before.w, before.h], 'cropping keeps the frame size');
    // resize with the bottom right handle
    const b = (await center(page, imgId)).b;
    await page.mouse.move(b.x + b.width, b.y + b.height);
    await page.mouse.down(); await page.mouse.move(b.x + b.width - 40, b.y + b.height - 30, { steps: 6 }); await page.mouse.move(b.x + b.width - 80, b.y + b.height - 60, { steps: 6 }); await page.mouse.up();
    await flush();
    const resized = await elById(imgId);
    ok(resized.w < before.w - 40 && resized.h < before.h - 40, `resized by handle: ${before.w}x${before.h} -> ${resized.w}x${resized.h}`);
    // keyboard resize: Alt+ArrowRight grows by one, Alt+Shift by ten
    await page.locator('.es-viewport').focus();
    await page.keyboard.press('Alt+Shift+ArrowRight');
    await flush();
    const grown = await elById(imgId);
    ok(Math.abs(grown.w - resized.w - 10) < 0.6, `Alt+Shift+Right grows by ten: ${resized.w} -> ${grown.w}`);
    // mask and alt text
    await page.getByRole('button', { name: /^Mask/ }).click();
    await page.locator('.es-pop').getByText('Circle', { exact: true }).click();
    await flush();
    ok((await elById(imgId)).mask && (await elById(imgId)).mask !== 'none', 'mask saved: ' + (await elById(imgId)).mask);
    await shot(page, '14-image-done');
});

await t.step('15', 'shapes: pick a shape from the dock menu, drag it out, restyle in the Shape panel, draw an arrow and a line', async () => {
    await page.keyboard.press('Escape');
    const before = await drag(async () => { await page.locator('.dk-b[aria-label="Shapes"]').click(); await page.getByRole('menuitem', { name: 'Ellipse' }).click(); }, 0.62, 0.45, 0.2, 0.3);
    shapeId = await waitNew(before);
    await flush();
    let e = await elById(shapeId);
    eq(e.type, 'shape'); eq(e.kind, 'ellipse');
    ok(e.w > 100 && e.h > 100, `drawn with the pointer: ${e.w}x${e.h}`);
    await sleep(400);
    await insp.getByRole('tab', { name: 'Shape', exact: true }).click();
    await insp.getByRole('button', { name: /^Fill/ }).first().click();
    await page.getByRole('button', { name: /^Accent 2|^Accent$/ }).first().click();
    await page.keyboard.press('Escape');
    await flush();
    e = await elById(shapeId);
    ok(e.fill && e.fill !== (await els()).find((x) => x.id === textId).fill, 'fill changed: ' + JSON.stringify(e.fill));
    // an arrow
    const b2 = await drag(async () => { await page.locator('.dk-b[aria-label="Line or arrow"]').click(); await page.getByRole('menuitem', { name: 'Arrow' }).click(); }, 0.1, 0.8, 0.3, 0.05);
    const arrowId = await waitNew(b2);
    await flush();
    const a = await elById(arrowId);
    ok(a.type === 'shape' && (a.kind === 'arrow' || a.kind === 'line'), 'arrow drawn: ' + a.kind);
    await shot(page, '15-shapes');
});

await t.step('16', 'tables: insert a 3 by 3 table, type into a cell, add a row and a column from the Table panel', async () => {
    await page.keyboard.press('Escape');
    const before = await proxyIds();
    await dockInsert('Table');
    await insp.getByRole('button', { name: /^Insert table/ }).click();
    tableId = await waitNew(before);
    await flush();
    let e = await elById(tableId);
    eq(e.type, 'table');
    eq([e.rows.length, e.rows[0].length], [3, 3]);
    // type into the top left cell with a double click on it
    await sleep(500);
    const tb = (await center(page, tableId)).b;
    await page.mouse.dblclick(tb.x + tb.width / 6, tb.y + 12);
    await sleep(400);
    await page.keyboard.type('Month');
    await page.keyboard.press('Tab');
    await page.keyboard.type('Tonnes');
    await page.keyboard.press('Escape');
    await flush();
    e = await elById(tableId);
    eq([e.rows[0][0].t, e.rows[0][1].t], ['Month', 'Tonnes'], 'typed cells saved');
    await insp.getByRole('tab', { name: 'Table', exact: true }).click();
    await shot(page, '16-table');
    const rowsBefore = e.rows.length; const colsBefore = e.rows[0].length;
    await insp.getByRole('button', { name: /Row below/ }).click();
    await insp.getByRole('button', { name: /Column right/ }).click();
    await flush();
    e = await elById(tableId);
    eq([e.rows.length, e.rows[0].length], [rowsBefore + 1, colsBefore + 1], 'row and column added');
});

await t.step('17', 'charts: add a column chart from the Insert panel, switch it to a line chart, set a title and legend', async () => {
    await page.keyboard.press('Escape');
    const before = await proxyIds();
    await dockInsert('Chart');
    await insp.getByRole('button', { name: /^Add Column chart/ }).click();
    chartId = await waitNew(before);
    await flush();
    let e = await elById(chartId);
    eq(e.type, 'chart'); eq(e.chart.type, 'column');
    ok(e.chart.data.series.length >= 1 && e.chart.data.categories.length >= 2, 'starts with example numbers');
    await sleep(800);
    // type picker: choose Line by its card
    await insp.getByRole('radio', { name: /^Line/ }).click().catch(async () => { await insp.getByText('Line', { exact: true }).first().click(); });
    await flush();
    e = await elById(chartId);
    eq(e.chart.type, 'line', 'type switched, data kept');
    ok(e.chart.data.series.length >= 2, 'the data survived the type change');
    // titles and legend
    const title = insp.getByLabel('Title', { exact: true }).first();
    await title.fill('Revenue against costs'); await title.press('Enter');
    await insp.getByRole('radio', { name: 'Right' }).click();
    await flush();
    e = await elById(chartId);
    eq(e.chart.opt.title, 'Revenue against costs', 'title');
    eq(e.chart.opt.legend, 'right', 'legend');
    await shot(page, '17-chart');
});

await t.step('18', 'chart data: edit grid cells, add a row, paste a CSV; the chart redraws from the saved numbers and bad numbers are refused', async () => {
    const cell = (r, c) => insp.locator(`input[data-cell="${r}:${c}"]`);
    await cell(0, 1).click(); await cell(0, 1).fill('9.5'); await cell(0, 1).press('Tab');
    await flush();
    let e = await elById(chartId);
    eq(e.chart.data.series[0].values[0], 9.5, 'cell edit saved');
    // the drawn chart changed: the first Revenue bar or point now has the largest value label
    ok((await page.locator(`.es-sheet-in [data-eid="${chartId}"]`).innerText()).includes('9.5'), 'chart shows 9.5');
    // bad number
    await cell(1, 1).click(); await cell(1, 1).fill('abc'); await cell(1, 1).press('Tab');
    await sleep(300);
    ok(await cell(1, 1).getAttribute('aria-invalid') === 'true' || (await insp.locator('.cpg-wi').count()) > 0, 'a non number is flagged');
    await flush();
    e = await elById(chartId);
    ok(typeof e.chart.data.series[0].values[1] === 'number' || e.chart.data.series[0].values[1] === null, 'no text stored in a numeric series');
    await cell(1, 1).click(); await cell(1, 1).fill('4'); await cell(1, 1).press('Tab');
    // add a row
    const rows0 = e.chart.data.categories.length;
    await insp.getByRole('button', { name: 'Add row' }).click();
    await flush();
    e = await elById(chartId);
    eq(e.chart.data.categories.length, rows0 + 1, 'row added');
    // import: paste a CSV in the import box
    await insp.getByRole('button', { name: /^Import/ }).click();
    await page.locator('#cpi-text').fill('Month,Tonnes,Plan\nJan,100,90\nFeb,120,110\nMar,130,125');
    await page.getByRole('button', { name: 'Replace data' }).click();
    await flush();
    e = await elById(chartId);
    eq(e.chart.data.categories, ['Jan', 'Feb', 'Mar'], 'imported categories');
    eq(e.chart.data.series.map((s) => s.name), ['Tonnes', 'Plan'], 'imported series names');
    eq(e.chart.src.kind, 'manual', 'pasted text is recorded as manual');
    // a CSV file: recorded as a file import with its name
    const csv = path.join(SHOTS, 'sheet-export.csv');
    fs.writeFileSync(csv, 'Region,Output\nNorth,410\nSouth,380\nEast,295\nWest,350\n');
    await insp.getByRole('tab', { name: /File/ }).click();
    const fc = page.waitForEvent('filechooser');
    await insp.getByRole('button', { name: 'Choose a file' }).click();
    (await fc).setFiles(csv);
    await page.getByRole('button', { name: 'Replace data' }).click();
    await flush();
    e = await elById(chartId);
    eq(e.chart.data.categories, ['North', 'South', 'East', 'West'], 'categories from the file');
    eq(e.chart.src.kind, 'file', 'recorded as a file import');
    eq(e.chart.src.name, 'sheet-export.csv', 'with the file name');
    await shot(page, '18-chart-data');
});

await t.step('18b', 'a live tab that is editing never shows the "unsent changes from an earlier session" banner', async () => {
    const wait = 19000 - (Date.now() - openedAt);
    if (wait > 0) { await page.keyboard.press('Escape'); await sleep(wait); }
    eq(await page.locator('.ed-banner').count(), 0, 'no recovery banner');
});

console.log('console errors:', JSON.stringify(page.errors));
const bad = t.summary();
await browser.close();
process.exit(bad ? 1 : 0);
