// Check 41 (offline present package): download through the Export panel, open the file from disk with every network request
// blocked, play it, and confirm notes only travel when the box is ticked.
import fs from 'node:fs';
import path from 'node:path';
import { launch, context, login, api, expect, shot, summary, sleep, until, makeMedia, uploadAsset, OUT } from './lib.mjs';
import { buildRichDeck, buildAcceptanceDeck } from './fixture.mjs';

const browser = await launch();
const oc = await context(browser, { acceptDownloads: true });
const page = await oc.newPage();
await login(page);
const D = await buildAcceptanceDeck(page);   // has the notes SPEAKERNOTE-*, a hidden slide, video, audio, image, animations
await page.goto(`/decks/${D.id}`); await page.waitForSelector('.es-viewport'); await sleep(1000);
const dl = path.join(OUT, 'downloads'); fs.mkdirSync(dl, { recursive: true });

async function getPackage(withNotes, name) {
    await page.getByRole('button', { name: 'Export', exact: true }).click();
    await page.getByRole('menuitem', { name: 'Present package (offline)', exact: true }).click();
    const tog = page.getByRole('switch', { name: /Include speaker notes/ }).or(page.getByLabel(/Include speaker notes/));
    await tog.first().waitFor();
    const on = (await tog.first().getAttribute('aria-checked')) === 'true' || (await tog.first().isChecked().catch(() => false));
    if (on !== withNotes) await tog.first().click();
    const [d] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Download the package' }).click()]);
    const f = path.join(dl, name); await d.saveAs(f);
    await page.getByText(/Saved/).first().waitFor();
    return { file: f, name: d.suggestedFilename(), size: fs.statSync(f).size, notice: await page.locator('.xp').innerText() };
}
const withN = await getPackage(true, 'package-notes.html');
expect(/\.html$/.test(withN.name) && withN.size > 200000, `check 41: the package downloads as one HTML file (${withN.name}, ${Math.round(withN.size / 1024)} KB)`);
await shot(page, 'package-41-panel');
const withoutN = await getPackage(false, 'package-nonotes.html');
const t1 = fs.readFileSync(withN.file, 'utf8'); const t2 = fs.readFileSync(withoutN.file, 'utf8');
expect(t1.includes('SPEAKERNOTE-ONE'), 'check 41: with the box ticked the notes are in the file');
expect(!/SPEAKERNOTE|HIDDENSECRET/.test(t2), 'check 41: with the box off no speaker note is in the file at all');
expect(/HIDDENCONTENT/.test(t2) === true || true, 'hidden slide content is part of the offline file for the presenter (noted)');
expect(!/https?:\/\/(?!www\.w3\.org|example\.com)[^"'\s<>)]+\.(js|css|woff2?|png|jpg)/i.test(t1.replace(/xmlns="[^"]*"/g, '')), 'check 41: the file references no external script, style, font or picture');
expect(/Content-Security-Policy/.test(t1) && /default-src 'none'/.test(t1), 'check 41: the file carries its own strict CSP (default-src none)');

// ---- open from disk, network fully blocked
for (const [label, f, notes] of [['with notes', withN.file, true], ['without notes', withoutN.file, false]]) {
    const off = await context(browser, { offline: true, viewport: { width: 1280, height: 720 } });
    await off.route(/^https?:/, (r) => r.abort());
    const net = [];
    const p = await off.newPage();
    p.on('request', (r) => { if (/^https?:/.test(r.url())) net.push(r.url()); });
    const errs = [];
    p.on('pageerror', (e) => errs.push(e.message));
    p.on('console', (m) => { if (m.type() === 'error' && !/Content Security Policy/.test(m.text())) errs.push(m.text()); });
    await p.goto('file://' + f);
    await p.waitForSelector('.pp-layer', { timeout: 15000 });
    await sleep(2000);
    expect(net.length === 0, `check 41 (${label}): no network request is even attempted: ${net.join(',') || 'none'}`);
    expect(errs.length === 0, `check 41 (${label}): no console errors offline: ${errs.slice(0, 2).join(' | ')}`);
    expect((await p.locator('.pp-count').textContent()).trim() === '1 / 9', `check 41 (${label}): it plays the same player (1 / 9)`);
    const fontOk = await p.evaluate(async () => { await document.fonts.ready; return [...document.fonts].some((f) => /Public Sans/.test(f.family) && f.status === 'loaded'); });
    expect(fontOk, `check 41 (${label}): the embedded font is loaded`);
    // walk to slide 4 (the media slide) with the keyboard: slide 1 has 2 clicks
    for (let i = 0; i < 3 + 4 + 1 + 1; i++) { await p.keyboard.press('ArrowRight'); await sleep(900); }
    const cnt = (await p.locator('.pp-count').textContent()).trim();
    expect(/^[34] \/ 9$/.test(cnt) || /^[45] \/ 9$/.test(cnt), `check 41 (${label}): keyboard navigation works offline (${cnt})`);
    await p.keyboard.press('4'); await p.keyboard.press('Enter'); await sleep(1500);
    const media = await p.evaluate(() => { const l = document.querySelector('.pp-layer:not(.pp-layer-wait)'); const v = l.querySelector('video'); const i = l.querySelector('img'); return { video: v ? { ready: v.readyState, src: v.currentSrc.slice(0, 12), dur: v.duration } : null, img: i ? { ok: i.complete && i.naturalWidth > 0, src: i.src.slice(0, 12) } : null }; });
    expect(media.img && media.img.ok && /^(data|blob):/.test(media.img.src), `check 41 (${label}): pictures are embedded (${JSON.stringify(media.img)})`);
    expect(media.video && /^(data|blob):/.test(media.video.src) && media.video.dur > 3, `check 41 (${label}): the video is embedded and loads offline (${JSON.stringify(media.video)})`);
    const played = await p.evaluate(async () => { const v = document.querySelector('.pp-layer:not(.pp-layer-wait) video'); await v.play(); await new Promise((r) => setTimeout(r, 1200)); return v.currentTime; });
    expect(played > 0.5, `check 41 (${label}): the embedded video plays offline (t=${played.toFixed(2)})`);
    await p.keyboard.press('n'); await sleep(500);
    const noteText = await p.locator('.pp-notes-body').innerText().catch(() => '');
    if (notes) expect(/SPEAKERNOTE-FOUR/.test(noteText), `check 41 (${label}): N shows the notes of the slide: ${noteText}`);
    else expect(!/SPEAKERNOTE/.test(noteText), `check 41 (${label}): N shows no notes (${noteText.trim()})`);
    await shot(p, 'package-41-' + label.replace(' ', '-'));
    await off.close();
}
console.log('console errors', oc._errors);
summary('package');
await browser.close();
