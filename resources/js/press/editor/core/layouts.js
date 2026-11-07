/**
 * Applying and saving layouts. Pure functions over layout DEFINITIONS; the layout library itself
 * (the 13 built in layouts and the custom ones) is supplied by another module.
 *
 * LAYOUT DEFINITION FORMAT
 *   {
 *     id: 'title-body' | 'custom:abc',      the value stored in slide.layout
 *     name: 'Title and body',
 *     bg?: fill,                            default background, used only when the slide has none
 *     ph: [                                 placeholders, bottom to top
 *       { role: 'title'|'subtitle'|'body'|'media'|'caption'|'footer'|'number'|'kpi',
 *         type: 'text' | 'media' | 'shape', text kind receives text elements, media receives image,
 *               video, chart or table elements, shape receives shapes
 *         x, y, w, h,                       FRACTIONS of the slide (0 to 1)
 *         name?, text?,                     name for the layers list, prompt text for a new text placeholder
 *         sizeF?,                           font size as a fraction of the slide height (new placeholders only)
 *         style?: {font, weight, color, align, valign, fit, lh}   defaults for a new placeholder only
 *       } ]
 *   }
 *
 * applyLayout(doc, slideId, layout, opts?) -> { ops, report }
 *   ops    slide.set (layout id, background when the slide has none), el.set (matched elements move and
 *          resize into their placeholder, take the role) and el.add (missing placeholders). NEVER el.remove.
 *   report { matched: [{id, role}], added: [{id, role}], unmatched: [{id, reason}] }
 *   Matching: first by role (several elements with the same role go in reading order), then by type in
 *   z-order among elements that have no role the layout knows. Locked elements are never moved and
 *   are reported as unmatched. Text content and the user's styling are kept: only x y w h (and rot 0)
 *   and the role are set. opts.addMissing = false skips adding placeholders.
 *
 * saveLayoutFromSlide(slide, deck, {id?, name?}) -> layout definition built from the slide's elements.
 */
import { round2, uniqueId } from './util.js';
import { newText, usedIds, docText } from './doc.js';

const ACCEPTS = {
  text: ['text'],
  media: ['image', 'video', 'chart', 'table'],
  shape: ['shape'],
};

const PROMPTS = {
  title: 'Title', subtitle: 'Subtitle', body: 'Text', caption: 'Caption', footer: 'Footer', number: '#', kpi: '0',
};

const readingOrder = (a, b) => (a.y - b.y > 24 ? 1 : b.y - a.y > 24 ? -1 : a.x - b.x);
const px = (def, deck) => ({
  x: round2(def.x * deck.w), y: round2(def.y * deck.h), w: round2(def.w * deck.w), h: round2(def.h * deck.h),
});

export function applyLayout(doc, slideId, layout, opts = {}) {
  const slide = doc.slides.find((s) => s.id === String(slideId));
  const empty = { ops: [], report: { matched: [], added: [], unmatched: [] } };
  if (!slide || !layout || !Array.isArray(layout.ph)) return empty;
  const deck = doc.deck;
  const ops = [];
  const report = { matched: [], added: [], unmatched: [] };
  const taken = new Set();
  const free = slide.els.filter((e) => !e.lock);
  const assign = new Map(); // placeholder index -> element
  const accepts = (ph, el) => (ACCEPTS[ph.type] || ACCEPTS.text).includes(el.type);

  // pass 1: by role
  layout.ph.forEach((ph, i) => {
    const cands = free.filter((e) => e.role === ph.role && !taken.has(e.id) && accepts(ph, e)).sort(readingOrder);
    if (cands.length) { assign.set(i, cands[0]); taken.add(cands[0].id); }
  });
  // pass 2: by type and z-order among elements without a role the layout knows
  layout.ph.forEach((ph, i) => {
    if (assign.has(i)) return;
    const el = free.find((e) => !taken.has(e.id) && !e.role && accepts(ph, e));
    if (el) { assign.set(i, el); taken.add(el.id); }
  });

  layout.ph.forEach((ph, i) => {
    const el = assign.get(i);
    const box = px(ph, deck);
    if (el) {
      const p = {};
      for (const k of ['x', 'y', 'w', 'h']) if (el[k] !== box[k]) p[k] = box[k];
      if (el.rot) p.rot = 0;
      if (!el.role) p.role = ph.role;
      if (Object.keys(p).length) ops.push({ op: 'el.set', slide: slide.id, id: el.id, p });
      report.matched.push({ id: el.id, role: ph.role });
    }
  });

  if (opts.addMissing !== false) {
    const used = usedIds(slide);
    layout.ph.forEach((ph, i) => {
      if (assign.has(i)) return;
      const id = uniqueId(used, 'e', opts.rng);
      used.add(id);
      const box = px(ph, deck);
      const style = ph.style || {};
      let el;
      if (ph.type === 'text') {
        el = newText(deck, {
          id, role: ph.role, text: ph.text ?? PROMPTS[ph.role] ?? 'Text', origin: 'template', ...box, ...style,
          ...(ph.sizeF ? { size: round2(ph.sizeF * deck.h) } : {}),
          ...(ph.role === 'title' && !style.font ? { font: '@heading', weight: deck.theme?.t?.hw ?? 700 } : {}),
          ...(ph.name ? { name: ph.name } : {}),
        });
      } else {
        el = {
          id, type: 'shape', kind: 'rect', role: ph.role, origin: 'template', name: ph.name || (ph.type === 'media' ? 'Media' : 'Shape'),
          ...box, rot: 0, op: 1, lock: false, hide: false, fill: '@surface', sw: 0, radius: 0, dash: 'solid',
        };
      }
      ops.push({ op: 'el.add', slide: slide.id, el });
      report.added.push({ id, role: ph.role });
    });
  }

  for (const el of slide.els) {
    if (taken.has(el.id)) continue;
    report.unmatched.push({ id: el.id, reason: el.lock ? 'locked' : 'no_placeholder' });
  }

  const slidePatch = {};
  if (slide.layout !== layout.id) slidePatch.layout = layout.id;
  if (layout.bg !== undefined && slide.bg == null && layout.bg != null) slidePatch.bg = layout.bg;
  if (Object.keys(slidePatch).length) ops.unshift({ op: 'slide.set', id: slide.id, ...slidePatch });
  return { ops, report };
}

function inferRole(el, textRank) {
  if (el.role) return el.role;
  if (el.type === 'text') return textRank === 0 ? 'title' : 'body';
  if (['image', 'video', 'chart', 'table'].includes(el.type)) return 'media';
  return null;
}

export function saveLayoutFromSlide(slide, deck, { id, name } = {}) {
  const texts = slide.els.filter((e) => e.type === 'text' && !e.hide);
  const rank = new Map();
  // the largest text near the top is the title
  texts.slice().sort((a, b) => (b.size || 0) - (a.size || 0) || a.y - b.y).forEach((e, i) => rank.set(e.id, i));
  const ph = [];
  slide.els.forEach((el) => {
    if (el.hide) return;
    const role = inferRole(el, rank.get(el.id));
    if (!role) return;
    const type = el.type === 'text' ? 'text' : el.type === 'shape' ? 'shape' : 'media';
    const def = {
      role, type,
      x: round2(el.x / deck.w * 10000) / 10000, y: round2(el.y / deck.h * 10000) / 10000,
      w: round2(el.w / deck.w * 10000) / 10000, h: round2(el.h / deck.h * 10000) / 10000,
    };
    if (el.name) def.name = el.name;
    if (type === 'text') {
      const text = docText(el.doc).trim();
      if (text) def.text = text.slice(0, 60);
      if (el.size) def.sizeF = round2(el.size / deck.h * 10000) / 10000;
      const style = {};
      for (const k of ['font', 'weight', 'color', 'align', 'valign', 'fit', 'lh']) if (el[k] !== undefined) style[k] = el[k];
      if (Object.keys(style).length) def.style = style;
    }
    ph.push(def);
  });
  const slug = (name || 'layout').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 24) || 'layout';
  const out = { id: id || `custom:${slug}`, name: name || 'Custom layout', ph };
  if (slide.bg && typeof slide.bg === 'string') out.bg = slide.bg;
  return out;
}

/** Two small definitions used by tests and by development before the real library lands. */
export const SAMPLE_LAYOUTS = {
  titleBody: {
    id: 'bullets', name: 'Title and body',
    ph: [
      { role: 'title', type: 'text', x: 0.06, y: 0.08, w: 0.88, h: 0.16, sizeF: 0.07, style: { font: '@heading' } },
      { role: 'body', type: 'text', x: 0.06, y: 0.3, w: 0.88, h: 0.6, sizeF: 0.04 },
    ],
  },
  twoColumns: {
    id: 'two', name: 'Two columns',
    ph: [
      { role: 'title', type: 'text', x: 0.06, y: 0.08, w: 0.88, h: 0.16, sizeF: 0.07, style: { font: '@heading' } },
      { role: 'body', type: 'text', x: 0.06, y: 0.3, w: 0.42, h: 0.6, sizeF: 0.04 },
      { role: 'body', type: 'text', x: 0.52, y: 0.3, w: 0.42, h: 0.6, sizeF: 0.04 },
    ],
  },
  titleMedia: {
    id: 'image-right', name: 'Title and image',
    ph: [
      { role: 'title', type: 'text', x: 0.06, y: 0.08, w: 0.5, h: 0.2, sizeF: 0.07, style: { font: '@heading' } },
      { role: 'media', type: 'media', x: 0.6, y: 0.08, w: 0.34, h: 0.84 },
    ],
  },
};
