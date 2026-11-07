/**
 * Clipboard: serialise, paste, duplicate, and paste-and-conform. Pure; the shell does the actual
 * navigator.clipboard calls with `toClipboardText` / `parseClipboard`.
 *
 * Payloads (JSON, version 1)
 *   {kind: 'dot-press/elements', v: 1, deckId, slide, theme, w, h, els: [...]}
 *   {kind: 'dot-press/slides',   v: 1, deckId, theme, w, h, slides: [...]}
 *
 * API
 *   serializeElements(doc, slideId, ids) -> payload        z-order kept
 *   serializeSlides(doc, slideIds) -> payload
 *   toClipboardText(payload) / parseClipboard(text) -> payload | null
 *   classifyText(text) -> {kind: 'payload', payload} | {kind: 'image-url', url} | {kind: 'text', text} | null
 *   cloneElements(els, taken, rng?) -> {els, idMap}        fresh element and group ids
 *   conformPreview(els, sourceTheme, targetTheme, mode) -> {els, report}
 *        mode 'conform'  (default) colours that are exactly a source theme colour become the target token of
 *                        the same role (accent -> accent, ink -> ink), fonts equal to the source heading or
 *                        body family become '@heading' / '@body'. Tokens already follow the target theme.
 *                        Other hex colours and fonts are kept.
 *        mode 'keep'     tokens are resolved to the source theme colours and fonts, so the pasted look is
 *                        identical to the source (it will no longer follow the target theme).
 *        mode 'none'     untouched.
 *        report: {mode, sameTheme, changes: [{kind: 'color'|'font', from, to, count, elements: [ids]}]}
 *        Nothing is applied: call this to preview, then paste with the same mode.
 *   pasteElements(doc, payload, {slideId, repeat?, mode?, rng?}) -> {ops, ids, report}
 *        ops are el.add (on top, original relative z-order). Pasting onto the slide it was copied from
 *        offsets by one step (24 units at 1920 wide) plus `repeat` steps. Other slide or deck: same
 *        position, scaled to the target deck size if the sizes differ; kept inside the slide. Links to
 *        slides of another deck are removed.
 *   pasteSlides(doc, payload, {after?, mode?, rng?}) -> {ops, ids, report}   slide.add ops with fresh ids
 *   duplicateElements(doc, slideId, ids, {repeat?, rng?}) -> {ops, ids}      cmd+D
 *   textElementFromString(deck, text, opts?) / imageElementFromUrl(deck, url, opts?)
 */
import { clone, isObj, uniqueId, round2 } from './util.js';
import { newText, newImage, usedIds } from './doc.js';
import { COLOR_TOKENS, resolveColor, resolveFont, mapColors, mapFonts, isHex, isToken, DEFAULT_THEME } from './theme.js';
import { resizePatch } from './resize.js';
import { rotatedBounds, unionBounds } from './geometry.js';

const KIND_EL = 'dot-press/elements';
const KIND_SLIDES = 'dot-press/slides';

const slideOf = (doc, id) => doc.slides.find((s) => s.id === String(id));

export function serializeElements(doc, slideId, ids) {
  const slide = slideOf(doc, slideId);
  const want = new Set(ids);
  return {
    kind: KIND_EL, v: 1, deckId: doc.deck.id ?? null, slide: slide?.id ?? null,
    theme: clone(doc.deck.theme || DEFAULT_THEME), w: doc.deck.w, h: doc.deck.h,
    els: clone((slide?.els || []).filter((e) => want.has(e.id))),
  };
}

export function serializeSlides(doc, slideIds) {
  const want = new Set(slideIds.map(String));
  return {
    kind: KIND_SLIDES, v: 1, deckId: doc.deck.id ?? null,
    theme: clone(doc.deck.theme || DEFAULT_THEME), w: doc.deck.w, h: doc.deck.h,
    slides: clone(doc.slides.filter((s) => want.has(s.id))),
  };
}

export const toClipboardText = (payload) => JSON.stringify(payload);

export function parseClipboard(text) {
  if (typeof text !== 'string' || text.length < 20 || text[0] !== '{') return null;
  let p;
  try { p = JSON.parse(text); } catch { return null; }
  if (!isObj(p) || p.v !== 1) return null;
  if (p.kind === KIND_EL && Array.isArray(p.els) && p.els.every((e) => isObj(e) && typeof e.id === 'string' && typeof e.type === 'string')) return p;
  if (p.kind === KIND_SLIDES && Array.isArray(p.slides) && p.slides.every((s) => isObj(s) && Array.isArray(s.els))) return p;
  return null;
}

const IMG_URL = /^https:\/\/[^\s]+\.(png|jpe?g|gif|webp|avif|svg)(\?[^\s]*)?$/i;

export function classifyText(text) {
  if (typeof text !== 'string') return null;
  const t = text.trim();
  if (!t) return null;
  const payload = parseClipboard(t);
  if (payload) return { kind: 'payload', payload };
  if (IMG_URL.test(t)) return { kind: 'image-url', url: t };
  return { kind: 'text', text };
}

export function textElementFromString(deck, text, opts = {}) {
  return newText(deck, { text, ...opts });
}

export function imageElementFromUrl(deck, url, opts = {}) {
  return newImage(deck, { src: { url }, ...opts });
}

export function cloneElements(els, taken, rng) {
  const used = new Set(taken);
  const idMap = new Map();
  const groupMap = new Map();
  const out = els.map((el) => {
    const copy = clone(el);
    const id = uniqueId(used, 'e', rng);
    used.add(id);
    idMap.set(el.id, id);
    copy.id = id;
    if (el.group) {
      if (!groupMap.has(el.group)) { const g = uniqueId(used, 'g', rng); used.add(g); groupMap.set(el.group, g); }
      copy.group = groupMap.get(el.group);
    }
    return copy;
  });
  return { els: out, idMap };
}

// ---- conform ----------------------------------------------------------------------------------

function colorTable(theme) {
  const m = new Map();
  for (const t of COLOR_TOKENS) {
    const hex = resolveColor(t, theme);
    if (hex && hex.length === 7 && !m.has(hex)) m.set(hex, t);
  }
  return m;
}

export function conformPreview(els, sourceTheme, targetTheme, mode = 'conform') {
  const src = sourceTheme || DEFAULT_THEME;
  const dst = targetTheme || DEFAULT_THEME;
  const same = JSON.stringify(src.c) === JSON.stringify(dst.c) && JSON.stringify(src.t) === JSON.stringify(dst.t);
  const report = { mode, sameTheme: same, changes: [] };
  if (mode === 'none' || (same && mode === 'conform')) return { els, report };
  const table = colorTable(src);
  const record = new Map();
  let current = null;
  const note = (kind, from, to) => {
    const k = `${kind}\u0000${from}\u0000${to}`;
    if (!record.has(k)) record.set(k, { kind, from, to, count: 0, elements: [] });
    const r = record.get(k);
    r.count++;
    if (!r.elements.includes(current)) r.elements.push(current);
  };
  const colorFn = (c) => {
    if (mode === 'keep') {
      if (isToken(c)) { const to = resolveColor(c, src); if (to) { note('color', c, to); return to; } }
      return c;
    }
    if (isHex(c) && c.length === 7) {
      const t = table.get(c.toLowerCase());
      if (t) { note('color', c, t); return t; }
    }
    return c;
  };
  const fontFn = (f) => {
    if (mode === 'keep') {
      if (f === '@heading' || f === '@body') { const to = resolveFont(f, src); note('font', f, to); return to; }
      return f;
    }
    if (f !== '@heading' && f !== '@body') {
      if (f === src.t?.heading && src.t?.heading !== src.t?.body) { note('font', f, '@heading'); return '@heading'; }
      if (f === src.t?.body && src.t?.heading !== src.t?.body) { note('font', f, '@body'); return '@body'; }
      if (f === src.t?.body) { note('font', f, '@body'); return '@body'; }
    }
    return f;
  };
  const out = els.map((el) => {
    current = el.id;
    return mapFonts(mapColors(el, colorFn), fontFn);
  });
  report.changes = [...record.values()];
  return { els: out, report };
}

// ---- paste ------------------------------------------------------------------------------------

function dropForeignLinks(el, report) {
  if (typeof el.link === 'string' && el.link.startsWith('#slide:')) {
    const { link: _l, ...rest } = el;
    report.droppedLinks = (report.droppedLinks || 0) + 1;
    return rest;
  }
  return el;
}

function placeEls(els, payload, doc, offsetSteps, clampInside = true) {
  const from = { w: payload.w, h: payload.h };
  const to = { w: doc.deck.w, h: doc.deck.h };
  let list = els;
  if (from.w !== to.w || from.h !== to.h) {
    list = list.map((el) => ({ ...el, ...resizePatch(el, from, to, 'fit') }));
  }
  const step = 24 * (doc.deck.w / 1920);
  const d = step * offsetSteps;
  if (d) list = list.map((el) => ({ ...el, x: round2(el.x + d), y: round2(el.y + d) }));
  // keep the pasted block inside the slide when it fits
  const b = clampInside ? unionBounds(list.map((e) => rotatedBounds(e))) : null;
  if (b) {
    let dx = 0;
    let dy = 0;
    if (b.w <= doc.deck.w) { if (b.x < 0) dx = -b.x; else if (b.x + b.w > doc.deck.w) dx = doc.deck.w - (b.x + b.w); }
    if (b.h <= doc.deck.h) { if (b.y < 0) dy = -b.y; else if (b.y + b.h > doc.deck.h) dy = doc.deck.h - (b.y + b.h); }
    if (dx || dy) list = list.map((el) => ({ ...el, x: round2(el.x + dx), y: round2(el.y + dy) }));
  }
  return list;
}

export function pasteElements(doc, payload, { slideId, repeat = 0, mode = 'conform', rng } = {}) {
  const slide = slideOf(doc, slideId);
  if (!slide || payload?.kind !== KIND_EL) return { ops: [], ids: [], report: { mode, sameTheme: true, changes: [] } };
  const conformed = conformPreview(payload.els, payload.theme, doc.deck.theme, mode);
  const report = conformed.report;
  const foreignDeck = payload.deckId !== doc.deck.id;
  let els = conformed.els;
  if (foreignDeck) els = els.map((e) => dropForeignLinks(e, report));
  const sameSlide = !foreignDeck && payload.slide === slide.id;
  els = placeEls(els, payload, doc, (sameSlide ? 1 : 0) + repeat);
  const cloned = cloneElements(els, usedIds(slide), rng);
  const ops = cloned.els.map((el) => ({ op: 'el.add', slide: slide.id, el }));
  return { ops, ids: cloned.els.map((e) => e.id), report };
}

export function duplicateElements(doc, slideId, ids, { repeat = 0, rng } = {}) {
  const payload = serializeElements(doc, slideId, ids);
  const r = pasteElements(doc, payload, { slideId, repeat, mode: 'none', rng });
  return { ops: r.ops, ids: r.ids };
}

export function pasteSlides(doc, payload, { after, mode = 'conform', rng } = {}) {
  if (payload?.kind !== KIND_SLIDES) return { ops: [], ids: [], report: { mode, sameTheme: true, changes: [] } };
  const ops = [];
  const ids = [];
  const report = { mode, sameTheme: true, changes: [] };
  const taken = new Set(doc.slides.map((s) => s.id));
  const foreignDeck = payload.deckId !== doc.deck.id;
  let anchor = after === undefined ? doc.slides[doc.slides.length - 1]?.id ?? null : after;
  for (const s of payload.slides) {
    const c = conformPreview(s.els, payload.theme, doc.deck.theme, mode);
    report.sameTheme = c.report.sameTheme;
    report.changes.push(...c.report.changes);
    let els = c.els;
    if (foreignDeck) els = els.map((e) => dropForeignLinks(e, report));
    els = placeEls(els, payload, doc, 0, false);
    const cloned = cloneElements(els, new Set(), rng);
    const tid = uniqueId(taken, 's', rng);
    taken.add(tid);
    const { id: _id, rev: _rev, section_id: _sec, ...fields } = s;
    ops.push({ op: 'slide.add', tid, after: anchor, slide: { ...clone(fields), section_id: null, els: cloned.els } });
    ids.push(tid);
    anchor = tid;
  }
  return { ops, ids, report };
}
