import test from 'node:test';
import assert from 'node:assert/strict';
import { alignPatches, distributePatches, tidyPatches, matchSizePatches } from '../align.js';

const E = (id, x, y, w, h, o = {}) => ({ id, type: 'shape', x, y, w, h, rot: 0, ...o });
const apply = (els, patches) => els.map((e) => ({ ...e, ...(patches.find((p) => p.id === e.id)?.p || {}) }));
const slide = { w: 1920, h: 1080 };

test('align to the selection', () => {
  const els = [E('a', 100, 100, 50, 50), E('b', 300, 200, 100, 100), E('c', 200, 400, 20, 20)];
  const left = apply(els, alignPatches(els, 'left'));
  assert.deepEqual(left.map((e) => e.x), [100, 100, 100]);
  const right = apply(els, alignPatches(els, 'right'));
  assert.deepEqual(right.map((e) => e.x + e.w), [400, 400, 400]);
  const centre = apply(els, alignPatches(els, 'center'));
  assert.deepEqual(centre.map((e) => e.x + e.w / 2), [250, 250, 250]);
  const middle = apply(els, alignPatches(els, 'middle'));
  assert.deepEqual(middle.map((e) => e.y + e.h / 2), [260, 260, 260]);
  const top = apply(els, alignPatches(els, 'top'));
  assert.deepEqual(top.map((e) => e.y), [100, 100, 100]);
  const bottom = apply(els, alignPatches(els, 'bottom'));
  assert.deepEqual(bottom.map((e) => e.y + e.h), [420, 420, 420]);
});

test('align leaves elements that are already aligned out of the patch list; one element needs the slide', () => {
  const els = [E('a', 100, 100, 50, 50), E('b', 100, 200, 100, 100)];
  assert.deepEqual(alignPatches(els, 'left').length, 0);
  assert.deepEqual(alignPatches([E('a', 100, 100, 50, 50)], 'left'), []);
  const p = alignPatches([E('a', 100, 100, 50, 50)], 'center', { to: 'slide', slide });
  assert.equal(p[0].p.x, 935);
});

test('align to the slide, with a margin for the edges', () => {
  const els = [E('a', 500, 500, 100, 100)];
  assert.equal(alignPatches(els, 'left', { to: 'slide', slide, margin: 96 })[0].p.x, 96);
  assert.equal(alignPatches(els, 'right', { to: 'slide', slide, margin: 96 })[0].p.x, 1724);
  assert.equal(alignPatches(els, 'top', { to: 'slide', slide })[0].p.y, 0);
  assert.equal(alignPatches(els, 'middle', { to: 'slide', slide })[0].p.y, 490);
});

test('rotated elements align by their visible bounds', () => {
  const els = [E('a', 100, 100, 200, 100), E('b', 500, 100, 200, 100, { rot: 90 })];
  // b rotated 90 around (600,150) occupies x 550..650
  const p = alignPatches(els, 'left');
  assert.equal(p.find((x) => x.id === 'b').p.x, 50, 'b moves left until its visible left edge is at 100');
  const moved = apply(els, p).find((e) => e.id === 'b');
  assert.equal(moved.x + (moved.w - moved.h) / 2, 100, 'visible left edge');
});

test('grouped elements move as one unit', () => {
  const els = [E('a', 100, 100, 50, 50, { group: 'g' }), E('b', 200, 100, 50, 50, { group: 'g' }), E('c', 500, 300, 50, 50)];
  const p = alignPatches(els, 'left');
  assert.deepEqual(p.map((x) => [x.id, x.p.x]), [['c', 100]]);
  const q = alignPatches(els, 'right');
  assert.equal(q.find((x) => x.id === 'a').p.x - q.find((x) => x.id === 'b').p.x, -100, 'spacing inside the group is kept');
});

test('distribute horizontally with equal gaps, the outer two stay', () => {
  const els = [E('a', 0, 0, 100, 50), E('b', 120, 0, 50, 50), E('c', 500, 0, 100, 50), E('d', 900, 0, 100, 50)];
  const out = apply(els, distributePatches(els, 'h'));
  const gaps = [out[1].x - (out[0].x + out[0].w), out[2].x - (out[1].x + out[1].w), out[3].x - (out[2].x + out[2].w)];
  assert.equal(out[0].x, 0);
  assert.equal(out[3].x, 900);
  assert.ok(gaps.every((g) => Math.abs(g - gaps[0]) < 0.02), JSON.stringify(gaps));
  assert.equal(distributePatches(els.slice(0, 2), 'h').length, 0, 'needs three');
});

test('distribute by centres, vertically, and relative to the slide', () => {
  const els = [E('a', 0, 0, 100, 100), E('b', 0, 130, 50, 50), E('c', 0, 800, 100, 100)];
  const v = apply(els, distributePatches(els, 'v', { mode: 'centers' }));
  assert.deepEqual(v.map((e) => e.y + e.h / 2), [50, 450, 850]);
  const s = apply(els, distributePatches(els, 'v', { to: 'slide', slide, margin: 96 }));
  assert.equal(s[0].y, 96);
  assert.equal(s[2].y + s[2].h, 984);
  const gap1 = s[1].y - (s[0].y + s[0].h);
  const gap2 = s[2].y - (s[1].y + s[1].h);
  assert.ok(Math.abs(gap1 - gap2) < 0.02);
});

test('tidy pulls near misses into line and leaves clear differences alone', () => {
  const els = [E('a', 100, 100, 200, 50), E('b', 104, 300, 150, 50), E('c', 97, 500, 100, 50), E('d', 600, 100, 100, 50)];
  const out = apply(els, tidyPatches(els, { tolerance: 8 }));
  assert.equal(out[0].x, out[1].x);
  assert.equal(out[1].x, out[2].x);
  assert.equal(out[3].x, 600);
  assert.equal(out[0].y, out[3].y, 'tops 100 and 100 stay');
  assert.deepEqual(tidyPatches([E('a', 0, 0, 10, 10), E('b', 500, 500, 10, 10)]), []);
});

test('tidy aligns right edges and centres when left edges differ', () => {
  const els = [E('a', 100, 100, 200, 50), E('b', 150, 300, 148, 50)]; // right edges 300 and 298
  const out = apply(els, tidyPatches(els, { tolerance: 8 }));
  assert.equal(out[0].x + out[0].w, out[1].x + out[1].w);
});

test('match size copies width and height from the reference', () => {
  const els = [E('a', 0, 0, 100, 50), E('b', 0, 0, 30, 30), E('c', 0, 0, 100, 10)];
  assert.deepEqual(matchSizePatches(els, 'both'), [{ id: 'b', p: { w: 100, h: 50 } }, { id: 'c', p: { h: 50 } }]);
  assert.deepEqual(matchSizePatches(els, 'w', 'b'), [{ id: 'a', p: { w: 30 } }, { id: 'c', p: { w: 30 } }]);
  assert.deepEqual(matchSizePatches([els[0]], 'both'), []);
});
