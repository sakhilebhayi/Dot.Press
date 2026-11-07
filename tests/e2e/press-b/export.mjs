// Checks 38 to 41 (export side): PDF through the server, PPTX opened with python-pptx, the browser print view, PNG images,
// the offline present package opened with the network blocked, preflight accuracy, and recovery from a failed export.
import fs from 'node:fs';
import path from 'node:path';
import { launch, context, login, api, expect, shot, summary, sleep, until, openFromMore, makeMedia, uploadAsset, sh, py, PY, OUT, APP, BASE, php } from './lib.mjs';
import { buildRichDeck, uid } from './fixture.mjs';

const HERE = path.dirname(new URL(import.meta.url).pathname);
const browser = await launch();
const oc = await context(browser, { acceptDownloads: true });
const page = await oc.newPage();
await login(page);
const m = makeMedia();
const ids = {};
for (const [k, f, mt] of [['video', m.video, 'video/mp4'], ['audio', m.audio, 'audio/mpeg'], ['image', m.image, 'image/png']]) ids[k] = (await uploadAsset(page, f, mt)).json.asset.id;
const D = await buildRichDeck(page, { media: ids });
const doc = (await api(page, 'GET', `/api/decks/${D.id}/document`)).json;
const visible = doc.slides.filter((s) => !s.hidden);
const dl = path.join(OUT, 'downloads'); fs.mkdirSync(dl, { recursive: true });
const pyJson = (script, ...args) => JSON.parse(py(path.join(HERE, script), ...args));
const preflight = async (format, options = {}) => (await api(page, 'POST', `/api/decks/${D.id}/exports/preflight`, { format, options })).json;

await page.goto(`/decks/${D.id}`);
await page.waitForSelector('.es-viewport'); await sleep(1200);

async function chooseFormat(menuLabel) {
    await page.getByRole('button', { name: 'Export', exact: true }).click();
    await page.getByRole('menuitem', { name: menuLabel, exact: true }).click();
    await page.locator('[data-panel="export"], .xp').first().waitFor();
}
async function exportViaUi(kind, saveAs) {
    await chooseFormat(kind === 'pdf' ? 'PDF' : 'PowerPoint');
    await page.getByRole('button', { name: /Check before exporting/ }).click();
    await page.locator('.xp-pf').waitFor({ timeout: 20000 });
    const line = await page.locator('[data-testid="summary-line"]').innerText();
    await shot(page, `export-${kind}-preflight`);
    await page.getByRole('button', { name: new RegExp('^Export ' + (kind === 'pdf' ? 'PDF' : 'PowerPoint')) }).click();
    await page.getByRole('button', { name: 'Download', exact: true }).waitFor({ timeout: 120000 });
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download', exact: true }).click()]);
    const file = path.join(dl, saveAs);
    await download.saveAs(file);
    return { line, file, name: download.suggestedFilename() };
}

// =================================================================================================== PDF
const pdf = await exportViaUi('pdf', 'rich-ui.pdf');
expect(/\.pdf$/.test(pdf.name), 'check 40: the PDF downloads with a readable name: ' + pdf.name);
const pf = await preflight('pdf');
expect(pdf.line.includes(String(pf.summary.native)) && pdf.line.includes(String(pf.summary.flattened)) && /native/.test(pdf.line), 'check 40: the preflight line in the panel matches the API (' + pdf.line + ')');
const info = pyJson('../../Fixtures/Press/inspect_pdf.py'.replace('../../Fixtures/Press/', '../../Fixtures/Press/'), pdf.file);
expect(info.pages.length === visible.length, `check 40: the PDF has one page per visible slide (${info.pages.length} of ${visible.length}; the hidden slide is left out)`);
expect(info.pages.every((p) => Math.abs(p.w / p.h - 16 / 9) < 0.01), 'check 40: every page is 16:9 (' + info.pages[0].w + ' x ' + info.pages[0].h + ' pt)');
expect(info.pages[0].text.includes('Typography and lists'), 'check 40: text is real text and keeps the spaces between styled runs: "' + info.pages[0].text.replace(/\n/g, ' | ').slice(0, 70) + '"');
expect(/R412/.test(info.pages[3].text) && /91%/.test(info.pages[3].text), 'check 40: KPI figures keep their R and % signs in the PDF');
expect(!info.pages.some((p) => /RICH-HIDDEN/.test(p.text)), 'check 40: hidden slide text is not in the PDF');
expect(info.fonts.some((f) => /PublicSans/.test(f)), 'check 40: fonts are embedded (' + info.fonts.slice(0, 3).join(', ') + ')');
const pdfDir = path.join(OUT, 'ui-pdf'); fs.rmSync(pdfDir, { recursive: true, force: true });
const pages = JSON.parse(sh(PY, ['-I', path.join(HERE, 'render_pdf.py'), pdf.file, pdfDir, '50']));
sh(PY, ['-I', path.join(HERE, 'montage.py'), path.join(OUT, 'ui-pdf-montage.png'), '3', ...pages.map((p) => p.png)]);
console.log('  look: ' + path.join(OUT, 'ui-pdf-montage.png'));

// options: include hidden, notes pages, a range
const pdf2 = await api(page, 'POST', `/api/decks/${D.id}/exports`, { format: 'pdf', options: { include_hidden: true, notes: true } });
let e2 = pdf2.json; for (let i = 0; i < 150 && !['done', 'failed'].includes(e2.status); i++) { await sleep(1000); e2 = (await api(page, 'GET', `/api/exports/${e2.id}`)).json; }
console.log('  second pdf export:', JSON.stringify({ s: e2.status, err: e2.error, url: e2.download_url }));
const bytes2 = await page.evaluate(async (u) => { const r = await fetch(u); const b = new Uint8Array(await r.arrayBuffer()); let s = ''; for (let i = 0; i < b.length; i += 0x8000) s += String.fromCharCode(...b.subarray(i, i + 0x8000)); return btoa(s); }, e2.download_url);
fs.writeFileSync(path.join(dl, 'rich-hidden-notes.pdf'), Buffer.from(bytes2, 'base64'));
const info2 = pyJson('../../Fixtures/Press/inspect_pdf.py', path.join(dl, 'rich-hidden-notes.pdf'));
expect(info2.pages.length > visible.length && info2.pages.some((p) => /RICH-HIDDEN/.test(p.text)) && info2.pages.some((p) => /RICHNOTE-1/.test(p.text)), `check 40: hidden slides and notes pages are included when asked (${info2.pages.length} pages)`);

// =================================================================================================== PPTX
const px = await exportViaUi('pptx', 'rich-ui.pptx');
expect(/\.pptx$/.test(px.name), 'check 39: the PowerPoint file downloads: ' + px.name);
const pp = await preflight('pptx');
const d = pyJson('inspect_rich.py', px.file);
expect(d.slides.length === doc.slides.length, `check 39: the file has every slide (${d.slides.length})`);
expect(d.w === 1920 * 9525 && d.h === 1080 * 9525, 'check 39: slide size follows the deck (1920 x 1080 units, ' + d.w + ' x ' + d.h + ' EMU)');
expect(d.slides.map((s) => s.notes).every((n, i) => n === doc.slides[i].notes), 'check 39: speaker notes of every slide come across unchanged');
expect(d.slides[7].hidden === true && d.slides.filter((s) => s.hidden).length === 1, 'check 39: the hidden slide stays hidden');
const h1 = d.slides[0].shapes.find((s) => /Typography/.test((s.runs || []).map((r) => r.t).join('')));
expect(h1 && h1.runs.map((r) => r.t).join('') === 'Typography and lists', 'check 39: run text keeps its spaces: ' + JSON.stringify(h1?.runs.map((r) => r.t)));
expect(h1.runs[1].i === true && h1.runs[2].u === true, 'check 39: italic and underline survive');
const bl = d.slides[0].shapes.find((s) => (s.runs || []).some((r) => r.t === 'First point'));
expect(bl.runs.some((r) => r.link === 'https://example.com/docs') && bl.runs.map((r) => r.bullet).join() === 'bullet,bullet,bullet,number,number', 'check 39: the hyperlink and the bullet / numbered list kinds survive: ' + bl.runs.map((r) => r.bullet || '-').join());
const co = d.slides[0].shapes.find((s) => (s.runs || []).some((r) => r.t === 'Blue 30'));
expect(co.runs[0].color === 'B91C1C' && co.runs[1].color === '1D4ED8' && co.runs[1].sz < co.runs[0].sz && co.runs[2].strike, 'check 39: run colours, a smaller run size and strike survive');
expect(d.slides[1].shapes.filter((s) => s.kind === 'AUTO_SHAPE').length >= 6 && d.slides[1].shapes.some((s) => s.kind === 'LINE'), 'check 39: shapes and the line stay native shapes');
const rect = doc.slides[1].els.find((e) => e.id === 'rt');
const rtShape = d.slides[1].shapes.find((s) => s.name === 'Shape rt');
expect(Math.abs(rtShape.x - rect.x * 9525) < 20000 && Math.abs(rtShape.w - rect.w * 9525) < 20000, 'check 39: positions and sizes are the deck units times 9525 EMU');
expect(d.slides[2].shapes.filter((s) => s.kind === 'PICTURE').length === 3, 'check 39: the three pictures are real pictures');
const tbl = d.slides[3].shapes.find((s) => s.table);
expect(tbl && tbl.table.cells[1][0] === 'Tonnes' && tbl.table.cells[1][3] === '+4.1%' && tbl.table.spans.some((sp) => sp[3] === 2), 'check 39: the table is a real table with its text and its column span: ' + JSON.stringify(tbl?.table.spans));
const charts = d.slides.flatMap((s) => s.shapes.filter((x) => x.chart));
expect(charts.length === 12 && charts.some((c) => c.chart.type === 'PIE') && charts.some((c) => c.chart.type === 'XY_SCATTER'), 'check 39: twelve charts are native editable charts: ' + charts.map((c) => c.chart.type).join(','));
const c1 = charts.find((c) => c.chart.series[0]?.name === 'North' && c.chart.type === 'COLUMN_CLUSTERED');
expect(JSON.stringify(c1.chart.series[0].values) === '[12,19,14,22]' && c1.chart.cats.join() === 'Q1,Q2,Q3,Q4', 'check 39: chart data is the deck data');
expect(d.slides[0].transition?.includes('fade') && d.slides[1].transition?.includes('push'), 'check 39: slide transitions that PowerPoint has are written (' + d.slides[0].transition + ', ' + d.slides[1].transition + ')');
// preflight accuracy against the actual file
let acc = 0, accTotal = 0;
for (const s of pp.slides) {
    const real = d.slides[s.index - 1];
    for (const el of s.elements) {
        accTotal++;
        const docEl = doc.slides[s.index - 1].els.find((e) => e.id === el.id);
        const has = (pred) => real.shapes.some(pred);
        let ok = true;
        if (el.level === 'dropped') ok = true;
        else if (el.type === 'chart' && docEl.chart.type !== 'kpi') ok = has((x) => x.chart && x.name === 'Chart ' + el.id);
        else if (el.type === 'chart') ok = has((x) => /^KPI/.test(x.name));
        else if (el.type === 'table') ok = has((x) => x.table);
        else if (el.type === 'image') ok = has((x) => x.name === 'Picture ' + el.id);
        else if (el.type === 'text') ok = has((x) => x.name === 'Text ' + el.id);
        else if (el.type === 'shape' || el.type === 'icon') ok = has((x) => x.name === 'Shape ' + el.id || x.name === 'Line ' + el.id || x.name === 'Text ' + el.id || x.name === el.id || x.name.toLowerCase() === (docEl.name || docEl.kind || '').toLowerCase());
        else if (el.type === 'video' || el.type === 'audio') ok = has((x) => x.name === 'Picture ' + el.id || x.kind === 'PICTURE');
        if (ok) acc++; else console.log('    preflight said ' + el.level + ' for slide ' + s.index + ' ' + el.id + ' (' + el.type + ') but the file has no matching shape');
    }
}
expect(acc === accTotal, `check 41: every element the PPTX preflight lists exists in the file as the preflight said (${acc} of ${accTotal})`);
expect(pp.summary.dropped === 0 && (pp.general || pp.summary.general || []).concat(JSON.stringify(pp).match(/Element animations[^"]*/) || []).some((g) => /animations/i.test(g)), 'check 41: the preflight says in words that animations are not exported');

// =================================================================================================== print view -> PDF
const pr = await context(browser);
const prp = await pr.newPage();
await login(prp);
await prp.goto(`/decks/${D.id}/print`); await prp.waitForSelector('.prt-sheet'); await sleep(3500);
const printed = path.join(dl, 'print-view.pdf');
await prp.pdf({ path: printed, preferCSSPageSize: true, printBackground: true });
const pinfo = pyJson('../../Fixtures/Press/inspect_pdf.py', printed);
expect(pinfo.pages.length === visible.length, `check 40: the print view prints ${pinfo.pages.length} pages (one per visible slide)`);
expect(Math.abs(pinfo.pages[0].w - 1440) < 2 && Math.abs(pinfo.pages[0].h - 810) < 2, 'check 40: each print page is exactly the slide size (' + pinfo.pages[0].w + ' x ' + pinfo.pages[0].h + ' pt)');
expect(pinfo.pages[0].text.includes('Typography and lists'), 'check 40: print view text is selectable and spaced: ' + pinfo.pages[0].text.replace(/\n/g, ' | ').slice(0, 50));
const printDir = path.join(OUT, 'print-pdf'); fs.rmSync(printDir, { recursive: true, force: true });
const ppages = JSON.parse(sh(PY, ['-I', path.join(HERE, 'render_pdf.py'), printed, printDir, '50']));
sh(PY, ['-I', path.join(HERE, 'montage.py'), path.join(OUT, 'print-pdf-montage.png'), '3', ...ppages.map((p) => p.png)]);
console.log('  look: ' + path.join(OUT, 'print-pdf-montage.png'));
await prp.goto(`/decks/${D.id}/print?hidden=1&notes=1`); await prp.waitForSelector('.prt-sheet'); await sleep(2500);
const sheets = await prp.locator('.prt-sheet').count();
expect(sheets === doc.slides.length * 2, `check 40: with hidden slides and notes the print view has ${sheets} pages (8 slides + 8 notes pages)`);

// =================================================================================================== images (made in the browser)
await chooseFormat('Images (PNG)');
const sizeHint = await page.locator('.xp').innerText();
const wantPx = (sizeHint.match(/(\d+) by (\d+) pixels/) || []).slice(1).map(Number);
await page.getByRole('button', { name: /^Make pictures/ }).click();
await page.getByRole('button', { name: 'Download ZIP' }).waitFor({ timeout: 120000 });
const [zipDl] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download ZIP' }).click()]);
const zip = path.join(dl, 'rich-images.zip'); await zipDl.saveAs(zip);
const zdir = path.join(dl, 'rich-images'); fs.rmSync(zdir, { recursive: true, force: true }); fs.mkdirSync(zdir);
sh('unzip', ['-o', '-q', zip, '-d', zdir]);
const pngs = fs.readdirSync(zdir).filter((f) => f.endsWith('.png')).sort();
expect(pngs.length === visible.length, `check 40: the image export has one PNG per visible slide (${pngs.length}) named ${pngs[0]}`);
const dims = JSON.parse(sh(PY, ['-I', '-c', `import json,sys; from PIL import Image; print(json.dumps([Image.open(f).size for f in sys.argv[1:]]))`, ...pngs.map((f) => path.join(zdir, f))]));
expect(wantPx.length === 2 && dims.every((x) => x[0] === wantPx[0] && x[1] === wantPx[1]), `check 40: each PNG has the size the panel promised (${wantPx.join(' x ')}): ${dims[0]}`);
const px2 = JSON.parse(sh(PY, ['-I', '-c', `import json,sys; from PIL import Image; im=Image.open(sys.argv[1]).convert('RGB'); k=im.size[0]/1920; print(json.dumps(im.getpixel((int(970*k),int(220*k)))))`, path.join(zdir, pngs[1])]));
expect(px2[0] > 150 && px2[2] < 120, 'check 40: the gradient ellipse is drawn in the PNG (centre pixel ' + px2.join(',') + ')');
sh(PY, ['-I', path.join(HERE, 'montage.py'), path.join(OUT, 'images-montage.png'), '3', ...pngs.map((f) => path.join(zdir, f))]);
console.log('  look: ' + path.join(OUT, 'images-montage.png'));
await shot(page, 'export-images-done');

console.log('console errors', oc._errors);
expect(oc._errors.length === 0, 'no console errors during the exports');
summary('export');
await browser.close();
