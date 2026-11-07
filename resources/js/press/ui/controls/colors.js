/**
 * Colour helpers for the colour controls (pure, node tested).
 *   normalizeHex(text)            '#abc' | 'abc' | 'AABBCC' | '#aabbcc80' -> '#aabbcc' or '#aabbcc80', else null
 *   splitAlpha(hex)               {rgb: '#rrggbb', alpha: 0..1}
 *   joinAlpha(rgb, alpha)         '#rrggbb' (alpha 1) or '#rrggbbaa'
 *   toRgb(hex) / fromRgb(r,g,b)   {r,g,b} <-> '#rrggbb'
 *   pushRecent(list, color, max)  new list, newest first, no duplicates (case insensitive), tokens and gradients skipped
 *   gradientCss(g)                css linear-gradient for the spec gradient {g:'linear', a, stops:[[pos, hex|@token]]}
 *   newGradient(from, to)         a two stop gradient
 *   addStop / removeStop / moveStop / setStopColor / sortStops   return new gradients; a gradient keeps 2 to 8 stops
 *   normAngle(a)                  0..359.99
 */
const hx = (n) => Math.round(Math.min(255, Math.max(0, n))).toString(16).padStart(2, '0');

export function normalizeHex(text) {
  if (typeof text !== 'string') return null;
  let t = text.trim().replace(/^#/, '');
  if (!/^([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(t)) return null;
  if (t.length <= 4) t = t.split('').map((c) => c + c).join('');
  t = t.toLowerCase();
  if (t.length === 8 && t.endsWith('ff')) t = t.slice(0, 6);
  return `#${t}`;
}

export function toRgb(hex) {
  const n = normalizeHex(hex);
  if (!n) return null;
  return { r: parseInt(n.slice(1, 3), 16), g: parseInt(n.slice(3, 5), 16), b: parseInt(n.slice(5, 7), 16) };
}
export const fromRgb = (r, g, b) => `#${hx(r)}${hx(g)}${hx(b)}`;

export function splitAlpha(hex) {
  const n = normalizeHex(hex);
  if (!n) return null;
  if (n.length === 9) return { rgb: n.slice(0, 7), alpha: Math.round((parseInt(n.slice(7, 9), 16) / 255) * 1000) / 1000 };
  return { rgb: n, alpha: 1 };
}
export function joinAlpha(rgb, alpha) {
  const base = normalizeHex(rgb);
  if (!base) return null;
  const a = Math.min(1, Math.max(0, Number(alpha)));
  if (!(a < 1)) return base.slice(0, 7);
  return `${base.slice(0, 7)}${hx(a * 255)}`;
}

export function pushRecent(list, color, max = 8) {
  const n = typeof color === 'string' && color[0] !== '@' ? normalizeHex(color) : null;
  if (!n) return list;
  return [n, ...list.filter((c) => c.toLowerCase() !== n)].slice(0, max);
}

export const normAngle = (a) => { const v = Number(a) || 0; return Math.round((((v % 360) + 360) % 360) * 100) / 100; };

const MAX_STOPS = 8;
export const sortStops = (stops) => stops.slice().sort((a, b) => a[0] - b[0]);
const clamp01 = (n) => Math.min(1, Math.max(0, Math.round(n * 1000) / 1000));

export function newGradient(from = '#2563eb', to = '#f59e0b', angle = 90) {
  return { g: 'linear', a: angle, stops: [[0, from], [1, to]] };
}

/** `resolve(color)` maps a token to hex for css; tokens fall back to grey when unresolved. */
export function gradientCss(g, resolve = (c) => c) {
  const stops = sortStops(g.stops).map(([p, c]) => `${resolve(c) || '#808080'} ${Math.round(p * 1000) / 10}%`);
  return `linear-gradient(${normAngle(g.a)}deg, ${stops.join(', ')})`;
}

export function addStop(g, pos, color) {
  if (g.stops.length >= MAX_STOPS) return g;
  return { ...g, stops: sortStops([...g.stops, [clamp01(pos), color]]) };
}
export function removeStop(g, index) {
  if (g.stops.length <= 2 || index < 0 || index >= g.stops.length) return g;
  return { ...g, stops: g.stops.filter((_, i) => i !== index) };
}
export function moveStop(g, index, pos) {
  if (index < 0 || index >= g.stops.length) return g;
  const stops = g.stops.map((s, i) => (i === index ? [clamp01(pos), s[1]] : s));
  return { ...g, stops };
}
export function setStopColor(g, index, color) {
  if (index < 0 || index >= g.stops.length) return g;
  return { ...g, stops: g.stops.map((s, i) => (i === index ? [s[0], color] : s)) };
}
