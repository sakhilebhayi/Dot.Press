import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeHex, splitAlpha, joinAlpha, toRgb, fromRgb, pushRecent, gradientCss, newGradient, addStop, removeStop, moveStop, setStopColor, normAngle } from '../colors.js';
import { snap, parseNumber, formatNumber, nudge, scrub, decimals } from '../numbers.js';
import { MIXED, getPath, resolveMixed, readProp, patchFor, buildPatches, panelUndoKey } from '../mixed.js';
import { moveItem, dropIndex, stepIndex } from '../reorder.js';

test('normalizeHex accepts short, long, bare and alpha forms', () => {
  assert.equal(normalizeHex('#ABC'), '#aabbcc');
  assert.equal(normalizeHex('abc'), '#aabbcc');
  assert.equal(normalizeHex(' #11223344 '), '#11223344');
  assert.equal(normalizeHex('#112233ff'), '#112233');
  assert.equal(normalizeHex('#1234'), '#11223344');
  for (const bad of ['', '#12', '#12345', 'rgb(1,2,3)', '#gggggg', null, 12]) assert.equal(normalizeHex(bad), null);
});

test('alpha split and join round trip', () => {
  assert.deepEqual(splitAlpha('#112233'), { rgb: '#112233', alpha: 1 });
  assert.deepEqual(splitAlpha('#11223380'), { rgb: '#112233', alpha: 0.502 });
  assert.equal(joinAlpha('#112233', 1), '#112233');
  assert.equal(joinAlpha('#112233', 0.5), '#11223380');
  assert.equal(joinAlpha('#112233', 0), '#11223300');
  assert.equal(joinAlpha('#112233', 2), '#112233');
  assert.equal(joinAlpha('nope', 0.5), null);
  assert.deepEqual(toRgb('#ff8000'), { r: 255, g: 128, b: 0 });
  assert.equal(fromRgb(255, 128, 0), '#ff8000');
  assert.equal(fromRgb(300, -5, 0.4), '#ff0000');
});

test('recent colours: newest first, no duplicates, no tokens, capped', () => {
  let l = [];
  for (const c of ['#111111', '#222222', '@accent', 'zzz', '#111', '#333333']) l = pushRecent(l, c, 3);
  assert.deepEqual(l, ['#333333', '#111111', '#222222']);
  assert.deepEqual(pushRecent(['#111111'], '#111111'), ['#111111']);
});

test('gradient editing keeps 2 to 8 sorted stops', () => {
  let g = newGradient('#000000', '#ffffff', 45);
  assert.equal(gradientCss(g), 'linear-gradient(45deg, #000000 0%, #ffffff 100%)');
  g = addStop(g, 0.5, '#ff0000');
  assert.deepEqual(g.stops.map((s) => s[0]), [0, 0.5, 1]);
  g = moveStop(g, 1, 1.4);
  assert.deepEqual(g.stops.map((s) => s[0]), [0, 1, 1]);
  g = moveStop(g, 0, -3);
  assert.equal(g.stops[0][0], 0);
  g = setStopColor(g, 2, '@accent');
  assert.equal(g.stops[2][1], '@accent');
  assert.equal(gradientCss(g, (c) => (c === '@accent' ? '#2563eb' : c)).includes('#2563eb'), true);
  g = removeStop(g, 1);
  assert.equal(g.stops.length, 2);
  assert.equal(removeStop(g, 0), g);
  let big = g;
  for (let i = 0; i < 12; i += 1) big = addStop(big, i / 12, '#123456');
  assert.equal(big.stops.length, 8);
  assert.equal(normAngle(-90), 270);
  assert.equal(normAngle(725), 5);
});

test('number parsing and formatting', () => {
  assert.equal(parseNumber('12'), 12);
  assert.equal(parseNumber('12.5px'), 12.5);
  assert.equal(parseNumber('12,5'), 12.5);
  assert.equal(parseNumber(' -3 %'), -3);
  assert.equal(parseNumber('−3'), -3);
  assert.equal(parseNumber('.5'), 0.5);
  for (const bad of ['', 'abc', null, undefined, NaN]) assert.equal(parseNumber(bad), null);
  assert.equal(formatNumber(12.5, 0.1), '12.5');
  assert.equal(formatNumber(12, 0.1), '12');
  assert.equal(formatNumber(1 / 3, 0.01), '0.33');
  assert.equal(formatNumber(NaN), '');
  assert.equal(decimals(0.25), 2);
  assert.equal(decimals(5), 0);
});

test('snap clamps and follows the step grid', () => {
  assert.equal(snap(7.4, { step: 1 }), 7);
  assert.equal(snap(7.26, { step: 0.5 }), 7.5);
  assert.equal(snap(-5, { min: 0 }), 0);
  assert.equal(snap(500, { max: 100 }), 100);
  assert.equal(snap(13, { min: 3, step: 5 }), 13);
  assert.equal(snap(0.1 + 0.2, { step: 0.1 }), 0.3);
  assert.equal(snap(NaN), null);
});

test('arrow nudge and shift', () => {
  assert.equal(nudge(10, 1), 11);
  assert.equal(nudge(10, -1, { shift: true }), 0);
  assert.equal(nudge(0.5, 1, { step: 0.1, shift: true }), 1.5);
  assert.equal(nudge(99, 1, { max: 100, shift: true }), 100);
  assert.equal(nudge(null, 1), 1);
});

test('label scrubbing: pixels per step, shift coarse, alt fine, clamped', () => {
  assert.equal(scrub(10, 8, { pxPerStep: 4 }), 12);
  assert.equal(scrub(10, -8, { pxPerStep: 4 }), 8);
  assert.equal(scrub(10, 8, { pxPerStep: 4, shift: true }), 30);
  assert.equal(scrub(10, 8, { pxPerStep: 4, alt: true, step: 1 }), 10.2);
  assert.equal(scrub(10, 4000, { max: 100 }), 100);
  assert.equal(scrub(10, -4000, { min: 0 }), 0);
  assert.equal(scrub(1, 10, { step: 0.1, pxPerStep: 5 }), 1.2);
});

test('mixed value resolution', () => {
  assert.equal(resolveMixed([]), undefined);
  assert.equal(resolveMixed([4]), 4);
  assert.equal(resolveMixed([4, 4, 4]), 4);
  assert.equal(resolveMixed([4, 5]), MIXED);
  assert.equal(resolveMixed([{ a: 1 }, { a: 1 }]).a, 1);
  assert.equal(resolveMixed([{ a: 1 }, { a: 2 }]), MIXED);
  const els = [{ id: 'a', size: 40, shadow: { blur: 4 } }, { id: 'b', size: 40 }, { id: 'c', size: 60, shadow: { blur: 4 } }];
  assert.equal(readProp(els.slice(0, 2), 'size'), 40);
  assert.equal(readProp(els, 'size'), MIXED);
  assert.equal(readProp(els, 'shadow.blur'), MIXED);
  assert.equal(readProp([els[0], els[2]], 'shadow.blur'), 4);
  assert.equal(readProp([els[1]], 'shadow.blur', { fallback: 0 }), 0);
  assert.equal(readProp([els[0], els[1]], 'shadow.blur', { fallback: 4 }), 4);
  assert.equal(getPath(els[0], 'shadow.blur'), 4);
  assert.equal(getPath(els[1], 'shadow.blur'), undefined);
});

test('patches: top level, nested merge, delete, skip no ops', () => {
  const el = { id: 'a', size: 40, shadow: { x: 1, blur: 4, c: '#000' } };
  assert.deepEqual(patchFor(el, 'size', 50), { size: 50 });
  assert.deepEqual(patchFor(el, 'size', null), { size: null });
  assert.deepEqual(patchFor(el, 'shadow.blur', 8), { shadow: { x: 1, blur: 8, c: '#000' } });
  assert.deepEqual(patchFor(el, 'shadow.blur', null), { shadow: { x: 1, c: '#000' } });
  assert.deepEqual(patchFor({ id: 'z' }, 'shadow.blur', 3), { shadow: { blur: 3 } });
  assert.equal(el.shadow.blur, 4, 'the element is never mutated');
  const els = [el, { id: 'b', size: 50 }, { id: 'c' }];
  assert.deepEqual(buildPatches(els, 'size', 50), [{ id: 'a', p: { size: 50 } }, { id: 'c', p: { size: 50 } }]);
  assert.deepEqual(buildPatches(els, 'size', (e) => (e.size || 0) + 1).map((p) => p.p.size), [41, 51, 1]);
  assert.deepEqual(buildPatches(els, 'weight', null), []);
  assert.equal(panelUndoKey('size', els), 'panel:size:a,b,c');
});

test('reordering', () => {
  assert.deepEqual(moveItem(['a', 'b', 'c', 'd'], 0, 2), ['b', 'c', 'a', 'd']);
  assert.deepEqual(moveItem(['a', 'b', 'c', 'd'], 3, 0), ['d', 'a', 'b', 'c']);
  assert.deepEqual(moveItem(['a', 'b'], 1, 9), ['a', 'b']);
  const same = ['a', 'b'];
  assert.equal(moveItem(same, 1, 1), same);
  assert.equal(dropIndex([10, 30, 50, 70], 55, 0), 2);
  assert.equal(dropIndex([10, 30, 50, 70], 5, 3), 0);
  assert.equal(dropIndex([10, 30, 50, 70], 100, 1), 3);
  assert.equal(dropIndex([10, 30, 50, 70], 35, 1), 1);
  assert.equal(stepIndex(0, -1, 4), 0);
  assert.equal(stepIndex(3, 1, 4), 3);
  assert.equal(stepIndex(1, 1, 4), 2);
});
