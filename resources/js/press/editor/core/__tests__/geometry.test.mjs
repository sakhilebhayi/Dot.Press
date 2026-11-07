import test from 'node:test';
import assert from 'node:assert/strict';
import {
  rotatedBounds, corners, pointInBox, boxIntersectsRect, boxInsideRect, resizeFromHandle, handlePositions, rotationFromPointer,
  snapAngle, transformGroup, rotateGroup, flipPatch, flipGroup, growVertically, rotatePoint, unionBounds, moveBy, minSizeFor, normAngle,
} from '../geometry.js';

const near = (a, b, eps = 0.02) => assert.ok(Math.abs(a - b) <= eps, `${a} vs ${b}`);

test('rotatedBounds of a 90 degree box swaps width and height around the centre', () => {
  const b = rotatedBounds({ x: 100, y: 100, w: 200, h: 100, rot: 90 });
  near(b.x, 150); near(b.y, 50); near(b.w, 100); near(b.h, 200);
  assert.deepEqual(rotatedBounds({ x: 1, y: 2, w: 3, h: 4, rot: 0 }), { x: 1, y: 2, w: 3, h: 4 });
  const c = rotatedBounds({ x: 0, y: 0, w: 100, h: 100, rot: 45 });
  near(c.w, 141.42); near(c.x, -20.71);
});

test('pointInBox respects rotation', () => {
  const box = { x: 0, y: 0, w: 200, h: 20, rot: 90 };
  // rotated to a tall bar around the centre (100, 10)
  assert.equal(pointInBox(box, { x: 100, y: 100 }), true);
  assert.equal(pointInBox(box, { x: 10, y: 10 }), false);
  assert.equal(pointInBox({ ...box, rot: 0 }, { x: 10, y: 10 }), true);
  assert.equal(pointInBox(box, { x: 115, y: 10 }, 6), true, 'padding widens the target');
});

test('boxIntersectsRect and boxInsideRect', () => {
  const box = { x: 100, y: 100, w: 100, h: 100, rot: 45 };
  assert.equal(boxIntersectsRect(box, { x: 0, y: 0, w: 120, h: 120 }), true);
  assert.equal(boxIntersectsRect(box, { x: 0, y: 0, w: 60, h: 60 }), false);
  // the rotated diamond's corner is within the bounds but its edge is not touching this corner square
  assert.equal(boxIntersectsRect(box, { x: 90, y: 90, w: 15, h: 15 }), false);
  assert.equal(boxInsideRect(box, { x: 0, y: 0, w: 400, h: 400 }), true);
  assert.equal(boxInsideRect(box, { x: 100, y: 100, w: 100, h: 100 }), false);
});

test('resizeFromHandle: east handle keeps the west edge', () => {
  const r = resizeFromHandle({ x: 100, y: 100, w: 200, h: 100, rot: 0 }, 'e', { x: 400, y: 150 });
  assert.deepEqual(r, { x: 100, y: 100, w: 300, h: 100, rot: 0 });
});

test('resizeFromHandle: north west corner keeps the south east corner', () => {
  const r = resizeFromHandle({ x: 100, y: 100, w: 200, h: 100, rot: 0 }, 'nw', { x: 50, y: 60 });
  assert.deepEqual(r, { x: 50, y: 60, w: 250, h: 140, rot: 0 });
});

test('resizeFromHandle: aspect lock keeps the ratio on corners and edges', () => {
  const start = { x: 0, y: 0, w: 200, h: 100, rot: 0 };
  const c = resizeFromHandle(start, 'se', { x: 400, y: 150 }, { aspect: true });
  assert.equal(c.w / c.h, 2);
  assert.equal(c.w, 400);
  const e = resizeFromHandle(start, 'e', { x: 300, y: 50 }, { aspect: true });
  assert.deepEqual([e.w, e.h], [300, 150]);
  assert.equal(e.y, -25, 'edge handles grow the other axis around the centre line');
});

test('resizeFromHandle: from centre (alt) mirrors the change on both sides', () => {
  const r = resizeFromHandle({ x: 100, y: 100, w: 200, h: 100, rot: 0 }, 'e', { x: 350, y: 150 }, { center: true });
  assert.deepEqual(r, { x: 50, y: 100, w: 300, h: 100, rot: 0 });
});

test('resizeFromHandle: minimum size stops collapse and never flips', () => {
  const r = resizeFromHandle({ x: 100, y: 100, w: 200, h: 100, rot: 0 }, 'e', { x: 20, y: 150 }, { minW: 30 });
  assert.equal(r.w, 30);
  assert.equal(r.x, 100);
});

test('resizeFromHandle with rotation keeps the opposite corner fixed in the world', () => {
  const start = { x: 100, y: 100, w: 200, h: 100, rot: 30 };
  const fixedBefore = handlePositions(start).nw;
  const pointer = handlePositions({ ...start, w: 260, h: 140 }).se; // approximate target; any pointer works
  const r = resizeFromHandle(start, 'se', pointer);
  const fixedAfter = handlePositions(r).nw;
  near(fixedAfter.x, fixedBefore.x, 0.05); near(fixedAfter.y, fixedBefore.y, 0.05);
  assert.equal(r.rot, 30);
  assert.ok(r.w > 200);
});

test('rotation: shift snaps to 15 degrees, plain rotation follows the pointer', () => {
  const c = { x: 100, y: 100 };
  const start = { x: 100, y: 0 }; // straight above
  near(rotationFromPointer(c, start, { x: 200, y: 100 }, 0), 90);
  near(rotationFromPointer(c, start, { x: 200, y: 100 }, 10), 100);
  assert.equal(rotationFromPointer(c, start, { x: 190, y: 40 }, 0, true) % 15, 0);
  assert.equal(snapAngle(52), 45);
  assert.equal(snapAngle(-5), 0);
  assert.equal(snapAngle(-10), 345);
  assert.equal(normAngle(-90), 270);
});

test('transformGroup maps the group bounds onto new bounds', () => {
  const items = [{ id: 'a', x: 0, y: 0, w: 100, h: 100, rot: 0 }, { id: 'b', x: 100, y: 0, w: 100, h: 100, rot: 0 }];
  const p = transformGroup(items, { x: 0, y: 0, w: 200, h: 100 }, { x: 50, y: 50, w: 400, h: 100 });
  assert.deepEqual(p[0].p, { x: 50, y: 50, w: 200, h: 100 });
  assert.deepEqual(p[1].p, { x: 250, y: 50, w: 200, h: 100 });
});

test('moveBy, rotateGroup, unionBounds', () => {
  assert.deepEqual(moveBy([{ id: 'a', x: 1, y: 2 }], 3, 4)[0].p, { x: 4, y: 6 });
  const p = rotateGroup([{ id: 'a', x: 100, y: 0, w: 100, h: 100, rot: 0 }], 90, { x: 100, y: 100 });
  near(p[0].p.rot, 90);
  near(p[0].p.x + 50, 150); near(p[0].p.y + 50, 150);
  assert.deepEqual(unionBounds([{ x: 0, y: 0, w: 10, h: 10 }, { x: 20, y: 5, w: 10, h: 10 }]), { x: 0, y: 0, w: 30, h: 15 });
  assert.equal(unionBounds([]), null);
});

test('flip: images toggle flags, shapes mirror rotation, arrows swap direction, text cannot flip', () => {
  assert.deepEqual(flipPatch({ type: 'image', flipH: false }, 'h'), { flipH: true });
  assert.deepEqual(flipPatch({ type: 'image', flipV: true }, 'v'), { flipV: false });
  assert.deepEqual(flipPatch({ type: 'shape', kind: 'arrow-r', rot: 30 }, 'h'), { rot: 330, kind: 'arrow-l' });
  assert.deepEqual(flipPatch({ type: 'shape', kind: 'rect', rot: 30 }, 'v'), { rot: 150 });
  assert.equal(flipPatch({ type: 'text' }, 'h'), null);
  const g = flipGroup([{ id: 'a', type: 'shape', kind: 'rect', x: 0, y: 0, w: 10, h: 10, rot: 0 }, { id: 'b', type: 'shape', kind: 'rect', x: 90, y: 0, w: 10, h: 10, rot: 0 }], 'h', { x: 0, y: 0, w: 100, h: 10 });
  assert.equal(g[0].p.x, 90);
  assert.equal(g[1].p.x, 0);
});

test('growVertically grows downward with the top edge fixed, also when rotated', () => {
  assert.equal(growVertically({ x: 0, y: 0, w: 100, h: 50, rot: 0 }, 40), null);
  assert.deepEqual(growVertically({ x: 10, y: 20, w: 100, h: 50, rot: 0 }, 80.2), { x: 10, y: 20, h: 81 });
  const el = { x: 100, y: 100, w: 100, h: 50, rot: 90 };
  const g = growVertically(el, 100);
  const topBefore = corners(el)[0];
  const next = { ...el, ...g };
  const topAfter = corners(next)[0];
  near(topAfter.x, topBefore.x, 0.1); near(topAfter.y, topBefore.y, 0.1);
});

test('rotatePoint and minSizeFor', () => {
  const p = rotatePoint({ x: 10, y: 0 }, { x: 0, y: 0 }, 90);
  near(p.x, 0); near(p.y, 10);
  assert.deepEqual(minSizeFor({ type: 'text' }, 2), { w: 80, h: 48 });
  assert.deepEqual(minSizeFor({ type: 'shape', kind: 'line' }), { w: 0, h: 0 });
});
