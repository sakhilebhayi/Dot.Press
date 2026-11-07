import test from 'node:test';
import assert from 'node:assert/strict';
import {
  ALL_KINDS, KIND_ROWS, defaultShadow, defaultStrokeWidth, endsPatch, fillForMode, fillMode, isLineKind, kindSwapPatch, quickStylePatch, quickStyles,
} from '../shapeModel.js';

const deck = { w: 1920, h: 1080, theme: { s: { radius: 12, stroke: 2 } } };
const SPEC_KINDS = ['rect', 'round', 'ellipse', 'triangle', 'diamond', 'pentagon', 'hexagon', 'star', 'chevron', 'arrow-r', 'arrow-l', 'callout', 'cross', 'heart', 'line', 'arrow'];

test('the panel offers every kind in the spec and nothing else', () => {
  assert.deepEqual([...ALL_KINDS].sort(), [...SPEC_KINDS].sort());
  assert.equal(KIND_ROWS.every((r) => r.options.every((o) => o.title && o.icon === o.value)), true);
});

test('box to box keeps colours; rect gets the theme radius, others drop it', () => {
  const el = { kind: 'ellipse', fill: '@accent', sw: 0 };
  assert.deepEqual(kindSwapPatch(el, 'rect', deck), { kind: 'rect', radius: 12 });
  assert.deepEqual(kindSwapPatch({ kind: 'rect', radius: 30, fill: '#fff' }, 'star', deck), { kind: 'star', radius: null });
  assert.deepEqual(kindSwapPatch({ kind: 'rect', radius: 30 }, 'rect', deck), null);
  assert.equal(kindSwapPatch(el, 'blob', deck), null);
});

test('box to line moves the fill colour to the stroke so the line is visible', () => {
  const p = kindSwapPatch({ kind: 'rect', fill: '@accent', sw: 0, radius: 10, w: 300, h: 200 }, 'line', deck);
  assert.equal(p.stroke, '@accent');
  assert.equal(p.sw, 4);
  assert.equal(p.h, 0);
  assert.equal(p.fill, null);
  assert.deepEqual(p.ends, { s: 'none', e: 'none' });
  const arrow = kindSwapPatch({ kind: 'rect', fill: { g: 'linear', stops: [] }, stroke: '@bad', sw: 5 }, 'arrow', deck);
  assert.equal(arrow.stroke, '@bad');
  assert.equal(arrow.sw, 5);
  assert.deepEqual(arrow.ends, { s: 'none', e: 'arrow' });
});

test('line to box gives it a fill from the stroke and a height', () => {
  const p = kindSwapPatch({ kind: 'line', stroke: '@bad', sw: 6, w: 500, h: 0, ends: { s: 'dot', e: 'arrow' } }, 'ellipse', deck);
  assert.equal(p.fill, '@bad');
  assert.equal(p.stroke, null);
  assert.equal(p.sw, 0);
  assert.equal(p.ends, null);
  assert.equal(p.h, 300);
});

test('line to arrow and back keeps the start end', () => {
  assert.deepEqual(kindSwapPatch({ kind: 'line', ends: { s: 'dot', e: 'none' } }, 'arrow', deck).ends, { s: 'dot', e: 'arrow' });
  assert.deepEqual(kindSwapPatch({ kind: 'arrow', ends: { s: 'none', e: 'arrow' } }, 'line', deck).ends, { s: 'none', e: 'none' });
  assert.equal(isLineKind('arrow'), true);
  assert.equal(isLineKind('arrow-r'), false);
});

test('quick styles follow the theme tokens', () => {
  const styles = quickStyles();
  assert.ok(styles.every((s) => [s.fill, s.stroke].every((c) => c === null || c.startsWith('@'))));
  const accent = styles.find((s) => s.id === 'accent');
  assert.deepEqual(quickStylePatch({ kind: 'rect', sw: 4 }, accent, deck), { fill: '@accent', stroke: null, sw: 0 });
  const outline = styles.find((s) => s.id === 'outline');
  assert.deepEqual(quickStylePatch({ kind: 'rect', sw: 0 }, outline, deck), { fill: null, stroke: '@ink', sw: 2 });
  assert.deepEqual(quickStylePatch({ kind: 'line', sw: 6 }, accent, deck), { stroke: '@accent', sw: 6 });
});

test('fill modes', () => {
  assert.equal(fillMode(null), 'none');
  assert.equal(fillMode(undefined), 'none');
  assert.equal(fillMode('@accent'), 'solid');
  assert.equal(fillMode({ g: 'linear', a: 90, stops: [] }), 'gradient');
  assert.equal(fillMode('mixed'), 'mixed');
});

test('switching modes keeps the colour', () => {
  assert.equal(fillForMode('solid', null), '@accent');
  assert.equal(fillForMode('none', '@bad'), null);
  const g = fillForMode('gradient', '@bad');
  assert.deepEqual(g.stops[0], [0, '@bad']);
  assert.equal(g.g, 'linear');
  assert.equal(fillForMode('solid', g), '@bad');
  assert.equal(fillForMode('gradient', g), g);
  assert.notEqual(fillForMode('gradient', '@accent2').stops[1][1], '@accent2');
});

test('defaults scale with the deck', () => {
  assert.equal(defaultStrokeWidth({ w: 960, theme: { s: { stroke: 4 } } }), 2);
  assert.deepEqual(defaultShadow({ w: 960 }), { x: 0, y: 4, blur: 12, c: '#00000040' });
});

test('endsPatch changes one end and keeps the arrow default of an arrow line', () => {
  assert.deepEqual(endsPatch({ kind: 'line' }, 's', 'dot'), { s: 'dot', e: 'none' });
  assert.deepEqual(endsPatch({ kind: 'arrow' }, 's', 'dot'), { s: 'dot', e: 'arrow' });
  assert.deepEqual(endsPatch({ kind: 'arrow', ends: { s: 'none', e: 'none' } }, 'e', 'dot'), { s: 'none', e: 'dot' });
});
