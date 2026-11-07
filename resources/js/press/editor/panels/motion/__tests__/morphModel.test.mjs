import test from 'node:test';
import assert from 'node:assert/strict';
import { analyzeMorph, elementLabel, pairOps, suggestPartners, unpairOps } from '../morphModel.js';

const el = (id, type = 'shape', extra = {}) => ({ id, type, x: 0, y: 0, w: 100, h: 100, ...extra });
const A = { id: 'A', els: [el('title', 'text'), el('logo', 'image'), el('only-a'), el('h', 'shape', { hide: true })] };
const B = { id: 'B', els: [el('title', 'text'), el('pic', 'image', { morph: 'logo' }), el('only-b'), el('h', 'shape')] };

test('pairs by id and by morph key, same type only, hidden ignored', () => {
  const r = analyzeMorph(A, B);
  assert.deepEqual(r.pairs.map((p) => [p.aId, p.bId, p.via]), [['title', 'title', 'id'], ['logo', 'pic', 'key']]);
  assert.deepEqual(r.onlyFrom.map((e) => e.id), ['only-a']);
  assert.deepEqual(r.onlyTo.map((e) => e.id), ['only-b', 'h']);
});

test('same key but different type is not a pair', () => {
  const r = analyzeMorph({ els: [el('x', 'text')] }, { els: [el('x', 'shape')] });
  assert.equal(r.pairs.length, 0);
});

test('pairing two unrelated elements gives both a new shared key', () => {
  const ops = pairOps(A, B, A.els[2], B.els[2]);
  assert.equal(ops.length, 2);
  assert.equal(ops[0].p.morph, ops[1].p.morph);
  assert.deepEqual([ops[0].slide, ops[0].id, ops[1].slide, ops[1].id], ['A', 'only-a', 'B', 'only-b']);
  const applied = { els: [{ ...A.els[2], morph: ops[0].p.morph }] };
  const applied2 = { els: [{ ...B.els[2], morph: ops[1].p.morph }] };
  assert.equal(analyzeMorph(applied, applied2).pairs.length, 1);
});

test('pairing onto an element that already has a morph key reuses the key when free', () => {
  const a = el('a1', 'text', { morph: 'k1' });
  const ops = pairOps({ id: 'A', els: [a] }, { id: 'B', els: [el('b1', 'text')] }, a, el('b1', 'text'));
  assert.deepEqual(ops, [{ op: 'el.set', slide: 'B', id: 'b1', p: { morph: 'k1' } }]);
});

test('a key already used by another arriving element is not reused', () => {
  const a = el('a1', 'text', { morph: 'k1' });
  const other = el('b2', 'text', { morph: 'k1' });
  const b = el('b1', 'text');
  const ops = pairOps({ id: 'A', els: [a] }, { id: 'B', els: [b, other] }, a, b);
  assert.equal(ops.length, 2);
  assert.notEqual(ops[0].p.morph, 'k1');
});

test('pairing different types or an existing pair does nothing', () => {
  assert.deepEqual(pairOps(A, B, A.els[0], B.els[1]), []);
  assert.deepEqual(pairOps(A, B, A.els[0], B.els[0]), []);
});

test('unpairing gives the arriving element a key of its own and breaks the pair', () => {
  const pair = analyzeMorph(A, B).pairs[0];
  const ops = unpairOps(A, B, pair);
  assert.equal(ops.length, 1);
  assert.equal(ops[0].id, 'title');
  assert.match(ops[0].p.morph, /^[A-Za-z0-9_-]{1,40}$/);
  const B2 = { id: 'B', els: B.els.map((e) => (e.id === 'title' ? { ...e, morph: ops[0].p.morph } : e)) };
  assert.equal(analyzeMorph(A, B2).pairs.find((p) => p.aId === 'title'), undefined);
});

test('labels: name, then a text snippet, then the type', () => {
  assert.equal(elementLabel({ id: 'a', type: 'shape', name: ' Hero ' }), 'Hero');
  assert.equal(elementLabel({ id: 'a', type: 'text', doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Revenue grew' }] }] } }), 'Revenue grew');
  assert.equal(elementLabel({ id: 'a', type: 'image' }), 'Picture');
});

test('partner suggestions are the same type, nearest first', () => {
  const near = el('n', 'text', { x: 10, y: 10 });
  const far = el('f', 'text', { x: 900, y: 900 });
  const wrong = el('w', 'shape', { x: 0, y: 0 });
  assert.deepEqual(suggestPartners([far, wrong, near], el('b', 'text')).map((e) => e.id), ['n', 'f']);
});
