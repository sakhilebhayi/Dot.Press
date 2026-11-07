import test from 'node:test';
import assert from 'node:assert/strict';
import { applyLayout, saveLayoutFromSlide, SAMPLE_LAYOUTS } from '../layouts.js';
import { applyOps } from '../ops.js';
import { newText } from '../doc.js';
import { mkDoc, S, T, R, content, mulberry32 } from './helpers.mjs';

const { titleBody, twoColumns, titleMedia } = SAMPLE_LAYOUTS;
const apply = (doc, ops) => applyOps(doc, ops).doc;

test('an empty slide gets the placeholders, as template elements with roles', () => {
  const doc = mkDoc([S('s1')]);
  const { ops, report } = applyLayout(doc, 's1', titleBody, { rng: mulberry32(1) });
  assert.equal(ops[0].op, 'slide.set');
  assert.equal(ops[0].layout, 'bullets');
  const adds = ops.filter((o) => o.op === 'el.add');
  assert.equal(adds.length, 2);
  assert.deepEqual(adds.map((o) => o.el.role), ['title', 'body']);
  assert.equal(adds[0].el.x, 115.2);
  assert.equal(adds[0].el.w, 1689.6);
  assert.equal(adds[0].el.font, '@heading');
  assert.equal(adds[0].el.size, 75.6);
  assert.ok(adds.every((o) => o.el.origin === 'template'));
  assert.equal(report.added.length, 2);
  const out = apply(doc, ops);
  assert.equal(out.slides[0].layout, 'bullets');
  assert.equal(out.slides[0].els.length, 2);
});

test('existing content is matched by role first, then by type and order; text and style are kept', () => {
  const doc = mkDoc([S('s1', [
    T('t1', 10, 10, 50, 20, { size: 33, color: '#ff0000' }),
    T('t2', 10, 100, 50, 20),
    R('r1', 0, 0, 5, 5),
  ])]);
  const { ops, report } = applyLayout(doc, 's1', titleBody);
  assert.deepEqual(report.matched, [{ id: 't1', role: 'title' }, { id: 't2', role: 'body' }]);
  assert.deepEqual(report.unmatched, [{ id: 'r1', reason: 'no_placeholder' }]);
  assert.equal(ops.filter((o) => o.op === 'el.add').length, 0);
  const set = ops.find((o) => o.op === 'el.set' && o.id === 't1');
  assert.deepEqual(Object.keys(set.p).sort(), ['h', 'role', 'w', 'x', 'y']);
  const out = apply(doc, ops);
  const t1 = out.slides[0].els.find((e) => e.id === 't1');
  assert.equal(t1.size, 33, 'user styling kept');
  assert.equal(t1.color, '#ff0000');
  assert.equal(t1.role, 'title');
  assert.equal(out.slides[0].els.find((e) => e.id === 'r1').x, 0, 'unmatched content stays where it is');
});

test('role matches win over order, and several body placeholders take elements in reading order', () => {
  const doc = mkDoc([S('s1', [
    T('right', 900, 300, 400, 300, { role: 'body' }),
    T('left', 100, 300, 400, 300, { role: 'body' }),
    T('head', 100, 50, 400, 100, { role: 'title' }),
  ])]);
  const { ops, report } = applyLayout(doc, 's1', twoColumns);
  assert.deepEqual(report.matched.map((m) => m.id), ['head', 'left', 'right']);
  const out = apply(doc, ops);
  const left = out.slides[0].els.find((e) => e.id === 'left');
  const right = out.slides[0].els.find((e) => e.id === 'right');
  assert.ok(left.x < right.x);
  assert.equal(left.x, 115.2);
});

test('never deletes user content: more elements than placeholders, locked elements, unknown roles', () => {
  const doc = mkDoc([S('s1', [
    T('a', 0, 0, 10, 10), T('b', 0, 0, 10, 10), T('c', 0, 0, 10, 10, { lock: true }), T('d', 0, 0, 10, 10, { role: 'caption' }), T('e', 0, 0, 10, 10),
  ])]);
  const { ops, report } = applyLayout(doc, 's1', titleBody);
  assert.ok(ops.every((o) => o.op !== 'el.remove'));
  assert.deepEqual(report.unmatched.map((u) => [u.id, u.reason]).sort(), [['c', 'locked'], ['d', 'no_placeholder'], ['e', 'no_placeholder']]);
  const out = apply(doc, ops);
  assert.equal(out.slides[0].els.length, 5);
  assert.equal(out.slides[0].els.find((e) => e.id === 'c').x, 0, 'locked stays put');
});

test('rotated matches are straightened; media placeholders take images, charts, tables', () => {
  const doc = mkDoc([S('s1', [
    { id: 'img', type: 'image', x: 0, y: 0, w: 10, h: 10, rot: 20, src: { url: 'https://x.test/a.png' } },
    T('t', 0, 0, 10, 10),
  ])]);
  const { ops, report } = applyLayout(doc, 's1', titleMedia);
  assert.deepEqual(report.matched.map((m) => [m.id, m.role]), [['t', 'title'], ['img', 'media']]);
  const set = ops.find((o) => o.id === 'img');
  assert.equal(set.p.rot, 0);
  assert.equal(set.p.role, 'media');
});

test('missing media placeholders become a visible frame the user can replace; addMissing false skips them', () => {
  const doc = mkDoc([S('s1', [T('t', 0, 0, 10, 10)])]);
  const { ops } = applyLayout(doc, 's1', titleMedia, { rng: mulberry32(2) });
  const frame = ops.find((o) => o.op === 'el.add').el;
  assert.equal(frame.type, 'shape');
  assert.equal(frame.role, 'media');
  assert.equal(frame.fill, '@surface');
  const none = applyLayout(doc, 's1', titleMedia, { addMissing: false });
  assert.equal(none.ops.filter((o) => o.op === 'el.add').length, 0);
});

test('applying the same layout twice changes nothing the second time', () => {
  const doc = mkDoc([S('s1')]);
  const once = apply(doc, applyLayout(doc, 's1', twoColumns, { rng: mulberry32(3) }).ops);
  const again = applyLayout(once, 's1', twoColumns);
  assert.deepEqual(again.ops, []);
  assert.deepEqual(content(apply(once, again.ops)), content(once));
});

test('a layout background is used only when the slide has none', () => {
  const lay = { ...titleBody, bg: '@surface' };
  const empty = mkDoc([S('s1')]);
  assert.equal(applyLayout(empty, 's1', lay).ops[0].bg, '@surface');
  const own = mkDoc([S('s1', [], { bg: '#000000' })]);
  assert.equal(applyLayout(own, 's1', lay).ops[0].bg, undefined);
});

test('layouts: bad input is a no-op', () => {
  const doc = mkDoc([S('s1')]);
  assert.deepEqual(applyLayout(doc, 'nope', titleBody).ops, []);
  assert.deepEqual(applyLayout(doc, 's1', null).ops, []);
});

test('saveLayoutFromSlide produces a definition that reproduces the slide', () => {
  const deck = { w: 1920, h: 1080 };
  const title = newText(deck, { role: 'title', text: 'My title', id: 'tt', x: 192, y: 108, w: 1536, h: 162 });
  const body = newText(deck, { text: 'Body copy', id: 'bb', x: 192, y: 324, w: 1536, h: 540, size: 44 });
  const img = { id: 'im', type: 'image', x: 960, y: 324, w: 768, h: 540, rot: 0, src: { url: 'https://x.test/a.png' } };
  const slide = S('s1', [title, body, img, R('dec', 0, 0, 10, 10, { hide: true })]);
  const lay = saveLayoutFromSlide(slide, deck, { name: 'My Layout!' });
  assert.equal(lay.id, 'custom:my-layout');
  assert.deepEqual(lay.ph.map((p) => p.role), ['title', 'body', 'media']);
  assert.deepEqual([lay.ph[0].x, lay.ph[0].y, lay.ph[0].w, lay.ph[0].h], [0.1, 0.1, 0.8, 0.15]);
  assert.equal(lay.ph[0].text, 'My title');
  assert.equal(lay.ph[0].style.font, '@heading');
  assert.equal(lay.ph[1].sizeF, 0.0407);
  // applying it to an empty slide recreates the geometry
  const doc = mkDoc([S('n1')]);
  const out = apply(doc, applyLayout(doc, 'n1', lay, { rng: mulberry32(9) }).ops);
  const t = out.slides[0].els.find((e) => e.role === 'title');
  assert.deepEqual([t.x, t.y, t.w, t.h], [192, 108, 1536, 162]);
  assert.equal(out.slides[0].layout, 'custom:my-layout');
});
