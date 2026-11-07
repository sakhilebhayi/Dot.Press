/**
 * Creating elements with the drawing tools. Pure.
 *
 *   toolKind(tool)                 'text' | 'shape' | 'line' | 'arrow' | 'image' | 'video' | 'audio' | 'chart' | 'table' | 'icon' | 'hand' | 'select'
 *   shapeKindOf(tool)              'shape:ellipse' -> 'ellipse'
 *   dragRect(a, b, {shift, alt})   normalised rect from two slide points (shift = square, alt = from the first point)
 *   isClick(a, b, zoom, tol?)      the pointer barely moved (screen px)
 *   buildFromTool(tool, deck, a, b, {shift, alt, click, taken, rng})  -> element (without id when `taken` is absent)
 *   NEEDS_FILE                     tools that need a file picker first
 */
import {
  newText, newShape, newChart, newTable, newIcon,
} from '../../core/doc.js';
import { lineFromEndpoints } from './transform.js';
import { snapAngle } from '../../core/geometry.js';
import { round2 } from '../../core/util.js';

export const NEEDS_FILE = new Set(['image', 'video', 'audio']);

export function toolKind(tool) {
  if (!tool) return 'select';
  if (tool.startsWith('shape:')) return 'shape';
  return tool;
}

export const shapeKindOf = (tool) => (tool.startsWith('shape:') ? tool.slice(6) : 'rect');

export function isClick(a, b, zoom = 1, tol = 4) {
  return Math.hypot(b.x - a.x, b.y - a.y) * zoom < tol;
}

export function dragRect(a, b, { shift = false, alt = false } = {}) {
  let dx = b.x - a.x;
  let dy = b.y - a.y;
  if (shift) {
    const m = Math.max(Math.abs(dx), Math.abs(dy));
    dx = Math.sign(dx || 1) * m;
    dy = Math.sign(dy || 1) * m;
  }
  let x1 = a.x;
  let y1 = a.y;
  let x2 = a.x + dx;
  let y2 = a.y + dy;
  if (alt) { x1 = a.x - dx; y1 = a.y - dy; }
  return {
    x: round2(Math.min(x1, x2)), y: round2(Math.min(y1, y2)), w: round2(Math.abs(x2 - x1)), h: round2(Math.abs(y2 - y1)),
  };
}

const clampInside = (rect, deck) => ({
  ...rect,
  x: round2(Math.min(Math.max(rect.x, 0), Math.max(0, deck.w - rect.w))),
  y: round2(Math.min(Math.max(rect.y, 0), Math.max(0, deck.h - rect.h))),
});

export function buildFromTool(tool, deck, a, b, { shift = false, alt = false, click = false, taken, rng } = {}) {
  const kind = toolKind(tool);
  const extra = { taken, rng };
  const u = (deck.w || 1920) / 1920;
  if (kind === 'line' || kind === 'arrow') {
    const base = newShape(deck, { kind, ...extra });
    if (click) {
      const len = base.w;
      return { ...base, x: round2(a.x - len / 2), y: round2(a.y), w: len, h: 0, rot: 0 };
    }
    let e = b;
    if (shift) {
      const ang = (snapAngle((Math.atan2(b.y - a.y, b.x - a.x) * 180) / Math.PI, 15) * Math.PI) / 180;
      const d = Math.hypot(b.x - a.x, b.y - a.y);
      e = { x: a.x + Math.cos(ang) * d, y: a.y + Math.sin(ang) * d };
    }
    const line = alt ? lineFromEndpoints({ x: 2 * a.x - e.x, y: 2 * a.y - e.y }, e, 0) : lineFromEndpoints(a, e, 0);
    return { ...base, ...line };
  }
  if (kind === 'text') {
    const base = newText(deck, { text: '', ...extra });
    if (click) {
      const w = round2(deck.w * 0.3);
      return clampInside({ ...base, x: round2(a.x), y: round2(a.y - base.h / 2), w }, deck);
    }
    const r = dragRect(a, b, { alt });
    return clampInside({ ...base, ...r, w: Math.max(r.w, 80 * u), h: Math.max(r.h, base.h) }, deck);
  }
  let base;
  if (kind === 'shape') base = newShape(deck, { kind: shapeKindOf(tool), ...extra });
  else if (kind === 'chart') base = newChart(deck, extra);
  else if (kind === 'table') base = newTable(deck, extra);
  else if (kind === 'icon') base = newIcon(deck, extra);
  else return null;
  if (click || kind === 'table') {
    // click: default size centred on the point. Tables take the default size and width, placed at the point
    if (kind === 'table') return clampInside({ ...base, x: round2(a.x - base.w / 2), y: round2(a.y) }, deck);
    return clampInside({ ...base, x: round2(a.x - base.w / 2), y: round2(a.y - base.h / 2) }, deck);
  }
  const r = dragRect(a, b, { shift: shift || kind === 'icon', alt });
  const min = 8 * u;
  return clampInside({ ...base, ...r, w: Math.max(r.w, min), h: Math.max(r.h, min) }, deck);
}
