import test from 'node:test';
import assert from 'node:assert/strict';
import {
  createDoc, docToServerShape, newId, newText, newShape, newImage, newChart, newTable, newIcon, newVideo, newAudio, newSlide, textDoc, docText,
  findEl, findSlide, slideOf, elementsInGroup, bounds, usedIds, slideIndex,
} from '../doc.js';
import { applyOps } from '../ops.js';
import { ID_RE } from '../util.js';
import { mulberry32 } from './helpers.mjs';

const server = {
  deck: { id: 7, title: 'D', w: 1280, h: 720, theme: null, rev: 3, status: 'draft', visibility: 'team', sections: [], slide_order: [12, 11], updated_at: 'x' },
  slides: [{ id: 11, title: 'a', els: [] }, { id: 12, title: 'b', els: [{ id: 'q', type: 'text', x: 0, y: 0, w: 1, h: 1 }] }],
  seq: 9, assets: { 5: { url: 'u' } },
};

test('createDoc normalises: string slide ids, slide_order applied, defaults, default theme, input untouched', () => {
  const before = JSON.stringify(server);
  const d = createDoc(server);
  assert.equal(JSON.stringify(server), before);
  assert.deepEqual(d.slides.map((s) => s.id), ['12', '11']);
  assert.deepEqual(d.deck.slide_order, ['12', '11']);
  assert.equal(d.deck.theme.id, 'slate');
  assert.equal(d.slides[1].layout, 'blank');
  assert.equal(d.slides[1].hidden, false);
  assert.equal(d.seq, 9);
  assert.equal(d.deck.w, 1280);
});

test('createDoc never gives a document without a slide', () => {
  const d = createDoc({ deck: { id: 1 }, slides: [] });
  assert.equal(d.slides.length, 1);
  assert.equal(d.deck.slide_order.length, 1);
  assert.equal(d.deck.w, 1920);
});

test('docToServerShape returns the server shape with synced order and no client extras', () => {
  const d = createDoc(server);
  const s = docToServerShape(d);
  assert.deepEqual(Object.keys(s).sort(), ['deck', 'seq', 'slides']);
  assert.deepEqual(s.deck.slide_order, ['12', '11']);
  assert.notEqual(s.slides[0], d.slides[0], 'a deep copy');
});

test('ids match the spec pattern and never collide', () => {
  const rnd = mulberry32(1);
  const taken = new Set();
  for (let i = 0; i < 5000; i++) {
    const id = newId(taken, 'e', rnd);
    assert.match(id, ID_RE);
    assert.ok(!taken.has(id));
    taken.add(id);
  }
  // a degenerate rng still produces unique ids
  const t2 = new Set();
  for (let i = 0; i < 50; i++) t2.add(newId(t2, 'e', () => 0.5));
  assert.equal(t2.size, 50);
  assert.ok(newId(['a', 'b']).startsWith('e'));
});

test('factories: sizes follow the deck size, colours are theme tokens, ids are valid', () => {
  const big = { w: 1920, h: 1080, theme: null };
  const small = { w: 1280, h: 720 };
  const tb = newText(big, { text: 'Hello\nWorld' });
  const ts = newText(small, { text: 'Hello' });
  assert.equal(tb.type, 'text');
  assert.equal(tb.color, '@ink');
  assert.equal(tb.font, '@body');
  assert.ok(Math.abs(ts.size / tb.size - 2 / 3) < 0.01);
  assert.equal(docText(tb.doc), 'Hello\nWorld');
  assert.ok(tb.x >= 0 && tb.x + tb.w <= big.w);
  const title = newText(big, { role: 'title', text: 'T' });
  assert.equal(title.font, '@heading');
  assert.equal(title.weight, 700);
  for (const el of [tb, newShape(big), newShape(big, { kind: 'line' }), newShape(big, { kind: 'arrow' }), newImage(big), newChart(big), newTable(big), newIcon(big), newVideo(big), newAudio(big)]) {
    assert.match(el.id, ID_RE);
    assert.ok(el.x >= 0 && el.y >= 0 && el.x + el.w <= big.w + 0.01 && el.y + el.h <= big.h + 0.01, `${el.type} fits`);
    assert.equal(el.rot, 0);
    assert.equal(el.lock, false);
    assert.equal('taken' in el || 'rng' in el || 'text' in el, false, 'factory-only props do not leak');
  }
  assert.equal(newShape(big).fill, '@accent');
  assert.equal(newShape(big, { kind: 'arrow' }).ends.e, 'arrow');
  assert.equal(newShape(big, { kind: 'line' }).h, 0);
});

test('newImage keeps the aspect ratio and fits the slide', () => {
  const deck = { w: 1920, h: 1080 };
  const wide = newImage(deck, { natural: { w: 4000, h: 1000 }, src: { asset: 'x' } });
  assert.equal(wide.w / wide.h, 4);
  assert.deepEqual(wide.src, { asset: 'x' });
  const tall = newImage(deck, { natural: { w: 1000, h: 4000 } });
  assert.ok(tall.h <= 1080 * 0.7 + 0.01);
  assert.ok(Math.abs(tall.w / tall.h - 0.25) < 0.01);
});

test('newTable: column widths sum to exactly 1 and the element height is the sum of the rows', () => {
  for (const cols of [1, 2, 3, 4, 6, 7]) {
    const t = newTable({ w: 1920, h: 1080 }, { rows: 4, cols });
    assert.equal(t.colw.length, cols);
    assert.ok(Math.abs(t.colw.reduce((a, b) => a + b, 0) - 1) < 1e-9, `cols ${cols}`);
    assert.equal(t.rows.length, 4);
    assert.equal(t.rows[0].length, cols);
    assert.equal(t.h, t.rowh.reduce((a, b) => a + b, 0));
  }
});

test('factory output is accepted by el.add', () => {
  const d = createDoc({ deck: { id: 1 }, slides: [{ id: 's1', els: [] }] });
  const els = [newText(d.deck), newShape(d.deck), newImage(d.deck), newChart(d.deck), newTable(d.deck), newIcon(d.deck), newVideo(d.deck), newAudio(d.deck)];
  const r = applyOps(d, els.map((el) => ({ op: 'el.add', slide: 's1', el })));
  assert.deepEqual(r.skipped, []);
  assert.equal(r.doc.slides[0].els.length, 8);
});

test('structural helpers', () => {
  const d = createDoc({
    deck: { id: 1 },
    slides: [
      { id: 's1', els: [{ id: 'a', type: 'text', x: 0, y: 0, w: 100, h: 50, group: 'g' }, { id: 'b', type: 'text', x: 200, y: 100, w: 100, h: 50, group: 'g' }] },
      { id: 's2', els: [{ id: 'c', type: 'text', x: 0, y: 0, w: 1, h: 1 }] },
    ],
  });
  assert.equal(findSlide(d, 's2').id, 's2');
  assert.equal(findSlide(d, 9), undefined);
  assert.equal(slideIndex(d, 's2'), 1);
  assert.equal(findEl(d, 's1', 'b').x, 200);
  assert.equal(findEl(d, 's1', 'zz'), undefined);
  assert.equal(slideOf(d, 'c').id, 's2');
  assert.equal(slideOf(d, 'c', 's1').id, 's2', 'a wrong hint falls back to a search');
  assert.deepEqual(elementsInGroup(d.slides[0], 'g').map((e) => e.id), ['a', 'b']);
  assert.deepEqual(bounds(d.slides[0].els), { x: 0, y: 0, w: 300, h: 150 });
  assert.equal(bounds([]), null);
  assert.deepEqual([...usedIds(d.slides[0])].sort(), ['a', 'b', 'g']);
  assert.deepEqual(newSlide(d).els, []);
  assert.deepEqual(textDoc('a\n\nb').content.map((p) => p.type), ['paragraph', 'paragraph', 'paragraph']);
});
