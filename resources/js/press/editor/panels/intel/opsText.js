/**
 * Plain-language reading of document operations (pure, node tested). The intelligence services only ever PROPOSE
 * operations; before one is applied the person sees what it does, in words, from the current document.
 *
 *   normalizeOps(ops)              copies with slide ids and section ids as strings (the server may send numbers)
 *   slideNo(doc, id)               1-based position of a slide, or null
 *   elLabel(el)                    a short name for an element: its name, its text, or its type
 *   elSetChanges(op, doc)          [{key, label, before, after}] for an el.set against the current element
 *   opLines(ops, doc, {max})       [string] one plain sentence per change (slide moves are grouped)
 *   moveSummary(ops, doc)          [string] "Moves slides 4 and 5 after slide 8"
 *   isSafeFix(fix)                 true when applying it only changes how things look or read, never removes or rewrites content
 *   chunkOps(ops, size)            batches that respect the server limit of 200 operations per request
 */
import { docText } from '../../core/doc.js';

const NUM_FIELDS = ['slide', 'after'];
const SLIDE_OPS = new Set(['slide.set', 'slide.move', 'slide.remove', 'slide.dup']);
const SECTION_OPS = new Set(['section.add', 'section.set', 'section.remove']);
const str = (v) => (typeof v === 'number' ? String(v) : v);

export function normalizeOps(ops) {
  return (Array.isArray(ops) ? ops : []).map((op) => {
    const out = { ...op };
    for (const f of NUM_FIELDS) if (f in out) out[f] = str(out[f]);
    if (SLIDE_OPS.has(out.op) || SECTION_OPS.has(out.op)) out.id = str(out.id);
    if (out.op === 'slide.set' && 'section_id' in out) out.section_id = str(out.section_id);
    if (out.op === 'slide.add' && out.slide && typeof out.slide === 'object' && 'section_id' in out.slide) out.slide = { ...out.slide, section_id: str(out.slide.section_id) };
    if (out.op === 'el.move') { out.from = str(out.from); out.to = str(out.to); }
    return out;
  });
}

export function slideNo(doc, id) {
  if (id === null || id === undefined || !doc) return null;
  const i = doc.slides.findIndex((s) => s.id === String(id));
  return i < 0 ? null : i + 1;
}

const clip = (s, n) => (s.length > n ? `${s.slice(0, n - 1).trimEnd()}...` : s);

export function elLabel(el) {
  if (!el) return 'an element that is no longer there';
  if (el.name) return `"${clip(String(el.name), 32)}"`;
  const t = el.doc ? docText(el.doc).replace(/\s+/g, ' ').trim() : '';
  if (t) return `"${clip(t, 32)}"`;
  return `the ${el.type || 'element'}`;
}

const KEY_WORDS = {
  x: 'left edge', y: 'top edge', w: 'width', h: 'height', rot: 'rotation', op: 'opacity', size: 'font size', font: 'font', weight: 'weight',
  color: 'text colour', fill: 'fill', stroke: 'line colour', sw: 'line width', lh: 'line height', ls: 'letter spacing', ps: 'paragraph spacing',
  align: 'alignment', valign: 'vertical alignment', alt: 'alt text', fit: 'text fitting', pad: 'padding', radius: 'corner radius', hide: 'visibility',
  origin: 'origin', doc: 'text', role: 'role', anim: 'animation', name: 'name', lock: 'lock', shadow: 'shadow', link: 'link', src: 'source', chart: 'chart data',
  title: 'title', notes: 'notes', layout: 'layout', section_id: 'section', hidden: 'visibility', bg: 'background', transition: 'transition',
};
export const keyWord = (k) => KEY_WORDS[k] || k;

function showValue(k, v) {
  if (v === undefined || v === null) return 'none';
  if (k === 'doc') return `"${clip(docText(v).replace(/\s+/g, ' ').trim(), 40)}"`;
  if (typeof v === 'number') return String(Math.round(v * 100) / 100);
  if (typeof v === 'boolean') return v ? 'yes' : 'no';
  if (typeof v === 'string') return v === '' ? 'empty' : clip(v, 40);
  try { return clip(JSON.stringify(v), 40); } catch { return 'changed'; }
}

export function elSetChanges(op, doc) {
  const slide = doc?.slides.find((s) => s.id === String(op.slide));
  const el = slide?.els.find((e) => e.id === op.id);
  return Object.keys(op.p || {}).map((key) => ({
    key,
    label: keyWord(key),
    before: showValue(key, el ? el[key] : undefined),
    after: showValue(key, op.p[key]),
  }));
}

function where(doc, slide) {
  const n = slideNo(doc, slide);
  return n ? `Slide ${n}` : 'A slide';
}

const listWords = (xs) => (xs.length <= 1 ? xs.join('') : `${xs.slice(0, -1).join(', ')} and ${xs[xs.length - 1]}`);

/** The order of slide ids after the slide.move ops are applied to `order` (same rules as the document ops). */
export function orderAfterMoves(order, ops) {
  let cur = order.map(String);
  for (const m of ops) {
    if (m.op !== 'slide.move') continue;
    const id = String(m.id);
    if (!cur.includes(id)) continue;
    cur = cur.filter((x) => x !== id);
    if (m.after === null) cur.unshift(id);
    else {
      const at = cur.indexOf(String(m.after));
      if (at < 0) cur.push(id); else cur.splice(at + 1, 0, id);
    }
  }
  return cur;
}

/** Ids of the longest run of slides that keep their relative order (patience sorting), so the rest are the ones that moved. */
function stayers(before, after) {
  const pos = new Map(after.map((id, i) => [id, i]));
  const seq = before.map((id) => pos.get(id)).map((p, i) => ({ p, i })).filter((x) => x.p !== undefined);
  const tails = [];
  const prev = new Array(seq.length).fill(-1);
  for (let k = 0; k < seq.length; k++) {
    let lo = 0;
    let hi = tails.length;
    while (lo < hi) { const mid = (lo + hi) >> 1; if (seq[tails[mid]].p < seq[k].p) lo = mid + 1; else hi = mid; }
    if (lo > 0) prev[k] = tails[lo - 1];
    tails[lo] = k;
  }
  const keep = new Set();
  let k = tails.length ? tails[tails.length - 1] : -1;
  while (k >= 0) { keep.add(before[seq[k].i]); k = prev[k]; }
  return keep;
}

/** "Moves slides 4 and 5 after slide 8": numbers are positions in the CURRENT deck; only the slides that really move are named. */
export function reorderSummary(before, after) {
  const keep = stayers(before, after);
  const num = new Map(before.map((id, i) => [id, i + 1]));
  const lines = [];
  let run = [];
  const flush = (anchor) => {
    if (!run.length) return;
    const nums = run.map((id) => num.get(id));
    lines.push(`Moves ${nums.length === 1 ? 'slide' : 'slides'} ${listWords(nums.map(String))} ${anchor === null ? 'to the start' : `after slide ${num.get(anchor)}`}`);
    run = [];
  };
  let anchor = null;
  let runAnchor = null;
  after.forEach((id) => {
    if (keep.has(id)) { flush(runAnchor); anchor = id; runAnchor = id; return; }
    if (!run.length) runAnchor = anchor;
    run.push(id);
  });
  flush(runAnchor);
  return lines;
}

export function moveSummary(ops, doc) {
  const before = doc.slides.map((s) => s.id);
  return reorderSummary(before, orderAfterMoves(before, ops));
}

export function opLines(ops, doc, { max = 12 } = {}) {
  const lines = [];
  const moves = ops.filter((o) => o.op === 'slide.move');
  for (const op of ops) {
    switch (op.op) {
      case 'el.set': {
        const slide = doc?.slides.find((s) => s.id === String(op.slide));
        const el = slide?.els.find((e) => e.id === op.id);
        const keys = Object.keys(op.p || {});
        const names = keys.slice(0, 3).map(keyWord).join(', ') + (keys.length > 3 ? ` and ${keys.length - 3} more` : '');
        lines.push(`${where(doc, op.slide)}: change the ${names} of ${elLabel(el)}`);
        break;
      }
      case 'el.add': lines.push(`${where(doc, op.slide)}: add ${op.el?.type === 'text' ? 'a text box' : `a ${op.el?.type || 'element'}`}${op.el?.name ? ` "${clip(op.el.name, 30)}"` : ''}`); break;
      case 'el.remove': {
        const slide = doc?.slides.find((s) => s.id === String(op.slide));
        lines.push(`${where(doc, op.slide)}: remove ${elLabel(slide?.els.find((e) => e.id === op.id))}`);
        break;
      }
      case 'el.order': lines.push(`${where(doc, op.slide)}: change the stacking order`); break;
      case 'slide.set': {
        const keys = Object.keys(op).filter((k) => !['op', 'id'].includes(k));
        lines.push(`${where(doc, op.id)}: change the ${keys.map(keyWord).join(', ')}`);
        break;
      }
      case 'slide.add': {
        const n = op.after === null || op.after === undefined ? 0 : slideNo(doc, op.after);
        const title = op.slide?.title ? ` "${clip(op.slide.title, 40)}"` : '';
        lines.push(n === 0 ? `Add a slide${title} at the start` : n ? `Add a slide${title} after slide ${n}` : `Add a slide${title}`);
        break;
      }
      case 'slide.remove': lines.push(`Remove ${where(doc, op.id).toLowerCase()}`); break;
      case 'slide.move': break;
      case 'section.add': lines.push(`Add the section "${clip(String(op.title || ''), 50)}"`); break;
      case 'section.set': lines.push(`Rename a section to "${clip(String(op.title || ''), 50)}"`); break;
      case 'section.remove': lines.push('Remove a section (its slides stay)'); break;
      case 'deck.set': lines.push(`Change the deck ${Object.keys(op).filter((k) => k !== 'op').map(keyWord).join(', ')}`); break;
      default: lines.push(`Apply ${op.op}`);
    }
  }
  if (moves.length) lines.push(...moveSummary(ops, doc));
  const merged = collapse(lines);
  return merged.length > max ? [...merged.slice(0, max), `and ${merged.length - max} more changes`] : merged;
}

/** Sequences of identical-looking lines ("Slide 3: change the font size of ...") stay; exact duplicates are counted. */
function collapse(lines) {
  const out = [];
  for (const l of lines) {
    const last = out[out.length - 1];
    if (last && last.text === l) last.n += 1; else out.push({ text: l, n: 1 });
  }
  return out.map((o) => (o.n > 1 ? `${o.text} (${o.n} times)` : o.text));
}

const CONTENT_KEYS = new Set(['doc', 'src', 'chart', 'rows', 'link', 'anim']);

export function isSafeFix(fix) {
  const ops = fix?.ops;
  if (!Array.isArray(ops) || !ops.length) return false;
  return ops.every((op) => {
    if (op.op === 'el.set') return Object.keys(op.p || {}).every((k) => !CONTENT_KEYS.has(k) && op.p[k] !== null);
    if (op.op === 'el.order') return true;
    return false;
  });
}

export function chunkOps(ops, size = 150) {
  const out = [];
  for (let i = 0; i < ops.length; i += size) out.push(ops.slice(i, i + size));
  return out;
}

/**
 * What a fix or proposal will do, ready to show. Property changes of elements come back as before and after rows
 * (when there are few enough to read); everything else as plain lines.
 *   -> {rows: [{target, label, before, after}], lines: [string]}
 */
export function fixPreview(ops, doc, { maxRows = 6 } = {}) {
  const list = normalizeOps(ops);
  const sets = list.filter((o) => o.op === 'el.set');
  if (sets.length === list.length && list.length) {
    const rows = [];
    for (const op of sets) {
      const slide = doc?.slides.find((s) => s.id === String(op.slide));
      const el = slide?.els.find((e) => e.id === op.id);
      for (const c of elSetChanges(op, doc)) {
        rows.push({ target: `Slide ${slideNo(doc, op.slide) ?? '?'}, ${elLabel(el)}`, label: c.label, before: c.before, after: c.after });
      }
    }
    if (rows.length <= maxRows) return { rows, lines: [] };
  }
  return { rows: [], lines: opLines(list, doc, { max: 6 }) };
}
