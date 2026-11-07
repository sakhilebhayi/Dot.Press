import test from 'node:test';
import assert from 'node:assert/strict';
import {
  canAlign, canDistribute, canMatchSize, framePatches, groupState, selectionFrame, unitCount,
} from '../arrangeModel.js';

const box = (id, x, y, w, h, extra = {}) => ({ id, type: 'shape', kind: 'rect', x, y, w, h, rot: 0, ...extra });
const patch = (out) => Object.fromEntries(out.map((o) => [o.id, o.p]));

test('selectionFrame: the element, or the bounds of all', () => {
  assert.deepEqual(selectionFrame([box('a', 10, 20, 100, 50)]), { x: 10, y: 20, w: 100, h: 50, single: true });
  assert.deepEqual(selectionFrame([box('a', 0, 0, 10, 10), box('b', 50, 40, 10, 10)]), { x: 0, y: 0, w: 60, h: 50, single: false });
  assert.equal(selectionFrame([]), null);
});

test('one element: x, y and size', () => {
  const a = box('a', 10, 20, 100, 50);
  assert.deepEqual(patch(framePatches([a], 'x', 30)), { a: { x: 30 } });
  assert.deepEqual(patch(framePatches([a], 'y', 0)), { a: { y: 0 } });
  assert.deepEqual(patch(framePatches([a], 'w', 200)), { a: { w: 200 } });
  assert.deepEqual(framePatches([a], 'x', 10), []);
  assert.deepEqual(framePatches([a], 'x', 'abc'), []);
});

test('locked ratio keeps the other side in proportion', () => {
  const a = box('a', 0, 0, 200, 100);
  assert.deepEqual(patch(framePatches([a], 'w', 400, { lockRatio: true })), { a: { w: 400, h: 200 } });
  assert.deepEqual(patch(framePatches([a], 'h', 50, { lockRatio: true })), { a: { w: 100, h: 50 } });
});

test('a rotated element keeps its centre when resized', () => {
  const a = box('a', 100, 100, 200, 100, { rot: 90 });
  const p = patch(framePatches([a], 'w', 100))['a'];
  assert.equal(p.w, 100);
  assert.equal(p.x + p.w / 2, 200);
  assert.equal(p.y, undefined); // the height did not change, so the centre row is still 150
});

test('minimum size is respected and a line has no height field', () => {
  const a = box('a', 0, 0, 200, 100);
  assert.equal(patch(framePatches([a], 'w', 0, { unit: 1 }))['a'].w, 8);
  const l = { id: 'l', type: 'shape', kind: 'line', x: 0, y: 0, w: 300, h: 0, rot: 0 };
  assert.deepEqual(framePatches([l], 'h', 40), []);
  assert.deepEqual(patch(framePatches([l], 'w', 100, { lockRatio: true })), { l: { w: 100 } });
});

test('rotation sets the same angle on every element and wraps', () => {
  const els = [box('a', 0, 0, 10, 10), box('b', 0, 0, 10, 10, { rot: 15 })];
  assert.deepEqual(patch(framePatches(els, 'rot', 45)), { a: { rot: 45 }, b: { rot: 45 } });
  assert.deepEqual(patch(framePatches([els[0]], 'rot', -90)), { a: { rot: 270 } });
  assert.deepEqual(framePatches([els[0]], 'rot', 360), []);
});

test('several elements: x moves them together, w scales the group', () => {
  const els = [box('a', 0, 0, 100, 100), box('b', 100, 0, 100, 100)];
  const moved = patch(framePatches(els, 'x', 50));
  assert.deepEqual(moved, { a: { x: 50 }, b: { x: 150 } });
  const scaled = patch(framePatches(els, 'w', 400));
  assert.equal(scaled.a.w, 200);
  assert.equal(scaled.b.x, 200);
  const locked = patch(framePatches(els, 'w', 400, { lockRatio: true }));
  assert.equal(locked.a.h, 200);
});

test('unit counts treat a group as one', () => {
  const els = [box('a', 0, 0, 1, 1, { group: 'G' }), box('b', 0, 0, 1, 1, { group: 'G' }), box('c', 0, 0, 1, 1)];
  assert.equal(unitCount(els), 2);
  assert.equal(canAlign(els, 'selection'), true);
  assert.equal(canAlign([els[0], els[1]], 'selection'), false);
  assert.equal(canAlign([els[0], els[1]], 'slide'), true);
  assert.equal(canDistribute(els, 'selection'), false);
  assert.equal(canDistribute(els, 'slide'), true);
  assert.equal(canMatchSize(els), true);
  assert.equal(canMatchSize([els[0]]), false);
});

test('groupState', () => {
  assert.equal(groupState([box('a', 0, 0, 1, 1)]), 'none');
  assert.equal(groupState([box('a', 0, 0, 1, 1, { group: 'g' })]), 'grouped');
  assert.equal(groupState([box('a', 0, 0, 1, 1, { group: 'g' }), box('b', 0, 0, 1, 1)]), 'mixed');
  assert.equal(groupState([]), 'none');
});
