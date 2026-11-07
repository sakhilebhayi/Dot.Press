/**
 * Pure logic of the Text panel (no Vue, no DOM; node tested).
 *
 *   isTextual(el)                       text elements and shapes that carry a `doc`
 *   presetDefs(deck)                    the five theme driven style presets, resolved to the deck's size and theme
 *   resolvePreset(id, deck)             one of them or null
 *   presetPatches(els, preset)          [{id, p}] that put the preset on elements (inline size, colour and font overrides are cleared)

 *   styleOfElement(el)                  {font, size, weight, color} of one text, to restyle others like it
 *   presetOfSelection(els)              the preset id whose role every element has, 'mixed', or null
 *   bulkRolePlan(slides, role, preset)  what "apply to every text with the same role" would change: {ops, matching, changing, slides, locked}
 *   weightOptions(font, theme, current) weights the chosen family can draw, named
 *   fittedSize(size, factor)            the size a shrink-to-fit box really draws
 *   docListKind(doc)                    'none' | 'bullet' | 'ordered' | 'mixed'
 *   docSetList(doc, kind)               whole document to a bullet list, a numbered list or plain paragraphs
 *   docIndent(doc, dir)                 whole list one level deeper (+1) or shallower (-1)
 *   docIndentDepth(doc)                 the deepest list nesting (0 for no list)
 */
import { docSetTextStyle } from '../../stage/lib/textdoc.js';
import { FONT_BY_FAMILY } from '../../../fonts.js';

export const isTextual = (el) => !!el && (el.type === 'text' || (el.type === 'shape' && !!el.doc));

const r2 = (n) => Math.round(n * 100) / 100;

/** The preset table: sizes are for a 1920 wide deck and scale with the deck width and the theme type scale. */
const BASE = [
  { id: 'title', label: 'Title', font: '@heading', size: 88, weight: 'hw', color: '@ink', role: 'title' },
  { id: 'heading', label: 'Heading', font: '@heading', size: 56, weight: 'hw', color: '@ink', role: 'subtitle' },
  { id: 'body', label: 'Body', font: '@body', size: 36, weight: 400, color: '@ink', role: 'body' },
  { id: 'caption', label: 'Caption', font: '@body', size: 24, weight: 400, color: '@muted', role: 'caption' },
  // The document has no "quote" layout role (spec section 6), so the Quote style leaves `role` alone.
  { id: 'quote', label: 'Quote', font: '@heading', size: 52, weight: 400, color: '@ink', role: null },
];

export function presetDefs(deck) {
  const u = (deck?.w || 1920) / 1920;
  const theme = deck?.theme || {};
  const scale = Number.isFinite(+theme.t?.scale) && +theme.t.scale > 0 ? +theme.t.scale : 1;
  const hw = Number.isFinite(+theme.t?.hw) ? Math.min(900, Math.max(100, +theme.t.hw)) : 700;
  return BASE.map((b) => {
    const patch = { font: b.font, size: r2(b.size * u * scale), weight: b.weight === 'hw' ? hw : b.weight, color: b.color };
    if (b.role) patch.role = b.role;
    return { id: b.id, label: b.label, role: b.role, patch };
  });
}

export const resolvePreset = (id, deck) => presetDefs(deck).find((p) => p.id === id) || null;

/** The inline overrides a preset replaces, so the preset is not hidden by a run level font, size or colour. */
function clearRuns(el) {
  if (!el.doc) return null;
  let doc = el.doc;
  for (const key of ['fontFamily', 'fontSize', 'color']) doc = docSetTextStyle(doc, key, null);
  return JSON.stringify(doc) === JSON.stringify(el.doc) ? null : doc;
}

function samePatch(el, p) {
  return Object.keys(p).every((k) => JSON.stringify(el[k] ?? null) === JSON.stringify(p[k] ?? null));
}

/** The look of one text as a preset patch: font, size, weight and colour (a missing value is carried as null so it is removed elsewhere). */
export function styleOfElement(el) {
  return { font: el.font ?? null, size: el.size ?? null, weight: el.weight ?? null, color: el.color ?? null };
}

export function presetPatches(els, preset) {
  const out = [];
  for (const el of els) {
    if (!isTextual(el)) continue;
    const p = { ...preset.patch };
    const doc = clearRuns(el);
    if (doc) p.doc = doc;
    if (samePatch(el, p)) continue;
    out.push({ id: el.id, p });
  }
  return out;
}

export function presetOfSelection(els) {
  const list = els.filter(isTextual);
  if (!list.length) return null;
  const roles = new Set(list.map((e) => e.role || ''));
  if (roles.size > 1) return 'mixed';
  const role = [...roles][0];
  if (!role) return null;
  return BASE.find((b) => b.role === role)?.id || null;
}

/**
 * Every text of the deck with `role`, as el.set ops for `preset`. Locked texts are counted but never touched, and a text that
 * already matches is counted as matching but produces no op. The caller shows `changing`, `slides` and `locked` and asks first.
 */
export function bulkRolePlan(slides, role, preset) {
  const ops = [];
  let matching = 0;
  let locked = 0;
  const touched = new Set();
  if (!role) return { ops, matching, changing: 0, slides: 0, locked };
  for (const s of slides || []) {
    for (const el of s.els || []) {
      if (!isTextual(el) || el.role !== role) continue;
      matching += 1;
      if (el.lock) { locked += 1; continue; }
      const patches = presetPatches([el], preset);
      if (!patches.length) continue;
      ops.push({ op: 'el.set', slide: s.id, id: el.id, p: patches[0].p });
      touched.add(s.id);
    }
  }
  return { ops, matching, changing: ops.length, slides: touched.size, locked };
}

const WEIGHT_NAMES = { 100: 'Thin', 200: 'Extra light', 300: 'Light', 400: 'Regular', 500: 'Medium', 600: 'Semibold', 700: 'Bold', 800: 'Extra bold', 900: 'Black' };

/** The weights a family can draw, 100 to 900 in hundreds inside its range; the current weight is always offered. */
export function weightOptions(font, theme, current) {
  let family = font;
  if (font === '@heading') family = theme?.t?.heading;
  else if (font === '@body' || !font) family = theme?.t?.body;
  const range = FONT_BY_FAMILY[family]?.weights || [100, 900];
  const values = [];
  for (let w = 100; w <= 900; w += 100) if (w >= range[0] && w <= range[1]) values.push(w);
  const cur = Number.isFinite(+current) ? Math.round(+current) : null;
  if (cur && !values.includes(cur)) { values.push(cur); values.sort((a, b) => a - b); }
  return values.map((value) => ({ value, label: WEIGHT_NAMES[value] || `Weight ${value}`, hint: String(value) }));
}

export function fittedSize(size, factor) {
  const f = Number.isFinite(+factor) && +factor > 0 ? Math.min(1, +factor) : 1;
  return Math.round(size * f * 10) / 10;
}

// ---- lists in a text document ---------------------------------------------------------------------------------------

const LISTS = new Set(['bulletList', 'orderedList']);

export function docListKind(doc) {
  const blocks = doc?.content || [];
  if (!blocks.length) return 'none';
  const kinds = new Set(blocks.map((b) => (b.type === 'bulletList' ? 'bullet' : b.type === 'orderedList' ? 'ordered' : 'none')));
  if (kinds.size > 1) return 'mixed';
  return [...kinds][0];
}

/** Flatten list nodes to their paragraphs (nested lists too). */
function unlist(node) {
  if (!LISTS.has(node.type)) return [node];
  const out = [];
  for (const li of node.content || []) {
    for (const child of li.content || []) out.push(...unlist(child));
  }
  return out.length ? out : [{ type: 'paragraph' }];
}

export function docSetList(doc, kind) {
  if (!doc || doc.type !== 'doc') return doc;
  const blocks = doc.content || [];
  if (kind !== 'bullet' && kind !== 'ordered') return { ...doc, content: blocks.flatMap(unlist) };
  const type = kind === 'bullet' ? 'bulletList' : 'orderedList';
  const flat = blocks.flatMap(unlist);
  return { ...doc, content: [{ type, content: flat.map((p) => ({ type: 'listItem', content: [p] })) }] };
}

export function docIndentDepth(doc) {
  const depth = (n) => (LISTS.has(n.type) ? 1 + Math.max(0, ...(n.content || []).flatMap((li) => (li.content || []).map(depth))) : 0);
  return Math.max(0, ...(doc?.content || []).map(depth));
}

const MAX_DEPTH = 5;

/** Whole list one level in (+1: the list moves inside a single item of a list of the same kind) or out (-1: the wrapper is removed). */
export function docIndent(doc, dir) {
  if (!doc || doc.type !== 'doc') return doc;
  const content = (doc.content || []).map((b) => {
    if (!LISTS.has(b.type)) return b;
    if (dir > 0) {
      let depth = 0;
      let n = b;
      while (n && LISTS.has(n.type)) { depth += 1; n = n.content?.length === 1 ? n.content[0].content?.[0] : null; }
      if (depth >= MAX_DEPTH) return b;
      return { type: b.type, content: [{ type: 'listItem', content: [b] }] };
    }
    const only = b.content?.length === 1 ? b.content[0] : null;
    const inner = only?.content?.length === 1 ? only.content[0] : null;
    return inner && LISTS.has(inner.type) ? inner : b;
  });
  return { ...doc, content };
}
