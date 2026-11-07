import test from 'node:test';
import assert from 'node:assert/strict';
import { describeStyle, pasteStylePatches, pickStyle } from '../styleModel.js';

const para = { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'x' }] }] };
const rect = { id: 'r', type: 'shape', kind: 'rect', fill: '@accent', stroke: '@ink', sw: 4, radius: 12, dash: 'dash', op: 0.8, shadow: { x: 0, y: 8, blur: 20, c: '#00000040' } };
const plainRect = { id: 'p', type: 'shape', kind: 'rect', fill: '@surface', sw: 0, op: 1 };
const line = { id: 'l', type: 'shape', kind: 'line', stroke: '@bad', sw: 6, dash: 'dot' };
const text = { id: 't', type: 'text', doc: para, font: '@heading', size: 60, weight: 700, color: '@accent', lh: 1.1, fill: '@bg' };
const image = { id: 'i', type: 'image', op: 0.5 };

test('pickStyle keeps the look and not the content or position', () => {
  const s = pickStyle(rect);
  assert.deepEqual(Object.keys(s.props).sort(), ['dash'.length ? 'fill' : '', 'op', 'radius', 'shadow', 'stroke', 'sw'].sort());
  assert.equal(s.hasText, false);
  const t = pickStyle(text);
  assert.equal(t.hasText, true);
  assert.equal(t.props.font, '@heading');
  assert.equal(t.props.doc, undefined);
  assert.equal(pickStyle(null), null);
  assert.equal(pickStyle({ type: 'mystery' }), null);
});

test('a style is a copy, not a reference to the source', () => {
  const s = pickStyle(rect);
  s.props.shadow.blur = 99;
  assert.equal(rect.shadow.blur, 20);
});

test('box to box: fill, stroke, radius, shadow, opacity; missing keys are removed', () => {
  const out = pasteStylePatches(pickStyle(rect), [plainRect]);
  assert.deepEqual(out[0].p, { fill: '@accent', stroke: '@ink', sw: 4, radius: 12, shadow: { x: 0, y: 8, blur: 20, c: '#00000040' }, op: 0.8 });
  const back = pasteStylePatches(pickStyle(plainRect), [rect]);
  assert.equal(back[0].p.stroke, null);
  assert.equal(back[0].p.shadow, null);
  assert.equal(back[0].p.fill, '@surface');
});

test('a rectangle look never gives a line a fill or removes its colour', () => {
  const out = pasteStylePatches(pickStyle(rect), [line]);
  assert.equal(out[0].p.fill, undefined);
  assert.equal(out[0].p.stroke, '@ink');
  assert.equal(out[0].p.sw, 4);
  const fillOnly = pasteStylePatches(pickStyle(plainRect), [line]);
  assert.equal(fillOnly[0].p.stroke, '@surface');
});

test('a line look gives a box stroke and width only', () => {
  const out = pasteStylePatches(pickStyle(line), [plainRect]);
  assert.equal(out[0].p.stroke, '@bad');
  assert.equal(out[0].p.sw, 6);
  assert.equal(out[0].p.fill, undefined);
  assert.equal(out[0].p.dash, undefined);
});

test('text look goes to texts and shapes with text only when the source had text', () => {
  const target = { id: 'x', type: 'text', doc: para, size: 20 };
  const out = pasteStylePatches(pickStyle(text), [target, image]);
  assert.equal(out.find((o) => o.id === 'x').p.font, '@heading');
  assert.equal(out.find((o) => o.id === 'x').p.size, 60);
  assert.deepEqual(out.find((o) => o.id === 'i').p, { op: null }); // only opacity and shadow reach an image
  const keep = pasteStylePatches(pickStyle(rect), [{ ...target, font: '@body' }]);
  assert.equal(keep[0].p.font, undefined);
  const shapeText = { id: 's', type: 'shape', kind: 'rect', doc: para, size: 12 };
  assert.equal(pasteStylePatches(pickStyle(text), [shapeText])[0].p.size, 60);
});

test('opacity and shadow reach images', () => {
  const out = pasteStylePatches(pickStyle(rect), [{ id: 'i', type: 'image' }]);
  assert.deepEqual(Object.keys(out[0].p).sort(), ['op', 'shadow']);
});

test('nothing is produced when the look already matches', () => {
  assert.deepEqual(pasteStylePatches(pickStyle(rect), [{ ...rect, id: 'copy' }]), []);
  assert.deepEqual(pasteStylePatches(null, [rect]), []);
});

test('describeStyle', () => {
  assert.equal(describeStyle(pickStyle(rect)), 'Fill, stroke, shadow and opacity');
  assert.equal(describeStyle(pickStyle(image)), 'Opacity');
  assert.equal(describeStyle(pickStyle(text)), 'Fill and font');
  assert.equal(describeStyle(null), '');
});
