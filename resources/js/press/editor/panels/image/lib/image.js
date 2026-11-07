/**
 * Pure helpers for the image panel (no DOM, no Vue).
 *
 *   CROP_PRESETS                          the aspect buttons: original, 1:1, 4:3, 16:9, 3:4, free
 *   aspectPatch({el, nat, preset, deckW, deckH})   patch {crop, x, y, w, h} that makes the picture frame and its crop window
 *                                         the preset's ratio (frame keeps its centre and roughly its area; the window is the
 *                                         largest one of that ratio inside what the picture shows now). null for 'free' or when
 *                                         the source size is unknown
 *   nudgePatch({el, nat, dir, step})      patch {crop} that moves the picture inside its frame (dir 'left'|'right'|'up'|'down'
 *                                         is the way the picture moves); null under fit contain or when nothing can move
 *   effectivePpi({nat, crop, box, fit, deckW})   pixels per inch the picture really gets when the slide is shown at full size
 *                                         (a 13.333 inch wide slide, the PowerPoint widescreen size), or null
 *   LOW_PPI                               below this the panel warns
 *   resetPatch(el)                        patch that removes crop, mask and adjustments (only keys the element has), or null
 *   isAdjusted(adj) / ADJUSTMENTS         the adjustment sliders and whether any is not at its neutral value
 *   MASKS                                 the mask shapes in picker order
 *   defaultShadow(deckW)                  the shadow the toggle switches on
 */
import { normalizeCrop } from '../../../../render/geometry.js';
import { visibleWindow, panWindow, clampWindow } from '../../../stage/lib/crop.js';

export const SLIDE_INCHES = 13.333;
export const LOW_PPI = 96;

export const CROP_PRESETS = [
  { id: 'orig', label: 'Original', ratio: null },
  { id: '1:1', label: '1:1', ratio: 1 },
  { id: '4:3', label: '4:3', ratio: 4 / 3 },
  { id: '16:9', label: '16:9', ratio: 16 / 9 },
  { id: '3:4', label: '3:4', ratio: 3 / 4 },
  { id: 'free', label: 'Free', ratio: null },
];

export const MASKS = [
  { id: 'none', label: 'None' },
  { id: 'round', label: 'Round corners' },
  { id: 'circle', label: 'Circle' },
  { id: 'ellipse', label: 'Ellipse' },
  { id: 'triangle', label: 'Triangle' },
  { id: 'star', label: 'Star' },
  { id: 'hexagon', label: 'Hexagon' },
];

export const ADJUSTMENTS = [
  { key: 'br', label: 'Brightness', min: -100, max: 100, step: 1, unit: '' },
  { key: 'ct', label: 'Contrast', min: -100, max: 100, step: 1, unit: '' },
  { key: 'sat', label: 'Saturation', min: -100, max: 100, step: 1, unit: '' },
  { key: 'blur', label: 'Blur', min: 0, max: 40, step: 1, unit: 'px' },
  { key: 'gray', label: 'Grayscale', min: 0, max: 100, step: 1, unit: '%' },
  { key: 'sepia', label: 'Sepia', min: 0, max: 100, step: 1, unit: '%' },
  { key: 'hue', label: 'Hue', min: -180, max: 180, step: 1, unit: '°' },
];

const r2 = (n) => Math.round(n * 100) / 100;
const r4 = (n) => Math.round(n * 1e4) / 1e4;

export function isAdjusted(adj) {
  if (!adj || typeof adj !== 'object') return false;
  return ADJUSTMENTS.some((a) => Number(adj[a.key]) > 0 || Number(adj[a.key]) < 0);
}

export function defaultShadow(deckW = 1920) {
  const u = (deckW || 1920) / 1920;
  return { x: 0, y: r2(8 * u), blur: r2(24 * u), c: '#00000040' };
}

function ratioOf(preset, nat) {
  if (preset === 'orig') return nat && nat.w > 0 && nat.h > 0 ? nat.w / nat.h : null;
  return CROP_PRESETS.find((p) => p.id === preset)?.ratio ?? null;
}

export function aspectPatch({ el, nat, preset, deckW = 1920, deckH = 1080 }) {
  if (!el || !nat || !(nat.w > 0) || !(nat.h > 0)) return null;
  const ratio = ratioOf(preset, nat);
  if (!ratio) return null;
  const box = { w: Math.max(1, +el.w || 1), h: Math.max(1, +el.h || 1) };
  const fit = el.fit === 'fill' ? 'fill' : 'cover';
  // Original shows the whole picture again; other ratios cut the largest window of that ratio out of what shows now.
  const cur = preset === 'orig' ? { x: 0, y: 0, w: 1, h: 1 } : visibleWindow({ crop: el.crop, nat, box, fit });
  const curW = cur.w * nat.w;
  const curH = cur.h * nat.h;
  let ww = curW;
  let wh = ww / ratio;
  if (wh > curH) { wh = curH; ww = wh * ratio; }
  const win = clampWindow({
    x: cur.x + (cur.w - ww / nat.w) / 2,
    y: cur.y + (cur.h - wh / nat.h) / 2,
    w: ww / nat.w,
    h: wh / nat.h,
  });
  // The frame: same centre, same area, new ratio, kept inside the slide.
  const area = box.w * box.h;
  let w = Math.sqrt(area * ratio);
  let h = w / ratio;
  const maxW = deckW * 0.96;
  const maxH = deckH * 0.96;
  const k = Math.min(1, maxW / w, maxH / h);
  w *= k;
  h *= k;
  const cx = (+el.x || 0) + box.w / 2;
  const cy = (+el.y || 0) + box.h / 2;
  const x = Math.min(Math.max(cx - w / 2, 0), Math.max(0, deckW - w));
  const y = Math.min(Math.max(cy - h / 2, 0), Math.max(0, deckH - h));
  return {
    crop: { x: r4(win.x), y: r4(win.y), w: r4(win.w), h: r4(win.h) },
    x: r2(x), y: r2(y), w: r2(w), h: r2(h),
  };
}

export function nudgePatch({ el, nat, dir, step = 0.06 }) {
  if (!el || !nat || !(nat.w > 0) || !(nat.h > 0)) return null;
  if (el.fit === 'contain') return null;
  const fit = el.fit === 'fill' ? 'fill' : 'cover';
  const box = { w: Math.max(1, +el.w || 1), h: Math.max(1, +el.h || 1) };
  const win = visibleWindow({ crop: el.crop, nat, box, fit });
  const dx = dir === 'left' ? -step * box.w : dir === 'right' ? step * box.w : 0;
  const dy = dir === 'up' ? -step * box.h : dir === 'down' ? step * box.h : 0;
  const next = panWindow(win, dx, dy, nat, box);
  if (Math.abs(next.x - win.x) < 1e-6 && Math.abs(next.y - win.y) < 1e-6) return null;
  return { crop: { x: r4(next.x), y: r4(next.y), w: r4(next.w), h: r4(next.h) } };
}

export function effectivePpi({ nat, crop, box, fit = 'cover', deckW = 1920 }) {
  if (!nat || !(nat.w > 0) || !(nat.h > 0) || !box || !(box.w > 0) || !(box.h > 0)) return null;
  const c = normalizeCrop(crop);
  const ww = c.w * nat.w;
  const wh = c.h * nat.h;
  const sx = box.w / ww; // slide units per source pixel, across
  const sy = box.h / wh;
  // The coarser axis decides how soft the picture looks.
  const unitsPerPx = fit === 'contain' ? Math.min(sx, sy) : fit === 'fill' ? Math.max(sx, sy) : Math.max(sx, sy);
  const unitsPerInch = (deckW || 1920) / SLIDE_INCHES;
  const ppi = unitsPerInch / unitsPerPx;
  return Number.isFinite(ppi) ? Math.round(ppi) : null;
}

export function resetPatch(el) {
  const p = {};
  if (el?.crop != null) p.crop = null;
  if (el?.mask != null && el.mask !== 'none') p.mask = null;
  if (el?.adj != null && isAdjusted(el.adj)) p.adj = null;
  return Object.keys(p).length ? p : null;
}
