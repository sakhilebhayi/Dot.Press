/**
 * Smart guides and snapping. Pure: boxes in, snapped box and drawing instructions out.
 * All boxes are axis aligned `{x, y, w, h}` in slide units (pass `rotatedBounds` of rotated elements).
 *
 * snapMove(box, others, slide, opts) -> result
 *   box     the box being dragged, at its proposed (unsnapped) position. For a selection pass the union
 *   others  boxes of the other elements `[{id?, x, y, w, h}]`
 *   slide   `{w, h, margin?}` slide size and optional safe margin (see margins.js)
 *   opts    zoom (screen px per unit, default 1), thresholdPx (default 6), grid (size in units, 0 = off),
 *           guides: {slide, margins, elements, centers, spacing} booleans (all true by default)
 *
 *   result  { x, y, dx, dy, snappedX, snappedY, guides, labels }
 *     |dx| and |dy| are never larger than thresholdPx / zoom: the snap is magnetic, not a jump.
 *     guides  [{kind, orient:'v'|'h', pos, from, to}]  a line at x=pos (v) or y=pos (h) between from and to
 *             [{kind:'spacing', orient:'h'|'v', from, to, at, value}]  an equal-gap segment
 *             kinds: slide, slide-center, margin, edge, center, grid, spacing
 *     labels  [{x, y, text, value}]  distance labels for spacing segments
 *
 * snapResize(rect, handle, others, slide, opts) -> {rect, guides, snappedX, snappedY}
 *   snaps only the edges that handle moves (e moves the right edge, nw moves left and top, ...)
 * measure(box, others, slide) -> [{orient, from, to, at, value, text}]   gaps to the nearest neighbour or
 *   slide edge in each direction (shown while a modifier key is held)
 */
import { round2 } from './util.js';

const AX = {
  x: { p: 'x', s: 'w', q: 'y', t: 'h' },
  y: { p: 'y', s: 'h', q: 'x', t: 'w' },
};
const PRIO = { spacing: 0, edge: 1, center: 1, margin: 2, 'slide-center': 3, slide: 3, grid: 4 };
const EPS = 1e-6;

function candidates(axis, box, others, slide, o) {
  const { p, s, q, t } = AX[axis];
  const thr = o.thr;
  const size = slide[s];
  const pos = { start: box[p], center: box[p] + box[s] / 2, end: box[p] + box[s] };
  const anchors = o.anchors || ['start', 'center', 'end'];
  const targets = [];
  const g = o.guides;
  if (g.slide) {
    targets.push({ v: 0, kind: 'slide' }, { v: size / 2, kind: 'slide-center' }, { v: size, kind: 'slide' });
  }
  if (g.margins && slide.margin != null) {
    targets.push({ v: slide.margin, kind: 'margin' }, { v: size - slide.margin, kind: 'margin' });
  }
  if (g.elements || g.centers) {
    for (const r of others) {
      if (g.elements) targets.push({ v: r[p], kind: 'edge', ref: r }, { v: r[p] + r[s], kind: 'edge', ref: r });
      if (g.centers) targets.push({ v: r[p] + r[s] / 2, kind: 'center', ref: r });
    }
  }
  const out = [];
  for (const a of anchors) {
    for (const tg of targets) {
      // centre only matches centres, edges match edges: a left edge on a centre line is allowed too
      const d = tg.v - pos[a];
      if (Math.abs(d) <= thr + EPS) out.push({ d, kind: tg.kind, target: tg, anchor: a });
    }
    if (o.grid > 0 && a !== 'center') {
      const gv = Math.round(pos[a] / o.grid) * o.grid;
      const d = gv - pos[a];
      if (Math.abs(d) <= thr + EPS) out.push({ d, kind: 'grid', target: { v: gv, kind: 'grid' }, anchor: a });
    }
  }
  if (g.spacing && !o.noSpacing) out.push(...spacingCandidates(axis, box, others, thr));
  return { list: out, pos, q, t, p, s };
}

function spacingCandidates(axis, box, others, thr) {
  const { p, s, q, t } = AX[axis];
  const row = others
    .filter((r) => r[q] < box[q] + box[t] && r[q] + r[t] > box[q])
    .sort((a, b) => a[p] - b[p]);
  const out = [];
  const mid = box[p] + box[s] / 2;
  const left = row.filter((r) => r[p] + r[s] / 2 <= mid);
  const right = row.filter((r) => r[p] + r[s] / 2 > mid);
  const L = left.length ? left.reduce((a, b) => (a[p] + a[s] > b[p] + b[s] ? a : b)) : null;
  const Rr = right.length ? right.reduce((a, b) => (a[p] < b[p] ? a : b)) : null;
  if (L && Rr) {
    const gap = Rr[p] - (L[p] + L[s]) - box[s];
    if (gap >= 0) {
      const d = (L[p] + L[s] + Rr[p] - box[s]) / 2 - box[p];
      if (Math.abs(d) <= thr + EPS) out.push({ d, kind: 'spacing', anchor: 'start', segs: [[L[p] + L[s], box[p] + d], [box[p] + d + box[s], Rr[p]]], value: gap / 2 });
    }
  }
  // continue an existing rhythm: gaps between consecutive neighbours repeat
  for (let i = 0; i + 1 < row.length; i++) {
    const P = row[i];
    const Q = row[i + 1];
    const gap = Q[p] - (P[p] + P[s]);
    if (gap < 1) continue;
    if (i + 2 === row.length && mid > Q[p] + Q[s] / 2) {
      const target = Q[p] + Q[s] + gap;
      const d = target - box[p];
      if (Math.abs(d) <= thr + EPS) out.push({ d, kind: 'spacing', anchor: 'start', segs: [[P[p] + P[s], Q[p]], [Q[p] + Q[s], target]], value: gap });
    }
    if (i === 0 && mid < P[p] + P[s] / 2) {
      const target = P[p] - gap - box[s];
      const d = target - box[p];
      if (Math.abs(d) <= thr + EPS) out.push({ d, kind: 'spacing', anchor: 'start', segs: [[target + box[s], P[p]], [P[p] + P[s], Q[p]]], value: gap });
    }
  }
  return out;
}

function pickBest(list) {
  let best = null;
  for (const c of list) {
    if (!best) { best = c; continue; }
    const da = Math.abs(c.d);
    const db = Math.abs(best.d);
    if (da < db - 0.01 || (Math.abs(da - db) <= 0.01 && PRIO[c.kind] < PRIO[best.kind])) best = c;
  }
  return best;
}

function linesFor(axis, list, d, finalBox, slide, o) {
  const { p, s, q, t } = AX[axis];
  const pos = { start: finalBox[p], center: finalBox[p] + finalBox[s] / 2, end: finalBox[p] + finalBox[s] };
  const lines = [];
  const seen = new Set();
  const tol = Math.max(0.25 / o.zoom, 0.01);
  for (const c of list) {
    if (c.kind === 'spacing') continue;
    // a guide is drawn for every target the snapped box now sits on
    if (Math.abs(c.target.v - pos[c.anchor]) > tol) continue;
    const key = `${c.kind}:${c.target.v}`;
    let from; let to;
    if (c.target.ref) {
      from = Math.min(finalBox[q], c.target.ref[q]);
      to = Math.max(finalBox[q] + finalBox[t], c.target.ref[q] + c.target.ref[t]);
    } else {
      from = 0;
      to = slide[t];
    }
    const full = `${key}`;
    if (seen.has(full) && !c.target.ref) continue;
    seen.add(full);
    lines.push({ kind: c.kind, orient: axis === 'x' ? 'v' : 'h', pos: round2(c.target.v), from: round2(from), to: round2(to) });
  }
  return lines;
}

export function snapMove(box, others, slide, opts = {}) {
  const zoom = opts.zoom > 0 ? opts.zoom : 1;
  const thr = (opts.thresholdPx ?? 6) / zoom;
  const g = { slide: true, margins: true, elements: true, centers: true, spacing: true, ...(opts.guides || {}) };
  const o = { thr, zoom, guides: g, grid: opts.grid || 0 };
  const cx = candidates('x', box, others, slide, o);
  const cy = candidates('y', box, others, slide, o);
  const bx = pickBest(cx.list);
  const by = pickBest(cy.list);
  const dx = bx ? bx.d : 0;
  const dy = by ? by.d : 0;
  const final = { x: box.x + dx, y: box.y + dy, w: box.w, h: box.h };
  const guides = [
    ...linesFor('x', cx.list, dx, final, slide, o),
    ...linesFor('y', cy.list, dy, final, slide, o),
  ];
  const labels = [];
  for (const [axis, best] of [['x', bx], ['y', by]]) {
    if (!best || best.kind !== 'spacing') continue;
    const at = axis === 'x' ? final.y + final.h / 2 : final.x + final.w / 2;
    for (const [from, to] of best.segs) {
      guides.push({ kind: 'spacing', orient: axis === 'x' ? 'h' : 'v', from: round2(from), to: round2(to), at: round2(at), value: round2(best.value) });
      const m = (from + to) / 2;
      labels.push({ x: round2(axis === 'x' ? m : at), y: round2(axis === 'x' ? at : m), text: String(Math.round(best.value)), value: round2(best.value) });
    }
  }
  return {
    x: round2(final.x), y: round2(final.y), dx: round2(dx), dy: round2(dy),
    snappedX: !!bx, snappedY: !!by, guides, labels,
  };
}

const EDGES = {
  nw: ['start', 'start'], n: [null, 'start'], ne: ['end', 'start'], e: ['end', null],
  se: ['end', 'end'], s: [null, 'end'], sw: ['start', 'end'], w: ['start', null],
};

export function snapResize(rect, handle, others, slide, opts = {}) {
  const zoom = opts.zoom > 0 ? opts.zoom : 1;
  const thr = (opts.thresholdPx ?? 6) / zoom;
  const g = { slide: true, margins: true, elements: true, centers: true, spacing: false, ...(opts.guides || {}) };
  const out = { ...rect };
  const guides = [];
  const flags = {};
  const [ex, ey] = EDGES[handle];
  for (const [axis, edge] of [['x', ex], ['y', ey]]) {
    if (!edge) continue;
    const o = { thr, zoom, guides: g, grid: opts.grid || 0, anchors: [edge], noSpacing: true };
    const c = candidates(axis, rect, others, slide, o);
    const best = pickBest(c.list);
    if (!best) continue;
    const { p, s } = AX[axis];
    if (edge === 'end') out[s] = rect[s] + best.d;
    else { out[p] = rect[p] + best.d; out[s] = rect[s] - best.d; }
    if (out[s] < 0) { out[p] = rect[p]; out[s] = rect[s]; continue; }
    flags[axis] = true;
    guides.push(...linesFor(axis, c.list, best.d, out, slide, o));
  }
  return {
    rect: { x: round2(out.x), y: round2(out.y), w: round2(out.w), h: round2(out.h) },
    guides, snappedX: !!flags.x, snappedY: !!flags.y,
  };
}

export function measure(box, others, slide) {
  const res = [];
  const mk = (orient, from, to, at) => {
    const value = round2(to - from);
    return { orient, from: round2(from), to: round2(to), at: round2(at), value, text: String(Math.round(value)) };
  };
  const midY = box.y + box.h / 2;
  const midX = box.x + box.w / 2;
  const overlapY = (r) => r.y < box.y + box.h && r.y + r.h > box.y;
  const overlapX = (r) => r.x < box.x + box.w && r.x + r.w > box.x;
  const lefts = others.filter((r) => overlapY(r) && r.x + r.w <= box.x).map((r) => r.x + r.w);
  const rights = others.filter((r) => overlapY(r) && r.x >= box.x + box.w).map((r) => r.x);
  const ups = others.filter((r) => overlapX(r) && r.y + r.h <= box.y).map((r) => r.y + r.h);
  const downs = others.filter((r) => overlapX(r) && r.y >= box.y + box.h).map((r) => r.y);
  res.push(mk('h', Math.max(0, ...lefts), box.x, midY));
  res.push(mk('h', box.x + box.w, Math.min(slide.w, ...rights), midY));
  res.push(mk('v', Math.max(0, ...ups), box.y, midX));
  res.push(mk('v', box.y + box.h, Math.min(slide.h, ...downs), midX));
  return res.filter((m) => m.value > 0);
}
