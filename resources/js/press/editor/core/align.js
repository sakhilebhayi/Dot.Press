/**
 * Align, distribute, tidy and match size. Pure: elements in, `el.set` patches out
 * (`[{id, p: {x?, y?, w?, h?}}]`, only elements that actually change).
 *
 * Elements that share a `group` id are one unit: they move together and are measured as one box.
 * Rotated elements are measured by their rotated bounding box (what the eye sees).
 *
 *   alignPatches(els, mode, opts?)
 *       mode: 'left' | 'center' | 'right' | 'top' | 'middle' | 'bottom'
 *       opts: { to: 'selection' (default) | 'slide', slide: {w, h}, margin: number }
 *             with to 'slide' and a margin, left/right/top/bottom align to the margin lines
 *   distributePatches(els, axis, opts?)
 *       axis: 'h' | 'v'; opts: { mode: 'gaps' (default) | 'centers', to: 'selection' | 'slide', slide, margin }
 *       selection: the outermost units stay, the rest are spread. Needs 3 or more units
 *       slide: the units span the slide (or the margin area): first flush with the start, last with the end
 *   tidyPatches(els, opts?)
 *       opts: { tolerance: 8 }  pulls near-miss edges and centres into line (left, right, centre per axis)
 *   matchSizePatches(els, what, refId?)
 *       what: 'w' | 'h' | 'both'. The reference is refId or the first element
 */
import { rotatedBounds, unionBounds } from './geometry.js';
import { round2 } from './util.js';

function unitsOf(els) {
  const byGroup = new Map();
  const units = [];
  for (const el of els) {
    if (el.group) {
      if (!byGroup.has(el.group)) { const u = { members: [] }; byGroup.set(el.group, u); units.push(u); }
      byGroup.get(el.group).members.push(el);
    } else units.push({ members: [el] });
  }
  for (const u of units) u.b = unionBounds(u.members.map((m) => rotatedBounds(m)));
  return units;
}

function shift(units, deltas) {
  const out = [];
  units.forEach((u, i) => {
    const [dx, dy] = deltas[i];
    if (Math.abs(dx) < 0.005 && Math.abs(dy) < 0.005) return;
    for (const m of u.members) {
      const p = {};
      if (Math.abs(dx) >= 0.005) p.x = round2(m.x + dx);
      if (Math.abs(dy) >= 0.005) p.y = round2(m.y + dy);
      out.push({ id: m.id, p });
    }
  });
  return out;
}

export function alignPatches(els, mode, opts = {}) {
  const units = unitsOf(els);
  if (!units.length) return [];
  let frame;
  if (opts.to === 'slide' && opts.slide) {
    const m = opts.margin || 0;
    frame = { x: m, y: m, w: opts.slide.w - 2 * m, h: opts.slide.h - 2 * m };
    frame.cx = opts.slide.w / 2;
    frame.cy = opts.slide.h / 2;
  } else {
    if (units.length < 2) return [];
    frame = unionBounds(units.map((u) => u.b));
    frame.cx = frame.x + frame.w / 2;
    frame.cy = frame.y + frame.h / 2;
  }
  const deltas = units.map((u) => {
    const b = u.b;
    switch (mode) {
      case 'left': return [frame.x - b.x, 0];
      case 'right': return [frame.x + frame.w - (b.x + b.w), 0];
      case 'center': return [frame.cx - (b.x + b.w / 2), 0];
      case 'top': return [0, frame.y - b.y];
      case 'bottom': return [0, frame.y + frame.h - (b.y + b.h)];
      case 'middle': return [0, frame.cy - (b.y + b.h / 2)];
      default: return [0, 0];
    }
  });
  return shift(units, deltas);
}

export function distributePatches(els, axis, opts = {}) {
  const units = unitsOf(els);
  const h = axis === 'h';
  const pos = (b) => (h ? b.x : b.y);
  const size = (b) => (h ? b.w : b.h);
  const sorted = units.map((u, i) => ({ u, i })).sort((a, b) => pos(a.u.b) - pos(b.u.b));
  const toSlide = opts.to === 'slide' && opts.slide;
  if (!toSlide && sorted.length < 3) return [];
  if (toSlide && sorted.length < 2) return [];
  const mode = opts.mode || 'gaps';
  const m = toSlide ? opts.margin || 0 : 0;
  const start = toSlide ? m : pos(sorted[0].u.b);
  const end = toSlide ? (h ? opts.slide.w : opts.slide.h) - m : pos(sorted[sorted.length - 1].u.b) + size(sorted[sorted.length - 1].u.b);
  const deltas = units.map(() => [0, 0]);
  if (mode === 'centers') {
    const first = toSlide ? start + size(sorted[0].u.b) / 2 : pos(sorted[0].u.b) + size(sorted[0].u.b) / 2;
    const lastU = sorted[sorted.length - 1].u.b;
    const last = toSlide ? end - size(lastU) / 2 : pos(lastU) + size(lastU) / 2;
    const step = (last - first) / (sorted.length - 1);
    sorted.forEach(({ u, i }, k) => {
      const d = first + step * k - (pos(u.b) + size(u.b) / 2);
      deltas[i] = h ? [d, 0] : [0, d];
    });
  } else {
    const total = sorted.reduce((a, s) => a + size(s.u.b), 0);
    const gap = (end - start - total) / (sorted.length - 1);
    let cursor = start;
    sorted.forEach(({ u, i }) => {
      const d = cursor - pos(u.b);
      deltas[i] = h ? [d, 0] : [0, d];
      cursor += size(u.b) + gap;
    });
  }
  return shift(units, deltas);
}

export function tidyPatches(els, opts = {}) {
  const tol = opts.tolerance ?? 8;
  const units = unitsOf(els);
  if (units.length < 2) return [];
  const total = units.map(() => [0, 0]);
  const moved = [new Set(), new Set()]; // per axis: units already placed
  const cur = (u, i) => ({
    x: u.b.x + total[i][0], y: u.b.y + total[i][1], w: u.b.w, h: u.b.h,
  });
  for (const axis of [0, 1]) {
    for (const anchor of ['start', 'end', 'center']) {
      const val = (u, i) => {
        const b = cur(u, i);
        const p = axis === 0 ? b.x : b.y;
        const s = axis === 0 ? b.w : b.h;
        return anchor === 'start' ? p : anchor === 'end' ? p + s : p + s / 2;
      };
      const list = units.map((u, i) => ({ i, v: val(u, i) })).sort((a, b) => a.v - b.v);
      let cluster = [list[0]];
      const flush = () => {
        if (cluster.length > 1 && cluster[cluster.length - 1].v - cluster[0].v > 0.005) {
          const fixed = cluster.find((c) => moved[axis].has(c.i));
          const vs = cluster.map((c) => c.v);
          const target = fixed ? fixed.v : vs[Math.floor(vs.length / 2)];
          for (const c of cluster) {
            if (moved[axis].has(c.i) && c !== fixed) continue;
            const d = target - c.v;
            if (Math.abs(d) > 0.005 && !moved[axis].has(c.i)) total[c.i][axis] += d;
            moved[axis].add(c.i);
          }
        }
      };
      for (let k = 1; k < list.length; k++) {
        if (list[k].v - cluster[cluster.length - 1].v <= tol) cluster.push(list[k]);
        else { flush(); cluster = [list[k]]; }
      }
      flush();
    }
  }
  return shift(units, total);
}

export function matchSizePatches(els, what = 'both', refId) {
  if (els.length < 2) return [];
  const ref = els.find((e) => e.id === refId) || els[0];
  const out = [];
  for (const el of els) {
    if (el === ref) continue;
    const p = {};
    if ((what === 'w' || what === 'both') && el.w !== ref.w) p.w = ref.w;
    if ((what === 'h' || what === 'both') && el.h !== ref.h) p.h = ref.h;
    if (Object.keys(p).length) out.push({ id: el.id, p });
  }
  return out;
}
