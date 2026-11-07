/**
 * Crop mode maths for pictures. Pure.
 *
 * The picture element shows a window of its source. In the document the window is `crop` (fractions of the
 * source). Rendering (render/geometry.js imagePlacement) scales the window to cover the frame, so when the
 * window's ratio differs from the frame's ratio part of it is cut off. Crop mode first turns that into an
 * exact window whose ratio equals the frame's, `visibleWindow`, and works on that. Committing writes the
 * window back as `crop`, which renders identically under `cover`.
 *
 *   visibleWindow({crop, nat, box, fit})          -> {x, y, w, h} fractions, ratio equal to the frame (cover, fill)
 *   imageRect(win, nat, box)                      -> {left, top, width, height} of the whole source relative to the frame
 *   panWindow(win, dx, dy, nat, box)              dx, dy: how far the picture is dragged inside the frame (slide units)
 *   zoomWindow(win, factor, anchor, nat, box)     factor > 1 magnifies; anchor {u, v} in 0..1 of the frame is kept fixed
 *   resizeWindow(start, handle, pointer, ctx)     drag a frame handle: {box, win} - the picture stays where it is, the frame changes
 *   commitCrop(win, box0, box1, el)               patch for the element ({crop, x, y, w, h, fit})
 *   MIN_WINDOW
 */
import { normalizeCrop } from '../../../render/geometry.js';
import { resizeFromHandle, rotatePoint, centerOf } from '../../core/geometry.js';
import { round2 } from '../../core/util.js';

export const MIN_WINDOW = 0.02;
const clamp = (n, lo, hi) => Math.min(hi, Math.max(lo, n));

function natOf(nat, box) {
  return { w: Math.max(1, +nat?.w || box.w), h: Math.max(1, +nat?.h || box.h) };
}

export function visibleWindow({ crop, nat, box, fit = 'cover' }) {
  const c = normalizeCrop(crop);
  if (fit === 'fill') return c;
  const n = natOf(nat, box);
  const ww = c.w * n.w;
  const wh = c.h * n.h;
  const bw = Math.max(1, box.w);
  const bh = Math.max(1, box.h);
  const s = Math.max(bw / ww, bh / wh);
  const sw = bw / s;
  const sh = bh / s;
  const x = c.x + (c.w - sw / n.w) / 2;
  const y = c.y + (c.h - sh / n.h) / 2;
  return clampWindow({ x, y, w: sw / n.w, h: sh / n.h });
}

export function clampWindow(win) {
  const w = clamp(win.w, MIN_WINDOW, 1);
  const h = clamp(win.h, MIN_WINDOW, 1);
  return { x: clamp(win.x, 0, 1 - w), y: clamp(win.y, 0, 1 - h), w, h };
}

export function imageRect(win, nat, box) {
  const n = natOf(nat, box);
  const width = box.w / win.w;
  const height = box.h / win.h;
  return { left: -win.x * width, top: -win.y * height, width, height, nw: n.w, nh: n.h };
}

export function panWindow(win, dx, dy, nat, box) {
  const r = imageRect(win, nat, box);
  return clampWindow({ ...win, x: win.x - dx / r.width, y: win.y - dy / r.height });
}

export function zoomWindow(win, factor, anchor, nat, box) {
  // k > 1 magnifies. The window may not grow past the whole source or shrink below the minimum.
  const lo = Math.max(win.w, win.h);
  const hi = Math.max(lo, Math.min(win.w, win.h) / MIN_WINDOW);
  const k = clamp(Math.max(0.01, factor), lo, hi);
  const w = win.w / k;
  const h = win.h / k;
  // the source point under the anchor before: win.x + u * win.w; keep it under the anchor after
  const px = win.x + anchor.u * win.w;
  const py = win.y + anchor.v * win.h;
  return clampWindow({ x: px - anchor.u * w, y: py - anchor.v * h, w, h });
}

/**
 * Resize the frame in crop mode. The picture keeps its place and scale on the slide, the frame moves over it,
 * so a smaller frame shows less of the source and a larger one shows more, up to the source's edge.
 * `start` is {x, y, w, h, rot} of the frame, `win` the window at the start.
 */
export function resizeWindow(start, win, handle, pointer, nat, opts = {}) {
  const rot = start.rot || 0;
  const c0 = centerOf(start);
  const rect = resizeFromHandle(start, handle, pointer, { minW: opts.minW ?? 24, minH: opts.minH ?? 24, center: !!opts.center });
  // work in the start frame's local axes: undo the rotation about the start centre
  const rc = rotatePoint(centerOf(rect), c0, -rot);
  let L = { x: rc.x - rect.w / 2, y: rc.y - rect.h / 2, w: rect.w, h: rect.h };
  const ir = imageRect(win, nat, start);
  const R = { x: start.x + ir.left, y: start.y + ir.top, w: ir.width, h: ir.height };
  // the frame may not leave the picture
  const x1 = clamp(L.x, R.x, R.x + R.w);
  const y1 = clamp(L.y, R.y, R.y + R.h);
  const x2 = clamp(L.x + L.w, R.x, R.x + R.w);
  const y2 = clamp(L.y + L.h, R.y, R.y + R.h);
  L = { x: x1, y: y1, w: Math.max(1, x2 - x1), h: Math.max(1, y2 - y1) };
  const nextWin = clampWindow({ x: (L.x - R.x) / R.w, y: (L.y - R.y) / R.h, w: L.w / R.w, h: L.h / R.h });
  const wc = rotatePoint({ x: L.x + L.w / 2, y: L.y + L.h / 2 }, c0, rot);
  const box = { x: round2(wc.x - L.w / 2), y: round2(wc.y - L.h / 2), w: round2(L.w), h: round2(L.h), rot };
  return { box, win: nextWin };
}

export function commitCrop(win, box0, box1, el) {
  const r4 = (n) => Math.round(n * 1e4) / 1e4;
  const patch = { crop: { x: r4(win.x), y: r4(win.y), w: r4(win.w), h: r4(win.h) } };
  if (el.fit === 'contain') patch.fit = 'cover';
  if (box1 && (box1.x !== box0.x || box1.y !== box0.y || box1.w !== box0.w || box1.h !== box0.h)) {
    patch.x = box1.x; patch.y = box1.y; patch.w = box1.w; patch.h = box1.h;
  }
  return patch;
}
