/**
 * Pure logic of the Shape panel (no Vue; node tested).
 *
 *   KIND_ROWS                         the 16 kinds of spec section 6 in three rows, with labels
 *   isLineKind(kind)
 *   kindSwapPatch(el, kind, deck)     the patch that turns a shape into another kind; swapping between a box and a line also moves the
 *                                     colour between `fill` and `stroke`, so nothing vanishes
 *   quickStyles()                     [{id, label, fill, stroke}] theme swatches for fills
 *   quickStylePatch(el, style, deck)  patch that applies one
 *   fillMode(fill)                    'none' | 'solid' | 'gradient' | 'mixed'
 *   fillForMode(mode, current, deck)  the fill value a mode switch starts from (keeps the last colour)
 *   defaultShadow(deck)               {x, y, blur, c} scaled to the deck width
 *   defaultStrokeWidth(deck)
 *   ENDS, ENDS_START, DASHES          option lists
 *   endsPatch(el, which, value)       the whole `ends` object with one end changed
 */
const r2 = (n) => Math.round(n * 100) / 100;
const unit = (deck) => (deck?.w || 1920) / 1920;

export const KIND_ROWS = [
  { id: 'basic', label: 'Basic shapes', kinds: [['rect', 'Rectangle'], ['round', 'Rounded rectangle'], ['ellipse', 'Ellipse'], ['triangle', 'Triangle'], ['diamond', 'Diamond'], ['pentagon', 'Pentagon'], ['hexagon', 'Hexagon']] },
  { id: 'more', label: 'More shapes', kinds: [['star', 'Star'], ['chevron', 'Chevron'], ['arrow-r', 'Arrow right'], ['arrow-l', 'Arrow left'], ['callout', 'Callout'], ['cross', 'Cross'], ['heart', 'Heart']] },
  { id: 'lines', label: 'Lines', kinds: [['line', 'Line'], ['arrow', 'Arrow line']] },
].map((row) => ({ ...row, options: row.kinds.map(([value, title]) => ({ value, icon: value, title })) }));

export const ALL_KINDS = KIND_ROWS.flatMap((r) => r.kinds.map(([k]) => k));
export const isLineKind = (kind) => kind === 'line' || kind === 'arrow';

export const defaultStrokeWidth = (deck) => r2((deck?.theme?.s?.stroke ?? 2) * unit(deck));
export const defaultShadow = (deck) => ({ x: 0, y: r2(8 * unit(deck)), blur: r2(24 * unit(deck)), c: '#00000040' });

export function kindSwapPatch(el, kind, deck) {
  if (!ALL_KINDS.includes(kind) || el.kind === kind) return null;
  const toLine = isLineKind(kind);
  const wasLine = isLineKind(el.kind);
  const p = { kind };
  if (toLine && !wasLine) {
    p.stroke = typeof el.stroke === 'string' && el.stroke ? el.stroke : (typeof el.fill === 'string' ? el.fill : '@ink');
    p.sw = el.sw > 0 ? el.sw : r2(defaultStrokeWidth(deck) * 2);
    p.fill = null;
    p.radius = null;
    p.h = 0;
    p.ends = { s: 'none', e: kind === 'arrow' ? 'arrow' : 'none' };
    p.dash = el.dash || 'solid';
  } else if (!toLine && wasLine) {
    p.fill = typeof el.stroke === 'string' && el.stroke ? el.stroke : '@accent';
    p.stroke = null;
    p.sw = 0;
    p.ends = null;
    p.h = el.h > 0 ? el.h : r2(Math.max(24 * unit(deck), (el.w || 0) * 0.6));
  } else if (toLine && wasLine) {
    p.ends = kind === 'arrow'
      ? { s: el.ends?.s || 'none', e: el.ends?.e && el.ends.e !== 'none' ? el.ends.e : 'arrow' }
      : { s: el.ends?.s || 'none', e: el.kind === 'arrow' && el.ends?.e === 'arrow' ? 'none' : el.ends?.e || 'none' };
  }
  if (!toLine) {
    if (kind === 'rect') p.radius = el.radius > 0 ? el.radius : r2((deck?.theme?.s?.radius ?? 12) * unit(deck));
    else if (el.radius) p.radius = null;
  }
  return p;
}

export function quickStyles() {
  return [
    { id: 'accent', label: 'Accent', fill: '@accent', stroke: null },
    { id: 'accent2', label: 'Highlight', fill: '@accent2', stroke: null },
    { id: 'surface', label: 'Surface', fill: '@surface', stroke: '@line' },
    { id: 'ink', label: 'Ink', fill: '@ink', stroke: null },
    { id: 'outline', label: 'Outline', fill: null, stroke: '@ink' },
  ];
}

export function quickStylePatch(el, style, deck) {
  const sw = el.sw > 0 ? el.sw : defaultStrokeWidth(deck);
  if (isLineKind(el.kind)) {
    // a line has one colour: the style's strongest one
    return { stroke: style.stroke || style.fill || '@ink', sw };
  }
  return style.stroke
    ? { fill: style.fill, stroke: style.stroke, sw }
    : { fill: style.fill, stroke: null, sw: 0 };
}

export function fillMode(fill) {
  if (fill === 'mixed') return 'mixed';
  if (fill && typeof fill === 'object' && fill.g) return 'gradient';
  return fill ? 'solid' : 'none';
}

export function fillForMode(mode, current) {
  const solid = typeof current === 'string' && current !== 'mixed' ? current : null;
  const firstStop = current && typeof current === 'object' && Array.isArray(current.stops) ? current.stops[0]?.[1] : null;
  if (mode === 'none') return null;
  if (mode === 'solid') return solid || firstStop || '@accent';
  if (mode === 'gradient') {
    if (current && typeof current === 'object' && current.g) return current;
    return { g: 'linear', a: 90, stops: [[0, solid || '@accent'], [1, solid === '@accent2' ? '@accent' : '@accent2']] };
  }
  return current;
}

export const ENDS = [
  { value: 'none', icon: 'p-endNone', title: 'No end' },
  { value: 'arrow', icon: 'p-endArrow', title: 'Arrow' },
  { value: 'dot', icon: 'p-endDot', title: 'Dot' },
];
export const ENDS_START = [
  { value: 'none', icon: 'p-endNone', title: 'No start' },
  { value: 'arrow', icon: 'p-startArrow', title: 'Arrow' },
  { value: 'dot', icon: 'p-startDot', title: 'Dot' },
];
export const DASHES = [
  { value: 'solid', icon: 'p-dashSolid', title: 'Solid' },
  { value: 'dash', icon: 'p-dashDash', title: 'Dashed' },
  { value: 'dot', icon: 'p-dashDot', title: 'Dotted' },
];

export function endsPatch(el, which, value) {
  const base = el.ends && typeof el.ends === 'object' ? el.ends : {};
  const kindDefaultE = el.kind === 'arrow' && !el.ends ? 'arrow' : 'none';
  return { s: base.s || 'none', e: base.e || kindDefaultE, [which]: value };
}
