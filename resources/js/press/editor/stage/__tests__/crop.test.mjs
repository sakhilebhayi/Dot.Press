import test from 'node:test';
import assert from 'node:assert/strict';
import {
  visibleWindow, imageRect, panWindow, zoomWindow, resizeWindow, commitCrop, clampWindow, MIN_WINDOW,
} from '../lib/crop.js';
import { imagePlacement } from '../../../render/geometry.js';

const nat = { w: 4000, h: 2000 };
const near = (a, b, e = 1e-6) => assert.ok(Math.abs(a - b) <= e, `${a} vs ${b}`);

test('the visible window has the ratio of the frame and renders identically to the stored crop', () => {
  const box = { w: 400, h: 400 };
  const crop = { x: 0, y: 0, w: 1, h: 1 }; // the whole 2:1 source in a square frame: cover cuts the sides
  const win = visibleWindow({ crop, nat, box, fit: 'cover' });
  near((win.w * nat.w) / (win.h * nat.h), 1);
  near(win.h, 1);
  near(win.x, 0.25);
  // rendering the exact window gives the same image rectangle as rendering the original crop
  const a = imagePlacement({ crop, nat, box, fit: 'cover' });
  const b = imagePlacement({ crop: win, nat, box, fit: 'cover' });
  for (const k of ['left', 'top', 'width', 'height']) near(a[k], b[k], 0.02);
});

test('fill fit uses the crop as it is', () => {
  const crop = { x: 0.1, y: 0.2, w: 0.5, h: 0.5 };
  assert.deepEqual(visibleWindow({ crop, nat, box: { w: 100, h: 100 }, fit: 'fill' }), crop);
});

test('the full source rectangle is placed relative to the frame', () => {
  const box = { w: 400, h: 200 };
  const r = imageRect({ x: 0.25, y: 0, w: 0.5, h: 1 }, nat, box);
  near(r.width, 800);
  near(r.height, 200);
  near(r.left, -200);
  near(r.top, 0);
});

test('dragging the picture inside the frame moves the window the opposite way and stays inside the source', () => {
  const box = { w: 400, h: 200 };
  const win = { x: 0.25, y: 0, w: 0.5, h: 1 };
  const p = panWindow(win, 100, 0, nat, box); // drag right by 100 units: the window moves left in the source
  near(p.x, 0.25 - 100 / 800);
  const stuck = panWindow(win, 100000, 50, nat, box);
  near(stuck.x, 0);
  near(stuck.y, 0, 1e-9);
  assert.equal(panWindow(win, 0, 0, nat, box).x, 0.25);
});

test('zooming keeps the point under the anchor fixed and respects the limits', () => {
  const box = { w: 400, h: 200 };
  const win = { x: 0.25, y: 0.25, w: 0.5, h: 0.5 };
  const z = zoomWindow(win, 2, { u: 0.5, v: 0.5 }, nat, box);
  near(z.w, 0.25);
  near(z.x, 0.375);
  // an off centre anchor stays put
  const a = { u: 0.2, v: 0.8 };
  const z2 = zoomWindow(win, 2, a, nat, box);
  near(z2.x + a.u * z2.w, win.x + a.u * win.w);
  near(z2.y + a.v * z2.h, win.y + a.v * win.h);
  const out = zoomWindow(win, 0.01, { u: 0.5, v: 0.5 }, nat, box);
  near(out.w, 1);
  near(out.h, 1);
  const deep = zoomWindow(win, 1e6, { u: 0.5, v: 0.5 }, nat, box);
  assert.ok(deep.w >= MIN_WINDOW - 1e-9);
});

test('resizing the frame keeps the picture fixed on the slide and may not leave the picture', () => {
  const start = { x: 100, y: 100, w: 400, h: 200, rot: 0 };
  const win = { x: 0.25, y: 0, w: 0.5, h: 1 };
  // drag the east handle left by 100: the frame narrows, the window narrows by 100/800
  const r = resizeWindow(start, win, 'e', { x: 400, y: 200 }, nat);
  near(r.box.w, 300);
  near(r.win.w, 300 / 800);
  near(r.win.x, 0.25);
  // drag the west handle left by 100: the frame grows to the left and shows more of the source
  const l = resizeWindow(start, win, 'w', { x: 0, y: 200 }, nat);
  near(l.box.x, 0);
  near(l.box.w, 500);
  near(l.win.x, 0.25 - 100 / 800);
  // dragging far past the picture's edge stops at the edge
  const far = resizeWindow(start, win, 'w', { x: -5000, y: 200 }, nat);
  near(far.win.x, 0);
  near(far.box.x, 100 - 200);
});

test('resizing a rotated frame works in its own axes', () => {
  const start = { x: 100, y: 100, w: 400, h: 200, rot: 90 };
  const win = { x: 0.25, y: 0, w: 0.5, h: 1 };
  // centre (300, 200); the frame's east edge now faces down; dragging it down by 50 widens it by 50
  const r = resizeWindow(start, win, 'e', { x: 300, y: 200 + 200 + 50 }, nat);
  near(r.box.w, 450);
  near(r.win.w, 450 / 800);
});

test('commit writes the crop and only changes the frame when it changed', () => {
  const el = { id: 'i', fit: 'cover', x: 1, y: 2, w: 3, h: 4 };
  const same = commitCrop({ x: 0.123456, y: 0, w: 0.5, h: 1 }, { x: 1, y: 2, w: 3, h: 4 }, { x: 1, y: 2, w: 3, h: 4 }, el);
  assert.deepEqual(same, { crop: { x: 0.1235, y: 0, w: 0.5, h: 1 } });
  const moved = commitCrop({ x: 0, y: 0, w: 1, h: 1 }, { x: 1, y: 2, w: 3, h: 4 }, { x: 1, y: 2, w: 5, h: 4 }, { ...el, fit: 'contain' });
  assert.equal(moved.w, 5);
  assert.equal(moved.fit, 'cover');
  assert.deepEqual(clampWindow({ x: 0.9, y: 0.9, w: 0.5, h: 2 }), { x: 0.5, y: 0, w: 0.5, h: 1 });
});
