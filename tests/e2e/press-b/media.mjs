// Check 33: playback of video and audio in presenting, from real mp4 / mp3 / png files made with ffmpeg and uploaded through
// the real asset endpoint. Native controls, click behaviour, autoplay with a start offset, pause on leaving the slide, the
// presenter console not playing media, the audience window playing it, reduced motion not autoplaying.
import { launch, context, login, api, expect, shot, summary, sleep, until } from './lib.mjs';
import { buildAcceptanceDeck, uid } from './fixture.mjs';

const browser = await launch();
const ctx = await context(browser);
const page = await ctx.newPage();
await login(page);
const D = await buildAcceptanceDeck(page);
const mediaSlide = D.slides.media;

const media = () => page.evaluate(() => {
    const l = document.querySelector('.pp-layer:not(.pp-layer-wait)');
    const v = l?.querySelector('video'); const a = l?.querySelector('audio'); const i = l?.querySelector('img');
    const s = (m) => m && { paused: m.paused, t: +m.currentTime.toFixed(2), ready: m.readyState, dur: +(m.duration || 0).toFixed(2), muted: m.muted, w: m.videoWidth, err: m.error && m.error.code, controls: m.controls };
    return { v: s(v), a: s(a), img: i && { ok: i.complete && i.naturalWidth > 0, w: i.naturalWidth, h: i.naturalHeight } };
});
const settle = () => until(() => page.evaluate(() => document.querySelectorAll('.pp-layer:not(.pp-layer-wait)').length === 1 && document.getAnimations().filter((a) => !(a instanceof CSSAnimation)).length === 0), { what: 'player settled' });

await page.goto(`/decks/${D.id}/present#4`);
await page.waitForSelector('.pp-layer video'); await sleep(2000); await settle();
let m = await media();
expect(m.img && m.img.ok && m.img.w === 800 && m.img.h === 450, 'check 33: the png loads from the signed asset route at its real size (800x450): ' + JSON.stringify(m.img));
expect(m.v && m.v.ready >= 3 && m.v.w === 640 && m.v.dur > 3.5 && m.v.err == null, 'check 33: the mp4 is decoded (640 wide, ' + m.v?.dur + ' s, readyState ' + m.v?.ready + ')');
expect(m.v && m.v.paused && m.v.controls, 'check 33: video waits paused with native controls (controls=true on the element)');
expect(m.a && m.a.dur > 2.5 && m.a.controls, 'check 33: the mp3 loads (' + m.a?.dur + ' s) with controls');
await shot(page, 'media-33-slide');

// real click on the native play control (bottom left of the video)
const vb = await page.locator('.pp-layer:not(.pp-layer-wait) video').boundingBox();
await page.mouse.move(vb.x + vb.width / 2, vb.y + vb.height / 2);
await page.mouse.click(vb.x + 24, vb.y + vb.height - 20);
await sleep(1500);
m = await media();
expect(m.v && !m.v.paused && m.v.t > 0.5, 'check 33: clicking the native play button plays the video (t=' + m.v?.t + ')');
const counter = (await page.locator('.pp-count').textContent()).trim();
expect(counter === '4 / 9', 'check 33: clicking the video control does not advance the slide: ' + counter);
// clicking the video surface also must not advance
await page.mouse.click(vb.x + vb.width / 2, vb.y + vb.height / 3);
await sleep(700);
expect((await page.locator('.pp-count').textContent()).trim() === '4 / 9', 'check 33: clicking the video picture does not advance the slide');
await shot(page, 'media-33-playing');
// audio plays and keeps time
await page.evaluate(() => document.querySelector('.pp-layer:not(.pp-layer-wait) audio').play());
await sleep(1500);
m = await media();
expect(m.a && !m.a.paused && m.a.t > 0.8, 'check 33: the audio plays (t=' + m.a?.t + ')');
// the video kept playing meanwhile (two sources at once is the presenter's choice)
// leave the slide: media stops (layer is torn down)
await page.keyboard.press('ArrowRight'); await sleep(1500); await settle();
const stillPlaying = await page.evaluate(() => [...document.querySelectorAll('video, audio')].filter((x) => !x.paused).length);
expect(stillPlaying === 0, 'check 33: leaving the slide stops all media (' + stillPlaying + ' still playing)');
await page.keyboard.press('ArrowLeft'); await sleep(1500); await settle();
m = await media();
expect(m.v && m.v.paused && m.v.t < 0.2, 'check 33: coming back shows the video from the start, paused (t=' + m.v?.t + ')');

// ---- autoplay with a start offset, muted
await api(page, 'POST', `/api/decks/${D.id}/ops`, { client: uid(), key: uid(), ops: [{ op: 'el.set', slide: mediaSlide, id: 'vid1', p: { autoplay: true, muted: true, start: 1, loop: false } }] });
await page.goto(`/decks/${D.id}/present?run=2#4`);
await page.waitForSelector('.pp-layer video'); await sleep(2500);
m = await media();
expect(m.v && !m.v.paused && m.v.t >= 1 && m.v.muted, 'check 33: autoplay starts on arrival, muted, from the start offset of 1 s (t=' + m.v?.t + ', muted ' + m.v?.muted + ')');
// arriving by transition (from slide 3) also starts it
await page.goto(`/decks/${D.id}/present?run=3#3`);
await page.waitForSelector('.pp-layer'); await sleep(1500); await settle();
await page.keyboard.press('ArrowRight'); await sleep(300);
await page.keyboard.press('ArrowRight'); await sleep(2500); await settle();
m = await media();
expect((await page.locator('.pp-count').textContent()).trim().startsWith('4') && m.v && !m.v.paused, 'check 33: autoplay also starts when arriving through the transition');

// ---- presenter console must not play it, audience window does
const pres = await context(browser);
const pp = await pres.newPage();
await login(pp);
await pp.goto(`/decks/${D.id}/presenter?from=3`);
await pp.waitForSelector('.pr-frame .pp-layer'); await sleep(1500);
const popP = pp.waitForEvent('popup');
await pp.getByRole('button', { name: 'Open the audience window' }).click();
const pop = await popP;
await pop.waitForSelector('.pa-view'); await sleep(2500);
const consolePlaying = await pp.evaluate(() => [...document.querySelectorAll('video, audio')].filter((x) => !x.paused).length);
expect(consolePlaying === 0, 'check 33: the presenter console never plays media itself (' + consolePlaying + ')');
await pp.bringToFront();
await pp.keyboard.press('Home'); await sleep(800);
await pp.locator('.pr-strip-item').nth(3).click();
await sleep(3000);
const audPlaying = await pop.evaluate(() => { const v = document.querySelector('.pp-layer:not(.pp-layer-wait) video'); return v ? { paused: v.paused, t: v.currentTime } : null; });
expect(audPlaying && !audPlaying.paused, 'check 33: the audience window plays the autoplay video when the console moves to slide 4: ' + JSON.stringify(audPlaying));
await shot(pop, 'media-33-audience-window');

// ---- reduced motion: no autoplay, the user stays in control
const rm = await context(browser, { reducedMotion: 'reduce' });
const rp = await rm.newPage();
await login(rp);
await rp.goto(`/decks/${D.id}/present#4`);
await rp.waitForSelector('.pp-layer video'); await sleep(2500);
const rmv = await rp.evaluate(() => { const v = document.querySelector('.pp-layer:not(.pp-layer-wait) video'); return { paused: v.paused, controls: v.controls }; });
expect(rmv.paused && rmv.controls, 'check 33: with reduced motion the video does not autoplay and keeps its controls');

// ---- a phone: play from the controls, tap the video does not flip the slide
const ph = await context(browser, { viewport: { width: 844, height: 390 }, hasTouch: true, isMobile: true, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148' });
const pg = await ph.newPage();
await login(pg);
await pg.goto(`/decks/${D.id}/present#4`);
await pg.waitForSelector('.pp-layer video'); await sleep(2500);
await shot(pg, 'media-33-phone-landscape');
const pv = await pg.locator('.pp-layer:not(.pp-layer-wait) video').boundingBox();
await pg.touchscreen.tap(pv.x + pv.width / 2, pv.y + pv.height / 2);
await sleep(700);
expect((await pg.locator('.pp-count').textContent()).trim() === '4 / 9', 'check 33: a tap on the video on a phone does not turn the slide');

console.log('console errors:', ctx._errors, pres._errors, rm._errors, ph._errors);
expect(ctx._errors.length === 0, 'no console errors while playing media');
summary('media');
await browser.close();
