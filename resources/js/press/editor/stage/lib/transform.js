/**
 * Gesture maths: from a pointer position to element patches. Pure, slide units.
 * Every gesture has a `begin*` that does the once-per-gesture work (snapshot, neighbour boxes) and a
 * `step*` that runs on every frame and only does arithmetic, so dragging 50 elements stays cheap.
 *
 *   beginMove({slide, ids, deck, margin, userGuides?})  -> ctx
 *   stepMove(ctx, {dx, dy, zoom, shift, snap, grid})    -> {patches, delta: {dx, dy}, box, guides, labels, readout}
 *       shift locks the axis, snap false (cmd held) turns smart guides off
 *   beginResize({slide, ids, handle, deck, margin, pointer0})  -> ctx
 *   stepResize(ctx, {pointer, zoom, shift, alt, snap})  -> {patches, box, guides, labels, readout}
 *       a single picture keeps its ratio unless shift is held; everything else is free unless shift is held
 *       alt resizes from the centre
 *   beginRotate({slide, ids})                           -> ctx
 *   stepRotate(ctx, {pointer, shift})                   -> {patches, angle, readout}
 *   stepLineEnd(el, which, pointer, {shift})            -> {patches, readout}   which is 's' or 'e'
 *   lineFromEndpoints(s, e, h?)                         -> {x, y, w, h, rot}
 *   snapToGuides(box, guides, zoom, tol?)               -> {dx, dy, lines}      user guides from the rulers
 *   readoutText(kind, box)                              the little mono label near the pointer
 */
import {
  rotatedBounds, unionBounds, resizeFromHandle, rotationFromPointer, transformGroup, rotateGroup, centerOf, rotatePoint,
  minSizeFor, normAngle,
} from '../../core/geometry.js';
import { snapMove, snapResize } from '../../core/snap.js';
import { round2 } from '../../core/util.js';
import { isLine } from './hit.js';

const visibleBoxes = (slide, skip) => slide.els
  .filter((e) => !e.hide && !skip.has(e.id))
  .map((e) => ({ id: e.id, ...rotatedBounds(e) }));

export function readoutText(kind, box, extra = {}) {
  const r = (n) => Math.round(n);
  if (kind === 'move') return `X ${r(box.x)}   Y ${r(box.y)}`;
  if (kind === 'resize') return `W ${r(box.w)}   H ${r(box.h)}`;
  if (kind === 'rotate') return `${r(extra.angle ?? box.rot ?? 0)}°`;
  if (kind === 'line') return `${r(box.w)}  ${r(extra.angle ?? 0)}°`;
  return '';
}

// ---- move ---------------------------------------------------------------------------------------

export function beginMove({ slide, ids, deck, margin = 0, userGuides = null }) {
  const set = new Set(ids);
  const els = slide.els.filter((e) => set.has(e.id));
  const start = unionBounds(els.map((e) => rotatedBounds(e)));
  return {
    els, start, others: visibleBoxes(slide, set), deck, margin, userGuides,
    slide: { w: deck.w, h: deck.h, margin },
  };
}

export function stepMove(ctx, { dx, dy, zoom = 1, shift = false, snap = true, grid = 0 }) {
  if (!ctx.els.length || !ctx.start) return { patches: [], delta: { dx: 0, dy: 0 }, box: null, guides: [], labels: [], readout: '' };
  let ddx = dx;
  let ddy = dy;
  let lockX = false;
  let lockY = false;
  if (shift) {
    if (Math.abs(dx) >= Math.abs(dy)) { ddy = 0; lockY = true; } else { ddx = 0; lockX = true; }
  }
  let guides = [];
  let labels = [];
  const proposed = { x: ctx.start.x + ddx, y: ctx.start.y + ddy, w: ctx.start.w, h: ctx.start.h };
  if (snap) {
    const s = snapMove(proposed, ctx.others, ctx.slide, { zoom, grid });
    if (!lockX) ddx += s.dx;
    if (!lockY) ddy += s.dy;
    guides = s.guides;
    labels = s.labels;
    // guides on the locked axis would lie: the box did not move to meet them
    if (lockX) guides = guides.filter((g) => g.orient === 'h' || g.kind === 'spacing' && g.orient === 'v');
    if (lockY) guides = guides.filter((g) => g.orient === 'v' || g.kind === 'spacing' && g.orient === 'h');
    if (ctx.userGuides?.length) {
      const u = snapToGuides({ ...ctx.start, x: ctx.start.x + ddx, y: ctx.start.y + ddy }, ctx.userGuides, zoom);
      if (u.dx && !lockX && !s.snappedX) ddx += u.dx;
      if (u.dy && !lockY && !s.snappedY) ddy += u.dy;
      guides = guides.concat(u.lines);
    }
  }
  ddx = round2(ddx);
  ddy = round2(ddy);
  const patches = ctx.els.map((e) => ({ id: e.id, p: { x: round2(e.x + ddx), y: round2(e.y + ddy) } }));
  const box = { x: round2(ctx.start.x + ddx), y: round2(ctx.start.y + ddy), w: ctx.start.w, h: ctx.start.h };
  return { patches, delta: { dx: ddx, dy: ddy }, box, guides, labels, readout: readoutText('move', box) };
}

// ---- user guides (from the rulers) ---------------------------------------------------------------

export function snapToGuides(box, guides, zoom = 1, tol = 6) {
  const thr = tol / zoom;
  let bestX = null;
  let bestY = null;
  for (const g of guides) {
    if (g.orient === 'v') {
      for (const v of [box.x, box.x + box.w / 2, box.x + box.w]) {
        const d = g.pos - v;
        if (Math.abs(d) <= thr && (bestX === null || Math.abs(d) < Math.abs(bestX.d))) bestX = { d, g };
      }
    } else {
      for (const v of [box.y, box.y + box.h / 2, box.y + box.h]) {
        const d = g.pos - v;
        if (Math.abs(d) <= thr && (bestY === null || Math.abs(d) < Math.abs(bestY.d))) bestY = { d, g };
      }
    }
  }
  const lines = [];
  if (bestX) lines.push({ kind: 'user', orient: 'v', pos: bestX.g.pos, from: 0, to: Infinity });
  if (bestY) lines.push({ kind: 'user', orient: 'h', pos: bestY.g.pos, from: 0, to: Infinity });
  return { dx: bestX ? bestX.d : 0, dy: bestY ? bestY.d : 0, lines };
}

// ---- resize -------------------------------------------------------------------------------------

export function beginResize({ slide, ids, handle, deck, margin = 0 }) {
  const set = new Set(ids);
  const els = slide.els.filter((e) => set.has(e.id));
  const single = els.length === 1 ? els[0] : null;
  const start = single
    ? { x: single.x, y: single.y, w: single.w, h: single.h, rot: single.rot || 0 }
    : { ...unionBounds(els.map((e) => rotatedBounds(e))), rot: 0 };
  const unit = (deck.w || 1920) / 1920;
  const mins = single ? minSizeFor(single, unit) : { w: 8 * unit, h: 8 * unit };
  return {
    els, single, start, handle, deck, mins, slide: { w: deck.w, h: deck.h, margin },
    others: visibleBoxes(slide, set), lockedRatio: !!single && single.type === 'image',
  };
}

function reAspect(rect, handle, ratio, prefer) {
  // keep the rect's ratio after a snap changed one dimension, anchored on the opposite corner
  let { w, h } = rect;
  if (prefer === 'x') h = w / ratio; else w = h * ratio;
  const out = { ...rect, w, h };
  if (handle.includes('w')) out.x = rect.x + rect.w - w;
  if (handle.includes('n')) out.y = rect.y + rect.h - h;
  return out;
}

export function stepResize(ctx, { pointer, zoom = 1, shift = false, alt = false, snap = true }) {
  const ratioLocked = ctx.lockedRatio ? !shift : shift;
  const unit = (ctx.deck.w || 1920) / 1920;
  let rect = resizeFromHandle(ctx.start, ctx.handle, pointer, {
    aspect: ratioLocked, center: alt, minW: ctx.mins.w, minH: ctx.mins.h,
  });
  let guides = [];
  const rot = ctx.start.rot || 0;
  if (snap && !rot && !alt) {
    const s = snapResize(rect, ctx.handle, ctx.others, ctx.slide, { zoom });
    if (s.snappedX || s.snappedY) {
      let r = s.rect;
      if (ratioLocked && rect.w > 0 && rect.h > 0) {
        const prefer = s.snappedX && (ctx.handle.length === 2 || ctx.handle === 'e' || ctx.handle === 'w') ? 'x' : 'y';
        r = reAspect(r, ctx.handle, rect.w / rect.h, prefer);
        // when the ratio is locked the snapped guide only holds on the preferred axis
        guides = s.guides.filter((g) => (prefer === 'x' ? g.orient === 'v' : g.orient === 'h'));
      } else guides = s.guides;
      rect = { ...r, rot };
    }
  }
  rect = { x: round2(rect.x), y: round2(rect.y), w: round2(rect.w), h: round2(rect.h), rot };
  let patches;
  if (ctx.single) {
    patches = [{ id: ctx.single.id, p: { x: rect.x, y: rect.y, w: rect.w, h: rect.h } }];
  } else {
    patches = transformGroup(ctx.els, ctx.start, rect);
  }
  return {
    patches, box: rect, guides, labels: [], readout: readoutText('resize', rect), unit,
  };
}

// ---- rotate -------------------------------------------------------------------------------------

export function beginRotate({ slide, ids, pointer0 }) {
  const set = new Set(ids);
  const els = slide.els.filter((e) => set.has(e.id));
  const single = els.length === 1 ? els[0] : null;
  const b = single ? { x: single.x, y: single.y, w: single.w, h: single.h, rot: single.rot || 0 }
    : { ...unionBounds(els.map((e) => rotatedBounds(e))), rot: 0 };
  return { els, single, start: b, center: centerOf(b), pointer0 };
}

const MAGNETS = [0, 45, 90, 135, 180, 225, 270, 315, 360];

export function magnetAngle(deg, tol = 2) {
  const a = normAngle(deg);
  for (const m of MAGNETS) if (Math.abs(a - m) <= tol) return m % 360;
  return a;
}

export function stepRotate(ctx, { pointer, shift = false }) {
  const rot0 = ctx.start.rot || 0;
  let rot = rotationFromPointer(ctx.center, ctx.pointer0, pointer, rot0, shift);
  if (!shift) rot = round2(magnetAngle(rot));
  let patches;
  if (ctx.single) patches = [{ id: ctx.single.id, p: { rot } }];
  else patches = rotateGroup(ctx.els, rot - rot0, ctx.center);
  const angle = ctx.single ? rot : round2(normAngle(rot - rot0));
  return { patches, angle, rot, readout: readoutText('rotate', ctx.start, { angle }) };
}

// ---- lines --------------------------------------------------------------------------------------

export function lineFromEndpoints(s, e, h = 0) {
  const w = Math.hypot(e.x - s.x, e.y - s.y);
  const rot = normAngle((Math.atan2(e.y - s.y, e.x - s.x) * 180) / Math.PI);
  const mid = { x: (s.x + e.x) / 2, y: (s.y + e.y) / 2 };
  return { x: round2(mid.x - w / 2), y: round2(mid.y - h / 2), w: round2(w), h, rot: round2(rot) };
}

export function stepLineEnd(el, which, pointer, { shift = false } = {}) {
  const c = centerOf(el);
  const y = el.y + el.h / 2;
  const S = rotatePoint({ x: el.x, y }, c, el.rot || 0);
  const E = rotatePoint({ x: el.x + el.w, y }, c, el.rot || 0);
  const fixed = which === 's' ? E : S;
  let p = { x: pointer.x, y: pointer.y };
  if (shift) {
    const ang = Math.atan2(p.y - fixed.y, p.x - fixed.x);
    const step = Math.PI / 12; // 15 degrees
    const snapped = Math.round(ang / step) * step;
    const d = Math.hypot(p.x - fixed.x, p.y - fixed.y);
    p = { x: fixed.x + Math.cos(snapped) * d, y: fixed.y + Math.sin(snapped) * d };
  }
  const line = which === 's' ? lineFromEndpoints(p, fixed, el.h) : lineFromEndpoints(fixed, p, el.h);
  return {
    patches: [{ id: el.id, p: { x: line.x, y: line.y, w: line.w, rot: line.rot } }],
    box: line,
    readout: readoutText('line', line, { angle: line.rot }),
  };
}

export { isLine };
