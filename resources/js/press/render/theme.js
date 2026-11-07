// Theme and colour resolution (spec sections 2 and 3). Pure functions, no DOM.
import { DEFAULT_FONT, fontStack, isKnownFont } from '../fonts.js';

export const TOKEN_NAMES = ['bg', 'surface', 'ink', 'muted', 'line', 'accent', 'accent2', 'good', 'warn', 'bad'];

export const DEFAULT_THEME = Object.freeze({
    id: 'slate',
    name: 'Slate',
    c: {
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
        chart: ['#2563eb', '#f59e0b', '#15803d', '#7c3aed', '#db2777', '#0891b2', '#65a30d', '#475569'],
    },
    t: { heading: 'Public Sans', body: 'Public Sans', hw: 700, scale: 1 },
    s: { radius: 12, stroke: 2 },
    bg: null,
});

const HEX = /^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i;
const TOKEN = /^@(bg|surface|ink|muted|line|accent|accent2|good|warn|bad|chart[1-8])$/;

export function isHex(v) {
    return typeof v === 'string' && HEX.test(v);
}

/** Expand #rgb and #rgba to the long form so every consumer sees 6 or 8 digits. */
export function normalizeHex(v) {
    if (!isHex(v)) return null;
    let h = v.slice(1).toLowerCase();
    if (h.length === 3 || h.length === 4) h = [...h].map((c) => c + c).join('');
    return '#' + h;
}

/** Fill in anything a stored theme leaves out, so a half written theme never breaks the renderer. */
export function normalizeTheme(theme) {
    const t = theme && typeof theme === 'object' ? theme : {};
    const d = DEFAULT_THEME;
    const c = { ...d.c, ...(t.c && typeof t.c === 'object' ? t.c : {}) };
    for (const name of TOKEN_NAMES) if (!isHex(c[name])) c[name] = d.c[name];
    const chart = Array.isArray(c.chart) ? c.chart.filter(isHex) : [];
    c.chart = chart.length ? chart : d.c.chart.slice();
    const tt = { ...d.t, ...(t.t && typeof t.t === 'object' ? t.t : {}) };
    const num = (v, def, lo, hi) => (Number.isFinite(+v) && v !== null && v !== '' ? Math.min(hi, Math.max(lo, +v)) : def);
    tt.hw = num(tt.hw, d.t.hw, 100, 1000);
    tt.scale = num(tt.scale, 1, 0.5, 2);
    const s = { ...d.s, ...(t.s && typeof t.s === 'object' ? t.s : {}) };
    s.radius = num(s.radius, d.s.radius, 0, 400);
    s.stroke = num(s.stroke, d.s.stroke, 0, 40);
    return { id: String(t.id || d.id), name: String(t.name || d.name), c, t: tt, s, bg: t.bg ?? null };
}

/** The concrete hex a colour value resolves to, or the fallback for anything unusable. */
export function resolveColor(value, theme, fallback = null) {
    if (typeof value !== 'string') return fallback;
    if (value[0] === '@') {
        const m = TOKEN.exec(value);
        if (!m) return fallback;
        const th = theme && theme.c ? theme : normalizeTheme(theme);
        const name = m[1];
        if (name.startsWith('chart')) {
            const list = th.c.chart && th.c.chart.length ? th.c.chart : DEFAULT_THEME.c.chart;
            return normalizeHex(list[(+name.slice(5) - 1) % list.length]) || fallback;
        }
        return normalizeHex(th.c[name]) || fallback;
    }
    return normalizeHex(value) || fallback;
}

/** A CSS colour for DOM use. Tokens become CSS variables so a theme change needs no re-render of the style. */
export function colorCss(value, fallback = 'transparent') {
    if (typeof value !== 'string') return fallback;
    if (value[0] === '@') {
        const m = TOKEN.exec(value);
        return m ? `var(--p-${m[1]})` : fallback;
    }
    return normalizeHex(value) || fallback;
}

/** Alpha as 0..1 of a resolved hex. */
export function hexAlpha(hex) {
    const n = normalizeHex(hex);
    return n && n.length === 9 ? parseInt(n.slice(7), 16) / 255 : 1;
}

/** Concrete rgb/rgba string for canvas-like consumers and tests. */
export function hexToRgba(hex) {
    const n = normalizeHex(hex);
    if (!n) return null;
    const r = parseInt(n.slice(1, 3), 16);
    const g = parseInt(n.slice(3, 5), 16);
    const b = parseInt(n.slice(5, 7), 16);
    const a = hexAlpha(n);
    return a === 1 ? `rgb(${r}, ${g}, ${b})` : `rgba(${r}, ${g}, ${b}, ${+a.toFixed(3)})`;
}

/** Mix a hex colour with an alpha, returning an 8 digit hex. */
export function withAlpha(hex, alpha) {
    const n = normalizeHex(hex);
    if (!n) return null;
    const a = Math.round(Math.min(1, Math.max(0, alpha)) * 255);
    return n.slice(0, 7) + a.toString(16).padStart(2, '0');
}

/** Perceived brightness 0..1 (sRGB luma), used for contrast picks. */
export function luma(hex) {
    const n = normalizeHex(hex);
    if (!n) return 0;
    const r = parseInt(n.slice(1, 3), 16);
    const g = parseInt(n.slice(3, 5), 16);
    const b = parseInt(n.slice(5, 7), 16);
    return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255;
}

/** A font value ("@heading", "@body" or a family name) to { family, stack }. Unknown names fall back to the body font. */
export function resolveFont(font, theme) {
    const th = theme && theme.t ? theme : normalizeTheme(theme);
    let family;
    if (font === '@heading') family = th.t.heading;
    else if (font === '@body' || font == null || font === '') family = th.t.body;
    else family = font;
    if (!isKnownFont(family)) family = isKnownFont(th.t.body) ? th.t.body : DEFAULT_FONT;
    return { family, stack: fontStack(family) };
}

/** Weight for a text element: an explicit weight wins, headings default to the theme heading weight. */
export function resolveWeight(el, theme) {
    const th = theme && theme.t ? theme : normalizeTheme(theme);
    if (Number.isFinite(+el?.weight) && el.weight) return Math.min(1000, Math.max(100, +el.weight));
    return el?.font === '@heading' ? th.t.hw : 400;
}

function clampNum(v, lo, hi, def) {
    const n = +v;
    return Number.isFinite(n) ? Math.min(hi, Math.max(lo, n)) : def;
}

/**
 * Resolve any fill to a plain description:
 *   { kind: 'none' }
 *   { kind: 'solid', color: '#rrggbb[aa]', css }
 *   { kind: 'linear'|'radial', angle, stops: [{ offset, color, css }], css }
 * `css` is a value for the CSS `background` shorthand and uses CSS variables for tokens.
 */
export function resolveFill(fill, theme) {
    if (fill == null || fill === '' || fill === 'none') return { kind: 'none', css: 'none' };
    if (typeof fill === 'string') {
        const color = resolveColor(fill, theme, null);
        if (!color) return { kind: 'none', css: 'none' };
        return { kind: 'solid', color, css: colorCss(fill) };
    }
    if (typeof fill === 'object' && (fill.g === 'linear' || fill.g === 'radial') && Array.isArray(fill.stops)) {
        const stops = fill.stops
            .map((s) => (Array.isArray(s) ? { offset: clampNum(s[0], 0, 1, 0), raw: s[1] } : null))
            .filter((s) => s && resolveColor(s.raw, theme, null))
            .slice(0, 12)
            .map((s) => ({ offset: s.offset, color: resolveColor(s.raw, theme), css: colorCss(s.raw) }));
        if (stops.length === 0) return { kind: 'none', css: 'none' };
        if (stops.length === 1) return { kind: 'solid', color: stops[0].color, css: stops[0].css };
        const list = stops.map((s) => `${s.css} ${+(s.offset * 100).toFixed(2)}%`).join(', ');
        if (fill.g === 'radial') return { kind: 'radial', angle: 0, stops, css: `radial-gradient(circle at 50% 50%, ${list})` };
        const angle = ((clampNum(fill.a, -3600, 3600, 180) % 360) + 360) % 360;
        return { kind: 'linear', angle, stops, css: `linear-gradient(${+angle.toFixed(2)}deg, ${list})` };
    }
    return { kind: 'none', css: 'none' };
}

/** CSS variables for a theme, to set on the slide stage (and on the page for chrome that follows the theme). */
export function themeVars(theme) {
    const th = normalizeTheme(theme);
    const v = {};
    for (const name of TOKEN_NAMES) v[`--p-${name}`] = normalizeHex(th.c[name]);
    th.c.chart.slice(0, 8).forEach((c, i) => {
        v[`--p-chart${i + 1}`] = normalizeHex(c);
    });
    v['--p-font-heading'] = fontStack(isKnownFont(th.t.heading) ? th.t.heading : DEFAULT_FONT);
    v['--p-font-body'] = fontStack(isKnownFont(th.t.body) ? th.t.body : DEFAULT_FONT);
    v['--p-heading-weight'] = String(th.t.hw);
    v['--p-radius'] = th.s.radius + 'px';
    v['--p-stroke'] = th.s.stroke + 'px';
    return v;
}

/** themeVars as an inline style string. */
export function themeStyle(theme) {
    return Object.entries(themeVars(theme))
        .map(([k, v]) => `${k}:${v}`)
        .join(';');
}

/** The slide background description: a fill, an image, or the theme default, or the theme bg colour. */
export function resolveBackground(slideBg, theme) {
    const th = normalizeTheme(theme);
    const pick = slideBg != null ? slideBg : th.bg;
    if (pick && typeof pick === 'object' && pick.img) {
        const dim = clampNum(pick.dim, 0, 1, 0);
        return { kind: 'image', img: pick.img, fit: ['cover', 'contain', 'fill'].includes(pick.fit) ? pick.fit : 'cover', dim, base: resolveFill('@bg', th) };
    }
    const f = resolveFill(pick, th);
    if (f.kind === 'none') return { kind: 'fill', fill: resolveFill('@bg', th) };
    return { kind: 'fill', fill: f };
}

/** CSS length helper for numbers in slide units, with a sane clamp. */
export function px(n, def = 0) {
    const v = Number.isFinite(+n) ? +n : def;
    return `${+v.toFixed(3)}px`;
}
