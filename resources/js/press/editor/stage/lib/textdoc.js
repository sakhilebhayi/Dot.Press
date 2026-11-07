/**
 * Text documents for the in place editor. Pure (no DOM, no Tiptap).
 * The allowed shape is spec section 6 text: nodes paragraph, bulletList, orderedList, listItem, hardBreak, text;
 * marks bold, italic, underline, strike, link, textStyle{color, fontSize, fontFamily}.
 *
 *   ALLOWED_NODES, ALLOWED_MARKS
 *   normalizeColor(value)        css colour or token -> '#rrggbb' | '@token' | null
 *   normalizeFontSize(value)     12, '12', '12px', '12.5pt' -> '12px' | null
 *   normalizeFontFamily(value)   a css stack or name -> a known family, '@heading', '@body' or null
 *   sanitizeDoc(doc)             prune to the allowed nodes and marks (what pasted text goes through); never empty
 *   docIsBlank(doc)              no visible text
 *   docSetMark(doc, type, attrs, on)       whole element: turn a mark on or off on every text run
 *   docMarkOn(doc, type)                   true when every text run carries the mark
 *   docSetTextStyle(doc, key, value)       whole element: set (or with null clear) a textStyle attribute on every run
 *   docTextStyleValue(doc, key)            the value shared by every run, else undefined
 *   docEqual(a, b)
 */
import { isHex, isToken, parseColor } from '../../core/theme.js';
import { deepEqual } from '../../core/util.js';
import { safeHref } from '../../../render/doc.js';
import { isKnownFont, FONT_FAMILIES } from '../../../fonts.js';

export const ALLOWED_NODES = ['doc', 'paragraph', 'bulletList', 'orderedList', 'listItem', 'hardBreak', 'text'];
export const ALLOWED_MARKS = ['bold', 'italic', 'underline', 'strike', 'link', 'textStyle'];
const SIMPLE = ['bold', 'italic', 'underline', 'strike'];

const hex2 = (n) => Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2, '0');

export function normalizeColor(value) {
  if (typeof value !== 'string') return null;
  const v = value.trim();
  if (isToken(v)) return v;
  if (isHex(v)) {
    const p = parseColor(v);
    if (!p) return null;
    return p.a < 1 ? `#${hex2(p.r)}${hex2(p.g)}${hex2(p.b)}${hex2(p.a * 255)}` : `#${hex2(p.r)}${hex2(p.g)}${hex2(p.b)}`;
  }
  const m = /^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:[\s,/]+([\d.]+%?))?\s*\)$/i.exec(v);
  if (m) {
    let a = 1;
    if (m[4] != null) a = m[4].endsWith('%') ? parseFloat(m[4]) / 100 : parseFloat(m[4]);
    return `#${hex2(+m[1])}${hex2(+m[2])}${hex2(+m[3])}${a < 1 ? hex2(a * 255) : ''}`;
  }
  return null;
}

export function normalizeFontSize(value) {
  if (value == null || value === '') return null;
  let n;
  if (typeof value === 'number') n = value;
  else {
    const m = /^\s*([\d.]+)\s*(px|pt)?\s*$/i.exec(String(value));
    if (!m) return null;
    n = parseFloat(m[1]) * (m[2] && m[2].toLowerCase() === 'pt' ? 96 / 72 : 1);
  }
  if (!Number.isFinite(n) || n < 1 || n > 5000) return null;
  return `${Math.round(n * 100) / 100}px`;
}

export function normalizeFontFamily(value) {
  if (typeof value !== 'string') return null;
  if (value === '@heading' || value === '@body') return value;
  const first = value.split(',')[0].replace(/["']/g, '').trim();
  if (isKnownFont(first)) return first;
  const hit = FONT_FAMILIES.find((f) => value.includes(f));
  return hit || null;
}

function cleanTextStyle(attrs) {
  const out = {};
  const c = normalizeColor(attrs?.color);
  if (c) out.color = c;
  const s = normalizeFontSize(attrs?.fontSize);
  if (s) out.fontSize = s;
  const f = normalizeFontFamily(attrs?.fontFamily);
  if (f) out.fontFamily = f;
  return out;
}

function cleanMarks(marks) {
  if (!Array.isArray(marks)) return undefined;
  const seen = new Set();
  const out = [];
  for (const m of marks) {
    const t = m?.type;
    if (!ALLOWED_MARKS.includes(t) || seen.has(t)) continue;
    if (SIMPLE.includes(t)) { out.push({ type: t }); seen.add(t); } else if (t === 'link') {
      const href = safeHref(m.attrs?.href);
      if (href) { out.push({ type: 'link', attrs: { href } }); seen.add(t); }
    } else {
      const a = cleanTextStyle(m.attrs);
      if (Object.keys(a).length) { out.push({ type: 'textStyle', attrs: a }); seen.add(t); }
    }
  }
  return out.length ? out : undefined;
}

function inlineOf(nodes) {
  const out = [];
  for (const n of nodes || []) {
    if (!n) continue;
    if (n.type === 'hardBreak') out.push({ type: 'hardBreak' });
    else if (n.type === 'text' && typeof n.text === 'string' && n.text !== '') {
      const marks = cleanMarks(n.marks);
      out.push(marks ? { type: 'text', text: n.text, marks } : { type: 'text', text: n.text });
    } else if (Array.isArray(n.content)) out.push(...inlineOf(n.content));
  }
  return out;
}

function blocksOf(nodes, depth) {
  const out = [];
  if (depth > 8) return out;
  for (const n of nodes || []) {
    if (!n) continue;
    if (n.type === 'paragraph' || n.type === 'heading') {
      const inline = inlineOf(n.content);
      out.push(inline.length ? { type: 'paragraph', content: inline } : { type: 'paragraph' });
    } else if (n.type === 'bulletList' || n.type === 'orderedList') {
      const items = (n.content || []).map((li) => {
        const kids = li?.type === 'listItem' ? blocksOf(li.content, depth + 1) : blocksOf([li], depth + 1);
        return { type: 'listItem', content: kids.length ? kids : [{ type: 'paragraph' }] };
      });
      if (items.length) out.push({ type: n.type, content: items });
    } else if (n.type === 'text' || n.type === 'hardBreak') {
      const inline = inlineOf([n]);
      if (inline.length) out.push({ type: 'paragraph', content: inline });
    } else if (Array.isArray(n.content)) out.push(...blocksOf(n.content, depth + 1));
  }
  return out;
}

export function sanitizeDoc(doc) {
  const blocks = doc && doc.type === 'doc' ? blocksOf(doc.content, 0) : [];
  return { type: 'doc', content: blocks.length ? blocks : [{ type: 'paragraph' }] };
}

function plain(doc) {
  let s = '';
  const walk = (n) => {
    if (!n) return;
    if (n.type === 'text') s += n.text || '';
    (n.content || []).forEach(walk);
  };
  walk(doc);
  return s;
}

export const docIsBlank = (doc) => plain(doc).trim() === '';

function mapRuns(doc, fn) {
  const walk = (n) => {
    if (n.type === 'text') return fn(n);
    if (!Array.isArray(n.content)) return n;
    const content = n.content.map(walk);
    return content.every((c, i) => c === n.content[i]) ? n : { ...n, content };
  };
  return walk(doc);
}

function runs(doc) {
  const list = [];
  const walk = (n) => {
    if (n.type === 'text') list.push(n);
    (n.content || []).forEach(walk);
  };
  walk(doc);
  return list;
}

const withMarks = (n, marks) => {
  const { marks: _m, ...rest } = n;
  return marks.length ? { ...rest, marks } : rest;
};

export function docMarkOn(doc, type) {
  const r = runs(doc);
  return r.length > 0 && r.every((n) => (n.marks || []).some((m) => m.type === type));
}

export function docSetMark(doc, type, attrs, on) {
  return mapRuns(doc, (n) => {
    const rest = (n.marks || []).filter((m) => m.type !== type);
    return withMarks(n, on ? [...rest, attrs ? { type, attrs } : { type }] : rest);
  });
}

export function docTextStyleValue(doc, key) {
  const r = runs(doc);
  if (!r.length) return undefined;
  const vals = r.map((n) => (n.marks || []).find((m) => m.type === 'textStyle')?.attrs?.[key]);
  return vals.every((v) => v === vals[0]) ? vals[0] : undefined;
}

export function docSetTextStyle(doc, key, value) {
  return mapRuns(doc, (n) => {
    const marks = n.marks || [];
    const cur = marks.find((m) => m.type === 'textStyle');
    const attrs = { ...(cur?.attrs || {}) };
    if (value == null) delete attrs[key]; else attrs[key] = value;
    const rest = marks.filter((m) => m.type !== 'textStyle');
    return withMarks(n, Object.keys(attrs).length ? [...rest, { type: 'textStyle', attrs }] : rest);
  });
}

export const docEqual = (a, b) => deepEqual(a, b);
