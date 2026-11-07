/**
 * Turns the layout-free content model of an outline slide (title, bullets, quote, steps, figures, chart, ...) into the
 * fields of a `slide.add` operation (pure, node tested). There is no server endpoint for this, so the composition
 * lives here. It uses the deck's own theme tokens ("@ink", "@heading"...) and the geometry of the layout library
 * (GET /api/layout-library: placeholders as fractions of the slide), so a generated slide matches the layouts a person
 * can choose and follows later theme changes.
 *
 *   composeSlide(model, {deck, layout, ids})  -> {title, notes, layout, els}
 *     model   {title, kind, slots: {title, subtitle, bullets, quote, attribution, left, right, steps, kpis, chart,
 *             imageHint, leftTitle, rightTitle}, notes, needs: [{slot, label, question}], origin: 'local'|'ai'}
 *     deck    {w, h, theme}
 *     layout  a library definition {id, ph: [{role, type, key, x, y, w, h, size?, sizeF?, name?, style?}]} or null
 *     ids     a Set of element ids already used in the new slide (kept up to date), or omitted
 *   composeInsert(models, {deck, layouts, afterSlide, makeTid}) -> slide.add ops, one per model, chained in order
 *
 * Rules (the SlideComposer interface of the outline service): origin "ai" on every element the proposal wrote when the
 * model's origin is ai; nothing is added that is not in the model; each `need` is a visible text slot reading its label
 * and question; a picture slot becomes a labelled placeholder shape, never an invented picture.
 */
import { newChart, newText, textDoc } from '../../core/doc.js';
import { uniqueId } from '../../core/util.js';

export const FALLBACK_LAYOUTS = {
  bullets: { id: 'bullets', ph: [
    { role: 'title', type: 'text', key: 'title', x: 0.0625, y: 0.09, w: 0.875, h: 0.17, sizeF: 0.056, style: { font: '@heading', fit: 'shrink', lh: 1.1 } },
    { role: 'body', type: 'text', key: 'body', x: 0.0625, y: 0.3, w: 0.875, h: 0.6, sizeF: 0.036, style: { fit: 'shrink', lh: 1.3, ps: 0.6 } },
  ] },
};

const round = (n) => Math.round(n * 100) / 100;

/** blocks: [{text, list: 'bullet'|'ordered'|null}] -> a text document with real lists. */
export function buildDoc(blocks) {
  const content = [];
  let list = null;
  for (const b of blocks) {
    const text = String(b.text ?? '');
    const para = text === '' ? { type: 'paragraph' } : { type: 'paragraph', content: [{ type: 'text', text }] };
    if (b.list) {
      const kind = b.list === 'ordered' ? 'orderedList' : 'bulletList';
      if (!list || list.type !== kind) { list = { type: kind, content: [] }; content.push(list); }
      list.content.push({ type: 'listItem', content: [para] });
    } else {
      list = null;
      content.push(para);
    }
  }
  return { type: 'doc', content: content.length ? content : [{ type: 'paragraph' }] };
}

const bullets = (xs) => xs.map((text) => ({ text, list: 'bullet' }));
const clean = (xs) => (Array.isArray(xs) ? xs.map((x) => String(x ?? '').trim()).filter(Boolean) : []);

function placeholders(layout) {
  const by = {};
  for (const p of layout?.ph || []) (by[p.role] ||= []).push(p);
  return by;
}

export function composeSlide(model, { deck, layout = null, ids = new Set() } = {}) {
  const slots = { ...(model.slots || {}) };
  const kind = model.kind || 'bullets';
  const title = String(slots.title || model.title || '').trim();
  const origin = model.origin === 'ai' ? 'ai' : null;
  const out = { title, notes: String(model.notes || ''), layout: kind, els: [] };
  if (kind === 'blank') return out;

  const def = layout || FALLBACK_LAYOUTS[kind] || FALLBACK_LAYOUTS.bullets;
  const by = placeholders(def);
  const used = new Set();
  const W = deck.w;
  const H = deck.h;
  const id = (hint) => { const v = uniqueId(ids, hint || 'e'); ids.add(v); return v; };
  const box = (ph) => ({ x: round(ph.x * W), y: round(ph.y * H), w: round(ph.w * W), h: round(ph.h * H) });

  const text = (ph, blocks, extra = {}) => {
    if (!ph || !blocks.length) return false;
    used.add(ph);
    const style = ph.style || {};
    const size = ph.sizeF ? round(ph.sizeF * H) : ph.size ? round(ph.size * (H / 1080)) : undefined;
    const el = newText(deck, { id: id('e'), role: ph.role, text: '', ...box(ph), ...style, ...(size ? { size } : {}), ...(ph.name ? { name: ph.name } : {}), ...extra });
    el.doc = buildDoc(blocks);
    if (origin) el.origin = origin;
    out.els.push(el);
    return true;
  };
  const picture = (ph) => {
    if (!ph) return;
    used.add(ph);
    const hint = String(slots.imageHint || '').trim();
    out.els.push({
      id: id('e'), type: 'shape', kind: 'rect', role: 'media', origin: 'template', name: hint ? `Picture: ${hint}` : 'Picture', ...box(ph), rot: 0, op: 1, lock: false, hide: false,
      fill: '@surface', sw: 0, radius: 0, dash: 'solid', alt: hint ? `Placeholder for a picture: ${hint}` : 'Placeholder for a picture',
    });
  };
  const chart = (ph) => {
    if (!ph || !slots.chart) return false;
    used.add(ph);
    const c = slots.chart;
    const el = newChart(deck, { id: id('e'), chartType: c.type, data: c.data, ...box(ph) });
    const opt = { ...el.chart.opt, ...(c.opt || {}) };
    if (!(c.opt && 'dec' in c.opt)) opt.dec = decimalsOf(c.data);
    el.chart = { ...el.chart, opt, src: c.src || el.chart.src };
    el.alt = chartAlt(c);
    if (origin) el.origin = origin;
    out.els.push(el);
    return true;
  };

  const one = (role, i = 0) => by[role]?.[i];
  const titleBlocks = title ? [{ text: title, list: null }] : [];
  const sub = clean([slots.subtitle]);
  const bl = clean(slots.bullets);

  if (kind === 'image-full') picture(one('media'));
  else if (['image-left', 'image-right'].includes(kind) || (kind === 'data' && !slots.chart && one('media'))) picture(one('media'));

  text(one('title'), titleBlocks);

  switch (kind) {
    case 'title':
    case 'section':
      text(one('subtitle'), sub.map((t) => ({ text: t, list: null })));
      break;
    case 'closing':
      text(one('subtitle'), sub.map((t) => ({ text: t, list: null })));
      text(one('body'), bullets(bl));
      break;
    case 'image-full':
      text(one('caption'), sub.map((t) => ({ text: t, list: null })));
      break;
    case 'two': {
      const half = Math.ceil(bl.length / 2);
      text(one('body', 0), bullets(bl.slice(0, half)));
      text(one('body', 1), bullets(bl.slice(half)));
      break;
    }
    case 'compare': {
      const sides = [[slots.leftTitle, slots.left], [slots.rightTitle, slots.right]];
      sides.forEach(([head, list], i) => {
        text(one('subtitle', i), clean([head]).map((t) => ({ text: t, list: null })));
        text(one('body', i), bullets(clean(list)));
      });
      break;
    }
    case 'timeline': {
      const steps = clean(slots.steps);
      const slotsN = (by.body || []).length || 1;
      const lead = steps.slice(0, slotsN - 1);
      const rest = steps.slice(slotsN - 1);
      lead.forEach((s, i) => text(one('body', i), [{ text: s, list: null }]));
      if (rest.length) text(one('body', slotsN - 1), rest.map((s) => ({ text: s, list: rest.length > 1 ? 'ordered' : null })));
      break;
    }
    case 'quote':
      text(one('body'), clean([slots.quote]).map((t) => ({ text: t, list: null })));
      text(one('caption'), clean([slots.attribution]).map((t) => ({ text: t, list: null })));
      break;
    case 'data':
      chart(one('media'));
      text(one('body'), bullets(bl));
      break;
    case 'kpi': {
      (slots.kpis || []).slice(0, (by.kpi || []).length || 4).forEach((k, i) => {
        text(one('kpi', i), [{ text: String(k.value), list: null }]);
        const cap = [k.label, k.delta ? `(${k.delta})` : ''].filter(Boolean).join(' ');
        text(one('caption', i), cap ? [{ text: cap, list: null }] : []);
      });
      text(one('body'), bullets(bl));
      break;
    }
    default:
      text(one('subtitle'), sub.map((t) => ({ text: t, list: null })));
      text(one('body'), bullets(bl));
  }

  // Slots the source had nothing for stay visible as questions, never as invented content.
  for (const n of model.needs || []) {
    const blocks = [{ text: n.label || 'Needs your evidence', list: null }, ...(n.question ? [{ text: n.question, list: null }] : [])];
    const free = (by.body || []).find((p) => !used.has(p));
    if (free) text(free, blocks, { color: '@muted', name: 'Needs your evidence' });
    else {
      const ph = { role: 'body', x: 0.0625, y: 0.8, w: 0.875, h: 0.1, sizeF: 0.03, style: { fit: 'shrink' } };
      text(ph, blocks, { color: '@muted', name: 'Needs your evidence' });
    }
  }
  return out;
}

/** Decimal places the numbers really have (0 to 3), so 5.5 is never drawn as 6. */
export function decimalsOf(data) {
  let max = 0;
  for (const s of data?.series || []) {
    for (const v of s.values || []) {
      if (typeof v !== 'number' || !Number.isFinite(v)) continue;
      const m = /\.(\d+)$/.exec(String(v));
      if (m) max = Math.max(max, Math.min(3, m[1].length));
    }
  }
  return max;
}

function chartAlt(c) {
  const d = c.data || {};
  const cats = d.categories || [];
  const ser = d.series?.[0];
  if (!ser || !cats.length) return '';
  const pairs = cats.slice(0, 8).map((cat, i) => (ser.values?.[i] != null ? `${cat} ${ser.values[i]}` : null)).filter(Boolean);
  return `${String(c.type || 'chart').replace(/^./, (m) => m.toUpperCase())} chart. ${pairs.join(', ')}.`.slice(0, 400);
}

/**
 * slide.add operations that insert the models after `afterSlide` in order, each after the one before. `layouts` is the
 * library list; `makeTid(i)` supplies temporary slide ids (not all digits).
 */
export function composeInsert(models, { deck, layouts = [], afterSlide = null, makeTid }) {
  const byId = new Map((layouts || []).map((l) => [l.id, l]));
  const ops = [];
  let after = afterSlide;
  models.forEach((m, i) => {
    const tid = makeTid(i);
    const slide = composeSlide(m, { deck, layout: byId.get(m.kind) || null });
    ops.push({ op: 'slide.add', tid, after, slide });
    after = tid;
  });
  return ops;
}
