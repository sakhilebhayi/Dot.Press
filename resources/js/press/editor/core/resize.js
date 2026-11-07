/**
 * deck.resize geometry (spec section 8; the server reading is authoritative, the coordinator fixed it
 * on 2026-11-07). The server logs `deck.resize` followed by `deck.reload`, so a client reloads after a
 * resize: this code only produces the optimistic local view. The PHP side implements the same rules.
 *
 * Let W,H be the old slide size and W',H' the new one (each within 320..7680, otherwise the op is
 * skipped, never clamped). Every element of every slide is transformed. Results are rounded half away
 * from zero to 2 decimals. Rotation, opacity and anything not listed below are never touched.
 *
 *   mode "scale"   s = MAX(W'/W, H'/H): the content fills the new slide and may crop.
 *   mode "fit"     s = MIN(W'/W, H'/H): the content fits inside the new slide, letterboxed.
 *       both       x' = x*s + (W' - W*s)/2, y' = y*s + (H' - H*s)/2, w' = w*s, h' = h*s, every number in
 *                  `rowh` times s, and the style sizes times s: `size`, `sw`, `radius`, `pad`, `shadow.x`,
 *                  `shadow.y`, `shadow.blur`, and `fontSize` in text documents (a number, or a string
 *                  "<n>px" which stays a "px" string). Content is centred.
 *   mode "anchor"  sizes stay. A block is a whole group (elements sharing a `group` id; its box is the
 *                  union of the members' unrotated x y w h) or a single element. Each block shifts by
 *                  (W' - W) * (cx / W) horizontally and (H' - H) * (cy / H) vertically, where (cx, cy) is the
 *                  centre of the block's box: things on the left stay left, things on the right stay right.
 *                  Nothing is clamped.
 */
import { isObj, round2 } from './util.js';

export const RESIZE_MODES = ['scale', 'anchor', 'fit'];
export const MIN_DIM = 320;
export const MAX_DIM = 7680;

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

/** Scale factor and offsets for the scale and fit modes. */
function plan(from, to, mode) {
  const s = mode === 'scale' ? Math.max(to.w / from.w, to.h / from.h) : Math.min(to.w / from.w, to.h / from.h);
  return { s, ox: (to.w - from.w * s) / 2, oy: (to.h - from.h * s) / 2 };
}

/** The patch (changed keys only) that moves one element from the old slide size to the new one (scale and fit). */
export function resizePatch(el, from, to, mode) {
  if (mode === 'anchor') return {};
  const { s, ox, oy } = plan(from, to, mode);
  const p = {};
  if (num(el.x)) p.x = round2(el.x * s + ox);
  if (num(el.y)) p.y = round2(el.y * s + oy);
  if (num(el.w)) p.w = round2(el.w * s);
  if (num(el.h)) p.h = round2(el.h * s);
  if (Array.isArray(el.rowh)) p.rowh = el.rowh.map((v) => (num(v) ? round2(v * s) : v));
  for (const k of ['size', 'sw', 'radius', 'pad']) if (num(el[k])) p[k] = round2(el[k] * s);
  if (isObj(el.shadow)) {
    const sh = { ...el.shadow };
    for (const k of ['x', 'y', 'blur']) if (num(sh[k])) sh[k] = round2(sh[k] * s);
    p.shadow = sh;
  }
  if (el.doc) p.doc = scaleFontSizes(el.doc, s);
  const out = {};
  for (const k of Object.keys(p)) {
    if (JSON.stringify(p[k]) !== JSON.stringify(el[k])) out[k] = p[k];
  }
  return out;
}

/** Anchor mode for one slide: blocks (groups or single elements) shift in proportion to where their centre is. */
function anchorSlide(slide, from, to) {
  const blocks = new Map();
  for (const el of slide.els) {
    if (!num(el.x) || !num(el.y) || !num(el.w) || !num(el.h)) continue;
    const key = el.group ? `g:${el.group}` : `e:${el.id}`;
    const b = blocks.get(key);
    if (!b) blocks.set(key, { x1: el.x, y1: el.y, x2: el.x + el.w, y2: el.y + el.h });
    else {
      b.x1 = Math.min(b.x1, el.x); b.y1 = Math.min(b.y1, el.y);
      b.x2 = Math.max(b.x2, el.x + el.w); b.y2 = Math.max(b.y2, el.y + el.h);
    }
  }
  let changed = false;
  const els = slide.els.map((el) => {
    if (!num(el.x) || !num(el.y) || !num(el.w) || !num(el.h)) return el;
    const b = blocks.get(el.group ? `g:${el.group}` : `e:${el.id}`);
    const dx = (to.w - from.w) * (((b.x1 + b.x2) / 2) / from.w);
    const dy = (to.h - from.h) * (((b.y1 + b.y2) / 2) / from.h);
    const x = round2(el.x + dx);
    const y = round2(el.y + dy);
    if (x === el.x && y === el.y) return el;
    changed = true;
    return { ...el, x, y };
  });
  return changed ? { ...slide, els } : slide;
}

/** Resize a whole document (deck size and all elements). Returns a new document. */
export function resizeDoc(doc, w, h, mode) {
  const from = { w: doc.deck.w, h: doc.deck.h };
  const to = { w, h };
  const slides = doc.slides.map((slide) => {
    if (mode === 'anchor') return anchorSlide(slide, from, to);
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
