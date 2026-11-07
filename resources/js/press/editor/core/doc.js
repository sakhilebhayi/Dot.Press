/**
 * The client side document model. A document is plain JSON, never mutated, shaped like the
 * server document of spec section 9, with the slides held in order:
 *
 *   { deck: {id, title, w, h, theme, rev, status, visibility, sections, slide_order, ...},
 *     slides: [ {id, title, notes, section_id, hidden, layout, bg, transition, rev, els:[...]} ],
 *     seq: number, assets: {} }
 *
 * `deck.slide_order` always equals the ids of `slides` in array order. Slide ids are strings.
 *
 * Public API
 *   createDoc(serverDocument)            normalise a server document (defaults, string slide ids, order)
 *   docToServerShape(doc)                {deck, slides, seq?} in the exact server shape
 *   newId(takenIds, prefix?, rng?)       collision safe element/group/slide id (matches the id pattern)
 *   usedIds(slide)                       Set of element and group ids in a slide
 *   newText / newShape / newImage / newChart / newTable / newIcon / newVideo / newAudio
 *         (deck, props?)  element factories. `deck` is any {w, h, theme} (a doc.deck works).
 *         Sizes scale with the deck width (unit = deck.w / 1920) and colours are theme tokens.
 *   newSlide(doc, props?)                a blank slide object with a fresh id
 *   textDoc(string)                      string -> text document (one paragraph per line)
 *   docText(doc)                         text document -> plain string
 *   findSlide(doc, slideId) / slideIndex(doc, slideId)
 *   findEl(doc, slideId, elId)           element or undefined
 *   slideOf(doc, elId, hintSlideId?)     slide that holds the element id (hint tried first)
 *   elementsInGroup(slide, groupId)      elements sharing a group id, z-order
 *   bounds(els)                          rotation aware union box of elements, null when empty
 *   deepClone(v)
 */
import { clone, uniqueId, isObj } from './util.js';
import { DEFAULT_THEME } from './theme.js';
import { rotatedBounds, unionBounds } from './geometry.js';

export const deepClone = clone;

export function newId(taken = [], prefix = 'e', rng = Math.random) {
  return uniqueId(taken, prefix, rng);
}

export function usedIds(slide) {
  const s = new Set();
  for (const el of slide?.els || []) {
    s.add(el.id);
    if (el.group) s.add(el.group);
  }
  return s;
}

// ---- document ---------------------------------------------------------------------------------

export function createDoc(server) {
  const src = clone(server || {});
  const deckIn = src.deck || {};
  const slidesIn = Array.isArray(src.slides) ? src.slides : [];
  const slides = slidesIn.map((s) => normSlide(s));
  const order = Array.isArray(deckIn.slide_order) ? deckIn.slide_order.map(String) : null;
  if (order) {
    const byId = new Map(slides.map((s) => [s.id, s]));
    const sorted = [];
    for (const id of order) if (byId.has(id)) { sorted.push(byId.get(id)); byId.delete(id); }
    for (const s of slides) if (byId.has(s.id)) sorted.push(s);
    slides.length = 0;
    slides.push(...sorted);
  }
  const deck = {
    title: '', w: 1920, h: 1080, rev: 0, status: 'draft', visibility: 'private', sections: [],
    ...deckIn,
    theme: isObj(deckIn.theme) ? deckIn.theme : clone(DEFAULT_THEME),
    sections: Array.isArray(deckIn.sections) ? deckIn.sections : [],
    slide_order: slides.map((s) => s.id),
  };
  const doc = { deck, slides, seq: Number(src.seq) || 0, assets: isObj(src.assets) ? src.assets : {} };
  if (!slides.length) {
    doc.slides = [newSlide(doc)];
    doc.deck.slide_order = doc.slides.map((s) => s.id);
  }
  return doc;
}

function normSlide(s) {
  return {
    title: '', notes: '', section_id: null, hidden: false, layout: 'blank', bg: null, transition: null, rev: 0,
    ...s,
    id: String(s.id),
    els: Array.isArray(s.els) ? s.els : [],
  };
}

export function docToServerShape(doc) {
  const out = {
    deck: { ...clone(doc.deck), slide_order: doc.slides.map((s) => s.id) },
    slides: clone(doc.slides),
  };
  if (doc.seq != null) out.seq = doc.seq;
  return out;
}

export function newSlide(doc, props = {}) {
  const taken = new Set(doc.slides.map((s) => s.id));
  return {
    title: '', notes: '', section_id: null, hidden: false, layout: 'blank', bg: null, transition: null, rev: 0,
    els: [],
    ...props,
    id: props.id || uniqueId(taken, 's'),
  };
}

export const slideIndex = (doc, id) => doc.slides.findIndex((s) => s.id === String(id));
export const findSlide = (doc, id) => doc.slides.find((s) => s.id === String(id));

export function findEl(doc, slideId, elId) {
  return findSlide(doc, slideId)?.els.find((e) => e.id === elId);
}

export function slideOf(doc, elId, hint) {
  if (hint != null) {
    const h = findSlide(doc, hint);
    if (h?.els.some((e) => e.id === elId)) return h;
  }
  return doc.slides.find((s) => s.els.some((e) => e.id === elId));
}

export function elementsInGroup(slide, groupId) {
  return slide.els.filter((e) => e.group && e.group === groupId);
}

export function bounds(els) {
  return unionBounds((els || []).map((e) => rotatedBounds(e)));
}

// ---- text documents ---------------------------------------------------------------------------

export function textDoc(str = '') {
  const lines = String(str).split('\n');
  return {
    type: 'doc',
    content: lines.map((line) => (line === ''
      ? { type: 'paragraph' }
      : { type: 'paragraph', content: [{ type: 'text', text: line }] })),
  };
}

export function docText(d) {
  if (!d) return '';
  const walk = (n) => {
    if (n.type === 'text') return n.text || '';
    if (n.type === 'hardBreak') return '\n';
    const inner = (n.content || []).map(walk);
    if (n.type === 'doc' || n.type === 'bulletList' || n.type === 'orderedList') return inner.join('\n');
    return inner.join('');
  };
  return walk(d);
}

// ---- element factories ------------------------------------------------------------------------
// Every factory takes (deck, props). Props override the defaults; `taken` (ids already in the slide)
// and `rng` only steer id generation and never reach the element. Kind specific props are named below.

const unitOf = (deck) => (deck?.w || 1920) / 1920;
const themeOf = (deck) => deck?.theme || DEFAULT_THEME;
const r = (n) => Math.round(n * 100) / 100;

function make(type, props, geometry, defaults) {
  const { taken, rng, ...rest } = props;
  return {
    id: rest.id || uniqueId(taken || [], 'e', rng),
    type,
    x: geometry.x, y: geometry.y, w: geometry.w, h: geometry.h, rot: 0, op: 1, lock: false, hide: false,
    ...defaults,
    ...rest,
  };
}

/** props.text = initial string. props.role 'title' gives a heading style. */
export function newText(deck, props = {}) {
  const { text, ...rest } = props;
  const u = unitOf(deck);
  const w = r(deck.w * 0.5);
  const heading = rest.role === 'title';
  return make('text', rest, { x: r((deck.w - w) / 2), y: r(deck.h * 0.4), w, h: r((heading ? 110 : 80) * u) }, {
    doc: textDoc(text ?? 'Text'),
    font: heading ? '@heading' : '@body',
    size: r((heading ? 72 : 44) * u),
    weight: heading ? themeOf(deck).t?.hw ?? 700 : 400,
    color: '@ink', align: 'left', valign: 'top', lh: 1.25, ls: 0, ps: 0.4, fit: 'none', pad: 0, radius: 0, fx: 'none',
  });
}

export function newShape(deck, props = {}) {
  const u = unitOf(deck);
  const kind = props.kind || 'rect';
  const line = kind === 'line' || kind === 'arrow';
  const size = r(Math.min(deck.w, deck.h) * 0.25);
  const sw = r((themeOf(deck).s?.stroke ?? 2) * u);
  const gw = line ? r(size * 1.4) : size;
  const defaults = line
    ? { kind, stroke: '@ink', sw: r(sw * 2), dash: 'solid', ends: { s: 'none', e: kind === 'arrow' ? 'arrow' : 'none' } }
    : { kind, fill: '@accent', sw: 0, radius: kind === 'rect' ? r((themeOf(deck).s?.radius ?? 12) * u) : 0, dash: 'solid' };
  return make('shape', props, { x: r((deck.w - gw) / 2), y: r((deck.h - (line ? 0 : size)) / 2), w: gw, h: line ? 0 : size }, defaults);
}

/** props.natural = {w, h} pixel size of the source, keeps the aspect ratio. props.src = {asset}|{url}. */
export function newImage(deck, props = {}) {
  const { natural, ...rest } = props;
  const nat = natural || { w: 4, h: 3 };
  const ratio = nat.w / nat.h || 4 / 3;
  let w = deck.w * 0.5;
  let h = w / ratio;
  if (h > deck.h * 0.7) { h = deck.h * 0.7; w = h * ratio; }
  return make('image', rest, { x: r((deck.w - w) / 2), y: r((deck.h - h) / 2), w: r(w), h: r(h) }, {
    src: { url: '' }, crop: { x: 0, y: 0, w: 1, h: 1 }, fit: 'cover', mask: 'none',
    adj: { br: 0, ct: 0, sat: 0, blur: 0, gray: 0, sepia: 0, hue: 0 }, flipH: false, flipV: false,
  });
}

/** props.chartType (default 'column'), props.data (chart data, default a small editable sample). */
export function newChart(deck, props = {}) {
  const { chartType, data, ...rest } = props;
  const w = r(deck.w * 0.6);
  const h = r(deck.h * 0.55);
  return make('chart', rest, { x: r((deck.w - w) / 2), y: r((deck.h - h) / 2), w, h }, {
    chart: {
      type: chartType || 'column',
      data: data || { categories: ['A', 'B', 'C', 'D'], series: [{ name: 'Series 1', values: [4, 7, 5, 9] }] },
      opt: {
        legend: 'bottom', labels: 'none', stacked: false, smooth: false, grid: true, fmt: 'num', dec: 0,
        ax: { x: { show: true }, y: { show: true } }, anim: true,
      },
      src: { kind: 'manual' },
    },
  });
}

/** props.rows, props.cols (default 3 x 3). Column widths are fractions that sum to exactly 1. */
export function newTable(deck, props = {}) {
  const { rows: nr, cols: nc, ...rest } = props;
  const u = unitOf(deck);
  const rows = Math.max(1, nr || 3);
  const cols = Math.max(1, nc || 3);
  const w = r(deck.w * 0.6);
  const rowh = r(70 * u);
  const each = Math.floor(10000 / cols) / 10000;
  const colw = Array.from({ length: cols }, () => each);
  colw[cols - 1] = r((1 - each * (cols - 1)) * 10000) / 10000;
  return make('table', rest, { x: r((deck.w - w) / 2), y: r(deck.h * 0.3), w, h: r(rowh * rows) }, {
    rows: Array.from({ length: rows }, () => Array.from({ length: cols }, () => ({ t: '' }))),
    colw, rowh: Array.from({ length: rows }, () => rowh), header: true, band: true,
    size: r(30 * u), font: '@body', lines: 'rows',
  });
}

export function newIcon(deck, props = {}) {
  const s = r(Math.min(deck.w, deck.h) * 0.1);
  return make('icon', props, { x: r((deck.w - s) / 2), y: r((deck.h - s) / 2), w: s, h: s }, {
    name: 'star', color: '@accent', sw: r(2 * unitOf(deck)),
  });
}

export function newVideo(deck, props = {}) {
  const w = r(deck.w * 0.6);
  const h = r((w * 9) / 16);
  return make('video', props, { x: r((deck.w - w) / 2), y: r((deck.h - h) / 2), w, h }, {
    src: { url: '' }, autoplay: false, loop: false, muted: false, controls: true, start: 0,
  });
}

export function newAudio(deck, props = {}) {
  const s = r(Math.min(deck.w, deck.h) * 0.08);
  return make('audio', props, { x: r(deck.w * 0.05), y: r(deck.h * 0.9 - s), w: s, h: s }, {
    src: { asset: '' }, autoplay: false, loop: false, controls: true,
  });
}
