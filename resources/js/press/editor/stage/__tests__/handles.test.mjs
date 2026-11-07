import test from 'node:test';
import assert from 'node:assert/strict';
import {
  selectionFrame, screenBox, handleLayout, hitHandle, cursorFor, lineEnds, hitLineEnd, ROTATE_OFFSET,
} from '../lib/handles.js';

test('a large box has eight handles and a rotation handle above the top edge', () => {
  const sb = screenBox({ x: 100, y: 100, w: 400, h: 200, rot: 0 }, { x: 10, y: 20 }, 0.5);
  assert.deepEqual([sb.x, sb.y, sb.w, sb.h], [60, 70, 200, 100]);
  const l = handleLayout(sb);
  assert.equal(Object.keys(l.handles).length, 8);
  assert.deepEqual(l.rotate, { x: 160, y: 70 - ROTATE_OFFSET });
});

test('small boxes drop the edge handles, tiny boxes keep one corner', () => {
  const small = handleLayout({ x: 0, y: 0, w: 20, h: 100, rot: 0 });
  assert.ok(!small.handles.n && !small.handles.s && small.handles.e && small.handles.w);
  const tiny = handleLayout({ x: 0, y: 0, w: 12, h: 12, rot: 0 });
  assert.deepEqual(Object.keys(tiny.handles), ['se']);
});

test('the rotation handle follows the rotation', () => {
  const l = handleLayout({ x: 0, y: 0, w: 200, h: 100, rot: 90 });
  // centre (100, 50); up rotated 90 clockwise points right
  assert.ok(Math.abs(l.rotate.x - (100 + 50 + ROTATE_OFFSET)) < 1e-6);
  assert.ok(Math.abs(l.rotate.y - 50) < 1e-6);
});

test('handle hit testing prefers corners, then rotation, then edges', () => {
  const l = handleLayout({ x: 100, y: 100, w: 200, h: 100, rot: 0 });
  assert.deepEqual(hitHandle(l, { x: 102, y: 98 }), { kind: 'resize', handle: 'nw' });
  assert.deepEqual(hitHandle(l, { x: 200, y: 100 - ROTATE_OFFSET + 3 }), { kind: 'rotate' });
  assert.deepEqual(hitHandle(l, { x: 200, y: 103 }), { kind: 'resize', handle: 'n' });
  assert.equal(hitHandle(l, { x: 200, y: 150 }), null);
  // touch gets a bigger target
  assert.equal(hitHandle(l, { x: 100 - 14, y: 100 }, { coarse: false }), null);
  assert.deepEqual(hitHandle(l, { x: 100 - 14, y: 100 }, { coarse: true }), { kind: 'resize', handle: 'nw' });
});

test('resize cursors turn with the box', () => {
  assert.equal(cursorFor('n', 0), 'ns-resize');
  assert.equal(cursorFor('e', 0), 'ew-resize');
  assert.equal(cursorFor('nw', 0), 'nwse-resize');
  assert.equal(cursorFor('ne', 0), 'nesw-resize');
  assert.equal(cursorFor('n', 90), 'ew-resize');
  assert.equal(cursorFor('nw', 90), 'nesw-resize');
  assert.equal(cursorFor('e', 45), 'nwse-resize');
});

test('line ends follow rotation and hit testing picks the nearest', () => {
  const el = { x: 100, y: 100, w: 200, h: 0, rot: 90 };
  const ends = lineEnds(el);
  // centre (200, 100): the line runs vertically
  assert.ok(Math.abs(ends.s.x - 200) < 1e-9 && Math.abs(ends.s.y - 0) < 1e-9);
  assert.ok(Math.abs(ends.e.x - 200) < 1e-9 && Math.abs(ends.e.y - 200) < 1e-9);
  assert.equal(hitLineEnd(ends, { x: 202, y: 198 }, { x: 0, y: 0 }, 1), 'e');
  assert.equal(hitLineEnd(ends, { x: 202, y: 3 }, { x: 0, y: 0 }, 1), 's');
  assert.equal(hitLineEnd(ends, { x: 300, y: 100 }, { x: 0, y: 0 }, 1), null);
});

test('the selection frame is the element itself, a line, or the union of several', () => {
  assert.equal(selectionFrame([]), null);
  const one = selectionFrame([{ id: 'a', type: 'shape', kind: 'rect', x: 1, y: 2, w: 3, h: 4, rot: 30 }]);
  assert.deepEqual(one.box, { x: 1, y: 2, w: 3, h: 4, rot: 30 });
  assert.ok(one.single && !one.line);
  assert.ok(selectionFrame([{ id: 'l', type: 'shape', kind: 'line', x: 0, y: 0, w: 10, h: 0 }]).line);
  const many = selectionFrame([{ id: 'a', x: 0, y: 0, w: 10, h: 10 }, { id: 'b', x: 20, y: 5, w: 10, h: 10, rot: 0 }]);
  assert.deepEqual(many.box, { x: 0, y: 0, w: 30, h: 15, rot: 0 });
  assert.ok(!many.single);
});
