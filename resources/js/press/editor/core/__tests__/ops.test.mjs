import test from 'node:test';
import assert from 'node:assert/strict';
import { applyOps, remapOps, remapDoc, dupToAdd } from '../ops.js';
import { applyGuarded } from '../history.js';
import {
  mkDoc, S, T, R, mulberry32, randomDoc, randomOp, counterIds, content,
} from './helpers.mjs';

test('property: applying ops then the inverse returns the original document (500 seeded runs)', () => {
  let totalOps = 0;
  let totalApplied = 0;
  for (let seed = 1; seed <= 500; seed++) {
    const rnd = mulberry32(seed);
    const doc = randomDoc(rnd);
    const counter = { n: 0 };
    const ops = [];
    let cur = doc;
    const count = 1 + Math.floor(rnd() * 12);
    for (let i = 0; i < count; i++) {
      const op = randomOp(rnd, cur, counter);
      ops.push(op);
      cur = applyOps(cur, [op], { makeId: counterIds() }).doc;
    }
    const before = JSON.stringify(doc);
    const r = applyOps(doc, ops, { makeId: counterIds() });
    assert.equal(JSON.stringify(doc), before, `seed ${seed}: input mutated`);
    const back = applyOps(r.doc, r.inverse, { makeId: counterIds() });
    assert.deepEqual(back.skipped, [], `seed ${seed}: inverse skipped ${JSON.stringify(back.skipped)}`);
    assert.deepEqual(content(back.doc), content(doc), `seed ${seed}: inverse mismatch\nops ${JSON.stringify(ops)}`);
    assert.equal(r.guards.length, r.inverse.length);
    totalOps += ops.length;
    totalApplied += r.applied.length;
  }
  assert.ok(totalApplied > totalOps * 0.4, `generator should apply most ops (${totalApplied}/${totalOps})`);
});

test('property: guarded redo of an undo reproduces the edited document', () => {
  for (let seed = 1; seed <= 100; seed++) {
    const rnd = mulberry32(seed * 7);
    const doc = randomDoc(rnd);
    const counter = { n: 0 };
    const ops = [];
    let cur = doc;
    for (let i = 0; i < 6; i++) {
      const op = randomOp(rnd, cur, counter);
      ops.push(op);
      cur = applyOps(cur, [op], { makeId: counterIds() }).doc;
    }
    const r = applyOps(doc, ops, { makeId: counterIds() });
    const undo = applyGuarded(r.doc, r.inverse, r.guards);
    assert.deepEqual(content(undo.doc), content(doc), `seed ${seed} undo`);
    const redo = applyGuarded(undo.doc, undo.inverse, undo.guards);
    assert.deepEqual(content(redo.doc), content(r.doc), `seed ${seed} redo`);
  }
});

test('structural sharing: untouched slides and elements keep identity, no-ops return the same document', () => {
  const doc = mkDoc([S('s1', [T('a', 0, 0, 10, 10), T('b', 0, 0, 10, 10)]), S('s2', [T('c', 0, 0, 10, 10)])]);
  const r = applyOps(doc, [{ op: 'el.set', slide: 's1', id: 'a', p: { x: 5 } }]);
  assert.notEqual(r.doc, doc);
  assert.equal(r.doc.slides[1], doc.slides[1]);
  assert.equal(r.doc.slides[0].els[1], doc.slides[0].els[1]);
  assert.notEqual(r.doc.slides[0].els[0], doc.slides[0].els[0]);
  assert.equal(applyOps(doc, [{ op: 'el.set', slide: 's1', id: 'a', p: { x: 0 } }]).doc, doc);
  assert.equal(applyOps(doc, [{ op: 'el.set', slide: 'zz', id: 'a', p: { x: 0 } }]).doc, doc);
  assert.equal(applyOps(doc, []).doc, doc);
});

test('deck.resize inverse restores exactly, including clamped anchor moves', () => {
  const doc = mkDoc([S('s1', [T('a', 1700, 900, 400, 300, { size: 33, rot: 15 }), R('b', -50, 20.37, 120.55, 80.1, { sw: 3 })])]);
  for (const mode of ['scale', 'fit', 'anchor']) {
    const r = applyOps(doc, [{ op: 'deck.resize', w: 1000, h: 1000, mode }]);
    assert.equal(r.doc.deck.w, 1000);
    const back = applyOps(r.doc, r.inverse);
    assert.deepEqual(content(back.doc), content(doc), mode);
    assert.equal(back.doc.deck.w, 1920);
  }
});

test('the inverse of a removal brings the element back at the same z position, a slide back with its elements', () => {
  const doc = mkDoc([S('s1', [T('a', 0, 0, 1, 1), T('b', 0, 0, 1, 1), T('c', 0, 0, 1, 1)], { title: 'One' }), S('s2')]);
  const r = applyOps(doc, [{ op: 'el.remove', slide: 's1', id: 'b' }, { op: 'slide.remove', id: 's1' }]);
  assert.equal(r.doc.slides.length, 1);
  const back = applyOps(r.doc, r.inverse);
  assert.deepEqual(content(back.doc), content(doc));
});

test('slide.dup: fresh element and group ids, source untouched, morph pairs the copy', () => {
  const doc = mkDoc([S('s1', [T('a', 0, 0, 1, 1, { group: 'g' }), T('b', 0, 0, 1, 1, { group: 'g' })])]);
  const r = applyOps(doc, [{ op: 'slide.dup', id: 's1', tid: 'n1' }]);
  const copy = r.doc.slides[1];
  assert.equal(copy.els.length, 2);
  assert.ok(copy.els.every((e) => e.id !== 'a' && e.id !== 'b'));
  assert.equal(copy.els[0].group, copy.els[1].group);
  assert.notEqual(copy.els[0].group, 'g');
  assert.deepEqual(copy.els.map((e) => e.morph), ['a', 'b']);
  assert.equal(r.doc.slides[0], doc.slides[0]);
});

test('dupToAdd gives a slide.add with explicit elements that yields the same result', () => {
  const doc = mkDoc([S('s1', [T('a', 3, 4, 5, 6)]), S('s2')]);
  const add = dupToAdd(doc, { op: 'slide.dup', id: 's1', tid: 'n1' }, { makeId: counterIds() });
  assert.equal(add.op, 'slide.add');
  assert.equal(add.after, 's1');
  assert.equal(add.slide.els.length, 1);
  const r = applyOps(doc, [add]);
  assert.deepEqual(r.skipped, []);
  assert.equal(r.doc.slides[1].id, 'n1');
  assert.equal(r.doc.slides[1].els[0].x, 3);
  assert.equal(dupToAdd(doc, { op: 'slide.dup', id: 'nope', tid: 'n2' }), null);
});

test('limits: 600 slides and 400 elements are enforced', () => {
  const els = Array.from({ length: 400 }, (_, i) => T(`e${i}`, 0, 0, 1, 1));
  const doc = mkDoc([S('s1', els)]);
  const r = applyOps(doc, [{ op: 'el.add', slide: 's1', el: T('extra', 0, 0, 1, 1) }]);
  assert.deepEqual(r.skipped.map((s) => s.reason), ['limit']);
  const slides = Array.from({ length: 600 }, (_, i) => S(`s${i}`));
  const big = mkDoc(slides);
  assert.deepEqual(applyOps(big, [{ op: 'slide.add', tid: 'x' }]).skipped.map((s) => s.reason), ['limit']);
  assert.deepEqual(applyOps(big, [{ op: 'slide.dup', id: 's0', tid: 'x' }]).skipped.map((s) => s.reason), ['limit']);
});

test('remapOps and remapDoc replace temporary slide ids everywhere they appear', () => {
  const ops = [
    { op: 'el.add', slide: 't1', el: T('a', 0, 0, 1, 1) },
    { op: 'slide.move', id: 't1', after: 't2' },
    { op: 'el.move', from: 't1', id: 'a', to: 't2' },
    { op: 'slide.add', tid: 't3', after: 't1' },
    { op: 'deck.set', title: 'x' },
  ];
  const out = remapOps(ops, { t1: '11', t2: '12' });
  assert.equal(out[0].slide, '11');
  assert.deepEqual([out[1].id, out[1].after], ['11', '12']);
  assert.deepEqual([out[2].from, out[2].to], ['11', '12']);
  assert.deepEqual([out[3].tid, out[3].after], ['t3', '11']);
  assert.equal(ops[0].slide, 't1', 'input untouched');
  assert.equal(out[4], ops[4]);
  const doc = mkDoc([S('t1'), S('s2')]);
  const d2 = remapDoc(doc, { t1: '11' });
  assert.deepEqual(d2.deck.slide_order, ['11', 's2']);
  assert.equal(remapDoc(doc, {}), doc);
});

test('numeric slide ids from the wire are treated as strings', () => {
  const doc = mkDoc([S('5', [T('a', 0, 0, 1, 1)])]);
  const r = applyOps(doc, [{ op: 'el.set', slide: 5, id: 'a', p: { x: 9 } }]);
  assert.equal(r.doc.slides[0].els[0].x, 9);
});

test('guards: a key someone else changed since is not overwritten by the inverse', () => {
  const doc = mkDoc([S('s1', [T('a', 10, 10, 5, 5)])]);
  const r = applyOps(doc, [{ op: 'el.set', slide: 's1', id: 'a', p: { x: 50, y: 60 } }]);
  // somebody else moves x
  const theirs = applyOps(r.doc, [{ op: 'el.set', slide: 's1', id: 'a', p: { x: 99 } }]).doc;
  const u = applyGuarded(theirs, r.inverse, r.guards);
  assert.equal(u.doc.slides[0].els[0].x, 99, 'their x survives');
  assert.equal(u.doc.slides[0].els[0].y, 10, 'our y is undone');
});

test('guards: an op on an element somebody deleted is skipped, not resurrected', () => {
  const doc = mkDoc([S('s1', [T('a', 10, 10, 5, 5)])]);
  const r = applyOps(doc, [{ op: 'el.set', slide: 's1', id: 'a', p: { x: 50 } }]);
  const theirs = applyOps(r.doc, [{ op: 'el.remove', slide: 's1', id: 'a' }]).doc;
  const u = applyGuarded(theirs, r.inverse, r.guards);
  assert.equal(u.doc.slides[0].els.length, 0);
  assert.equal(u.skipped.length, 1);
});
