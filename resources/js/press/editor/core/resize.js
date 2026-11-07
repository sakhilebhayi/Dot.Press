/**
 * deck.resize geometry (spec section 8). The PHP side implements the same rules from the same words.
 *
 * Let W,H be the old slide size and W',H' the new one. Every element of every slide is transformed.
 * All results are rounded half away from zero to 2 decimals. Rotation, opacity and everything not
 * listed below are never touched.
 *
 *   mode "scale"   s = min(W'/W, H'/H). Geometry: x' = x*s + (W' - W*s)/2, y' = y*s + (H' - H*s)/2,
 *                  w' = w*s, h' = h*s, and each number in `rowh` (table row heights) times s.
 *                  Style sizes are multiplied by s too: `size`, `sw`, `radius`, `pad`,
 *                  `shadow.x`, `shadow.y`, `shadow.blur`, and `fontSize` in text documents
 *                  (a number, or a string "<n>px" which stays a "px" string).
 *   mode "fit"     the same geometry as "scale" (uniform scale, centred, letterboxed) but the style
 *                  sizes listed above are NOT scaled.
 *   mode "anchor"  sizes stay. The centre of each element moves proportionally per axis:
 *                  cx' = cx * W'/W, cy' = cy * H'/H, then x' = cx' - w/2, y' = cy' - h/2, clamped inside
 *                  the new slide: x' in [0, W'-w] (if w > W' the element is centred instead, x' = (W'-w)/2),
 *                  same for y. The clamp uses the unrotated box.
 */
import { isObj, round2, clamp } from './util.js';

export const RESIZE_MODES = ['scale', 'anchor', 'fit'];
export const MIN_DIM = 16;
export const MAX_DIM = 20000;

export function validResize(w, h, mode) {
  return Number.isFinite(w) && Number.isFinite(h) && w >= MIN_DIM && h >= MIN_DIM
    && w <= MAX_DIM && h <= MAX_DIM && RESIZE_MODES.includes(mode);
}

const num = (v) => typeof v === 'number' && Number.isFinite(v);

function scaleFontSizes(node, s) {
  if (!isObj(node)) return node;
  let out = node;
  if (Array.isArray(node.marks)) {
    let changed = false;
    const marks = node.marks.map((m) => {
      if (m?.type !== 'textStyle' || !isObj(m.attrs) || m.attrs.fontSize == null) return m;
      const fs = m.attrs.fontSize;
      let nf = fs;
      if (num(fs)) nf = round2(fs * s);
      else if (typeof fs === 'string') {
        const mt = /^(\d+(?:\.\d+)?)px$/.exec(fs);
        if (mt) nf = `${round2(Number(mt[1]) * s)}px`;
      }
      if (nf === fs) return m;
      changed = true;
      return { ...m, attrs: { ...m.attrs, fontSize: nf } };
    });
    if (changed) out = { ...out, marks };
  }
  if (Array.isArray(node.content)) {
    let changed = false;
    const content = node.content.map((n) => {
      const nn = scaleFontSizes(n, s);
      if (nn !== n) changed = true;
      return nn;
    });
    if (changed) out = { ...out, content };
  }
  return out;
}

/** The patch (changed keys only) that moves one element from the old slide size to the new one. */
export function resizePatch(el, from, to, mode) {
  const p = {};
  if (mode === 'anchor') {
    const place = (pos, size, oW, nW) => {
      const c = ((pos + size / 2) * nW) / oW;
      const lo = 0;
      const hi = nW - size;
      return hi < lo ? hi / 2 : clamp(c - size / 2, lo, hi);
    };
    if (num(el.x) && num(el.w)) p.x = round2(place(el.x, el.w, from.w, to.w));
    if (num(el.y) && num(el.h)) p.y = round2(place(el.y, el.h, from.h, to.h));
  } else {
    const s = Math.min(to.w / from.w, to.h / from.h);
    const ox = (to.w - from.w * s) / 2;
    const oy = (to.h - from.h * s) / 2;
    if (num(el.x)) p.x = round2(el.x * s + ox);
    if (num(el.y)) p.y = round2(el.y * s + oy);
    if (num(el.w)) p.w = round2(el.w * s);
    if (num(el.h)) p.h = round2(el.h * s);
    if (Array.isArray(el.rowh)) p.rowh = el.rowh.map((v) => (num(v) ? round2(v * s) : v));
    if (mode === 'scale') {
      for (const k of ['size', 'sw', 'radius', 'pad']) if (num(el[k])) p[k] = round2(el[k] * s);
      if (isObj(el.shadow)) {
        const sh = { ...el.shadow };
        for (const k of ['x', 'y', 'blur']) if (num(sh[k])) sh[k] = round2(sh[k] * s);
        p.shadow = sh;
      }
      if (el.doc) p.doc = scaleFontSizes(el.doc, s);
    }
  }
  const out = {};
  for (const k of Object.keys(p)) {
    if (JSON.stringify(p[k]) !== JSON.stringify(el[k])) out[k] = p[k];
  }
  return out;
}

/** Resize a whole document (deck size and all elements). Returns a new document. */
export function resizeDoc(doc, w, h, mode) {
  const from = { w: doc.deck.w, h: doc.deck.h };
  const to = { w, h };
  const slides = doc.slides.map((slide) => {
    let changed = false;
    const els = slide.els.map((el) => {
      const p = resizePatch(el, from, to, mode);
      if (!Object.keys(p).length) return el;
      changed = true;
      return { ...el, ...p };
    });
    return changed ? { ...slide, els } : slide;
  });
  return { ...doc, deck: { ...doc.deck, w, h }, slides };
}
