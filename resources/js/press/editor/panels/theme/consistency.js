/**
 * "Consistency after theme change": hard coded colours that match no token of the deck theme, and the proposals to map
 * them onto tokens. Pure (node tested). Nothing here changes a document; the panel shows the proposals and the user
 * confirms each one.
 *
 *   findHardColours(doc, theme, {maxDistance}) -> {proposals, alphaKept}
 *     proposal {hex, token, color, distance, count, close, elements: [{slide, id}]}
 *       token / color   the nearest token of the theme (core nearestToken, CIE76 delta E) and its colour
 *       close           distance <= maxDistance (default 24): ticked by default; the rest start unticked
 *     alphaKept        colours with transparency are left alone (a token has no alpha)
 *   mappingOps(doc, accepted) -> ops   one el.set per element, only the keys that changed; accepted = [{hex, token}]
 */
import {
  isHex, mapColors, nearestToken, resolveColor, COLOR_TOKENS,
} from '../../core/theme.js';

export const CLOSE_DISTANCE = 24;
const lower = (c) => String(c).toLowerCase();

export function findHardColours(doc, theme, { maxDistance = CLOSE_DISTANCE } = {}) {
  const tokenColors = new Set(COLOR_TOKENS.map((t) => resolveColor(t, theme)));
  const byHex = new Map();
  let alphaKept = 0;
  for (const slide of doc.slides) {
    for (const el of slide.els) {
      const seen = new Set();
      mapColors(el, (c) => {
        if (!isHex(c)) return c;
        const hex = lower(c);
        if (hex.length === 9 && !hex.endsWith('ff')) { if (!seen.has(`a${hex}`)) { alphaKept++; seen.add(`a${hex}`); } return c; }
        const flat = hex.length === 9 ? hex.slice(0, 7) : hex.length === 4 ? `#${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}` : hex;
        if (tokenColors.has(flat)) return c;
        if (seen.has(flat)) return c;
        seen.add(flat);
        let p = byHex.get(flat);
        if (!p) { p = { hex: flat, count: 0, elements: [] }; byHex.set(flat, p); }
        p.count++;
        p.elements.push({ slide: slide.id, id: el.id });
        return c;
      });
    }
  }
  const proposals = [];
  for (const p of byHex.values()) {
    const near = nearestToken(p.hex, theme);
    if (!near) continue;
    proposals.push({ ...p, token: near.token, color: near.color, distance: near.distance, close: near.distance <= maxDistance });
  }
  proposals.sort((a, b) => b.count - a.count || a.distance - b.distance || a.hex.localeCompare(b.hex));
  return { proposals, alphaKept };
}

export function mappingOps(doc, accepted) {
  const map = new Map();
  for (const a of accepted || []) if (a && isHex(a.hex) && typeof a.token === 'string') map.set(lower(a.hex), a.token);
  if (!map.size) return [];
  const norm = (c) => {
    const h = lower(c);
    return h.length === 4 ? `#${h[1]}${h[1]}${h[2]}${h[2]}${h[3]}${h[3]}` : h.length === 9 && h.endsWith('ff') ? h.slice(0, 7) : h;
  };
  const ops = [];
  for (const slide of doc.slides) {
    for (const el of slide.els) {
      const next = mapColors(el, (c) => (isHex(c) && map.has(norm(c)) ? map.get(norm(c)) : c));
      if (next === el) continue;
      const p = {};
      for (const k of Object.keys(next)) if (next[k] !== el[k]) p[k] = next[k];
      if (Object.keys(p).length) ops.push({ op: 'el.set', slide: slide.id, id: el.id, p });
    }
  }
  return ops;
}
