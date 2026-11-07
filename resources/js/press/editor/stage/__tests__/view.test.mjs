import test from 'node:test';
import assert from 'node:assert/strict';
import {
  fitZoom, effectiveZoom, sheetOrigin, toSlide, toScreen, zoomAtPoint, clampPan, zoomToRect, normalizeWheel, wheelZoomFactor, pinchStep,
  snapZoom, stepZoomTo, clampZoom,
} from '../lib/view.js';

const deck = { w: 1920, h: 1080 };
const vp = { w: 1200, h: 800 };

test('fit zoom matches the store formula and clamps', () => {
  assert.equal(fitZoom(vp, deck), Math.min((1200 - 48) / 1920, (800 - 48) / 1080));
  assert.equal(fitZoom({ w: 0, h: 0 }, deck), 1);
  assert.equal(effectiveZoom('fit', vp, deck), fitZoom(vp, deck));
  assert.equal(effectiveZoom(9, vp, deck), 4);
  assert.equal(effectiveZoom(0.5, vp, deck), 0.5);
});

test('the sheet is centred at pan zero and screen and slide coordinates round trip', () => {
  const z = 0.5;
  const o = sheetOrigin(vp, deck, z, { x: 0, y: 0 });
  assert.equal(o.x, (1200 - 960) / 2);
  assert.equal(o.y, (800 - 540) / 2);
  const p = { x: 300, y: 200 };
  const back = toSlide(toScreen(p, o, z), o, z);
  assert.ok(Math.abs(back.x - 300) < 1e-9 && Math.abs(back.y - 200) < 1e-9);
});

test('zooming at a point keeps the slide point under the pointer', () => {
  const view = { zoom: 0.5, pan: { x: 40, y: -20 }, vp, deck };
  const anchor = { x: 700, y: 300 };
  const before = toSlide(anchor, sheetOrigin(vp, deck, 0.5, view.pan), 0.5);
  for (const next of [0.25, 1, 2.7]) {
    const r = zoomAtPoint(view, next, anchor);
    const after = toSlide(anchor, sheetOrigin(vp, deck, r.zoom, r.pan), r.zoom);
    assert.ok(Math.abs(after.x - before.x) < 1e-6 && Math.abs(after.y - before.y) < 1e-6, `zoom ${next}`);
  }
  assert.equal(zoomAtPoint(view, 50, anchor).zoom, 4);
  assert.equal(zoomAtPoint(view, 0.001, anchor).zoom, 0.1);
});

test('panning is clamped so part of the sheet stays visible', () => {
  const z = 0.5;
  const c = clampPan({ x: 5000, y: -5000 }, vp, deck, z);
  const o = sheetOrigin(vp, deck, z, c);
  assert.ok(o.x < vp.w, 'left edge inside the viewport');
  assert.ok(o.y + deck.h * z > 0, 'bottom edge inside the viewport');
  assert.deepEqual(clampPan({ x: 10, y: 10 }, vp, deck, z), { x: 10, y: 10 });
});

test('zoom to a rect centres it', () => {
  const rect = { x: 400, y: 300, w: 400, h: 200 };
  const r = zoomToRect(rect, vp, deck, { pad: 100, maxZoom: 2 });
  assert.ok(r.zoom <= 2 && r.zoom >= 0.1);
  const o = sheetOrigin(vp, deck, r.zoom, r.pan);
  const c = toScreen({ x: 600, y: 400 }, o, r.zoom);
  assert.ok(Math.abs(c.x - 600) < 1 && Math.abs(c.y - 400) < 1);
  assert.equal(zoomToRect(null, vp, deck).zoom, fitZoom(vp, deck));
});

test('wheel normalisation and zoom factors', () => {
  assert.deepEqual(normalizeWheel({ deltaX: 0, deltaY: 3, deltaMode: 1 }), { dx: 0, dy: 48 });
  assert.deepEqual(normalizeWheel({ deltaX: 0, deltaY: 10, deltaMode: 0, shiftKey: true }), { dx: 10, dy: 0 });
  assert.ok(wheelZoomFactor(-10, true) > 1);
  assert.ok(wheelZoomFactor(10, true) < 1);
  assert.ok(wheelZoomFactor(-1000, false) < Math.exp(0.31), 'one huge notch cannot jump');
});

test('pinch step reports the scale and the move of the midpoint', () => {
  const p = pinchStep({ a: { x: 0, y: 0 }, b: { x: 100, y: 0 } }, { a: { x: 10, y: 10 }, b: { x: 210, y: 10 } });
  assert.equal(p.scale, 2);
  assert.equal(p.cx, 110);
  assert.equal(p.dx, 60);
  assert.equal(p.dy, 10);
});

test('zoom ladder and 100 percent snapping', () => {
  assert.equal(snapZoom(1.01), 1);
  assert.equal(snapZoom(1.2), 1.2);
  assert.equal(stepZoomTo(1, 1), 1.25);
  assert.equal(stepZoomTo(1, -1), 0.75);
  assert.equal(stepZoomTo(4, 1), 4);
  assert.equal(stepZoomTo(0.1, -1), 0.1);
  assert.equal(clampZoom(10), 4);
});
