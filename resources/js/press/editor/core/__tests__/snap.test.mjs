import test from 'node:test';
import assert from 'node:assert/strict';
import { snapMove, snapResize, measure } from '../snap.js';
import { safeMargin, marginRect, marginLines, gutter } from '../margins.js';
import { mulberry32 } from './helpers.mjs';

const slide = { w: 1920, h: 1080, margin: 96 };
const none = { guides: { slide: false, margins: false, elements: false, centers: false, spacing: false } };

test('snaps to slide edges, centre lines and margins within the threshold only', () => {
  let r = snapMove({ x: 4, y: 500, w: 200, h: 100 }, [], slide);
  assert.equal(r.x, 0);
  assert.ok(r.guides.some((g) => g.kind === 'slide' && g.orient === 'v' && g.pos === 0));
  r = snapMove({ x: 11, y: 500, w: 200, h: 100 }, [], slide);
  assert.equal(r.x, 11, 'outside the 6 px threshold');
  assert.equal(r.snappedX, false);
  r = snapMove({ x: 860, y: 100, w: 200, h: 100 }, [], slide);
  assert.equal(r.x, 860, 'centre x is 960 already');
  r = snapMove({ x: 858, y: 100, w: 200, h: 100 }, [], slide);
  assert.equal(r.x, 860);
  assert.ok(r.guides.some((g) => g.kind === 'slide-center'));
  r = snapMove({ x: 99, y: 600, w: 200, h: 100 }, [], slide);
  assert.equal(r.x, 96);
  assert.ok(r.guides.some((g) => g.kind === 'margin'));
  r = snapMove({ x: 1620, y: 600, w: 200, h: 100 }, [], slide); // right edge 1820 vs margin 1824
  assert.equal(r.x, 1624);
});

test('the threshold is in screen pixels: zooming in makes it smaller in slide units', () => {
  assert.equal(snapMove({ x: 5, y: 500, w: 100, h: 100 }, [], slide, { zoom: 1 }).x, 0);
  assert.equal(snapMove({ x: 5, y: 500, w: 100, h: 100 }, [], slide, { zoom: 4 }).x, 5);
  assert.equal(snapMove({ x: 20, y: 500, w: 100, h: 100 }, [], slide, { zoom: 0.25 }).x, 0, '6px at 25% is 24 units');
});

test('snaps edges and centres to other elements and draws guides between them', () => {
  const others = [{ id: 'o', x: 500, y: 300, w: 200, h: 100 }];
  const r = snapMove({ x: 497, y: 700, w: 100, h: 100 }, others, slide, { guides: { slide: false, margins: false } });
  assert.equal(r.x, 500);
  const g = r.guides.find((x) => x.kind === 'edge');
  assert.ok(g);
  assert.equal(g.orient, 'v');
  assert.equal(g.pos, 500);
  assert.equal(g.from, 300, 'the line runs from the other element');
  assert.equal(g.to, 800, 'to the dragged one');
  const c = snapMove({ x: 552, y: 700, w: 100, h: 100 }, others, slide, { guides: { slide: false, margins: false } });
  assert.equal(c.x, 550, 'centre to centre');
  assert.ok(c.guides.some((x) => x.kind === 'center'));
});

test('x and y snap independently', () => {
  const others = [{ x: 500, y: 300, w: 200, h: 100 }];
  const r = snapMove({ x: 497, y: 403, w: 100, h: 100 }, others, slide, { guides: { slide: false, margins: false } });
  assert.deepEqual([r.x, r.y], [500, 400]);
  assert.deepEqual([r.snappedX, r.snappedY], [true, true]);
});

test('equal spacing: sits in the middle of two neighbours and continues a rhythm', () => {
  const others = [{ x: 0 + 200, y: 100, w: 100, h: 100 }, { x: 700, y: 100, w: 100, h: 100 }];
  const mid = snapMove({ x: 445, y: 100, w: 100, h: 100 }, others, slide, { guides: { slide: false, margins: false, elements: false, centers: false } });
  assert.equal(mid.x, 450, 'gaps of 150 on both sides');
  const sp = mid.guides.filter((g) => g.kind === 'spacing');
  assert.equal(sp.length, 2);
  assert.ok(sp.every((g) => g.value === 150));
  assert.deepEqual(mid.labels.map((l) => l.text), ['150', '150']);

  const row = [{ x: 100, y: 100, w: 100, h: 100 }, { x: 300, y: 100, w: 100, h: 100 }, { x: 500, y: 100, w: 100, h: 100 }];
  const next = snapMove({ x: 696, y: 100, w: 100, h: 100 }, row, slide, { guides: { slide: false, margins: false, elements: false, centers: false } });
  assert.equal(next.x, 700, 'same 100 gap as between the others');
  const before = snapMove({ x: -97, y: 100, w: 100, h: 100 }, row, slide, { guides: { slide: false, margins: false, elements: false, centers: false } });
  assert.equal(before.x, -100);
});

test('grid snapping snaps the leading and trailing edge within the threshold', () => {
  const r = snapMove({ x: 43, y: 58, w: 100, h: 100 }, [], slide, { ...none, grid: 20 });
  assert.deepEqual([r.x, r.y], [40, 60]);
  assert.ok(r.guides.every((g) => g.kind === 'grid'));
  const off = snapMove({ x: 49, y: 51, w: 100, h: 100 }, [], slide, { ...none, grid: 40 });
  assert.equal(off.snappedX, false);
});

test('invariant: a snapped position is never farther than the threshold from the original (seeded)', () => {
  for (let seed = 1; seed <= 300; seed++) {
    const rnd = mulberry32(seed);
    const others = Array.from({ length: Math.floor(rnd() * 6) }, () => ({
      x: Math.round(rnd() * 1700), y: Math.round(rnd() * 900), w: 20 + Math.round(rnd() * 300), h: 20 + Math.round(rnd() * 200),
    }));
    const box = { x: rnd() * 1800 - 50, y: rnd() * 1000 - 50, w: 20 + rnd() * 400, h: 20 + rnd() * 300 };
    const zoom = [0.25, 0.5, 1, 2, 4][Math.floor(rnd() * 5)];
    const thr = 6 / zoom;
    const grid = rnd() < 0.3 ? 20 : 0;
    const r = snapMove(box, others, slide, { zoom, grid });
    assert.ok(Math.abs(r.x - box.x) <= thr + 0.01, `seed ${seed}: dx ${r.x - box.x} > ${thr}`);
    assert.ok(Math.abs(r.y - box.y) <= thr + 0.01, `seed ${seed}: dy ${r.y - box.y} > ${thr}`);
    if (!r.snappedX) assert.equal(r.dx, 0);
    if (!r.snappedY) assert.equal(r.dy, 0);
  }
});

test('snapResize moves only the edges the handle moves', () => {
  const others = [{ x: 500, y: 0, w: 100, h: 100 }];
  const r = snapResize({ x: 100, y: 100, w: 397, h: 200 }, 'e', others, slide, { guides: { slide: false, margins: false } });
  assert.deepEqual(r.rect, { x: 100, y: 100, w: 400, h: 200 });
  assert.equal(r.snappedY, false);
  const w = snapResize({ x: 103, y: 100, w: 300, h: 200 }, 'w', [{ x: 100, y: 0, w: 50, h: 50 }], slide, { guides: { slide: false, margins: false } });
  assert.deepEqual(w.rect, { x: 100, y: 100, w: 303, h: 200 });
});

test('measure gives the gaps to the nearest neighbours and the slide edges', () => {
  const m = measure({ x: 500, y: 400, w: 100, h: 100 }, [{ x: 100, y: 380, w: 200, h: 150 }, { x: 800, y: 420, w: 50, h: 50 }], slide);
  const left = m.find((x) => x.orient === 'h' && x.to === 500);
  assert.equal(left.value, 200);
  const right = m.find((x) => x.orient === 'h' && x.from === 600);
  assert.equal(right.value, 200);
  const top = m.find((x) => x.orient === 'v' && x.to === 400);
  assert.equal(top.value, 400, 'to the slide edge');
});

test('margins: derived from the width, or from the theme', () => {
  assert.equal(safeMargin({ w: 1920, h: 1080 }), 96);
  assert.equal(safeMargin({ w: 1280, h: 720 }), 64);
  assert.equal(safeMargin({ w: 1920, h: 1080, theme: { s: { margin: 120 } } }), 120);
  assert.equal(safeMargin({ w: 1920, h: 1080, theme: { s: { margin: 9999 } } }), 96, 'absurd values are ignored');
  assert.deepEqual(marginRect({ w: 1920, h: 1080 }), { x: 96, y: 96, w: 1728, h: 888 });
  assert.deepEqual(marginLines({ w: 1920, h: 1080 }), { x: [96, 1824], y: [96, 984] });
  assert.equal(gutter({ w: 1920, h: 1080 }), 48);
});
