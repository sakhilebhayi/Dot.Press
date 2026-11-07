/**
 * Data for the "new slide" layout chooser. The ids are the layouts of the document spec (section 5). Each carries a
 * generic glyph: rectangles in a 32 x 18 box (x, y, w, h, kind: 't' title text, 'b' body text, 'm' media, 'k' number).
 *
 * When a layout library answers GET /api/layout-library (any of: an array, {layouts: []}, {data: []}) of
 * definitions in the layouts.js format ({id, name, ph: [{role, type, x, y, w, h}]}), `mergeLibrary` replaces the
 * glyphs and names with the real placeholders so the chooser shows what will really be applied, and adds custom
 * layouts after the built in ones. Without it, the chooser lists the spec ids and a click adds a slide whose
 * layout id is stored and nothing else is moved.
 */
export const GLYPH_W = 32;
export const GLYPH_H = 18;

const g = (...r) => r.map(([x, y, w, h, k = 'b']) => ({ x, y, w, h, k }));

export const BUILTIN_LAYOUTS = [
  { id: 'title', name: 'Title', glyph: g([4, 6, 24, 4, 't'], [4, 12, 14, 2, 'b']) },
  { id: 'section', name: 'Section', glyph: g([4, 7, 18, 4, 't'], [4, 3, 6, 1.5, 'k']) },
  { id: 'bullets', name: 'Title and bullets', glyph: g([3, 2, 20, 3, 't'], [3, 7, 22, 1.5, 'b'], [3, 10, 18, 1.5, 'b'], [3, 13, 20, 1.5, 'b']) },
  { id: 'two', name: 'Two columns', glyph: g([3, 2, 20, 3, 't'], [3, 7, 12, 8, 'b'], [17, 7, 12, 8, 'b']) },
  { id: 'image-left', name: 'Picture left', glyph: g([2, 2, 13, 14, 'm'], [18, 3, 12, 3, 't'], [18, 8, 12, 6, 'b']) },
  { id: 'image-right', name: 'Picture right', glyph: g([17, 2, 13, 14, 'm'], [2, 3, 12, 3, 't'], [2, 8, 12, 6, 'b']) },
  { id: 'image-full', name: 'Full picture', glyph: g([0, 0, 32, 18, 'm'], [3, 11, 16, 3, 't']) },
  { id: 'compare', name: 'Compare', glyph: g([3, 2, 26, 3, 't'], [3, 7, 12, 2, 't'], [17, 7, 12, 2, 't'], [3, 10.5, 12, 5, 'b'], [17, 10.5, 12, 5, 'b']) },
  { id: 'timeline', name: 'Timeline', glyph: g([3, 2, 20, 3, 't'], [3, 9, 26, 0.8, 'b'], [4, 6, 4, 2, 'b'], [14, 11, 4, 2, 'b'], [24, 6, 4, 2, 'b']) },
  { id: 'quote', name: 'Quote', glyph: g([5, 4, 22, 3, 't'], [5, 8, 20, 3, 't'], [5, 14, 8, 1.5, 'b']) },
  { id: 'data', name: 'Chart and note', glyph: g([3, 2, 20, 3, 't'], [3, 7, 18, 9, 'm'], [23, 7, 6, 9, 'b']) },
  { id: 'kpi', name: 'Key figures', glyph: g([3, 2, 20, 3, 't'], [3, 8, 8, 6, 'k'], [12, 8, 8, 6, 'k'], [21, 8, 8, 6, 'k']) },
  { id: 'closing', name: 'Closing', glyph: g([6, 6, 20, 4, 't'], [10, 12, 12, 2, 'b']) },
  { id: 'blank', name: 'Blank', glyph: [] },
];

export const BUILTIN_IDS = BUILTIN_LAYOUTS.map((l) => l.id);

/** Pull an array of layout definitions out of whatever the library endpoint answered. */
export function libraryList(payload) {
  const list = Array.isArray(payload) ? payload : Array.isArray(payload?.layouts) ? payload.layouts : Array.isArray(payload?.data) ? payload.data : [];
  return list.filter((d) => d && typeof d.id === 'string' && Array.isArray(d.ph));
}

const KIND = { title: 't', subtitle: 'b', body: 'b', media: 'm', caption: 'b', footer: 'b', number: 'k', kpi: 'k' };
const clamp = (n, lo, hi) => Math.min(hi, Math.max(lo, n));

export function glyphFromDefinition(def) {
  return (def.ph || []).map((p) => ({
    x: clamp(p.x, 0, 1) * GLYPH_W,
    y: clamp(p.y, 0, 1) * GLYPH_H,
    w: clamp(p.w, 0.02, 1) * GLYPH_W,
    h: clamp(p.h, 0.02, 1) * GLYPH_H,
    k: p.type === 'media' ? 'm' : KIND[p.role] || 'b',
  }));
}

/**
 * The list the chooser shows: built in layouts first (with library names and glyphs when the library has them), then
 * every other layout of the library. Each item: {id, name, glyph, def|null, custom}.
 */
export function mergeLibrary(payload) {
  const defs = new Map(libraryList(payload).map((d) => [d.id, d]));
  const items = BUILTIN_LAYOUTS.map((b) => {
    const def = defs.get(b.id) || null;
    return { id: b.id, name: def?.name || b.name, glyph: def ? glyphFromDefinition(def) : b.glyph, def, custom: false };
  });
  for (const [id, def] of defs) {
    if (BUILTIN_IDS.includes(id)) continue;
    items.push({ id, name: def.name || id, glyph: glyphFromDefinition(def), def, custom: id.startsWith('custom:') });
  }
  return items;
}
