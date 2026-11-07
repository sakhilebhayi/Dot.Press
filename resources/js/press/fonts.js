// Font list for Dot.Press. The files live in public/fonts (self hosted, SIL Open Font License, see LICENSES.txt) and
// the @font-face rules in public/fonts/fonts.css. This module has no DOM dependency so node tests and the theme
// resolvers can use it.
//
// Exports
//   FONTS             array of { family, role, category, stack, weights, italic }
//   FONT_FAMILIES     array of family names
//   FONT_BY_FAMILY    map family -> entry
//   FONT_CSS_URL      '/fonts/fonts.css'
//   isKnownFont(name)
//   fontStack(name)   css font-family value for a known family, or null
//   ensureFonts()     browser only: add the stylesheet link once

export const FONT_CSS_URL = '/fonts/fonts.css';

const SYSTEM_SANS = 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
const SYSTEM_SERIF = 'ui-serif, Georgia, Cambria, "Times New Roman", serif';
const SYSTEM_MONO = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
const SYSTEM_HAND = '"Segoe Print", "Bradley Hand", cursive';

const entry = (family, role, category, weights, italic, fallback) => ({
    family,
    role,
    category,
    weights,
    italic,
    stack: `"${family}", ${fallback}`,
});

/** role: what the family is good for. category: sans | serif | mono | hand | display. */
export const FONTS = [
    entry('Public Sans', 'Neutral workhorse for business decks. The default.', 'sans', [100, 900], true, SYSTEM_SANS),
    entry('Inter', 'Dense interface text, tables and numbers.', 'sans', [100, 900], true, SYSTEM_SANS),
    entry('IBM Plex Sans', 'Technical and engineering decks.', 'sans', [100, 700], true, SYSTEM_SANS),
    entry('DM Sans', 'Friendly geometric sans for headings and body.', 'sans', [100, 1000], true, SYSTEM_SANS),
    entry('Manrope', 'Modern sans with strong numerals.', 'sans', [200, 800], false, SYSTEM_SANS),
    entry('Space Grotesk', 'Distinctive display sans for short headings.', 'display', [300, 700], false, SYSTEM_SANS),
    entry('Literata', 'Book serif for long reading text.', 'serif', [200, 900], true, SYSTEM_SERIF),
    entry('Source Serif 4', 'Editorial serif, sturdy at small sizes.', 'serif', [200, 900], true, SYSTEM_SERIF),
    entry('IBM Plex Serif', 'Technical serif that pairs with Plex Sans and Mono.', 'serif', [400, 700], true, SYSTEM_SERIF),
    entry('Playfair Display', 'High contrast serif for large headings.', 'display', [400, 900], true, SYSTEM_SERIF),
    entry('IBM Plex Mono', 'Code, data and technical labels.', 'mono', [400, 700], false, SYSTEM_MONO),
    entry('Caveat', 'Handwriting for annotations. Use sparingly.', 'hand', [400, 700], false, SYSTEM_HAND),
];

export const FONT_FAMILIES = FONTS.map((f) => f.family);
export const FONT_BY_FAMILY = Object.fromEntries(FONTS.map((f) => [f.family, f]));

export function isKnownFont(name) {
    return typeof name === 'string' && Object.prototype.hasOwnProperty.call(FONT_BY_FAMILY, name);
}

export function fontStack(name) {
    return isKnownFont(name) ? FONT_BY_FAMILY[name].stack : null;
}

export const DEFAULT_FONT = 'Public Sans';

let linked = false;
export function ensureFonts(href = FONT_CSS_URL) {
    if (linked || typeof document === 'undefined') return;
    if (document.querySelector('link[data-press-fonts]')) {
        linked = true;
        return;
    }
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.setAttribute('data-press-fonts', '');
    document.head.appendChild(link);
    linked = true;
}
