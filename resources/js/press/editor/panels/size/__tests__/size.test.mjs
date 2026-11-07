import test from 'node:test';
import assert from 'node:assert/strict';
import { resizeDoc } from '../../../core/resize.js';
import { applyOps } from '../../../core/ops.js';
import { mkDoc, S, T, R } from '../../../core/__tests__/helpers.mjs';
import {
  MODES, PRESETS, crossing, modeFact, presetFor, previewPair, ratioLabel, validSize,
} from '../sizeModel.js';

const doc = () => mkDoc([
  S('s1', [T('t', 100, 100, 800, 200, { size: 80 }), R('r', 1500, 700, 300, 300, { sw: 4, radius: 20 })]),
  S('s2', [R('q', 0, 0, 1920, 1080)]),
]);

test('every preset is a size the server accepts and presets are recognised by size', () => {
  for (const p of PRESETS) assert.ok(validSize(p.w, p.h), p.id);
  assert.equal(presetFor(1920, 1080), '16-9');
  assert.equal(presetFor(1440, 1080), '4-3');
  assert.equal(presetFor(1080, 1920), 'portrait');
  assert.equal(presetFor(1000, 700), 'custom');
  assert.equal(PRESETS.length, 5);
});

test('custom sizes are whole numbers from 320 to 7680', () => {
  assert.ok(validSize(320, 7680));
  assert.ok(!validSize(319, 1080));
  assert.ok(!validSize(1920, 7681));
  assert.ok(!validSize(1920.5, 1080));
  assert.ok(!validSize(NaN, 1080));
  assert.ok(!validSize(undefined, 1080));
});

test('ratios are named in lowest terms, or as decimals when they are awkward', () => {
  assert.equal(ratioLabel(1920, 1080), '16:9');
  assert.equal(ratioLabel(1440, 1080), '4:3');
  assert.equal(ratioLabel(1080, 1920), '9:16');
  assert.equal(ratioLabel(1080, 1080), '1:1');
  assert.equal(ratioLabel(1920, 1358), '1.41:1');
  assert.equal(ratioLabel(0, 10), '');
});

test('there are three modes, each with a label and an explanation sentence', () => {
  assert.deepEqual(MODES.map((m) => m.id), ['scale', 'fit', 'anchor']);
  for (const m of MODES) assert.ok(m.label && m.text.endsWith('.'));
});

test('the preview is computed on a copy with the same geometry the server uses', () => {
  const d = doc();
  for (const mode of ['scale', 'fit', 'anchor']) {
    const p = previewPair(d, 's1', 1080, 1920, mode);
    const full = resizeDoc(d, 1080, 1920, mode);
    assert.deepEqual(p.after.slide, full.slides[0], mode);
    assert.deepEqual(p.after.deck.w, 1080);
    assert.equal(p.before.deck.w, 1920);
    assert.equal(p.before.slide, d.slides[0]);
  }
  assert.equal(d.deck.w, 1920); // the document is untouched
  assert.equal(d.slides[0].els[0].x, 100);
});

test('scale to fit letterboxes: the content is centred and nothing crosses the edge', () => {
  const d = doc();
  const p = previewPair(d, 's2', 1080, 1920, 'fit');
  const q = p.after.slide.els[0];
  assert.equal(q.w, 1080);
  assert.equal(q.x, 0);
  assert.ok(q.y > 0);
  assert.deepEqual(crossing(d, 1080, 1920, 'fit'), { elements: 0, slides: 0 });
});

test('scale to fill reports the elements that end up past the edge', () => {
  const d = doc();
  const x = crossing(d, 1080, 1920, 'scale');
  assert.ok(x.elements >= 2);
  assert.equal(x.slides, 2);
  assert.match(modeFact(d, 1080, 1920, 'scale'), /past the edge on 2 slides/);
  assert.match(modeFact(d, 1080, 1920, 'fit'), /Bars appear/);
  assert.equal(modeFact(d, 1920, 1080, 'fit'), 'The shapes match, so no bars appear.');
  assert.equal(modeFact(d, 3840, 2160, 'scale'), 'The shapes match, so nothing is cut off.');
});

test('keeping sizes moves blocks in proportion and never resizes text', () => {
  const d = doc();
  const p = previewPair(d, 's1', 1440, 1080, 'anchor');
  const t = p.after.slide.els[0];
  assert.equal(t.w, 800);
  assert.equal(t.size, 80);
  assert.equal(t.x, -25); // 100 + (1440 - 1920) * (500 / 1920): its centre was left of the middle
});

test('the deck.resize op the panel sends is the one the core applies, and its inverse restores the document', () => {
  const d = doc();
  const r = applyOps(d, [{ op: 'deck.resize', w: 1440, h: 1080, mode: 'scale' }]);
  assert.equal(r.doc.deck.w, 1440);
  assert.equal(r.applied.length, 1);
  const back = applyOps(r.doc, r.inverse).doc;
  assert.deepEqual(back.slides, d.slides);
  assert.equal(back.deck.w, 1920);
  assert.equal(applyOps(d, [{ op: 'deck.resize', w: 100, h: 1080, mode: 'scale' }]).applied.length, 0);
});
