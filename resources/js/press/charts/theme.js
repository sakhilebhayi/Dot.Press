// Theme resolution: the spec theme tokens (R40_DOCUMENT_SPEC section 3) with safe defaults,
// colour token lookup and the per-series style (colour, marker, dash, pattern).
import { parseColor, toHex, mix, isDark, relLum } from './util.js';

export const DEFAULT_THEME = Object.freeze({
  id: 'slate',
  name: 'Slate',
  c: Object.freeze({
    bg: '#ffffff',
    surface: '#f4f5f7',
    ink: '#111827',
    muted: '#6b7280',
    line: '#d1d5db',
    accent: '#2563eb',
    accent2: '#f59e0b',
    good: '#15803d',
    warn: '#b45309',
    bad: '#b91c1c',
    chart: Object.freeze(['#2563eb', '#f59e0b', '#15803d', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#475569']),
  }),
  t: Object.freeze({ heading: 'Public Sans', body: 'Public Sans', hw: 700, scale: 1 }),
});

/**
 * The same eight hues stepped for a dark surface. Validated with the dataviz validator on #111827
 * (see the header of index.js). Use for dark slides; chartToSvg itself only follows the theme it is given.
 */
export const DEFAULT_DARK_THEME = Object.freeze({
  id: 'slate-dark',
  name: 'Slate dark',
  c: Object.freeze({
    bg: '#111827',
    surface: '#1f2937',
    ink: '#f3f4f6',
    muted: '#9ca3af',
    line: '#374151',
    accent: '#3b82f6',
    accent2: '#d86f06',
    good: '#4ade80',
    warn: '#fbbf24',
    bad: '#f87171',
    chart: Object.freeze(['#3b82f6', '#d86f06', '#16a25a', '#8b5cf6', '#ec4899', '#0d9cbc', '#65a30d', '#64748b']),
  }),
  t: Object.freeze({ heading: 'Public Sans', body: 'Public Sans', hw: 700, scale: 1 }),
});

const COLOR_KEYS = ['bg', 'surface', 'ink', 'muted', 'line', 'accent', 'accent2', 'good', 'warn', 'bad'];

function validHex(c, fallback) {
  const p = parseColor(typeof c === 'string' ? c : '');
  return p ? toHex(p) : fallback;
}

/** Fill every missing token from the defaults and validate every colour. Never throws. */
export function resolveTheme(theme) {
  const base = DEFAULT_THEME;
  const src = theme && typeof theme === 'object' ? theme : {};
  const sc = src.c && typeof src.c === 'object' ? src.c : {};
  const c = {};
  for (const k of COLOR_KEYS) c[k] = validHex(sc[k], base.c[k]);
  const chart = Array.isArray(sc.chart) ? sc.chart.map((x, i) => validHex(x, base.c.chart[i % 8])).slice(0, 16) : [];
  c.chart = chart.length ? chart : [...base.c.chart];
  const st = src.t && typeof src.t === 'object' ? src.t : {};
  const t = {
    heading: typeof st.heading === 'string' ? st.heading : base.t.heading,
    body: typeof st.body === 'string' ? st.body : base.t.body,
    hw: Number.isFinite(st.hw) ? st.hw : base.t.hw,
    scale: Number.isFinite(st.scale) && st.scale > 0 ? st.scale : 1,
  };
  return { id: typeof src.id === 'string' ? src.id : base.id, c, t, dark: isDark(c.bg) };
}

/** Resolve a colour or a theme token ("@accent", "@chart3") to a hex colour. */
export function resolveColor(value, theme, fallback) {
  if (typeof value !== 'string') return fallback;
  const v = value.trim();
  if (v[0] === '@') {
    const key = v.slice(1);
    const m = /^chart([1-9]\d?)$/.exec(key);
    if (m) return theme.c.chart[(Number(m[1]) - 1) % theme.c.chart.length] || fallback;
    return COLOR_KEYS.includes(key) ? theme.c[key] : fallback;
  }
  const p = parseColor(v);
  return p ? toHex(p) : fallback;
}

export const MARKER_SHAPES = ['circle', 'square', 'diamond', 'triangle', 'triangle-down', 'plus', 'hex', 'star'];
export const DASHES = ['', '', '', '', '10 6', '3 5', '14 5 3 5', '2 4'];

/**
 * Style for series `i`: colour order is fixed by index. Slots 0..7 use the theme colours.
 * Slots 8 and up reuse the palette with a lightness step (darker on light surfaces, lighter on dark ones,
 * then the opposite step) plus a hatch pattern on filled marks and a dash on lines, so identity never
 * rests on colour alone. `custom` (a series colour or opt.colors entry) wins over the palette.
 */
export function seriesStyle(i, theme, custom) {
  const n = theme.c.chart.length;
  const round = Math.floor(i / n);
  let color = resolveColor(custom, theme, null);
  let tier = 0;
  if (!color) {
    color = theme.c.chart[i % n];
    if (round > 0) {
      tier = round;
      const toward = theme.dark ? '#ffffff' : '#000000';
      const away = theme.dark ? '#000000' : '#ffffff';
      color = round % 2 === 1 ? mix(color, toward, 0.28) : mix(color, away, 0.4);
    }
  }
  const dashIdx = i % 8;
  return {
    color,
    tier,
    pattern: tier > 0 ? 'hatch' : null,
    marker: MARKER_SHAPES[i % MARKER_SHAPES.length],
    dash: tier > 0 ? '10 6' : DASHES[dashIdx],
  };
}

/** Lightness-adjusted colour for text-safe use on the surface (never used for text by default). */
export function surfaceLum(theme) {
  return relLum(parseColor(theme.c.bg) || { r: 255, g: 255, b: 255 });
}
