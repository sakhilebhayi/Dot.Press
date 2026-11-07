import test from 'node:test';
import assert from 'node:assert/strict';
import { aspectPatch, nudgePatch, effectivePpi, resetPatch, isAdjusted, defaultShadow, CROP_PRESETS, MASKS, ADJUSTMENTS } from '../lib/image.js';
import { visibleWindow } from '../../../stage/lib/crop.js';

const nat = { w: 4000, h: 3000 };
const el = (o = {}) => ({ id: 'a', type: 'image', x: 400, y: 200, w: 800, h: 600, fit: 'cover', crop: { x: 0, y: 0, w: 1, h: 1 }, ...o });
const close = (a, b, eps = 0.01) => assert.ok(Math.abs(a - b) <= eps, `${a} is not close to ${b}`);
const pixelRatio = (crop) => (crop.w * nat.w) / (crop.h * nat.h);

test('every preset gives a window and a frame of the same ratio, inside the image and the slide', () => {
  for (const [id, ratio] of [['1:1', 1], ['4:3', 4 / 3], ['16:9', 16 / 9], ['3:4', 3 / 4]]) {
    const p = aspectPatch({ el: el(), nat, preset: id });
    assert.ok(p, id);
    close(pixelRatio(p.crop), ratio, 0.005);
    close(p.w / p.h, ratio, 0.005);
    assert.ok(p.crop.x >= 0 && p.crop.y >= 0 && p.crop.x + p.crop.w <= 1.0001 && p.crop.y + p.crop.h <= 1.0001, id);
    assert.ok(p.x >= 0 && p.y >= 0 && p.x + p.w <= 1920.01 && p.y + p.h <= 1080.01, id);
    // the frame keeps its centre
    close(p.x + p.w / 2, 800, 0.1);
    close(p.y + p.h / 2, 500, 0.1);
    // and roughly its area
    close((p.w * p.h) / (800 * 600), 1, 0.01);
  }
});

test('the 4:3 source is cut to the largest window for 16:9', () => {
  const p = aspectPatch({ el: el(), nat, preset: '16:9' });
  close(p.crop.w, 1, 0.001);
  close(p.crop.h, 0.75, 0.005);
  close(p.crop.y, 0.125, 0.005);
});

test('a preset never reveals pixels that were cropped away', () => {
  const crop = { x: 0.25, y: 0.25, w: 0.5, h: 0.5 };
  const e = el({ crop, w: 600, h: 450 });
  const before = visibleWindow({ crop, nat, box: { w: 600, h: 450 }, fit: 'cover' });
  const p = aspectPatch({ el: e, nat, preset: '1:1' });
  assert.ok(p.crop.x >= before.x - 1e-3 && p.crop.y >= before.y - 1e-3);
  assert.ok(p.crop.x + p.crop.w <= before.x + before.w + 1e-3);
  assert.ok(p.crop.y + p.crop.h <= before.y + before.h + 1e-3);
});

test('original restores the whole picture at its own ratio', () => {
  const e = el({ crop: { x: 0.3, y: 0.3, w: 0.2, h: 0.2 } });
  const p = aspectPatch({ el: e, nat, preset: 'orig' });
  assert.deepEqual(p.crop, { x: 0, y: 0, w: 1, h: 1 });
  close(p.w / p.h, 4 / 3, 0.005);
});

test('a frame that would leave the slide is shrunk and pulled back inside', () => {
  const e = el({ x: 1500, y: 700, w: 400, h: 300 });
  const p = aspectPatch({ el: e, nat, preset: '3:4' });
  assert.ok(p.x + p.w <= 1920.01 && p.y + p.h <= 1080.01 && p.x >= 0 && p.y >= 0);
  const big = aspectPatch({ el: el({ x: 0, y: 0, w: 1920, h: 1080 }), nat, preset: '1:1' });
  assert.ok(big.h <= 1080 * 0.96 + 0.01);
});

test('free and unknown sizes give no patch', () => {
  assert.equal(aspectPatch({ el: el(), nat, preset: 'free' }), null);
  assert.equal(aspectPatch({ el: el(), nat: null, preset: '1:1' }), null);
  assert.equal(aspectPatch({ el: el(), nat: { w: 0, h: 10 }, preset: '1:1' }), null);
  assert.equal(aspectPatch({ el: el(), nat, preset: 'nope' }), null);
});

test('nudging moves the window the way the picture moves, within the image', () => {
  const e = el({ w: 800, h: 450, crop: { x: 0.2, y: 0.1, w: 0.5, h: 0.5 } });
  const left = nudgePatch({ el: e, nat, dir: 'left' });
  const base = visibleWindow({ crop: e.crop, nat, box: { w: 800, h: 450 }, fit: 'cover' });
  assert.ok(left.crop.x > base.x, 'the picture moves left, so the window moves right over the source');
  const up = nudgePatch({ el: e, nat, dir: 'up' });
  assert.ok(up.crop.y > base.y);
  const down = nudgePatch({ el: e, nat, dir: 'down' });
  assert.ok(down.crop.y < base.y);
  close(left.crop.w, base.w, 1e-3);
  assert.ok(left.crop.x + left.crop.w <= 1.0001);
});

test('nudging stops at the edge, and does nothing under contain', () => {
  const edge = el({ w: 800, h: 450, crop: { x: 0, y: 0, w: 0.5, h: 0.5 } });
  assert.equal(nudgePatch({ el: edge, nat, dir: 'right' }), null);
  assert.equal(nudgePatch({ el: el({ fit: 'contain' }), nat, dir: 'left' }), null);
  assert.equal(nudgePatch({ el: el(), nat: null, dir: 'left' }), null);
});

test('effective ppi follows pixels per slide inch', () => {
  // 1920 px across a full 1920 unit wide frame on a 13.333 inch slide: 144 ppi
  assert.equal(effectivePpi({ nat: { w: 1920, h: 1080 }, box: { w: 1920, h: 1080 }, deckW: 1920 }), 144);
  // half the pixels over the same frame: 72 ppi, below the warning line
  assert.equal(effectivePpi({ nat: { w: 960, h: 540 }, box: { w: 1920, h: 1080 }, deckW: 1920 }), 72);
  // a small frame of a big picture is crisp
  assert.equal(effectivePpi({ nat: { w: 4000, h: 3000 }, box: { w: 480, h: 360 }, deckW: 1920 }), 1200);
  // cropping to a quarter of the width halves the pixels that remain
  assert.equal(effectivePpi({ nat: { w: 1920, h: 1080 }, crop: { x: 0, y: 0, w: 0.5, h: 0.5 }, box: { w: 960, h: 540 }, deckW: 1920 }), 144);
  // a 1280 wide legacy deck has fewer units per inch, so the same units mean the same inches
  assert.equal(effectivePpi({ nat: { w: 1280, h: 720 }, box: { w: 1280, h: 720 }, deckW: 1280 }), 96);
});

test('stretching one axis is judged by the worse axis', () => {
  const ppi = effectivePpi({ nat: { w: 1000, h: 1000 }, box: { w: 1000, h: 4000 }, fit: 'fill', deckW: 1920 });
  assert.equal(ppi, 36);
});

test('effective ppi is null when it cannot be known', () => {
  assert.equal(effectivePpi({ nat: null, box: { w: 10, h: 10 } }), null);
  assert.equal(effectivePpi({ nat: { w: 10, h: 10 }, box: { w: 0, h: 10 } }), null);
});

test('reset removes only what is there', () => {
  assert.equal(resetPatch({ id: 'a' }), null);
  assert.equal(resetPatch({ mask: 'none', adj: { br: 0, ct: 0 } }), null);
  assert.deepEqual(resetPatch({ crop: { x: 0, y: 0, w: 0.5, h: 0.5 }, mask: 'star', adj: { br: 20 }, flipH: true, fit: 'contain' }), { crop: null, mask: null, adj: null });
  assert.deepEqual(resetPatch({ mask: 'circle' }), { mask: null });
});

test('isAdjusted sees negative and positive values only', () => {
  assert.equal(isAdjusted(null), false);
  assert.equal(isAdjusted({ br: 0, hue: 0 }), false);
  assert.equal(isAdjusted({ hue: -20 }), true);
  assert.equal(isAdjusted({ blur: 3 }), true);
});

test('lists are complete and the shadow scales with the deck', () => {
  assert.deepEqual(MASKS.map((m) => m.id), ['none', 'round', 'circle', 'ellipse', 'triangle', 'star', 'hexagon']);
  assert.deepEqual(ADJUSTMENTS.map((a) => a.key), ['br', 'ct', 'sat', 'blur', 'gray', 'sepia', 'hue']);
  assert.equal(CROP_PRESETS.length, 6);
  assert.equal(defaultShadow(1280).blur, 16);
  for (const t of [...MASKS.map((m) => m.label), ...CROP_PRESETS.map((p) => p.label), ...ADJUSTMENTS.map((a) => a.label)]) assert.doesNotMatch(t, /[\u2013\u2014]/);
});
