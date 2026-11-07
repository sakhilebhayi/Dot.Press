/**
 * Copy style and paste style. Pure.
 *
 *   styleKeysFor(type, kind?)     the element keys that make up an element's look
 *   copyStyle(el)                 {type, kind, props} snapshot (colours stay tokens, so a theme change still applies)
 *   pasteStylePatch(style, el)    patch for `el`, only keys that make sense for its type; null when nothing applies
 */
const COMMON = ['op', 'shadow'];
const KEYS = {
  text: ['font', 'size', 'weight', 'color', 'align', 'valign', 'lh', 'ls', 'ps', 'fill', 'stroke', 'sw', 'radius', 'fx', 'pad'],
  shape: ['fill', 'stroke', 'sw', 'dash', 'radius'],
  line: ['stroke', 'sw', 'dash', 'ends'],
  image: ['mask', 'adj', 'fit', 'fill'],
  icon: ['color', 'sw'],
  chart: [],
  table: ['size', 'font', 'lines', 'band', 'header'],
  video: [],
  audio: [],
};

const slot = (el) => (el.type === 'shape' && (el.kind === 'line' || el.kind === 'arrow') ? 'line' : el.type);

export function styleKeysFor(type, kind) {
  const k = kind === 'line' || kind === 'arrow' ? 'line' : type;
  return [...(KEYS[k] || []), ...COMMON];
}

export function copyStyle(el) {
  const s = slot(el);
  const props = {};
  for (const k of styleKeysFor(el.type, el.kind)) if (el[k] !== undefined) props[k] = JSON.parse(JSON.stringify(el[k]));
  return { type: el.type, slot: s, props };
}

export function pasteStylePatch(style, el) {
  if (!style) return null;
  const allowed = new Set(styleKeysFor(el.type, el.kind));
  const patch = {};
  for (const [k, v] of Object.entries(style.props)) {
    if (!allowed.has(k)) continue;
    // shapes and text share fill, stroke and radius; lines only take line keys, so a rect style never turns a line into a box
    if (JSON.stringify(el[k]) === JSON.stringify(v)) continue;
    patch[k] = JSON.parse(JSON.stringify(v));
  }
  // keys the source did not set are cleared on the target so the look matches
  for (const k of allowed) if (!(k in style.props) && el[k] !== undefined && k !== 'op' && k !== 'shadow' && style.slot === slot(el)) patch[k] = null;
  return Object.keys(patch).length ? patch : null;
}
