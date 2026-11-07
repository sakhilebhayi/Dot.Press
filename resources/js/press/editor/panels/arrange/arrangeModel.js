/**
 * Pure logic of the Arrange panel's geometry fields and layer commands (no Vue; node tested).
 *
 *   selectionFrame(els)                   {x, y, w, h, single}: the element itself, or the rotated bounds of the whole selection
 *   framePatches(els, key, value, opts)   [{id, p}] for X, Y, W, H or rotation typed into a field
 *       key: 'x' | 'y' | 'w' | 'h' | 'rot'   opts: { lockRatio, unit }
 *       one element: x and y move it; w and h resize it (a rotated box keeps its centre, an upright one keeps its top left);
 *       with the ratio locked the other side follows. Several elements: the field edits the box around all of them
 *       (move, or scale the group); rotation sets the same angle on each element.
 *   unitCount(els)                        groups count as one unit (align and distribute need 2 and 3 units)
 *   canAlign(els, to), canDistribute(els, to)
 *   canMatchSize(els)
 *   isLine(el)
 *   groupState(els)                       'none' | 'grouped' | 'mixed': whether the selection is all in groups
 */
import {
  centerOf, minSizeFor, moveBy, rotatedBounds, transformGroup, unionBounds,
} from '../../core/geometry.js';
import { round2 } from '../../core/util.js';

export const isLine = (el) => el?.type === 'shape' && (el.kind === 'line' || el.kind === 'arrow');

export function selectionFrame(els) {
  if (!els.length) return null;
  if (els.length === 1) {
    const e = els[0];
    return { x: e.x, y: e.y, w: e.w, h: e.h, single: true };
  }
  const u = unionBounds(els.map((e) => rotatedBounds(e)));
  return { x: round2(u.x), y: round2(u.y), w: round2(u.w), h: round2(u.h), single: false };
}

const upright = (el) => ((el.rot || 0) % 360 + 360) % 360 === 0;

function resizeOne(el, key, value, lockRatio, unit) {
  const min = minSizeFor(el, unit);
  const line = isLine(el);
  let w = el.w;
  let h = el.h;
  if (key === 'w') {
    w = Math.max(min.w, value);
    if (lockRatio && el.w > 0 && el.h > 0 && !line) h = Math.max(min.h, round2((el.h / el.w) * w));
  } else {
    if (line) return null; // a line's thickness is its stroke width, not its height
    h = Math.max(min.h, value);
    if (lockRatio && el.w > 0 && el.h > 0) w = Math.max(min.w, round2((el.w / el.h) * h));
  }
  w = round2(w);
  h = round2(h);
  const p = { w, h };
  if (!upright(el)) {
    const c = centerOf(el);
    p.x = round2(c.x - w / 2);
    p.y = round2(c.y - h / 2);
  }
  return p;
}

export function framePatches(els, key, value, { lockRatio = false, unit = 1 } = {}) {
  const v = Number(value);
  if (!els.length || !Number.isFinite(v)) return [];
  const out = [];
  if (key === 'rot') {
    const rot = round2(((v % 360) + 360) % 360);
    for (const e of els) if ((e.rot || 0) !== rot) out.push({ id: e.id, p: { rot } });
    return out;
  }
  if (els.length === 1) {
    const e = els[0];
    let p;
    if (key === 'x' || key === 'y') p = { [key]: round2(v) };
    else p = resizeOne(e, key, v, lockRatio, unit);
    if (p) {
      const changed = Object.fromEntries(Object.entries(p).filter(([k, val]) => e[k] !== val));
      if (Object.keys(changed).length) out.push({ id: e.id, p: changed });
    }
    return out;
  }
  const from = selectionFrame(els);
  if (key === 'x' || key === 'y') {
    const moved = key === 'x' ? moveBy(els, v - from.x, 0) : moveBy(els, 0, v - from.y);
    return moved.map((m) => ({ id: m.id, p: { [key]: m.p[key] } })).filter((m) => m.p[key] !== els.find((e) => e.id === m.id)[key]);
  }
  const to = { ...from };
  if (key === 'w') {
    to.w = Math.max(1, v);
    if (lockRatio && from.w > 0) to.h = round2((from.h / from.w) * to.w);
  } else {
    to.h = Math.max(1, v);
    if (lockRatio && from.h > 0) to.w = round2((from.w / from.h) * to.h);
  }
  return transformGroup(els, from, to).map((p) => {
    const e = els.find((x) => x.id === p.id);
    const changed = Object.fromEntries(Object.entries(p.p).filter(([k, val]) => e[k] !== val));
    return { id: p.id, p: changed };
  }).filter((p) => Object.keys(p.p).length);
}

export function unitCount(els) {
  const groups = new Set();
  let n = 0;
  for (const e of els) {
    if (e.group) { if (!groups.has(e.group)) { groups.add(e.group); n += 1; } } else n += 1;
  }
  return n;
}

export const canAlign = (els, to) => (to === 'slide' ? els.length >= 1 : unitCount(els) >= 2);
export const canDistribute = (els, to) => unitCount(els) >= (to === 'slide' ? 2 : 3);
export const canMatchSize = (els) => unitCount(els) >= 2;

export function groupState(els) {
  if (!els.length) return 'none';
  const n = els.filter((e) => e.group).length;
  return n === 0 ? 'none' : n === els.length ? 'grouped' : 'mixed';
}
