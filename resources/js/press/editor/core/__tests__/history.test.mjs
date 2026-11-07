import test from 'node:test';
import assert from 'node:assert/strict';
import { createHistory } from '../history.js';
import { applyOps } from '../ops.js';
import {
  mkDoc, S, T, mulberry32, randomDoc, randomOp, counterIds, content,
} from './helpers.mjs';

function setup(opts = {}) {
  let t = 0;
  const clock = { now: () => t, advance: (ms) => { t += ms; } };
  const h = createHistory({ now: clock.now, ...opts });
  let doc = mkDoc([S('s1', [T('a', 0, 0, 10, 10), T('b', 5, 5, 10, 10)]), S('s2')]);
  const start = doc;
  const run = (ops, meta = {}) => {
    const r = applyOps(doc, ops, { makeId: counterIds() });
    doc = r.doc;
    h.record({ inverse: r.inverse, guards: r.guards, ...meta });
    return r;
  };
  const undo = () => { const r = h.undo(doc); if (r) doc = r.doc; return r; };
  const redo = () => { const r = h.redo(doc); if (r) doc = r.doc; return r; };
  return { h, clock, run, undo, redo, get doc() { return doc; }, set doc(d) { doc = d; }, start };
}
const set = (id, p, slide = 's1') => ({ op: 'el.set', slide, id, p });

test('undo and redo walk the stack and report the ops to send', () => {
  const s = setup();
  s.run([set('a', { x: 10 })], { label: 'Move' });
  s.run([set('a', { x: 20 })], { label: 'Move again' });
  assert.deepEqual([s.h.canUndo(), s.h.canRedo(), s.h.undoLabel()], [true, false, 'Move again']);
  const u = s.undo();
  assert.equal(s.doc.slides[0].els[0].x, 10);
  assert.deepEqual(u.ops, [set('a', { x: 10 })]);
  assert.equal(s.h.redoLabel(), 'Move again');
  s.undo();
  assert.deepEqual(content(s.doc), content(s.start));
  assert.equal(s.undo(), null);
  s.redo(); s.redo();
  assert.equal(s.doc.slides[0].els[0].x, 20);
  s.run([set('a', { x: 1 })]);
  assert.equal(s.h.canRedo(), false, 'a new edit clears redo');
});

test('coalescing: rapid edits with the same key within the window are one step', () => {
  const s = setup({ coalesceMs: 500 });
  for (let i = 1; i <= 20; i++) {
    s.run([set('a', { x: i * 3, y: i })], { key: 'drag:a' });
    s.clock.advance(50);
  }
  assert.equal(s.h.size().undo, 1);
  s.undo();
  assert.deepEqual([s.doc.slides[0].els[0].x, s.doc.slides[0].els[0].y], [0, 0]);
  s.redo();
  assert.deepEqual([s.doc.slides[0].els[0].x, s.doc.slides[0].els[0].y], [60, 20]);
});

test('coalescing stops at a time gap, a different key, or a seal', () => {
  const s = setup({ coalesceMs: 500 });
  s.run([set('a', { x: 1 })], { key: 'k' });
  s.clock.advance(100);
  s.run([set('a', { x: 2 })], { key: 'k' });
  s.clock.advance(900);
  s.run([set('a', { x: 3 })], { key: 'k' });
  assert.equal(s.h.size().undo, 2, 'gap breaks the run');
  s.clock.advance(10);
  s.run([set('a', { y: 3 })], { key: 'other' });
  assert.equal(s.h.size().undo, 3);
  s.clock.advance(10);
  s.run([set('a', { y: 4 })], { key: 'other' });
  s.h.breakCoalescing();
  s.run([set('a', { y: 5 })], { key: 'other' });
  assert.equal(s.h.size().undo, 4);
});

test('coalesced non-el.set steps still undo completely', () => {
  const s = setup();
  s.run([{ op: 'el.add', slide: 's1', el: T('n', 0, 0, 1, 1) }], { key: 'k' });
  s.run([set('n', { x: 5 })], { key: 'k' });
  assert.equal(s.h.size().undo, 1);
  s.undo();
  assert.deepEqual(content(s.doc), content(s.start));
});

test('transactions group several records into one step; they nest', () => {
  const s = setup();
  s.h.begin('Align');
  s.run([set('a', { x: 1 })]);
  s.h.begin();
  s.run([set('b', { x: 2 })]);
  s.h.commit();
  s.run([{ op: 'el.remove', slide: 's1', id: 'b' }]);
  assert.equal(s.h.size().undo, 0, 'nothing committed yet');
  s.h.commit();
  assert.equal(s.h.size().undo, 1);
  assert.equal(s.h.undoLabel(), 'Align');
  s.undo();
  assert.deepEqual(content(s.doc), content(s.start));
  s.redo();
  assert.equal(s.doc.slides[0].els.length, 1);
});

test('cancel returns the ops that revert an open transaction', () => {
  const s = setup();
  s.h.begin('Drag');
  s.run([set('a', { x: 99 })]);
  const c = s.h.cancel();
  const r = applyOps(s.doc, c.ops);
  assert.equal(r.doc.slides[0].els[0].x, 0);
  assert.equal(s.h.size().undo, 0);
});

test('cap: only the newest 200 steps are kept', () => {
  const s = setup();
  for (let i = 1; i <= 250; i++) { s.run([set('a', { x: i })]); s.h.breakCoalescing(); }
  assert.equal(s.h.size().undo, 200);
  for (let i = 0; i < 200; i++) s.undo();
  assert.equal(s.doc.slides[0].els[0].x, 50, 'oldest 50 steps are gone');
});

test('undo never resurrects remote changes: edits to a deleted element are skipped, then the next step is tried', () => {
  const s = setup();
  s.run([set('b', { x: 77 })], { label: 'mine on b' });
  s.h.breakCoalescing();
  s.run([set('a', { x: 11 })], { label: 'mine on a' });
  s.h.breakCoalescing();
  // a remote user deletes a
  s.doc = applyOps(s.doc, [{ op: 'el.remove', slide: 's1', id: 'a' }]).doc;
  const u = s.undo();
  assert.ok(u, 'the step on the deleted element is dropped and the next step is undone');
  assert.equal(u.label, 'mine on b');
  assert.equal(s.doc.slides[0].els.find((e) => e.id === 'a'), undefined, 'a stays deleted');
  assert.equal(s.doc.slides[0].els.find((e) => e.id === 'b').x, 5);
});

test('undo does not overwrite a remote change to the same key, but undoes the rest of the step', () => {
  const s = setup();
  s.run([set('a', { x: 40, y: 41 })]);
  s.doc = applyOps(s.doc, [set('a', { x: 500 })]).doc; // remote
  s.undo();
  assert.deepEqual([s.doc.slides[0].els[0].x, s.doc.slides[0].els[0].y], [500, 0]);
});

test('redo after a remote change keeps the remote change', () => {
  const s = setup();
  s.run([set('a', { x: 40 })]);
  s.undo();
  s.doc = applyOps(s.doc, [set('a', { x: 300 })]).doc;
  const r = s.redo();
  assert.equal(r, null, 'x was changed remotely: nothing left to redo');
  assert.equal(s.doc.slides[0].els[0].x, 300);
});

test('remapSlideIds rewrites temporary slide ids inside stored steps', () => {
  const s = setup();
  s.run([{ op: 'slide.add', tid: 't1', after: 's1' }, { op: 'el.add', slide: 't1', el: T('z', 0, 0, 1, 1) }]);
  s.h.remapSlideIds({ t1: '99' });
  s.doc = applyOps(s.doc, [{ op: 'slide.remove', id: 't1' }]).doc; // simulate the id change in the doc
  s.doc = applyOps(mkDoc([S('s1', [T('a', 0, 0, 10, 10), T('b', 5, 5, 10, 10)]), S('99', [T('z', 0, 0, 1, 1)]), S('s2')]), []).doc;
  const u = s.h.undo(s.doc);
  assert.ok(u.ops.some((o) => o.op === 'slide.remove' && o.id === '99'));
});

test('property: undo all then redo all returns the same documents', () => {
  for (let seed = 1; seed <= 100; seed++) {
    const rnd = mulberry32(seed + 1000);
    let t = 0;
    const h = createHistory({ now: () => t });
    let doc = randomDoc(rnd);
    const states = [content(doc)];
    const counter = { n: 0 };
    for (let i = 0; i < 8; i++) {
      const op = randomOp(rnd, doc, counter);
      const r = applyOps(doc, [op], { makeId: counterIds() });
      if (!r.applied.length || r.doc === doc) continue;
      doc = r.doc;
      h.record({ inverse: r.inverse, guards: r.guards });
      h.breakCoalescing();
      states.push(content(doc));
    }
    for (let i = states.length - 2; i >= 0; i--) {
      const u = h.undo(doc);
      doc = u.doc;
      assert.deepEqual(content(doc), states[i], `seed ${seed} undo ${i}`);
    }
    for (let i = 1; i < states.length; i++) {
      const r = h.redo(doc);
      doc = r.doc;
      assert.deepEqual(content(doc), states[i], `seed ${seed} redo ${i}`);
    }
  }
});
