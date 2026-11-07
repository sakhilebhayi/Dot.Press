import test from 'node:test';
import assert from 'node:assert/strict';
import {
  beginMove, stepMove, beginResize, stepResize, beginRotate, stepRotate, stepLineEnd, lineFromEndpoints, snapToGuides, magnetAngle, readoutText,
} from '../lib/transform.js';
import { S, T, R } from '../../core/__tests__/helpers.mjs';

const deck = { w: 1920, h: 1080 };

test('moving applies the pointer delta to every selected element', () => {
  const slide = S('s', [R('a', 100, 100, 200, 100), R('b', 400, 300, 100, 100), R('far', 1500, 800, 100, 100)]);
  const ctx = beginMove({ slide, ids: ['a', 'b'], deck });
  const r = stepMove(ctx, { dx: 33, dy: 17, snap: false });
  assert.deepEqual(r.patches, [
    { id: 'a', p: { x: 133, y: 117 } },
    { id: 'b', p: { x: 433, y: 317 } },
  ]);
  assert.deepEqual(r.box, { x: 133, y: 117, w: 400, h: 300 });
  assert.equal(r.readout, 'X 133   Y 117');
});

test('shift locks the move to the dominant axis', () => {
  const slide = S('s', [R('a', 100, 100, 200, 100)]);
  const ctx = beginMove({ slide, ids: ['a'], deck });
  const r = stepMove(ctx, { dx: 80, dy: 20, shift: true, snap: false });
  assert.deepEqual(r.patches[0].p, { x: 180, y: 100 });
  const r2 = stepMove(ctx, { dx: 10, dy: -60, shift: true, snap: false });
  assert.deepEqual(r2.patches[0].p, { x: 100, y: 40 });
});

test('moving snaps to the slide centre, and reports the guide', () => {
  const slide = S('s', [R('a', 100, 100, 200, 100), R('n', 800, 600, 100, 100)]);
  const ctx = beginMove({ slide, ids: ['a'], deck });
  // a's centre x is at 200 + dx; the slide centre is 960: dx 758 puts it 2 units off
  const r = stepMove(ctx, { dx: 758, dy: 0, zoom: 1 });
  assert.equal(r.patches[0].p.x, 860);
  assert.ok(r.guides.some((g) => g.orient === 'v' && g.pos === 960));
  const off = stepMove(ctx, { dx: 758, dy: 0, zoom: 1, snap: false });
  assert.equal(off.patches[0].p.x, 858, 'cmd held: no snapping');
  assert.equal(off.guides.length, 0);
});

test('the snap threshold is constant on screen: zooming in makes it finer', () => {
  const slide = S('s', [R('a', 100, 100, 200, 100)]);
  const ctx = beginMove({ slide, ids: ['a'], deck });
  const near = stepMove(ctx, { dx: 758, dy: 0, zoom: 1 });
  const zoomed = stepMove(ctx, { dx: 758, dy: 0, zoom: 4 });
  assert.equal(near.patches[0].p.x, 860);
  assert.equal(zoomed.patches[0].p.x, 858, '2 units is 8 screen pixels at 4x, outside the 6 px threshold');
});

test('hidden elements are not snap targets and moved ones do not snap to themselves', () => {
  const slide = S('s', [R('a', 100, 100, 200, 100), R('h', 500, 500, 100, 100, { hide: true })]);
  const ctx = beginMove({ slide, ids: ['a'], deck });
  assert.equal(ctx.others.length, 0);
});

test('user guides from the rulers snap an edge', () => {
  const slide = S('s', [R('a', 100, 100, 200, 100)]);
  const ctx = beginMove({ slide, ids: ['a'], deck, userGuides: [{ orient: 'v', pos: 700 }] });
  const r = stepMove(ctx, { dx: 598, dy: 0, zoom: 1 });
  assert.equal(r.patches[0].p.x, 700, 'left edge 698 snaps to 700');
  assert.ok(r.guides.some((g) => g.kind === 'user'));
  const g = snapToGuides({ x: 0, y: 0, w: 100, h: 100 }, [{ orient: 'h', pos: 52 }], 1);
  assert.equal(g.dy, 2);
});

test('resizing a text box from the east edge changes only the width', () => {
  const slide = S('s', [T('t', 100, 100, 400, 100)]);
  const ctx = beginResize({ slide, ids: ['t'], handle: 'e', deck });
  const r = stepResize(ctx, { pointer: { x: 650, y: 400 }, snap: false });
  assert.deepEqual(r.patches[0].p, { x: 100, y: 100, w: 550, h: 100 });
  assert.equal(r.readout, 'W 550   H 100');
});

test('a picture keeps its ratio by default and shift frees it', () => {
  const slide = S('s', [{ id: 'i', type: 'image', x: 100, y: 100, w: 400, h: 200, rot: 0, op: 1, lock: false, hide: false }]);
  const ctx = beginResize({ slide, ids: ['i'], handle: 'se', deck });
  const locked = stepResize(ctx, { pointer: { x: 900, y: 300 }, snap: false }).patches[0].p;
  assert.ok(Math.abs(locked.w / locked.h - 2) < 0.01);
  const free = stepResize(ctx, { pointer: { x: 900, y: 300 }, shift: true, snap: false }).patches[0].p;
  assert.equal(free.w, 800);
  assert.equal(free.h, 200);
  // shapes are the other way around
  const s2 = S('s', [R('r', 100, 100, 400, 200)]);
  const c2 = beginResize({ slide: s2, ids: ['r'], handle: 'se', deck });
  assert.equal(stepResize(c2, { pointer: { x: 900, y: 300 }, snap: false }).patches[0].p.h, 200);
  const sq = stepResize(c2, { pointer: { x: 900, y: 300 }, shift: true, snap: false }).patches[0].p;
  assert.ok(Math.abs(sq.w / sq.h - 2) < 0.01);
});

test('alt resizes from the centre', () => {
  const slide = S('s', [R('r', 100, 100, 200, 200)]);
  const ctx = beginResize({ slide, ids: ['r'], handle: 'e', deck });
  const p = stepResize(ctx, { pointer: { x: 350, y: 200 }, alt: true, snap: false }).patches[0].p;
  assert.deepEqual(p, { x: 50, y: 100, w: 300, h: 200 });
});

test('resizing snaps an edge to a neighbour and the guide is reported', () => {
  const slide = S('s', [R('r', 100, 100, 200, 200), R('n', 600, 100, 100, 100)]);
  const ctx = beginResize({ slide, ids: ['r'], handle: 'e', deck });
  const r = stepResize(ctx, { pointer: { x: 598, y: 200 }, zoom: 1 });
  assert.equal(r.patches[0].p.w, 500);
  assert.ok(r.guides.length > 0);
});

test('a locked-ratio snap keeps the ratio', () => {
  const slide = S('s', [{ id: 'i', type: 'image', x: 100, y: 100, w: 400, h: 200, rot: 0, op: 1, lock: false, hide: false }, R('n', 700, 800, 100, 100)]);
  const ctx = beginResize({ slide, ids: ['i'], handle: 'se', deck });
  const p = stepResize(ctx, { pointer: { x: 698, y: 380 }, zoom: 1 }).patches[0].p;
  assert.ok(Math.abs(p.w / p.h - 2) < 0.02, `ratio ${p.w / p.h}`);
});

test('a selection of several elements scales as one group', () => {
  const slide = S('s', [R('a', 100, 100, 100, 100), R('b', 300, 100, 100, 100)]);
  const ctx = beginResize({ slide, ids: ['a', 'b'], handle: 'se', deck });
  // union 100..400 x 100..200, drag the south east corner to double the width
  const r = stepResize(ctx, { pointer: { x: 700, y: 200 }, snap: false });
  assert.deepEqual(r.box, { x: 100, y: 100, w: 600, h: 100, rot: 0 });
  const a = r.patches.find((p) => p.id === 'a').p;
  const b = r.patches.find((p) => p.id === 'b').p;
  assert.equal(a.w, 200);
  assert.equal(b.x, 500);
});

test('rotating follows the pointer, shift snaps to 15 degrees, near multiples of 45 are magnetic', () => {
  const slide = S('s', [R('r', 100, 100, 200, 200)]);
  const ctx = beginRotate({ slide, ids: ['r'], pointer0: { x: 200, y: 0 } });
  const quarter = stepRotate(ctx, { pointer: { x: 400, y: 200 } });
  assert.equal(quarter.rot, 90);
  assert.equal(quarter.readout, '90°');
  const pt = { x: 200 + 200 * Math.sin(0.4), y: 200 - 200 * Math.cos(0.4) };
  const free = stepRotate(ctx, { pointer: pt });
  assert.ok(Math.abs(free.rot - 22.92) < 0.05, `${free.rot}`);
  const snapped = stepRotate(ctx, { pointer: pt, shift: true });
  assert.equal(snapped.rot, 30);
  assert.equal(magnetAngle(44), 45);
  assert.equal(magnetAngle(358.5), 0);
  assert.equal(magnetAngle(30), 30);
});

test('rotating a selection turns every member around the shared centre', () => {
  const slide = S('s', [R('a', 0, 100, 100, 100), R('b', 200, 100, 100, 100)]);
  const ctx = beginRotate({ slide, ids: ['a', 'b'], pointer0: { x: 150, y: 0 } });
  const r = stepRotate(ctx, { pointer: { x: 300, y: 150 } });
  assert.equal(r.angle, 90);
  const a = r.patches.find((p) => p.id === 'a').p;
  assert.equal(a.rot, 90);
  // a's centre (50, 150) turns 90 degrees clockwise around (150, 150): to (150, 50)
  assert.ok(Math.abs(a.x + 50 - 150) < 0.02 && Math.abs(a.y + 50 - 50) < 0.02, JSON.stringify(a));
});

test('dragging a line end moves one end and keeps the other', () => {
  const el = { id: 'l', type: 'shape', kind: 'line', x: 100, y: 200, w: 300, h: 0, rot: 0 };
  const r = stepLineEnd(el, 'e', { x: 100, y: 500 });
  const p = r.patches[0].p;
  assert.equal(p.w, 300);
  assert.equal(p.rot, 90);
  assert.ok(Math.abs(p.x + p.w / 2 - 100) < 0.02, 'centre x');
  const s = stepLineEnd(el, 's', { x: 0, y: 200 });
  assert.equal(s.patches[0].p.w, 400);
  assert.equal(s.patches[0].p.rot, 0);
  // shift snaps the angle to 15 degrees
  const sh = stepLineEnd(el, 'e', { x: 100 + 300 * Math.cos(0.3), y: 200 + 300 * Math.sin(0.3) }, { shift: true });
  assert.equal(sh.patches[0].p.rot, 15);
  assert.ok(Math.abs(sh.patches[0].p.w - 300) < 0.02);
});

test('line from endpoints', () => {
  const l = lineFromEndpoints({ x: 0, y: 0 }, { x: 100, y: 0 });
  assert.deepEqual(l, { x: 0, y: 0, w: 100, h: 0, rot: 0 });
  const d = lineFromEndpoints({ x: 100, y: 100 }, { x: 0, y: 100 });
  assert.equal(d.rot, 180);
  assert.equal(readoutText('line', d, { angle: 180 }), '100  180°');
});

test('dragging 50 elements costs well under a frame of JavaScript per move', () => {
  const els = [];
  for (let i = 0; i < 120; i++) els.push(R(`e${i}`, (i % 12) * 150, Math.floor(i / 12) * 100, 120, 80));
  const slide = S('s', els);
  const ids = els.slice(0, 50).map((e) => e.id);
  const ctx = beginMove({ slide, ids, deck });
  for (let i = 0; i < 50; i++) stepMove(ctx, { dx: i, dy: i, zoom: 0.5 }); // warm up the JIT
  const frames = 600;
  const t0 = performance.now();
  for (let i = 0; i < frames; i++) stepMove(ctx, { dx: 3 + i * 0.37, dy: 2 + i * 0.21, zoom: 0.5 });
  const perFrame = (performance.now() - t0) / frames;
  assert.ok(perFrame < 4, `step took ${perFrame.toFixed(3)} ms per frame`);
  const resizeCtx = beginResize({ slide, ids, handle: 'se', deck });
  const t1 = performance.now();
  for (let i = 0; i < frames; i++) stepResize(resizeCtx, { pointer: { x: 1700 + i * 0.1, y: 700 + i * 0.1 }, zoom: 0.5, shift: true });
  const perResize = (performance.now() - t1) / frames;
  assert.ok(perResize < 4, `resize took ${perResize.toFixed(3)} ms per frame`);
  console.log(`# 50 elements: move ${perFrame.toFixed(3)} ms/frame, group resize ${perResize.toFixed(3)} ms/frame`);
});
