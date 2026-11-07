// Check 38 (PPTX import) and the round trip with check 39: independent python-pptx decks, the committed fixtures, a deck
// exported by Dot.Press and imported again, bad files, all through the Import PowerPoint box in the Export panel.
import fs from 'node:fs';
import path from 'node:path';
import { launch, context, login, api, expect, shot, summary, sleep, until, makeMedia, uploadAsset, sh, PY, OUT, APP } from './lib.mjs';
import { buildRichDeck, buildAcceptanceDeck } from './fixture.mjs';

const HERE = path.dirname(new URL(import.meta.url).pathname);
const FX = path.join(APP, 'tests/Fixtures/Press');
const GEN = path.join(OUT, 'pptx-fixtures');
sh(PY, ['-I', path.join(HERE, 'make_pptx.py'), GEN]);

const browser = await launch();
const oc = await context(browser, { acceptDownloads: true });
const page = await oc.newPage();
await login(page);
const base = (await buildAcceptanceDeck(page, { withMedia: false, title: 'Import host deck' }));
await page.goto(`/decks/${base.id}`);
await page.waitForSelector('.es-viewport'); await sleep(800);

async function importFile(file, { expectOk = true } = {}) {
    for (let attempt = 0; attempt < 4; attempt++) {
        await page.getByRole('button', { name: 'Export', exact: true }).click();
        await page.getByRole('menuitem', { name: 'Import PowerPoint', exact: true }).click();
        await page.locator('input[aria-label="PowerPoint file"]').waitFor({ state: 'attached' });
        await page.locator('input[aria-label="PowerPoint file"]').setInputFiles(file);
        await page.locator(expectOk ? '.xp-done, :text("The file was not imported")' : ':text("The file was not imported")').first().waitFor({ timeout: 90000 });
        const text = await page.locator('.xp-imp').innerText();
        const wait = text.match(/too many requests.*?(\d+) seconds/i);
        if (wait) {
            expect(true, 'the import limit answers in plain words and says when to come back: ' + text.replace(/\s+/g, ' ').slice(0, 90));
            await sleep((+wait[1] + 2) * 1000);
            await page.getByRole('button', { name: 'Choose another file' }).click();
            continue;
        }
        let deckId = null;
        if (expectOk) deckId = +(await page.getByRole('link', { name: 'Open the new deck' }).getAttribute('href')).split('/').pop();
        return { text, deckId };
    }
    throw new Error('import kept being rate limited');
}
const docOf = async (id) => (await api(page, 'GET', `/api/decks/${id}/document`)).json;
const textOf = (el) => (el.doc?.content || []).flatMap((p) => (p.content || []).flatMap((c) => c.type === 'text' ? [c.text] : (c.content || []).flatMap((q) => (q.content || []).map((t) => t.text)))).join('');
const allText = (slide) => slide.els.map(textOf).join(' | ');

// ---------------------------------------------------------------------------------------------- corporate deck by python-pptx
const r1 = await importFile(path.join(GEN, 'corporate.pptx'));
expect(/imported|slides/i.test(r1.text), 'check 38: the box reports the import: ' + r1.text.split('\n').filter(Boolean).slice(0, 3).join(' / '));
await shot(page, 'import-38-report');
const d1 = await docOf(r1.deckId);
expect(d1.slides.length === 6, `check 38: all 6 slides come across (${d1.slides.length})`);
expect(d1.deck.w === 1280 && d1.deck.h === 720, 'check 38: a 13.33 x 7.5 inch file becomes a 1280 x 720 deck (96 dpi, same 16:9 shape): ' + d1.deck.w + ' x ' + d1.deck.h);
expect(allText(d1.slides[0]).includes('Corporate review IMPORT-TITLE'), 'check 38: the title placeholder text arrives: ' + allText(d1.slides[0]));
expect(d1.slides[0].notes === 'IMPORT-NOTE-1 welcome' && d1.slides[4].notes === 'IMPORT-NOTE-5 shapes', 'check 38: speaker notes arrive');
expect(d1.slides[5].hidden === true, 'check 38: the hidden slide stays hidden');
const bul = d1.slides[1].els.find((e) => /Revenue grew/.test(textOf(e)));
const marks = JSON.stringify(bul?.doc);
expect(bul && /by twelve percent/.test(textOf(bul)) && marks.includes('"bold"') && marks.includes('https://example.com/report'), 'check 38: bold run and hyperlink arrive inside the text: ' + textOf(bul));
expect(/Revenue grew by twelve percent/.test(textOf(bul)), 'check 38: run spacing is intact ("Revenue grew by twelve percent")');
const tb = d1.slides[2].els.find((e) => e.type === 'table');
expect(tb && tb.rows[1][0].t === 'North' && tb.rows[2][2].t === '9', 'check 38: the table keeps its cells');
const charts = d1.slides[3].els.filter((e) => e.type === 'chart');
expect(charts.length === 2 && charts[0].chart.type.includes('column') && JSON.stringify(charts[0].chart.data.series[0].values) === '[5,7,6]' && charts[1].chart.type === 'pie', 'check 38: native charts become editable charts with their data: ' + charts.map((c) => c.chart.type));
const s5 = d1.slides[4].els;
expect(s5.some((e) => e.type === 'image') && s5.some((e) => e.rot === 15) && s5.filter((e) => e.group).length >= 2, 'check 38: picture, rotated shape and grouped shapes arrive (' + s5.map((e) => e.type + (e.group ? '*' : '')).join(',') + ')');
expect(s5.every((e) => e.origin === 'import'), 'check 38: everything is marked as imported content');
const fontEl = s5.find((e) => /Segoe UI/.test(textOf(e)) || /text box/.test(textOf(e)));
expect(!!fontEl, 'check 38: the text box arrives');
await page.getByRole('link', { name: 'Open the new deck' }).click();
await page.waitForSelector('.es-viewport'); await sleep(1500);
await shot(page, 'import-38-opened-deck');
const spineText = await page.locator('#ed-spine').innerText();
expect(/Corporate review/.test(spineText), 'check 38: the new deck opens in the editor with the imported slides in the Spine');
// look at the slides in the player thumbnails
await page.goto(`/decks/${r1.deckId}/present`); await page.waitForSelector('.pp-layer'); await sleep(1500);
await page.keyboard.press('g'); await sleep(1500);
await shot(page, 'import-38-overview');

// ---------------------------------------------------------------------------------------------- committed fixtures
await page.goto(`/decks/${base.id}`); await page.waitForSelector('.es-viewport'); await sleep(800);
for (const f of ['python_pptx_basic.pptx', 'python_pptx_freeform.pptx', 'python_pptx_unsupported.pptx']) {
    const r = await importFile(path.join(FX, f));
    const dd = await docOf(r.deckId);
    const honest = /left out|approximated|carried over/i.test(r.text);
    expect(dd.slides.length > 0 && honest, `check 38: ${f}: ${dd.slides.length} slides, and the report says what changed: ${r.text.replace(/\s+/g, ' ').slice(0, 200)}`);
    if (/unsupported/.test(f)) expect(/left out/.test(r.text) && !/Everything carried over/.test(r.text), 'check 38: unsupported content is reported, never silently dropped');
    await shot(page, 'import-38-' + f.replace('.pptx', ''));
    await page.getByRole('button', { name: 'Import another' }).click();
}
// 4:3
const r43 = await importFile(path.join(GEN, 'fourthree.pptx'));
const d43 = await docOf(r43.deckId);
expect(d43.deck.w === 960 && d43.deck.h === 720, `check 38: a 4:3 file becomes a 960 x 720 deck (${d43.deck.w} x ${d43.deck.h})`);
await page.getByRole('button', { name: 'Import another' }).click();

// ---------------------------------------------------------------------------------------------- bad files
fs.writeFileSync(path.join(GEN, 'notes.txt'), 'hello');
fs.writeFileSync(path.join(GEN, 'fake.pptx'), 'this is not a zip file at all');
const rb = await importFile(path.join(GEN, 'notes.txt'), { expectOk: false });
expect(/ending in \.pptx/.test(rb.text), 'check 38: a .txt is refused with a plain sentence: ' + rb.text.replace(/\s+/g, ' ').slice(0, 120));
await page.getByRole('button', { name: 'Choose another file' }).click();
await page.locator('input[aria-label="PowerPoint file"]').setInputFiles(path.join(GEN, 'fake.pptx'));
await page.getByText('The file was not imported').first().waitFor({ timeout: 30000 });
const fakeText = await page.locator('.xp-imp').innerText();
expect(!/Exception|stack|\/Users\//.test(fakeText) && /(not a|valid|PowerPoint|read)/i.test(fakeText), 'check 38: a file that is not a real .pptx says so without technical noise: ' + fakeText.replace(/\s+/g, ' ').slice(0, 160));
await shot(page, 'import-38-bad-file');
const before = (await api(page, 'GET', '/api/decks?per_page=60')).json.data.length;
expect(before > 0, 'library list works after failed imports');

// ---------------------------------------------------------------------------------------------- round trip: export from Dot.Press, import again
const m = makeMedia();
const ids = {};
for (const [k, f, mt] of [['video', m.video, 'video/mp4'], ['audio', m.audio, 'audio/mpeg'], ['image', m.image, 'image/png']]) ids[k] = (await uploadAsset(page, f, mt)).json.asset.id;
const rich = await buildRichDeck(page, { media: ids, title: 'Round trip source' });
const exp = await api(page, 'POST', `/api/decks/${rich.id}/exports`, { format: 'pptx', options: {} });
let e = exp.json; for (let i = 0; i < 150 && !['done', 'failed'].includes(e.status); i++) { await sleep(1000); e = (await api(page, 'GET', `/api/exports/${e.id}`)).json; }
expect(e.status === 'done', 'round trip: the export finished (' + e.status + ')');
const b64 = await page.evaluate(async (u) => { const r = await fetch(u); const b = new Uint8Array(await r.arrayBuffer()); let s = ''; for (let i = 0; i < b.length; i += 0x8000) s += String.fromCharCode(...b.subarray(i, i + 0x8000)); return btoa(s); }, e.download_url);
const rt = path.join(OUT, 'downloads', 'roundtrip.pptx'); fs.mkdirSync(path.dirname(rt), { recursive: true }); fs.writeFileSync(rt, Buffer.from(b64, 'base64'));
await page.goto(`/decks/${base.id}`); await page.waitForSelector('.es-viewport'); await sleep(800);
const rr = await importFile(rt);
const src = await docOf(rich.id); const back = await docOf(rr.deckId);
expect(back.slides.length === src.slides.length, `round trip: ${back.slides.length} of ${src.slides.length} slides`);
expect(back.slides.map((s) => s.hidden).join() === src.slides.map((s) => s.hidden).join(), 'round trip: hidden flags survive');
expect(back.slides.map((s) => s.notes).join('|') === src.slides.map((s) => s.notes).join('|'), 'round trip: every note survives word for word');
const norm = (t) => t.replace(/\s+/g, ' ').trim();
const srcTexts = src.slides.map((s) => s.els.filter((x) => x.type === 'text').map((x) => norm(textOf(x))).filter(Boolean));
const backText = back.slides.map((s) => norm(s.els.map(textOf).join(' ')));
let missing = [];
srcTexts.forEach((list, i) => list.forEach((t) => { if (!backText[i].includes(t.split('\n')[0].slice(0, 20))) missing.push(i + 1 + ':' + t.slice(0, 30)); }));
expect(missing.length === 0, 'round trip: the text of every text box is found again' + (missing.length ? ' missing ' + missing.join(' ; ') : ''));
const srcCharts = src.slides.flatMap((s) => s.els.filter((x) => x.type === 'chart' && x.chart.type !== 'kpi'));
const backCharts = back.slides.flatMap((s) => s.els.filter((x) => x.type === 'chart'));
expect(backCharts.length >= srcCharts.length - 2, `round trip: charts come back as charts (${backCharts.length} of ${srcCharts.length} non KPI charts; waterfall and funnel are stacked charts in PowerPoint)`);
const ct = back.slides[3].els.find((x) => x.type === 'table');
expect(ct && ct.rows[1][0].t === 'Tonnes' && ct.rows[1][3].t === '+4.1%', 'round trip: the table cells come back');
const picsBack = back.slides[2].els.filter((x) => x.type === 'image').length;
expect(picsBack === 3, 'round trip: the three pictures come back (' + picsBack + ')');
await page.getByRole('link', { name: 'Open the new deck' }).click();
await page.waitForSelector('.es-viewport'); await sleep(1500);
await shot(page, 'import-39-roundtrip-opened');
console.log('console errors', oc._errors.slice(0, 6));
expect(oc._errors.filter((x) => !/40\d|42[29]|415/.test(x)).length === 0, 'no unexpected console errors during imports');
summary('import');
await browser.close();
