/**
 * Rotation aware geometry for slide elements. Pure functions over plain `{x, y, w, h, rot}` boxes.
 *
 * Conventions (spec section 1): slide units, origin top left, y grows down, `rot` in degrees
 * clockwise around the element centre.
 *
 * Public API
 *   toRad(deg), normAngle(deg)               angle helpers (normAngle gives [0, 360))
 *   centerOf(box)                            {x, y} centre
 *   rotatePoint(p, c, deg)                   rotate point p around c, clockwise
 *   corners(box)                             [tl, tr, br, bl] after rotation
 *   rotatedBounds(box)                       axis aligned box that contains the rotated element
 *   unionBounds(rects)                       axis aligned union, null for an empty list
 *   pointInBox(box, p, pad?)                 hit test a point against the rotated box
 *   boxIntersectsRect(box, rect)             does the rotated box touch the axis aligned rect (SAT)
 *   boxInsideRect(box, rect)                 is the rotated box fully inside the rect
 *   HANDLES                                  ['nw','n','ne','e','se','s','sw','w']
 *   handlePositions(box)                     world positions of the 8 handles
 *   resizeFromHandle(start, handle, pointer, opts)   new {x,y,w,h,rot} while dragging a handle
 *       opts: { aspect, center, minW, minH }  aspect = keep ratio, center = resize from centre (alt)
 *   rotationFromPointer(center, startPointer, pointer, rot0, snap)   new rot, snap = 15 degree steps
 *   snapAngle(deg, step = 15)
 *   transformGroup(items, from, to)          patches that map the bounds `from` onto `to`
 *   moveBy(items, dx, dy)                    patches that translate
 *   rotateGroup(items, deltaDeg, center)     patches that rotate a selection around a point
 *   flipPatch(el, axis)                      patch that mirrors one element, or null when not flippable
 *   flipGroup(items, axis, bounds)           patches that mirror a selection about its bounds
 *   growVertically(el, contentHeight)        patch {x, y, h} so a text box grows downward, top edge fixed
 *   minSizeFor(el, unit)                     minimum {w, h} for an element type
 *
 * A "patch" is `{ id, p: {key: value} }`, ready for an `el.set` op.
 */
import { round2, clamp } from './util.js';

export const HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
const DIR = {
  nw: [-1, -1], n: [0, -1], ne: [1, -1], e: [1, 0], se: [1, 1], s: [0, 1], sw: [-1, 1], w: [-1, 0],
};

export const toRad = (deg) => (deg * Math.PI) / 180;
export function normAngle(deg) {
  const m = deg % 360;
  return m < 0 ? m + 360 : m + 0;
}

export const centerOf = (b) => ({ x: b.x + b.w / 2, y: b.y + b.h / 2 });

export function rotatePoint(p, c, deg) {
  if (!deg) return { x: p.x, y: p.y };
  const r = toRad(deg);
  const cos = Math.cos(r);
  const sin = Math.sin(r);
  const dx = p.x - c.x;
  const dy = p.y - c.y;
  return { x: c.x + dx * cos - dy * sin, y: c.y + dx * sin + dy * cos };
}

export function corners(b) {
  const c = centerOf(b);
  const rot = b.rot || 0;
  return [
    { x: b.x, y: b.y },
    { x: b.x + b.w, y: b.y },
    { x: b.x + b.w, y: b.y + b.h },
    { x: b.x, y: b.y + b.h },
  ].map((p) => rotatePoint(p, c, rot));
}

export function rotatedBounds(b) {
  if (!b.rot || b.rot % 360 === 0) return { x: b.x, y: b.y, w: b.w, h: b.h };
  const pts = corners(b);
  const xs = pts.map((p) => p.x);
  const ys = pts.map((p) => p.y);
  const x = Math.min(...xs);
  const y = Math.min(...ys);
  return { x, y, w: Math.max(...xs) - x, h: Math.max(...ys) - y };
}

export function unionBounds(rects) {
  if (!rects || !rects.length) return null;
  let x1 = Infinity;
  let y1 = Infinity;
  let x2 = -Infinity;
  let y2 = -Infinity;
  for (const r of rects) {
    x1 = Math.min(x1, r.x);
    y1 = Math.min(y1, r.y);
    x2 = Math.max(x2, r.x + r.w);
    y2 = Math.max(y2, r.y + r.h);
  }
  return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
}

export function pointInBox(b, p, pad = 0) {
  const c = centerOf(b);
  const q = rotatePoint(p, c, -(b.rot || 0));
  return q.x >= b.x - pad && q.x <= b.x + b.w + pad && q.y >= b.y - pad && q.y <= b.y + b.h + pad;
}

/** Separating axis test between a rotated box and an axis aligned rect. */
export function boxIntersectsRect(b, rect) {
  const poly = corners(b);
  const rp = [
    { x: rect.x, y: rect.y }, { x: rect.x + rect.w, y: rect.y },
    { x: rect.x + rect.w, y: rect.y + rect.h }, { x: rect.x, y: rect.y + rect.h },
  ];
  const axes = [{ x: 1, y: 0 }, { x: 0, y: 1 }];
  for (let i = 0; i < 2; i++) {
    const a = poly[i];
    const n = poly[i + 1];
    axes.push({ x: -(n.y - a.y), y: n.x - a.x });
  }
  for (const ax of axes) {
    let min1 = Infinity;
    let max1 = -Infinity;
    let min2 = Infinity;
    let max2 = -Infinity;
    for (const p of poly) {
      const d = p.x * ax.x + p.y * ax.y;
      min1 = Math.min(min1, d); max1 = Math.max(max1, d);
    }
    for (const p of rp) {
      const d = p.x * ax.x + p.y * ax.y;
      min2 = Math.min(min2, d); max2 = Math.max(max2, d);
    }
    if (max1 < min2 - 1e-9 || max2 < min1 - 1e-9) return false;
  }
  return true;
}

export function boxInsideRect(b, rect) {
  return corners(b).every((p) => p.x >= rect.x - 1e-6 && p.x <= rect.x + rect.w + 1e-6
    && p.y >= rect.y - 1e-6 && p.y <= rect.y + rect.h + 1e-6);
}

export function handlePositions(b) {
  const c = centerOf(b);
  const out = {};
  for (const h of HANDLES) {
    const [hx, hy] = DIR[h];
    out[h] = rotatePoint({ x: c.x + (hx * b.w) / 2, y: c.y + (hy * b.h) / 2 }, c, b.rot || 0);
  }
  return out;
}

/**
 * Drag a resize handle. `start` is the box when the drag began, `pointer` the current pointer in
 * slide coordinates. The opposite edge or corner stays where it is, unless opts.center, when the
 * centre stays. The box never flips through itself: it stops at the minimum size.
 */
export function resizeFromHandle(start, handle, pointer, opts = {}) {
  const [hx, hy] = DIR[handle];
  const rot = start.rot || 0;
  const minW = opts.minW ?? 8;
  const minH = opts.minH ?? 8;
  const c0 = centerOf(start);
  // pointer in the element's own (unrotated) frame, origin at the start centre
  const q = rotatePoint(pointer, c0, -rot);
  const lx = q.x - c0.x;
  const ly = q.y - c0.y;
  const hw = start.w / 2;
  const hh = start.h / 2;

  let w = start.w;
  let h = start.h;
  if (hx !== 0) w = opts.center ? 2 * Math.max(hx * lx, 0) : Math.max(hx * lx + hw, 0);
  if (hy !== 0) h = opts.center ? 2 * Math.max(hy * ly, 0) : Math.max(hy * ly + hh, 0);

  if (opts.aspect && start.w > 0 && start.h > 0) {
    const ratio = start.w / start.h;
    if (hx !== 0 && hy !== 0) {
      const s = Math.max(w / start.w, h / start.h);
      w = start.w * s;
      h = start.h * s;
    } else if (hx !== 0) {
      h = w / ratio;
    } else {
      w = h * ratio;
    }
  }
  // minimum sizes keep the ratio when it is locked
  if (opts.aspect && start.w > 0 && start.h > 0) {
    const k = Math.max(minW / w, minH / h, 1);
    if (k > 1) { w *= k; h *= k; }
  } else {
    w = Math.max(w, minW);
    h = Math.max(h, minH);
  }

  // Place the box in the local frame (origin = start centre).
  let left;
  let top;
  if (opts.center) {
    left = -w / 2;
    top = -h / 2;
  } else {
    // x axis: dragging east keeps the west edge, west keeps the east edge, n/s keep the centre line
    left = hx === 1 ? -hw : hx === -1 ? hw - w : -w / 2;
    top = hy === 1 ? -hh : hy === -1 ? hh - h : -h / 2;
  }
  const lc = { x: left + w / 2, y: top + h / 2 };
  const wc = rotatePoint({ x: c0.x + lc.x, y: c0.y + lc.y }, c0, rot);
  return {
    x: round2(wc.x - w / 2), y: round2(wc.y - h / 2), w: round2(w), h: round2(h), rot,
  };
}

export function snapAngle(deg, step = 15) {
  return normAngle(Math.round(deg / step) * step);
}

/** New rotation while dragging the rotate handle. Angles are clockwise degrees; shift snaps to 15. */
export function rotationFromPointer(center, startPointer, pointer, rot0 = 0, snap = false) {
  const a0 = (Math.atan2(startPointer.x - center.x, -(startPointer.y - center.y)) * 180) / Math.PI;
  const a1 = (Math.atan2(pointer.x - center.x, -(pointer.y - center.y)) * 180) / Math.PI;
  let rot = rot0 + (a1 - a0);
  if (snap) return snapAngle(rot, 15);
  return round2(normAngle(rot));
}

/** Patches that scale a set of boxes from the bounds `from` to the bounds `to`. */
export function transformGroup(items, from, to) {
  const sx = from.w ? to.w / from.w : 1;
  const sy = from.h ? to.h / from.h : 1;
  return items.map((el) => {
    const c = centerOf(el);
    const nc = { x: to.x + (c.x - from.x) * sx, y: to.y + (c.y - from.y) * sy };
    const quarter = Math.abs(Math.round((el.rot || 0) / 90)) % 2 === 1 && (el.rot || 0) % 90 === 0;
    let fw = sx;
    let fh = sy;
    if (quarter) { fw = sy; fh = sx; } else if (el.rot && el.rot % 180 !== 0) { fw = fh = Math.sqrt(sx * sy); }
    const w = round2(Math.max(el.w * fw, 0));
    const h = round2(Math.max(el.h * fh, 0));
    return { id: el.id, p: { x: round2(nc.x - w / 2), y: round2(nc.y - h / 2), w, h } };
  });
}

export function moveBy(items, dx, dy) {
  return items.map((el) => ({ id: el.id, p: { x: round2(el.x + dx), y: round2(el.y + dy) } }));
}

export function rotateGroup(items, deltaDeg, center) {
  return items.map((el) => {
    const c = rotatePoint(centerOf(el), center, deltaDeg);
    return {
      id: el.id,
      p: { x: round2(c.x - el.w / 2), y: round2(c.y - el.h / 2), rot: round2(normAngle((el.rot || 0) + deltaDeg)) },
    };
  });
}

const FLIPPABLE_NONE = new Set(['text', 'table', 'chart', 'audio']);

export function flipPatch(el, axis) {
  if (FLIPPABLE_NONE.has(el.type)) return null;
  if (el.type === 'image') {
    return axis === 'h' ? { flipH: !el.flipH } : { flipV: !el.flipV };
  }
  const rot = el.rot || 0;
  const p = { rot: round2(normAngle(axis === 'h' ? 360 - rot : 180 - rot)) };
  if (el.type === 'shape' && axis === 'h') {
    if (el.kind === 'arrow-r') p.kind = 'arrow-l';
    else if (el.kind === 'arrow-l') p.kind = 'arrow-r';
  }
  return p;
}

/** Mirror a selection about `bounds` (axis 'h' mirrors left/right, 'v' top/bottom). */
export function flipGroup(items, axis, bounds) {
  return items.map((el) => {
    const c = centerOf(el);
    const nc = axis === 'h'
      ? { x: 2 * bounds.x + bounds.w - c.x, y: c.y }
      : { x: c.x, y: 2 * bounds.y + bounds.h - c.y };
    const p = { x: round2(nc.x - el.w / 2), y: round2(nc.y - el.h / 2), ...(flipPatch(el, axis) || {}) };
    return { id: el.id, p };
  });
}

/**
 * A text box that grows downward when its content is taller than the box. The top edge (in the
 * element's own frame) stays put, so a rotated box grows along its own axis. Returns null when
 * nothing changes. Boxes never shrink here: shrinking is the author's decision.
 */
export function growVertically(el, contentHeight) {
  const need = Math.ceil(contentHeight);
  if (!(need > el.h)) return null;
  const dh = need - el.h;
  const c = centerOf(el);
  const nc = rotatePoint({ x: c.x, y: c.y + dh / 2 }, c, el.rot || 0);
  return { x: round2(nc.x - el.w / 2), y: round2(nc.y - need / 2), h: need };
}

export function minSizeFor(el, unit = 1) {
  const u = unit || 1;
  switch (el.type) {
    case 'text': return { w: round2(40 * u), h: round2(24 * u) };
    case 'icon': return { w: round2(16 * u), h: round2(16 * u) };
    case 'shape': return el.kind === 'line' || el.kind === 'arrow'
      ? { w: 0, h: 0 } : { w: round2(8 * u), h: round2(8 * u) };
    default: return { w: round2(24 * u), h: round2(24 * u) };
  }
}

export { clamp };
