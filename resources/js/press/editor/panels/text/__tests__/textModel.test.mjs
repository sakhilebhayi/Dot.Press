import test from 'node:test';
import assert from 'node:assert/strict';
import {
  bulkRolePlan, styleOfElement, docIndent, docIndentDepth, docListKind, docSetList, fittedSize, isTextual, presetDefs, presetOfSelection, presetPatches, resolvePreset, weightOptions,
} from '../textModel.js';

const deck = { w: 1920, h: 1080, theme: { t: { heading: 'Literata', body: 'Inter', hw: 600, scale: 1 } } };
const para = (t, marks) => ({ type: 'paragraph', content: [{ type: 'text', text: t, ...(marks ? { marks } : {}) }] });
const doc = (...ps) => ({ type: 'doc', content: ps });
const text = (id, extra = {}) => ({ id, type: 'text', x: 0, y: 0, w: 100, h: 50, doc: doc(para('Hi')), ...extra });

test('presets follow deck size, theme weight and type scale', () => {
  const p = resolvePreset('title', deck);
  assert.equal(p.patch.size, 88);
  assert.equal(p.patch.weight, 600);
  assert.equal(p.patch.font, '@heading');
  assert.equal(p.patch.role, 'title');
  const small = resolvePreset('title', { w: 1280, h: 720, theme: { t: { scale: 1.5 } } });
  assert.equal(small.patch.size, 88 * (1280 / 1920) * 1.5);
  assert.equal(presetDefs(deck).map((d) => d.id).join(), 'title,heading,body,caption,quote');
});

test('every preset role is one the server accepts, and Quote sets none', () => {
  const allowed = ['title', 'subtitle', 'body', 'media', 'caption', 'footer', 'number', 'kpi'];
  for (const d of presetDefs(deck)) if (d.role) assert.ok(allowed.includes(d.role), d.id);
  assert.equal(resolvePreset('quote', deck).patch.role, undefined);
  assert.equal(resolvePreset('nope', deck), null);
});

test('presetPatches skips non text, skips elements that already match, clears inline overrides', () => {
  const title = resolvePreset('title', deck);
  const plain = text('a');
  const styled = text('b', { doc: doc(para('x', [{ type: 'textStyle', attrs: { fontSize: '30px', color: '#ff0000' } }, { type: 'bold' }])) });
  const image = { id: 'i', type: 'image' };
  const matching = text('c', { ...title.patch });
  const out = presetPatches([plain, styled, image, matching], title);
  assert.deepEqual(out.map((o) => o.id), ['a', 'b']);
  const marks = out[1].p.doc.content[0].content[0].marks;
  assert.deepEqual(marks, [{ type: 'bold' }]);
  assert.equal(out[0].p.doc, undefined);
});

test('shapes with a doc count as text, shapes without do not', () => {
  assert.equal(isTextual({ type: 'shape', doc: doc(para('a')) }), true);
  assert.equal(isTextual({ type: 'shape' }), false);
  assert.equal(isTextual({ type: 'text' }), true);
});

test('presetOfSelection reads the role', () => {
  assert.equal(presetOfSelection([text('a', { role: 'title' })]), 'title');
  assert.equal(presetOfSelection([text('a', { role: 'title' }), text('b', { role: 'body' })]), 'mixed');
  assert.equal(presetOfSelection([text('a')]), null);
  assert.equal(presetOfSelection([{ type: 'image' }]), null);
});

test('bulkRolePlan counts, skips locked, skips already matching, spans slides', () => {
  const title = resolvePreset('title', deck);
  const slides = [
    { id: '1', els: [text('a', { role: 'title' }), text('b', { role: 'body' }), text('c', { role: 'title', lock: true })] },
    { id: '2', els: [text('d', { role: 'title' }), text('e', { role: 'title', ...title.patch })] },
    { id: '3', els: [{ id: 'f', type: 'shape', role: 'title' }] },
  ];
  const plan = bulkRolePlan(slides, 'title', title);
  assert.equal(plan.matching, 4);
  assert.equal(plan.locked, 1);
  assert.equal(plan.changing, 2);
  assert.equal(plan.slides, 2);
  assert.deepEqual(plan.ops.map((o) => [o.slide, o.id]), [['1', 'a'], ['2', 'd']]);
  assert.equal(plan.ops[0].op, 'el.set');
  assert.equal(bulkRolePlan(slides, null, title).changing, 0);
});

test('weightOptions follow the family range and keep the current weight', () => {
  const w = weightOptions('IBM Plex Mono', null, 400).map((o) => o.value);
  assert.deepEqual(w, [400, 500, 600, 700]);
  assert.deepEqual(weightOptions('@heading', deck.theme, 400).map((o) => o.value).slice(0, 2), [200, 300]);
  assert.ok(weightOptions('Caveat', null, 800).some((o) => o.value === 800));
  assert.equal(weightOptions('Inter', null, 700).find((o) => o.value === 700).label, 'Bold');
});

test('fittedSize', () => {
  assert.equal(fittedSize(48, 0.5), 24);
  assert.equal(fittedSize(48, 1), 48);
  assert.equal(fittedSize(48, undefined), 48);
  assert.equal(fittedSize(33, 0.5), 16.5);
});

test('lists: set, switch, clear', () => {
  const d = doc(para('a'), para('b'));
  assert.equal(docListKind(d), 'none');
  const b = docSetList(d, 'bullet');
  assert.equal(docListKind(b), 'bullet');
  assert.equal(b.content.length, 1);
  assert.equal(b.content[0].content.length, 2);
  const o = docSetList(b, 'ordered');
  assert.equal(docListKind(o), 'ordered');
  const back = docSetList(o, null);
  assert.deepEqual(back, d);
  assert.equal(docListKind(doc(para('a'), { type: 'bulletList', content: [{ type: 'listItem', content: [para('b')] }] })), 'mixed');
});

test('indent and outdent move a whole list one level', () => {
  const b = docSetList(doc(para('a'), para('b')), 'bullet');
  assert.equal(docIndentDepth(b), 1);
  const in1 = docIndent(b, 1);
  assert.equal(docIndentDepth(in1), 2);
  assert.deepEqual(docIndent(in1, -1), b);
  assert.deepEqual(docIndent(b, -1), b);
  let d = b;
  for (let i = 0; i < 9; i += 1) d = docIndent(d, 1);
  assert.equal(docIndentDepth(d), 5);
  const plain = doc(para('x'));
  assert.deepEqual(docIndent(plain, 1), plain);
});

test('styleOfElement carries missing values as null so other texts lose them too', () => {
  assert.deepEqual(styleOfElement({ font: '@body', size: 40, color: '@ink' }), { font: '@body', size: 40, weight: null, color: '@ink' });
  const plan = bulkRolePlan([{ id: '1', els: [text('a', { role: 'body', weight: 700, font: '@body', size: 40, color: '@ink' })] }], 'body', { patch: styleOfElement({ font: '@body', size: 40, color: '@ink' }) });
  assert.equal(plan.ops[0].p.weight, null);
  assert.equal(plan.changing, 1);
});
