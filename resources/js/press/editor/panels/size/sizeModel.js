/**
 * Pure helpers for the slide size panel (node tested). Geometry comes from the core resize.js (the same rules the server
 * applies for deck.resize); this module only prepares what the panel shows.
 *
 *   PRESETS, MODES, MIN_DIM, MAX_DIM
 *   ratioLabel(w, h)                    '16:9', '4:3', '9:16', '1.41:1'
 *   validSize(w, h)                     whole numbers inside 320..7680
 *   presetFor(w, h)                     the preset id of a size, or 'custom'
 *   previewPair(doc, slideId, w, h, mode)   {before: {deck, slide}, after: {deck, slide}} drawn on a copy of the document
 *   crossing(doc, w, h, mode)           {elements, slides}: elements that end up partly outside the new slide
 *   modeFact(doc, slideId, w, h, mode)  one plain sentence about what this mode does to this deck
 */
import { MAX_DIM, MIN_DIM, resizeDoc, validResize } from '../../core/resize.js';

export { MAX_DIM, MIN_DIM };

export const PRESETS = [
  { id: '16-9', name: 'Widescreen', ratio: '16:9', w: 1920, h: 1080 },
  { id: '4-3', name: 'Standard', ratio: '4:3', w: 1440, h: 1080 },
  { id: 'portrait', name: 'Portrait', ratio: '9:16', w: 1080, h: 1920 },
  { id: 'a4', name: 'A4 landscape', ratio: 'A4', w: 1920, h: 1358 },
  { id: 'square', name: 'Square', ratio: '1:1', w: 1080, h: 1080 },
];

export const MODES = [
  {
    id: 'scale', label: 'Scale to fill',
    text: 'Everything grows or shrinks together until the new slide is full. If the shapes differ, the edges that do not fit are cut off.',
  },
  {
    id: 'fit', label: 'Scale to fit, with bars',
    text: 'Everything grows or shrinks together until it all fits inside. If the shapes differ, empty bars appear at the sides or at the top and bottom.',
  },
  {
    id: 'anchor', label: 'Keep sizes, re-anchor',
    text: 'Nothing is resized. Each element moves in proportion, so things near an edge stay near that edge. Text keeps its size.',
  },
];

const gcd = (a, b) => (b ? gcd(b, a % b) : a);

export function ratioLabel(w, h) {
  if (!(w > 0) || !(h > 0)) return '';
  const g = gcd(Math.round(w), Math.round(h));
  const a = Math.round(w) / g;
  const b = Math.round(h) / g;
  if (a <= 32 && b <= 32) return `${a}:${b}`;
  return `${(w / h).toFixed(2)}:1`;
}

export function validSize(w, h) {
  return Number.isInteger(w) && Number.isInteger(h) && validResize(w, h, 'scale');
}

export function presetFor(w, h) {
  return PRESETS.find((p) => p.w === w && p.h === h)?.id || 'custom';
}

export function previewPair(doc, slideId, w, h, mode) {
  const slide = doc.slides.find((s) => s.id === slideId) || doc.slides[0];
  const one = { ...doc, slides: [slide] };
  const next = resizeDoc(one, w, h, mode);
  return {
    before: { deck: { w: doc.deck.w, h: doc.deck.h, theme: doc.deck.theme }, slide },
    after: { deck: { w, h, theme: doc.deck.theme }, slide: next.slides[0] },
  };
}

export function crossing(doc, w, h, mode) {
  const next = resizeDoc(doc, w, h, mode);
  let elements = 0;
  let slides = 0;
  for (const s of next.slides) {
    let any = false;
    for (const e of s.els) {
      if (typeof e.x !== 'number' || typeof e.w !== 'number') continue;
      if (e.x < -0.5 || e.y < -0.5 || e.x + e.w > w + 0.5 || e.y + e.h > h + 0.5) { elements++; any = true; }
    }
    if (any) slides++;
  }
  return { elements, slides };
}

const n = (c, one, many) => `${c} ${c === 1 ? one : many}`;

export function modeFact(doc, w, h, mode) {
  const same = Math.abs(doc.deck.w / doc.deck.h - w / h) < 0.005;
  const x = crossing(doc, w, h, mode);
  if (mode === 'fit') {
    return same ? 'The shapes match, so no bars appear.' : 'Bars appear on two sides; nothing is cut off.';
  }
  if (x.elements) return `${n(x.elements, 'element reaches', 'elements reach')} past the edge on ${n(x.slides, 'slide', 'slides')}. You can move them afterwards.`;
  return same ? 'The shapes match, so nothing is cut off.' : 'Everything stays inside the new slide.';
}
