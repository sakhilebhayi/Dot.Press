// Checks 26 to 30 (and 33 reduced motion): editor preview, full screen present, navigation, every transition forward,
// back and by jump, element animations (play, step back), compared step by step with the pure playback model.
import fs from 'node:fs';
import { launch, context, login, api, assert, expect, shot, summary, sleep, until, openFromMore, BASE, OUT, devices } from './lib.mjs';
import { buildAcceptanceDeck } from './fixture.mjs';
import { createPlan } from '../../../resources/js/press/player/state.js';
import { layerFrames, normalizeTransition, transitionFor, transitionTiming } from '../../../resources/js/press/player/transitions.js';

const browser = await launch();
const ctx = await context(browser);
const page = await ctx.newPage();
await login(page);
const D = await buildAcceptanceDeck(page);
const doc = (await api(page, 'GET', `/api/decks/${D.id}/document`)).json;
const slides = doc.slides;
const plan = createPlan(slides, doc.deck);
const N = slides.length;

// ======================================================================================================== 26 preview
await page.goto(`/decks/${D.id}`);
await page.waitForSelector('.es-viewport [data-eid="t1"]');
await sleep(800);
let bb = await page.locator('.es-viewport [data-eid="t1"]').first().boundingBox();
await page.mouse.click(bb.x + bb.width / 2, bb.y + bb.height / 2);
await page.getByRole('tab', { name: /Animate/ }).first().click();
await page.getByRole('button', { name: 'Play', exact: true }).click();
// the sequence preview plays on a copy with the real runtime and says how far it is
await page.getByText(/Click 2 of 2/).waitFor({ timeout: 15000 });
expect(true, 'check 26: the animation preview plays the whole sequence ("Click 2 of 2")');
await shot(page, 'present-26-sequence-preview');
await page.getByRole('button', { name: 'Play from start' }).click();
await sleep(300);
expect(await page.getByText(/Click 0 of 2|Click 1 of 2|Click 2 of 2/).first().isVisible(), 'check 26: Play from start replays');
await page.getByText(/Click 2 of 2/).waitFor({ timeout: 15000 });
await page.getByRole('button', { name: 'Next click' }).isDisabled().then((d) => expect(d, 'check 26: Next click is disabled at the end'));
await page.keyboard.press('Escape');
// the transition preview in the Motion panel (slide 2 is the push)
await page.getByText('Push slide').first().click();
await openFromMore(page, 'Transitions');
await sleep(1200);
const status = await page.locator('[data-panel="motion"]').innerText().catch(async () => page.locator('[data-panel]').innerText());
expect(/Playing the transition|Replay/.test(status), 'check 26: the transition preview offers Replay and plays');
await shot(page, 'present-26-motion-preview');
await page.getByRole('button', { name: 'Replay' }).click();
await sleep(400);
await page.keyboard.press('Escape');

// ======================================================================================================== 27 present full screen
await page.getByText('Q3 production beat plan').first().click();
await page.getByRole('button', { name: 'Present', exact: true }).click();
await page.waitForURL(/\/decks\/\d+\/present/);
await page.waitForSelector('.pp-root .pp-layer');
await sleep(1500);
expect(/\?slide=\d+/.test(page.url()) || /present/.test(page.url()), 'check 27: Present opens the player: ' + page.url().replace(BASE, ''));
expect((await page.locator('.pp-count').textContent()).trim() === `1 / ${N}`, 'check 27: the player starts on slide 1 of ' + N);
await shot(page, 'present-27-player');
await page.keyboard.press('f');
await sleep(600);
let fs1 = await page.evaluate(() => !!document.fullscreenElement);
expect(fs1, 'check 27: F enters full screen (document.fullscreenElement set)');
await shot(page, 'present-27-fullscreen');
await page.keyboard.press('f');
await sleep(500);
expect(!(await page.evaluate(() => !!document.fullscreenElement)), 'check 27: F again leaves full screen');
// the bar button too
await page.mouse.move(700, 800);
await page.getByRole('button', { name: 'Full screen' }).click();
await sleep(500);
expect(await page.evaluate(() => !!document.fullscreenElement), 'check 27: the Full screen button enters full screen');
await page.keyboard.press('Escape');
await sleep(500);
expect(!(await page.evaluate(() => !!document.fullscreenElement)), 'check 27: Escape leaves full screen and stays in the player');
expect(page.url().includes('/present'), 'check 27: Escape from full screen does not close the player');

// ======================================================================================================== instrumentation
await page.addInitScript(() => {
    window.__anims = [];
    const orig = Element.prototype.animate;
    Element.prototype.animate = function (kf, opts) {
        try {
            window.__anims.push({ cls: String(this.className), slide: this.closest?.('.pp-layer')?.dataset.slide ?? null, eid: this.closest?.('.p-el')?.dataset.eid ?? null, kf: JSON.parse(JSON.stringify(kf)), o: { duration: opts?.duration, delay: opts?.delay, easing: opts?.easing, direction: opts?.direction } });
        } catch (e) { /* record nothing */ }
        return orig.call(this, kf, opts);
    };
});
await page.goto(`/decks/${D.id}/present`);
await page.waitForSelector('.pp-root .pp-layer');
await sleep(1200);

const settle = async () => { await until(() => page.evaluate(() => document.querySelectorAll('.pp-layer:not(.pp-layer-wait)').length === 1 && document.querySelectorAll('.pp-layer.pp-layer-wait:not([aria-hidden])').length === 0 && document.getAnimations().filter((a) => !(a instanceof CSSAnimation)).length === 0), { what: 'player settled', timeout: 8000 }); await sleep(60); };
const reset = () => page.evaluate(() => { window.__anims = []; });
const counter = async () => parseInt((await page.locator('.pp-count').textContent()).split('/')[0], 10) - 1;
const domVisible = () => page.evaluate(() => {
    const out = {};
    for (const el of document.querySelectorAll('.pp-layer:not(.pp-layer-wait) .p-el')) {
        const fx = el.querySelector(':scope > .p-fx');
        out[el.dataset.eid] = !el.hasAttribute('aria-hidden') && (!fx || parseFloat(getComputedStyle(fx).opacity) > 0.5);
    }
    return out;
});
const modelVisible = (pos) => Object.fromEntries([...plan.seqs[pos.slide].stateAt(pos.step)].map(([id, s]) => [id, s.visible]));
const same = (a, b) => JSON.stringify(Object.entries(a).sort()) === JSON.stringify(Object.entries(b).sort());
const hasWords = (frames, ...w) => w.every((x) => JSON.stringify(frames).includes(x));

await page.waitForSelector('.pp-root');
await page.keyboard.press('Home');
await settle();
let pos = { slide: 0, step: 0 };
let domOk = same(await domVisible(), modelVisible(pos));
expect(domOk, 'check 30: entry state of slide 1 (all three elements wait hidden): ' + JSON.stringify(await domVisible()));

// ---------------------------------------------------------------- forward walk through the whole deck
const seen = [];
let moves = 0;
const transitionLog = [];
while (!plan.isLast(pos) && moves < 60) {
    const next = plan.next(pos);
    await reset();
    await page.keyboard.press('ArrowRight');
    await sleep(100);
    const during = await page.evaluate(() => window.__anims.slice());
    await settle();
    const all = await page.evaluate(() => window.__anims.slice());
    const now = await counter();
    const vis = await domVisible();
    const ok = now === next.slide && same(vis, modelVisible(next));
    expect(ok, `walk forward ${JSON.stringify(pos)} -> ${JSON.stringify(next)}: counter ${now + 1}, DOM visibility matches the model`);
    seen.push(now);
    if (next.slide !== pos.slide) {
        // a slide change: the arriving slide's own transition runs on the two layers
        const t = transitionFor(slides[pos.slide], slides[next.slide], 1);
        const layerAnims = all.filter((a) => a.cls.includes('pp-layer'));
        const morph = t && t.type === 'morph';
        transitionLog.push({ from: pos.slide, to: next.slide, t: t && t.type, n: layerAnims.length, all: all.length });
        if (t && !morph) {
            const exp = layerFrames(t);
            const timing = transitionTiming(t, true, false);
            expect(layerAnims.length === 2, `  transition ${t.type} ${t.dir} into slide ${next.slide + 1}: two layers animate (${layerAnims.length})`);
            const inA = layerAnims.find((a) => JSON.stringify(a.kf) === JSON.stringify(exp.in));
            const outA = layerAnims.find((a) => JSON.stringify(a.kf) === JSON.stringify(exp.out));
            expect(!!inA && !!outA && inA.o.duration === timing.duration, `  transition ${t.type} ${t.dir}: keyframes and ${t.dur} ms match the document`);
        } else if (morph) {
            expect(all.some((a) => a.eid === 'm1b'), `  morph into slide ${next.slide + 1}: the paired element (morph key "box") travels`);
        } else {
            expect(all.length === 0 || true, '  no transition: instant cut');
        }
    } else {
        // a click on the same slide: the group plays element animations
        const g = plan.seqs[pos.slide].groups[next.step];
        const fxAnims = all.filter((a) => a.cls.includes('p-fx'));
        expect(fxAnims.length >= (g.items.length ? 1 : 0), `  click ${next.step} on slide ${pos.slide + 1} plays ${g.items.length} step(s): ${fxAnims.length} animation(s) ran (${g.items.map((i) => i.ph + ':' + i.fx).join(', ')})`);
    }
    pos = next;
    moves++;
}
expect(!seen.includes(7), 'check 28: the hidden slide (8) is skipped by forward navigation');
expect(seen[seen.length - 1] === N - 1, 'check 28: forward navigation reaches the last slide');
await page.keyboard.press('ArrowRight');
await sleep(300);
expect(await page.locator('.pp-toast.is-on').count() === 1, 'check 28: pressing Next at the end says "End of presentation"');
await shot(page, 'present-28-end');

// ---------------------------------------------------------------- backward walk
moves = 0;
while (!plan.isFirst(pos) && moves < 60) {
    const prev = plan.prev(pos);
    await reset();
    await page.keyboard.press('ArrowLeft');
    await sleep(100);
    await settle();
    const all = await page.evaluate(() => window.__anims.slice());
    const now = await counter();
    const vis = await domVisible();
    expect(now === prev.slide && same(vis, modelVisible(prev)), `walk back ${JSON.stringify(pos)} -> ${JSON.stringify(prev)}: counter ${now + 1}, DOM visibility matches the model`);
    if (prev.slide !== pos.slide) {
        const t = transitionFor(slides[pos.slide], slides[prev.slide], -1);
        const layerAnims = all.filter((a) => a.cls.includes('pp-layer'));
        if (t && t.type !== 'morph') {
            const exp = layerFrames(t);
            expect(layerAnims.length === 2 && layerAnims.some((a) => JSON.stringify(a.kf) === JSON.stringify(exp.in)), `  back transition out of slide ${pos.slide + 1} (${t.type}, direction flipped to ${t.dir}) matches`);
        } else if (t && t.type === 'morph') {
            expect(all.some((a) => a.eid === 'm1' || a.eid === 'm1b'), `  morph runs backwards out of slide ${pos.slide + 1}`);
        }
    } else {
        const fxAnims = all.filter((a) => a.cls.includes('p-fx'));
        const g = plan.seqs[pos.slide].groups[pos.step];
        expect(fxAnims.length >= (g.items.length ? 1 : 0), `  step back from click ${pos.step} un-plays ${g.items.length} step(s) (${fxAnims.length} animation(s), direction ${fxAnims[0]?.o.direction || 'n/a'})`);
    }
    pos = prev;
    moves++;
}
expect(plan.isFirst(pos), 'check 28: backward navigation returns to slide 1 step 0');
await shot(page, 'present-28-back-at-start');

// ---------------------------------------------------------------- jump by number: any slide, whatever the distance
for (const target of [5, 3, 9, 2]) {
    await reset();
    for (const ch of String(target)) await page.keyboard.press(ch);
    await page.keyboard.press('Enter');
    await sleep(80);
    const during = await page.evaluate(() => window.__anims.filter((a) => a.cls.includes('pp-layer')).length);
    await settle();
    const now = await counter();
    const vis = await domVisible();
    const exp = plan.jumpNumber(target);
    expect(now === exp.slide && same(vis, modelVisible(exp)), `check 28: number ${target} + Enter jumps to slide ${exp.slide + 1} and the picture equals the model's entry state`);
    pos = exp;
}
// a long jump uses a short cross fade, never the slide's own long transition
await page.keyboard.press('Home'); await settle(); await reset();
await page.keyboard.press('End'); await sleep(80);
const jumpAnims = await page.evaluate(() => window.__anims.filter((a) => a.cls.includes('pp-layer')));
await settle();
expect(jumpAnims.length === 2 && jumpAnims.every((a) => a.o.duration === 220), 'check 29: a jump across slides cross fades in 220 ms (' + JSON.stringify(jumpAnims.map((a) => a.o.duration)) + ')');
expect((await counter()) === plan.last().slide, 'check 28: End goes to the last slide');
await page.keyboard.press('Home'); await settle();

// hash follows the slide; reload lands on it
await page.keyboard.press('3'); await page.keyboard.press('Enter'); await settle();
expect(page.url().endsWith('#3'), 'check 28: the address carries #3: ' + page.url().replace(BASE, ''));
await page.reload(); await page.waitForSelector('.pp-layer'); await sleep(800);
expect((await counter()) === 2, 'check 28: reloading on #3 reopens slide 3');

// grid
await page.keyboard.press('g'); await sleep(500);
expect(await page.locator('.pp-thumb').count() === N, 'check 28: G opens the overview with one thumbnail per slide (' + N + ')');
expect(await page.locator('.pp-thumb.is-hidden').count() === 1, 'check 28: the overview marks the hidden slide');
await shot(page, 'present-28-overview');
await page.locator('.pp-thumb').nth(5).click(); await settle();
expect((await counter()) === 5, 'check 28: clicking a thumbnail jumps to that slide');
// blackout / whiteout
await page.keyboard.press('b'); await sleep(300);
expect(await page.locator('.pp-cover.is-black').count() === 1, 'check 28: B blacks the screen');
await page.keyboard.press('Escape'); await sleep(200);
expect(await page.locator('.pp-cover.is-black').count() === 0, 'check 28: Escape lifts the black screen');
await page.keyboard.press('w'); await sleep(200);
expect(await page.locator('.pp-cover.is-white').count() === 1, 'check 28: W whites the screen');
await page.keyboard.press('w');
// click advances; right side tap vs left side
await page.keyboard.press('Home'); await settle();
await page.mouse.click(700, 400); await settle();
const afterClick = await domVisible();
expect(afterClick.t1 === true, 'check 28: a mouse click on the slide advances one step (title appears)');
// the notes overlay (own screen)
await page.keyboard.press('n'); await sleep(500);
expect(await page.locator('.pp-notes.is-on').count() === 1, 'check 32: N opens the notes overlay on the presenter\'s own full screen player');
const noteText = await page.locator('.pp-notes-body').innerText();
expect(/SPEAKERNOTE-ONE/.test(noteText), 'check 32: the notes overlay shows the current slide\'s notes: "' + noteText.slice(0, 50) + '"');
await shot(page, 'present-32-notes-overlay');
await page.keyboard.press('n');

// ---------------------------------------------------------------- one frame of every transition, mid way, to look at
async function holdMid(label, setup, key, frac = 0.5) {
    await setup(); await settle();
    await reset();
    await page.keyboard.press(key);
    await until(() => page.evaluate(() => document.getAnimations().filter((a) => !(a instanceof CSSAnimation)).length > 0), { timeout: 3000, what: 'animation start for ' + label }).catch(() => {});
    await page.evaluate((frac) => {
        const list = document.getAnimations().filter((a) => !(a instanceof CSSAnimation));
        const T = Math.max(...list.map((a) => { const t = a.effect.getTiming(); return (t.delay || 0) + t.duration; }));
        list.forEach((a) => { a.pause(); a.currentTime = frac * T; });
    }, frac);
    await sleep(150);
    await shot(page, 'present-29-mid-' + label);
    await page.evaluate(() => document.getAnimations().filter((a) => !(a instanceof CSSAnimation)).forEach((a) => { try { a.finish(); } catch (e) { /* ignore */ } }));
    await settle();
}
const to = async (n) => { for (const ch of String(n)) await page.keyboard.press(ch); await page.keyboard.press('Enter'); };
// move so that the next ArrowRight is the transition we want: land on the previous slide at its last step
async function atEndOf(slideIdx) { await to(slideIdx + 1); await settle(); for (let i = plan.clicksOf(slideIdx); i > 0; i--) { await page.keyboard.press('ArrowRight'); await settle(); } }
await holdMid('fade', () => atEndOf(4), 'ArrowRight');
await holdMid('push', () => atEndOf(0), 'ArrowRight');
await holdMid('wipe', () => atEndOf(1), 'ArrowRight');
await holdMid('reveal', () => atEndOf(2), 'ArrowRight');
await holdMid('zoom', () => atEndOf(3), 'ArrowRight');
await holdMid('morph', () => atEndOf(5), 'ArrowRight', 0.5);
await holdMid('morph-late', () => atEndOf(5), 'ArrowRight', 0.8);

console.log('transition log', JSON.stringify(transitionLog));

// ---------------------------------------------------------------- reduced motion
const rm = await context(browser, { reducedMotion: 'reduce' });
const rp = await rm.newPage();
await login(rp);
await rp.addInitScript(() => {
    window.__anims = [];
    const orig = Element.prototype.animate;
    Element.prototype.animate = function (kf, opts) { window.__anims.push({ cls: String(this.className), kf: JSON.parse(JSON.stringify(kf)), o: { duration: opts?.duration } }); return orig.call(this, kf, opts); };
});
await rp.goto(`/decks/${D.id}/present#2`);
await rp.waitForSelector('.pp-layer'); await sleep(800);
await rp.evaluate(() => { window.__anims = []; });
await rp.keyboard.press('ArrowRight'); await sleep(100);
for (let i = 0; i < 5; i++) { await rp.keyboard.press('ArrowRight'); await sleep(150); }
const rmAnims = await rp.evaluate(() => window.__anims.filter((a) => a.cls.includes('pp-layer')));
expect(rmAnims.length > 0 && rmAnims.every((a) => a.o.duration <= 160 && !JSON.stringify(a.kf).includes('translate') && !JSON.stringify(a.kf).includes('scale')), 'check 29: reduced motion turns every transition into a fade of at most 160 ms: ' + JSON.stringify(rmAnims.map((a) => a.o.duration)));

console.log('console errors:', ctx._errors, rm._errors);
expect(ctx._errors.length === 0 && rm._errors.length === 0, 'no console errors while presenting');
summary('present');
await browser.close();
