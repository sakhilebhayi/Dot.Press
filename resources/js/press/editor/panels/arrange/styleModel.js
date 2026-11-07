/**
 * Copy style / paste style (pure; node tested). A "style" is the look of an element without its content or position.
 *
 *   pickStyle(el)                      {from: 'text'|'shape'|..., line: bool, hasText: bool, props: {...}} or null for kinds with no style
 *   pasteStylePatches(style, els)      [{id, p}] that give each element the look; keys it cannot have are left alone, keys the
 *                                      source did not have are removed on elements of the same family (box or line)
 *   describeStyle(style)               'Fill, stroke, shadow and opacity' for the paste button
 *
 * Families: a box (text, rect, ellipse ...) takes fill, stroke, sw, radius; a line takes stroke, sw, dash. Shadow and opacity go
 * to everything. Text keys (font, size, weight, color, line height, spacing, effect) go to texts and shapes that hold a doc, and
 * only when the source had text. A line is never given a fill and a box never a dash, so a line cannot disappear by pasting a
 * rectangle's look onto it.
 */
import { isTextual } from '../text/textModel.js';

const BOX_KEYS = ['fill', 'stroke', 'sw', 'radius'];
const LINE_KEYS = ['stroke', 'sw', 'dash'];
const ALL_KEYS = ['shadow', 'op'];
const TEXT_KEYS = ['font', 'size', 'weight', 'color', 'lh', 'ls', 'ps', 'fx'];
const STYLED = new Set(['text', 'shape', 'image', 'icon', 'chart', 'table', 'video', 'audio']);

const isLine = (el) => el?.type === 'shape' && (el.kind === 'line' || el.kind === 'arrow');
const copy = (v) => (v && typeof v === 'object' ? JSON.parse(JSON.stringify(v)) : v);
const same = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);

export function pickStyle(el) {
  if (!el || !STYLED.has(el.type)) return null;
  const line = isLine(el);
  const hasText = isTextual(el);
  const keys = [...(line ? LINE_KEYS : (el.type === 'text' || el.type === 'shape') ? BOX_KEYS : []), ...ALL_KEYS, ...(hasText ? TEXT_KEYS : [])];
  const props = {};
  for (const k of keys) if (el[k] !== undefined && el[k] !== null) props[k] = copy(el[k]);
  return { from: el.type, line, hasText, box: !line && (el.type === 'text' || el.type === 'shape'), props };
}

export function pasteStylePatches(style, els) {
  if (!style) return [];
  const out = [];
  for (const el of els) {
    const line = isLine(el);
    const box = !line && (el.type === 'text' || el.type === 'shape');
    const keys = [];
    if (box && style.box) keys.push(...BOX_KEYS);
    if (line && style.line) keys.push(...LINE_KEYS);
    // a line's colour is its stroke: a box look gives a line its stroke colour and width too
    if (line && style.box) keys.push('stroke', 'sw');
    if (box && style.line) keys.push('stroke', 'sw');
    keys.push(...ALL_KEYS);
    if (isTextual(el) && style.hasText) keys.push(...TEXT_KEYS);
    const p = {};
    for (const k of new Set(keys)) {
      let v = style.props[k];
      // a box's fill becomes the line colour when the target is a line and the source box had no stroke
      if (line && style.box && k === 'stroke' && v === undefined) v = typeof style.props.fill === 'string' ? style.props.fill : undefined;
      if (line && style.box && k === 'sw' && v === undefined) v = el.sw;
      const next = v === undefined ? null : copy(v);
      if (!same(el[k], next)) p[k] = next;
    }
    if (Object.keys(p).length) out.push({ id: el.id, p });
  }
  return out;
}

export function describeStyle(style) {
  if (!style) return '';
  const parts = [];
  if (style.props.fill !== undefined) parts.push('fill');
  if (style.props.stroke !== undefined) parts.push('stroke');
  if (style.hasText) parts.push('font');
  if (style.props.shadow) parts.push('shadow');
  if (style.props.op !== undefined && style.props.op !== 1) parts.push('opacity');
  if (!parts.length) return 'plain style';
  const s = parts.join(', ').replace(/, ([^,]*)$/, ' and $1');
  return s.charAt(0).toUpperCase() + s.slice(1);
}
