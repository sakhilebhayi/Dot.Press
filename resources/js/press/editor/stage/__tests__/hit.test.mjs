import test from 'node:test';
import assert from 'node:assert/strict';
import { hitOrder, pickTop, pickBelow, lockedAt, contextPick, tabOrder, isLine } from '../lib/hit.js';
import { S, T, R } from '../../core/__tests__/helpers.mjs';

const slide = S('s1', [
  R('bottom', 0, 0, 400, 400),
  R('mid', 100, 100, 200, 200),
  T('top', 150, 150, 50, 50),
  R('lock', 500, 0, 100, 100, { lock: true }),
  R('hid', 700, 0, 100, 100, { hide: true }),
  { id: 'ln', type: 'shape', kind: 'line', x: 0, y: 600, w: 300, h: 0, rot: 0, fill: '@ink' },
]);

test('hit order is topmost first and skips locked and hidden', () => {
  assert.deepEqual(hitOrder(slide, { x: 160, y: 160 }), ['top', 'mid', 'bottom']);
  assert.deepEqual(hitOrder(slide, { x: 550, y: 50 }), []);
  assert.deepEqual(hitOrder(slide, { x: 550, y: 50 }, { locked: true }), ['lock']);
  assert.deepEqual(hitOrder(slide, { x: 750, y: 50 }), []);
  assert.equal(pickTop(slide, { x: 20, y: 20 }), 'bottom');
  assert.equal(pickTop(slide, { x: 1000, y: 20 }), null);
});

test('alt click cycles to the element behind', () => {
  const pt = { x: 160, y: 160 };
  assert.equal(pickBelow(slide, pt, 'top'), 'mid');
  assert.equal(pickBelow(slide, pt, 'mid'), 'bottom');
  assert.equal(pickBelow(slide, pt, 'bottom'), 'top');
  assert.equal(pickBelow(slide, { x: 999, y: 999 }, 'top'), null);
});

test('thin lines are easy to hit within the padding', () => {
  assert.equal(pickTop(slide, { x: 100, y: 604 }, { pad: 0 }), null);
  assert.equal(pickTop(slide, { x: 100, y: 604 }, { pad: 6 }), 'ln');
  assert.ok(isLine(slide.els[5]));
});

test('rotated elements hit in their own frame', () => {
  const s = S('s', [R('r', 100, 100, 200, 20, { rot: 90 })]);
  // rotated 90 degrees around (200, 110): now tall and thin
  assert.equal(pickTop(s, { x: 200, y: 20 }), 'r');
  assert.equal(pickTop(s, { x: 120, y: 110 }), null);
});

test('locked elements are reported for the hint, tab order is pickable bottom to top', () => {
  assert.equal(lockedAt(slide, { x: 550, y: 50 }), 'lock');
  assert.equal(lockedAt(slide, { x: 20, y: 20 }), null);
  assert.deepEqual(tabOrder(slide), ['bottom', 'mid', 'top', 'ln']);
  assert.deepEqual(tabOrder(null), []);
});

test('a right click lands on the top unlocked element, else on a locked one so the menu can offer Unlock', () => {
  const slide = { els: [
    { id: 'free', type: 'shape', kind: 'rect', x: 0, y: 0, w: 100, h: 100 },
    { id: 'lock', type: 'shape', kind: 'rect', x: 500, y: 0, w: 100, h: 100, lock: true },
    { id: 'under', type: 'shape', kind: 'rect', x: 500, y: 500, w: 100, h: 100, lock: true },
    { id: 'over', type: 'shape', kind: 'rect', x: 500, y: 500, w: 100, h: 100 },
  ] };
  assert.deepEqual(contextPick(slide, { x: 50, y: 50 }), { id: 'free', locked: false });
  assert.deepEqual(contextPick(slide, { x: 550, y: 50 }), { id: 'lock', locked: true });
  assert.deepEqual(contextPick(slide, { x: 550, y: 550 }), { id: 'over', locked: false });
  assert.equal(contextPick(slide, { x: 300, y: 300 }), null);
});
