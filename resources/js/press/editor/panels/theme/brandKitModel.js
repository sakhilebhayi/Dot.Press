/**
 * Pure helpers for brand kits (GET/POST/PUT/DELETE /api/brand-kits). A kit is a named palette (up to 12 named
 * colours, each optionally the bg, ink, accent ... of the theme), up to 3 fonts with roles and up to 6 logo assets.
 * The server converts a kit to a theme (`kit.theme`); this module only shapes drafts and checks them before sending.
 *
 *   KIT_LIMITS                       defaults used until the server answers with its own `limits`
 *   PALETTE_ROLES, FONT_ROLES        the allowed roles
 *   emptyKit()                       a blank draft
 *   draftFromKit(kit)                an editable copy of a payload
 *   addColour(draft, hex?), removeAt(draft, key, i), setAt(draft, key, i, patch)
 *   kitBody(draft)                   body for POST/PUT: {name, is_default, tokens: {palette, fonts, logos}}
 *   kitProblems(draft, limits)       {name?, palette: {i: msg}, fonts: {i: msg}, general: [msg]} ; empty object means valid
 *   hasProblems(problems)
 *   kitThemeCard(kit)                the kit as a theme-gallery entry
 */
import { isHex } from '../../core/theme.js';
import { clone } from '../../core/util.js';

export const KIT_LIMITS = { kits: 20, palette: 12, fonts: 3, logos: 6 };
export const PALETTE_ROLES = ['bg', 'surface', 'ink', 'muted', 'line', 'accent', 'accent2', 'good', 'warn', 'bad'];
export const FONT_ROLES = ['heading', 'body', 'accent'];
export const ROLE_WORDS = {
  bg: 'Background', surface: 'Surface', ink: 'Text', muted: 'Muted text', line: 'Line', accent: 'Accent', accent2: 'Accent 2', good: 'Good', warn: 'Warning', bad: 'Bad',
  heading: 'Heading', body: 'Body',
};
const FONT_RE = /^[\p{L}\p{N}][\p{L}\p{N} \-_.]{0,79}$/u;

export function emptyKit() {
  return { id: null, name: '', is_default: false, palette: [], fonts: [], logos: [], logo_assets: [] };
}

export function draftFromKit(kit) {
  const t = kit?.tokens || {};
  return {
    id: kit?.id ?? null,
    name: String(kit?.name ?? ''),
    is_default: !!kit?.is_default,
    palette: (t.palette || []).map((c) => ({ name: c.name, hex: c.hex, role: c.role || '' })),
    fonts: (t.fonts || []).map((f) => ({ family: f.family, role: f.role })),
    logos: (t.logos || []).slice(),
    logo_assets: clone(kit?.logo_assets || []),
  };
}

export function addColour(draft, hex = '#2563eb', limit = KIT_LIMITS.palette) {
  if (draft.palette.length >= limit) return draft;
  const n = draft.palette.length + 1;
  return { ...draft, palette: [...draft.palette, { name: `Colour ${n}`, hex, role: '' }] };
}

export function removeAt(draft, key, index) {
  const list = draft[key].filter((_, i) => i !== index);
  const extra = key === 'logos' ? { logo_assets: draft.logo_assets.filter((_, i) => i !== index) } : {};
  return { ...draft, [key]: list, ...extra };
}

export function setAt(draft, key, index, patch) {
  return { ...draft, [key]: draft[key].map((item, i) => (i === index ? { ...item, ...patch } : item)) };
}

export function addFont(draft, family = 'Public Sans', limit = KIT_LIMITS.fonts) {
  if (draft.fonts.length >= limit) return draft;
  const used = new Set(draft.fonts.map((f) => f.role));
  const role = FONT_ROLES.find((r) => !used.has(r)) || 'accent';
  return { ...draft, fonts: [...draft.fonts, { family, role }] };
}

export function addLogo(draft, asset, limit = KIT_LIMITS.logos) {
  if (!asset || draft.logos.includes(asset.id) || draft.logos.length >= limit) return draft;
  return { ...draft, logos: [...draft.logos, asset.id], logo_assets: [...draft.logo_assets, { id: asset.id, name: asset.name, url: asset.url, thumb: asset.thumb }] };
}

export function kitBody(draft) {
  return {
    name: String(draft.name || '').trim(),
    is_default: !!draft.is_default,
    tokens: {
      palette: draft.palette.map((c) => ({ name: String(c.name).trim(), hex: c.hex, ...(c.role ? { role: c.role } : {}) })),
      fonts: draft.fonts.map((f) => ({ family: String(f.family).trim(), role: f.role })),
      logos: draft.logos.slice(),
    },
  };
}

export function kitProblems(draft, limits = KIT_LIMITS) {
  const p = { palette: {}, fonts: {}, general: [] };
  if (!String(draft.name || '').trim()) p.name = 'Give the kit a name.';
  else if (String(draft.name).trim().length > 80) p.name = 'Use 80 characters or fewer.';
  if (draft.palette.length > limits.palette) p.general.push(`A palette holds at most ${limits.palette} colours.`);
  const roles = new Map();
  draft.palette.forEach((c, i) => {
    const name = String(c.name || '').trim();
    if (!name) p.palette[i] = 'Name this colour.';
    else if (name.length > 40) p.palette[i] = 'Use 40 characters or fewer.';
    else if (!isHex(c.hex)) p.palette[i] = 'Use a hex colour like #2563eb.';
    else if (c.role) {
      if (roles.has(c.role)) p.palette[i] = `Only one colour can be the ${ROLE_WORDS[c.role].toLowerCase()} colour.`;
      roles.set(c.role, i);
    }
  });
  const fontRoles = new Set();
  draft.fonts.forEach((f, i) => {
    if (!FONT_RE.test(String(f.family || '').trim())) p.fonts[i] = 'Use a font name of letters, digits, spaces, hyphens or dots.';
    else if (fontRoles.has(f.role)) p.fonts[i] = `Only one font can be the ${f.role} font.`;
    fontRoles.add(f.role);
  });
  if (draft.fonts.length > limits.fonts) p.general.push(`A kit holds at most ${limits.fonts} fonts.`);
  if (draft.logos.length > limits.logos) p.general.push(`A kit holds at most ${limits.logos} logos.`);
  return p;
}

export function hasProblems(p) {
  return !!p.name || Object.keys(p.palette).length > 0 || Object.keys(p.fonts).length > 0 || p.general.length > 0;
}

/** A kit as a gallery entry: its converted theme with the kit name and default flag. */
export function kitThemeCard(kit) {
  return { ...kit.theme, kitId: kit.id, kitName: kit.name, isDefault: !!kit.is_default, warnings: kit.warnings || [] };
}
