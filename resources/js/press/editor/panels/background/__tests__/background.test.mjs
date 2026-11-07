import test from 'node:test';
import assert from 'node:assert/strict';
import { DEFAULT_THEME } from '../../../core/theme.js';
import { applyOps } from '../../../core/ops.js';
import { mkDoc, S } from '../../../core/__tests__/helpers.mjs';
import {
  DIM_MAX, applyToAllOps, bgContrast, bgKind, bgSummary, imageBg, startValue, themeWithBg, withImage,
} from '../backgroundModel.js';

const grad = { g: 'linear', a: 90, stops: [[0, '#000000'], [1, '#ffffff']] };

test('a background is told apart: theme (null), colour, gradient or image', () => {
  assert.equal(bgKind(null), 'theme');
  assert.equal(bgKind(undefined), 'theme');
  assert.equal(bgKind('@surface'), 'solid');
  assert.equal(bgKind('#112233'), 'solid');
  assert.equal(bgKind(grad), 'gradient');
  assert.equal(bgKind(imageBg(4)), 'image');
});

test('switching kind starts from something sensible and keeps what can be kept', () => {
  assert.equal(startValue('theme', DEFAULT_THEME, '#123456'), null);
  assert.equal(startValue('solid', DEFAULT_THEME, null), '@bg');
  assert.equal(startValue('solid', DEFAULT_THEME, '#123456'), '#123456');
  assert.equal(startValue('solid', DEFAULT_THEME, grad), '#000000');
  const g = startValue('gradient', DEFAULT_THEME, '@accent');
  assert.equal(g.g, 'linear');
  assert.equal(g.stops[0][1], '#2563eb');
  assert.equal(g.stops.length, 2);
  assert.equal(startValue('gradient', DEFAULT_THEME, grad), grad);
  assert.equal(startValue('image', DEFAULT_THEME, null), null); // a picture is picked first
  const img = imageBg(3, { dim: 0.2 });
  assert.equal(startValue('image', DEFAULT_THEME, img), img);
});

test('image backgrounds carry the asset, fit and dim; dim and fit are kept in range', () => {
  const img = imageBg(12, { fit: 'contain', dim: 0.3 });
  assert.deepEqual(img, { img: { asset: 12 }, fit: 'contain', dim: 0.3 });
  assert.equal(withImage(img, { dim: 5 }).dim, DIM_MAX);
  assert.equal(withImage(img, { dim: -1 }).dim, 0);
  assert.equal(withImage(img, { fit: 'weird' }).fit, 'cover');
  assert.equal(withImage('#fff', { dim: 0.5 }), '#fff');
  assert.equal(imageBg(1).fit, 'cover');
});

test('contrast of the theme ink on a background: a warning for a clash, nothing for a picture', () => {
  assert.equal(bgContrast(null, DEFAULT_THEME).level, 'ok');
  const dark = bgContrast('#000000', DEFAULT_THEME);
  assert.equal(dark.level, 'issue');
  assert.match(dark.text, /hard to read/);
  assert.equal(bgContrast('#ffffff', DEFAULT_THEME).level, 'ok');
  const g = bgContrast(grad, DEFAULT_THEME); // the worst stop counts
  assert.equal(g.level, 'issue');
  assert.equal(bgContrast(imageBg(1), DEFAULT_THEME), null);
  const mid = bgContrast('#8a8f98', DEFAULT_THEME);
  assert.ok(['warn', 'issue'].includes(mid.level));
});

test('the deck default background is what a slide with no background shows in the check', () => {
  const dark = { ...DEFAULT_THEME, bg: '#000000' };
  assert.equal(bgContrast(null, dark).level, 'issue');
});

test('summaries read naturally', () => {
  assert.equal(bgSummary(null, DEFAULT_THEME), 'Follows the deck default');
  assert.equal(bgSummary('@accent', DEFAULT_THEME), 'Theme colour @accent');
  assert.equal(bgSummary('#abcdef', DEFAULT_THEME), '#ABCDEF');
  assert.equal(bgSummary(grad, DEFAULT_THEME), 'Gradient, 2 stops');
  assert.equal(bgSummary(imageBg(2, { dim: 0.3 }), DEFAULT_THEME, { 2: { name: 'site.png' } }), 'Image: site.png, dimmed 30%');
});

test('apply to all slides produces one slide.set per slide that differs, and undoes cleanly', () => {
  const doc = mkDoc([S('a', [], { bg: '#111111' }), S('b', [], { bg: null }), S('c', [], { bg: '#222222' })]);
  const ops = applyToAllOps(doc, '#111111');
  assert.deepEqual(ops.map((o) => o.id), ['b', 'c']);
  assert.ok(ops.every((o) => o.op === 'slide.set' && o.bg === '#111111'));
  const r = applyOps(doc, ops);
  assert.deepEqual(r.doc.slides.map((s) => s.bg), ['#111111', '#111111', '#111111']);
  const back = applyOps(r.doc, r.inverse).doc;
  assert.deepEqual(back.slides.map((s) => s.bg), ['#111111', null, '#222222']);
  assert.deepEqual(applyToAllOps(doc, '#111111').length, 2);
  assert.equal(applyToAllOps(mkDoc([S('a', [], { bg: null })]), null).length, 0);
  assert.equal(applyToAllOps(doc, null).filter((o) => o.bg === null).length, 2);
});

test('the deck default is the theme bg, set without touching the rest of the theme', () => {
  const t = themeWithBg(DEFAULT_THEME, '#101010');
  assert.equal(t.bg, '#101010');
  assert.equal(t.id, 'slate');
  assert.equal(DEFAULT_THEME.bg, null);
  assert.equal(themeWithBg(t, undefined).bg, null);
  const doc = mkDoc([S('a')]);
  const out = applyOps(doc, [{ op: 'deck.set', theme: t }]).doc;
  assert.equal(out.deck.theme.bg, '#101010');
});
