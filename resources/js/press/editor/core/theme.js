/**
 * Themes, colour tokens and fonts (spec sections 2 and 3). Pure.
 *
 * Public API
 *   DEFAULT_THEME                         the "slate" theme from the spec
 *   COLOR_TOKENS                          ['@bg', '@surface', ... '@chart8']
 *   isToken(c), isHex(c), isGradient(c)
 *   tokenKey(token)                       '@accent' -> 'accent', '@chart3' -> 'chart3'
 *   parseColor(hex) -> {r,g,b,a}          accepts #rgb #rrggbb #rrggbbaa
 *   resolveColor(c, theme)                '@accent' -> '#2563eb'; hex returned as written (lower case); else null
 *   resolveFill(fill, theme)              like resolveColor and also resolves gradient stops
 *   contrastRatio(a, b, theme?)           WCAG 2 contrast ratio of two colours (tokens allowed)
 *   nearestToken(hex, theme)              {token, color, distance} closest theme colour (CIE76 delta E)
 *   resolveFont(font, theme)              '@heading' / '@body' / a family name -> a family name
 *   resolveWeight(el, theme)              the element weight, or the heading weight for '@heading' text
 *   mapColors(el, fn)                     new element with every colour string passed through fn (structural sharing)
 *   mapFonts(el, fn)                      same for font families
 *   usageOf(el)                           {tokens: [...], hex: [...], fonts: [...]} used by an element
 *   applyTheme(doc, theme)                {doc, ops, report}: replaces deck.theme, hex colours untouched
 */
import { isObj } from './util.js';

export const DEFAULT_THEME = {
  id: 'slate',
  name: 'Slate',
  c: {
    bg: '#ffffff', surface: '#f4f5f7', ink: '#111827', muted: '#6b7280', line: '#d1d5db',
    accent: '#2563eb', accent2: '#f59e0b', good: '#15803d', warn: '#b45309', bad: '#b91c1c',
    chart: ['#2563eb', '#f59e0b', '#15803d', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#475569'],
  },
  t: { heading: 'Public Sans', body: 'Public Sans', hw: 700, scale: 1 },
  s: { radius: 12, stroke: 2 },
  bg: null,
};

const SINGLE = ['bg', 'surface', 'ink', 'muted', 'line', 'accent', 'accent2', 'good', 'warn', 'bad'];
export const COLOR_TOKENS = [...SINGLE, ...Array.from({ length: 8 }, (_, i) => `chart${i + 1}`)].map((k) => `@${k}`);
const TOKEN_SET = new Set(COLOR_TOKENS);

export const isToken = (c) => typeof c === 'string' && TOKEN_SET.has(c);
export const isHex = (c) => typeof c === 'string' && /^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(c);
export const isGradient = (c) => isObj(c) && typeof c.g === 'string' && Array.isArray(c.stops);
export const tokenKey = (t) => t.slice(1);

export function parseColor(hex) {
  if (!isHex(hex)) return null;
  let h = hex.slice(1);
  if (h.length === 3) h = h.split('').map((x) => x + x).join('');
  return {
    r: parseInt(h.slice(0, 2), 16),
    g: parseInt(h.slice(2, 4), 16),
    b: parseInt(h.slice(4, 6), 16),
    a: h.length === 8 ? parseInt(h.slice(6, 8), 16) / 255 : 1,
  };
}

function normHex(c) {
  const p = parseColor(c);
  if (!p) return null;
  const h = (n) => n.toString(16).padStart(2, '0');
  return `#${h(p.r)}${h(p.g)}${h(p.b)}${p.a < 1 ? h(Math.round(p.a * 255)) : ''}`;
}

export function resolveColor(c, theme) {
  const th = theme || DEFAULT_THEME;
  if (isToken(c)) {
    const k = tokenKey(c);
    const m = /^chart([1-8])$/.exec(k);
    const v = m ? th.c?.chart?.[Number(m[1]) - 1] : th.c?.[k];
    return normHex(v) || null;
  }
  return isHex(c) ? normHex(c) : null;
}

export function resolveFill(fill, theme) {
  if (isGradient(fill)) {
    return { ...fill, stops: fill.stops.map(([o, c]) => [o, resolveColor(c, theme) || c]) };
  }
  return resolveColor(fill, theme);
}

function luminance(c) {
  const lin = (v) => {
    const s = v / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
}

export function contrastRatio(a, b, theme) {
  const pa = parseColor(resolveColor(a, theme));
  const pb = parseColor(resolveColor(b, theme));
  if (!pa || !pb) return null;
  const la = luminance(pa);
  const lb = luminance(pb);
  const hi = Math.max(la, lb);
  const lo = Math.min(la, lb);
  return Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100;
}

function toLab({ r, g, b }) {
  const f = (v) => { const s = v / 255; return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; };
  const R = f(r); const G = f(g); const B = f(b);
  const x = (R * 0.4124 + G * 0.3576 + B * 0.1805) / 0.95047;
  const y = R * 0.2126 + G * 0.7152 + B * 0.0722;
  const z = (R * 0.0193 + G * 0.1192 + B * 0.9505) / 1.08883;
  const t = (v) => (v > 0.008856 ? Math.cbrt(v) : 7.787 * v + 16 / 116);
  return [116 * t(y) - 16, 500 * (t(x) - t(y)), 200 * (t(y) - t(z))];
}

export function nearestToken(hex, theme) {
  const p = parseColor(hex);
  if (!p) return null;
  const lab = toLab(p);
  let best = null;
  for (const token of COLOR_TOKENS) {
    const col = resolveColor(token, theme);
    const q = parseColor(col);
    if (!q) continue;
    const l2 = toLab(q);
    const d = Math.hypot(lab[0] - l2[0], lab[1] - l2[1], lab[2] - l2[2]);
    if (!best || d < best.distance) best = { token, color: col, distance: Math.round(d * 100) / 100 };
  }
  return best;
}

export function resolveFont(font, theme) {
  const th = theme || DEFAULT_THEME;
  if (font === '@heading') return th.t?.heading || 'sans-serif';
  if (font === '@body' || font == null || font === '') return th.t?.body || 'sans-serif';
  return String(font);
}

export function resolveWeight(el, theme) {
  if (el.weight != null) return el.weight;
  const th = theme || DEFAULT_THEME;
  return el.font === '@heading' ? th.t?.hw ?? 700 : 400;
}

// ---- structural colour and font mapping ------------------------------------------------------

function mapFill(v, fn) {
  if (typeof v === 'string') return fn(v);
  if (isGradient(v)) {
    let changed = false;
    const stops = v.stops.map((s) => {
      const nc = fn(s[1]);
      if (nc === s[1]) return s;
      changed = true;
      return [s[0], nc];
    });
    return changed ? { ...v, stops } : v;
  }
  return v;
}

function mapDoc(node, colorFn, fontFn) {
  if (!isObj(node)) return node;
  let out = node;
  if (Array.isArray(node.marks)) {
    let changed = false;
    const marks = node.marks.map((m) => {
      if (m?.type !== 'textStyle' || !isObj(m.attrs)) return m;
      const attrs = { ...m.attrs };
      if (colorFn && typeof attrs.color === 'string') attrs.color = colorFn(attrs.color);
      if (fontFn && typeof attrs.fontFamily === 'string') attrs.fontFamily = fontFn(attrs.fontFamily);
      if (attrs.color === m.attrs.color && attrs.fontFamily === m.attrs.fontFamily) return m;
      changed = true;
      return { ...m, attrs };
    });
    if (changed) out = { ...out, marks };
  }
  if (Array.isArray(node.content)) {
    let changed = false;
    const content = node.content.map((n) => {
      const nn = mapDoc(n, colorFn, fontFn);
      if (nn !== n) changed = true;
      return nn;
    });
    if (changed) out = { ...out, content };
  }
  return out;
}

function set(el, patch) {
  let changed = false;
  for (const k of Object.keys(patch)) if (patch[k] !== el[k]) { changed = true; break; }
  return changed ? { ...el, ...patch } : el;
}

export function mapColors(el, fn) {
  const p = {};
  for (const k of ['color', 'fill', 'stroke']) if (el[k] != null) p[k] = mapFill(el[k], fn);
  if (isObj(el.shadow) && typeof el.shadow.c === 'string') {
    const c = fn(el.shadow.c);
    if (c !== el.shadow.c) p.shadow = { ...el.shadow, c };
  }
  if (el.doc) p.doc = mapDoc(el.doc, fn, null);
  if (el.type === 'table' && Array.isArray(el.rows)) {
    let changed = false;
    const rows = el.rows.map((row) => row.map((cell) => {
      if (!isObj(cell)) return cell;
      const f = cell.fill != null ? mapFill(cell.fill, fn) : cell.fill;
      const c = cell.color != null ? mapFill(cell.color, fn) : cell.color;
      if (f === cell.fill && c === cell.color) return cell;
      changed = true;
      const n = { ...cell };
      if (cell.fill != null) n.fill = f;
      if (cell.color != null) n.color = c;
      return n;
    }));
    if (changed) p.rows = rows;
  }
  if (el.type === 'chart' && isObj(el.chart)) {
    let chart = el.chart;
    const series = chart.data?.series;
    if (Array.isArray(series)) {
      let ch = false;
      const ns = series.map((s) => {
        if (typeof s?.color !== 'string') return s;
        const c = fn(s.color);
        if (c === s.color) return s;
        ch = true;
        return { ...s, color: c };
      });
      if (ch) chart = { ...chart, data: { ...chart.data, series: ns } };
    }
    const cols = chart.opt?.colors;
    if (Array.isArray(cols)) {
      const nc = cols.map((c) => (typeof c === 'string' ? fn(c) : c));
      if (nc.some((c, i) => c !== cols[i])) chart = { ...chart, opt: { ...chart.opt, colors: nc } };
    }
    if (chart !== el.chart) p.chart = chart;
  }
  return set(el, p);
}

export function mapFonts(el, fn) {
  const p = {};
  if (typeof el.font === 'string') p.font = fn(el.font);
  if (el.doc) p.doc = mapDoc(el.doc, null, fn);
  return set(el, p);
}

export function usageOf(el) {
  const tokens = new Set();
  const hex = new Set();
  const fonts = new Set();
  mapColors(el, (c) => {
    if (isToken(c)) tokens.add(c);
    else if (isHex(c)) hex.add(c.toLowerCase());
    return c;
  });
  mapFonts(el, (f) => { fonts.add(f); return f; });
  return { tokens: [...tokens], hex: [...hex], fonts: [...fonts] };
}

/**
 * Replace the deck theme. Elements that use tokens follow the new theme by themselves because the
 * document stores the token, not the colour; hex colours are left alone. The report says what moved.
 */
export function applyTheme(doc, theme) {
  const report = {
    themeId: theme.id, elementsWithTokens: 0, elementsWithHex: 0, elementsWithFontTokens: 0,
    byToken: {}, slides: [],
  };
  for (const slide of doc.slides) {
    const ids = [];
    for (const el of slide.els) {
      const u = usageOf(el);
      if (u.tokens.length) { report.elementsWithTokens++; ids.push(el.id); }
      if (u.hex.length) report.elementsWithHex++;
      if (u.fonts.some((f) => f === '@heading' || f === '@body')) report.elementsWithFontTokens++;
      for (const t of u.tokens) report.byToken[t] = (report.byToken[t] || 0) + 1;
    }
    if (ids.length) report.slides.push({ slide: slide.id, elements: ids });
  }
  return {
    doc: { ...doc, deck: { ...doc.deck, theme } },
    ops: [{ op: 'deck.set', theme }],
    report,
  };
}
