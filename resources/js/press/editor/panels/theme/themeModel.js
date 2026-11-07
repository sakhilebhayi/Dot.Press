/**
 * Pure helpers for the theme panel: shaping the answer of GET /api/themes and /api/brand-kits, the draft of the custom
 * theme editor, and plain-language API errors. No DOM, no Vue (node tested).
 *
 *   cleanTheme(t)               a theme in the shape of spec section 3 ({id, name, c, t, s, bg}); API extras are dropped
 *   themeKind(t)                'builtin' | 'custom' | 'brand'
 *   splitThemes(themes)         {builtin, custom, brand} in server order
 *   isCurrent(deckTheme, t)     the deck uses this theme (same id)
 *   isModified(deckTheme, t)    same id but the deck snapshot differs from the library entry
 *   COLOR_ROLES, CHART_COUNT    the editable colour roles
 *   draftFromTheme(t, name?)    an editable copy
 *   setDraft(draft, path, v)    immutable update of 'c.accent', 'c.chart.2', 't.heading', 's.radius', 'bg', 'name'
 *   hexOf(value, draft)         a hex for a colour field value (tokens are resolved inside the draft)
 *   draftPayload(draft)         body for POST/PUT /api/themes
 *   draftDirty(draft, saved)    unsaved changes
 *   apiErrorText(err, what)     plain language for 401, 403, 404, 422, 429 and network errors
 */
import { DEFAULT_THEME, isHex, isToken, resolveColor } from '../../core/theme.js';
import { clone, deepEqual } from '../../core/util.js';

export const COLOR_ROLES = [
  { key: 'bg', label: 'Background' },
  { key: 'surface', label: 'Surface' },
  { key: 'ink', label: 'Text' },
  { key: 'muted', label: 'Muted text' },
  { key: 'line', label: 'Line' },
  { key: 'accent', label: 'Accent' },
  { key: 'accent2', label: 'Accent 2' },
  { key: 'good', label: 'Good' },
  { key: 'warn', label: 'Warning' },
  { key: 'bad', label: 'Bad' },
];
export const CHART_COUNT = 8;

export function cleanTheme(t) {
  const base = DEFAULT_THEME;
  const c = { ...base.c, ...(t?.c || {}) };
  const chart = Array.isArray(c.chart) ? c.chart.slice(0, CHART_COUNT) : base.c.chart.slice();
  while (chart.length < CHART_COUNT) chart.push(base.c.chart[chart.length]);
  return {
    id: String(t?.id ?? ''),
    name: String(t?.name ?? ''),
    c: { ...c, chart },
    t: { ...base.t, ...(t?.t || {}) },
    s: { ...base.s, ...(t?.s || {}) },
    bg: t?.bg ?? null,
  };
}

export function themeKind(t) {
  const id = String(t?.id ?? '');
  if (id.startsWith('brand-kit:')) return 'brand';
  if (id.startsWith('custom:')) return 'custom';
  return t?.builtin === false ? 'custom' : 'builtin';
}

export function splitThemes(themes) {
  const out = { builtin: [], custom: [], brand: [] };
  for (const t of Array.isArray(themes) ? themes : []) {
    if (!t || typeof t.id !== 'string') continue;
    out[themeKind(t)].push(t);
  }
  return out;
}

export const isCurrent = (deckTheme, t) => !!deckTheme && !!t && deckTheme.id === t.id;

export function isModified(deckTheme, t) {
  if (!isCurrent(deckTheme, t)) return false;
  return !deepEqual({ ...cleanTheme(deckTheme), name: '' }, { ...cleanTheme(t), name: '' });
}

export function draftFromTheme(t, name) {
  const c = cleanTheme(t);
  return { ...clone(c), name: name ?? c.name };
}

export function setDraft(draft, path, value) {
  const next = clone(draft);
  const parts = String(path).split('.');
  const key = (p) => (/^\d+$/.test(p) ? Number(p) : p);
  let node = next;
  for (let i = 0; i < parts.length - 1; i++) {
    const k = key(parts[i]);
    if (node[k] == null || typeof node[k] !== 'object') node[k] = {};
    node = node[k];
  }
  node[key(parts.at(-1))] = value;
  return next;
}

/** Theme definitions only hold hex colours: a token picked in a colour field is replaced by what it is in the draft. */
export function hexOf(value, draft) {
  if (isHex(value)) return value.toLowerCase();
  if (isToken(value)) return resolveColor(value, draft) || '#000000';
  return '#000000';
}

export function draftPayload(draft) {
  const d = cleanTheme(draft);
  return { name: String(draft.name || '').trim(), c: d.c, t: d.t, s: d.s, bg: d.bg };
}

export function draftDirty(draft, saved) {
  return !deepEqual(draftPayload(draft), draftPayload(saved));
}

export function apiErrorText(err, what = 'this') {
  if (!err) return '';
  if (err.network) return 'The server could not be reached. Check your connection and try again.';
  switch (err.status) {
    case 401: return 'Your session has ended. Sign in again and retry.';
    case 403: return `You can look at ${what} but your role does not allow changing it.`;
    case 404: return 'That item no longer exists. Someone may have deleted it.';
    case 422: {
      const first = err.data?.errors ? Object.values(err.data.errors).flat()[0] : null;
      return String(first || err.message || 'The server did not accept that. Check the values and try again.');
    }
    case 429: return 'Too many requests in a short time. Wait a moment and try again.';
    default: return err.status >= 500 ? 'The server had a problem. Try again in a moment.' : String(err.message || 'Something went wrong. Try again.');
  }
}
