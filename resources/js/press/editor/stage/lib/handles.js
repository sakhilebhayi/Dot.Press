/**
 * Selection handles in SCREEN pixels. Pure.
 *
 *   screenBox(box, origin, zoom)                   slide box -> screen box (same rotation)
 *   handleLayout(box, {coarse})                    {corners, handles, rotate, rot, box} for a screen box
 *        handles: { nw: {x, y}, ... } only the handles worth drawing at this size
 *   hitHandle(layout, pt, {coarse})                {kind: 'resize', handle} | {kind: 'rotate'} | null
 *   cursorFor(handle, rot)                         css cursor name for a resize handle on a rotated box
 *   lineEnds(el)                                   {s, e} endpoints of a line element in slide units
 *   hitLineEnd(ends, pt, origin, zoom, tol)        's' | 'e' | null
 *   selectionFrame(els)                            {box, single, line, el?} the box the handles belong to (slide units)
 *   HANDLE_VISUAL, HANDLE_HIT, ROTATE_OFFSET
 */
import {
  handlePositions, rotatePoint, centerOf, normAngle, rotatedBounds, unionBounds,
} from '../../core/geometry.js';
import { isLine } from './hit.js';

export const HANDLE_VISUAL = 10;
export const HANDLE_HIT = 9; // radius around the handle centre, mouse
export const HANDLE_HIT_COARSE = 18; // radius for touch
export const ROTATE_OFFSET = 26;

export function screenBox(box, origin, zoom) {
  return {
    x: origin.x + box.x * zoom, y: origin.y + box.y * zoom, w: box.w * zoom, h: box.h * zoom, rot: box.rot || 0,
  };
}

export function handleLayout(sb, { coarse = false } = {}) {
  const all = handlePositions(sb);
  const handles = {};
  const small = Math.min(sb.w, sb.h);
  const tiny = sb.w < 22 && sb.h < 22;
  for (const h of ['nw', 'ne', 'se', 'sw']) if (!tiny || h === 'se') handles[h] = all[h];
  // edge handles only when the edge is long enough to hold one next to its corner handles
  const gap = coarse ? 44 : 30;
  if (sb.w >= gap) { handles.n = all.n; handles.s = all.s; }
  if (sb.h >= gap) { handles.e = all.e; handles.w = all.w; }
  const c = centerOf(sb);
  const up = rotatePoint({ x: c.x, y: c.y - sb.h / 2 - ROTATE_OFFSET }, c, sb.rot || 0);
  return { corners: [all.nw, all.ne, all.se, all.sw], handles, rotate: up, rot: sb.rot || 0, box: sb, small };
}

export function hitHandle(layout, pt, { coarse = false } = {}) {
  const r = coarse ? HANDLE_HIT_COARSE : HANDLE_HIT;
  const d2 = (a) => (a.x - pt.x) ** 2 + (a.y - pt.y) ** 2;
  // corners first: they win where edge handles crowd them
  for (const h of ['nw', 'ne', 'se', 'sw']) {
    const p = layout.handles[h];
    if (p && d2(p) <= r * r) return { kind: 'resize', handle: h };
  }
  if (d2(layout.rotate) <= (r + 1) * (r + 1)) return { kind: 'rotate' };
  for (const h of ['n', 'e', 's', 'w']) {
    const p = layout.handles[h];
    if (p && d2(p) <= r * r) return { kind: 'resize', handle: h };
  }
  return null;
}

const BASE_ANGLE = { n: 0, ne: 45, e: 90, se: 135, s: 180, sw: 225, w: 270, nw: 315 };
const CURSORS = ['ns-resize', 'nesw-resize', 'ew-resize', 'nwse-resize'];

export function cursorFor(handle, rot = 0) {
  const a = normAngle(BASE_ANGLE[handle] + rot);
  const step = Math.round(a / 45) % 8; // 0..7
  return CURSORS[step % 4];
}

export function lineEnds(el) {
  const c = centerOf(el);
  const y = el.y + el.h / 2;
  return {
    s: rotatePoint({ x: el.x, y }, c, el.rot || 0),
    e: rotatePoint({ x: el.x + el.w, y }, c, el.rot || 0),
  };
}

export function hitLineEnd(ends, pt, origin, zoom, tol = HANDLE_HIT) {
  for (const k of ['e', 's']) {
    const sx = origin.x + ends[k].x * zoom;
    const sy = origin.y + ends[k].y * zoom;
    if ((sx - pt.x) ** 2 + (sy - pt.y) ** 2 <= tol * tol) return k;
  }
  return null;
}

/** One element: its own rotated box (a line gets endpoint handles instead). Several: the upright union of their rotated bounds. */
export function selectionFrame(els) {
  if (!els || !els.length) return null;
  if (els.length === 1) {
    const e = els[0];
    return {
      box: { x: e.x, y: e.y, w: e.w, h: e.h, rot: e.rot || 0 }, single: true, line: isLine(e), el: e,
    };
  }
  const u = unionBounds(els.map((e) => rotatedBounds(e)));
  return { box: { ...u, rot: 0 }, single: false, line: false, el: null };
}
