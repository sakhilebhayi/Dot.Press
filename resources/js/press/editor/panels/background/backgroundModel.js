/**
 * Pure helpers for the background panel (node tested). A background is a fill (hex, token or linear gradient), an
 * image background {img: {asset} | {url}, fit: 'cover'|'contain'|'fill', dim: 0..1}, or null (a slide with null follows
 * the deck default `deck.theme.bg`, and the deck default null means the theme's @bg colour).
 *
 *   bgKind(bg)                     'theme' (null) | 'solid' | 'gradient' | 'image'
 *   startValue(kind, theme, current)   what a kind starts with when the user switches to it
 *   imageBg(assetId, {fit, dim})   an image background
 *   withImage(bg, patch)           change fit or dim of an image background
 *   bgContrast(bg, theme)          {ratio, level, text} for the theme ink on this background, or null for images
 *   bgSummary(bg, theme, assets)   one line for a list ("Image: team.png, dimmed 30%")
 *   applyToAllOps(doc, bg)         slide.set ops for every slide whose background differs (one op per slide)
 *   themeWithBg(theme, bg)         the theme with a new default background
 *   DIM_MAX
 */
import {
  contrastRatio, isGradient, isToken, resolveColor,
} from '../../core/theme.js';
import { newGradient } from '../../../ui/controls/colors.js';
import { deepEqual } from '../../core/util.js';

export const DIM_MAX = 0.9;
export const FITS = [
  { value: 'cover', label: 'Fill', title: 'Fill the slide and crop the edges' },
  { value: 'contain', label: 'Fit', title: 'Show the whole picture, with the page colour around it' },
  { value: 'fill', label: 'Stretch', title: 'Stretch to the slide shape' },
];

const isImage = (bg) => !!bg && typeof bg === 'object' && !Array.isArray(bg) && bg.img != null;

export function bgKind(bg) {
  if (bg == null || bg === '') return 'theme';
  if (isImage(bg)) return 'image';
  if (isGradient(bg)) return 'gradient';
  return 'solid';
}

export function startValue(kind, theme, current) {
  const was = bgKind(current);
  switch (kind) {
    case 'theme': return null;
    case 'solid':
      if (was === 'solid') return current;
      if (was === 'gradient') return current.stops?.[0]?.[1] ?? '@bg';
      return '@bg';
    case 'gradient':
      if (was === 'gradient') return current;
      return newGradient(resolveColor(was === 'solid' ? current : '@accent', theme) || '#2563eb', resolveColor('@accent2', theme) || '#f59e0b', 135);
    case 'image':
      return was === 'image' ? current : null; // an image needs a picked asset first
    default: return current;
  }
}

export function imageBg(assetId, { fit = 'cover', dim = 0 } = {}) {
  return { img: { asset: assetId }, fit, dim };
}

export function withImage(bg, patch) {
  if (!isImage(bg)) return bg;
  const out = { ...bg, ...patch };
  out.dim = Math.min(DIM_MAX, Math.max(0, Number(out.dim) || 0));
  if (!['cover', 'contain', 'fill'].includes(out.fit)) out.fit = 'cover';
  return out;
}

export function bgContrast(bg, theme) {
  const kind = bgKind(bg);
  if (kind === 'image') return null;
  const colours = kind === 'gradient' ? bg.stops.map((s) => s[1]) : [kind === 'theme' ? (theme?.bg && !isImage(theme.bg) ? solidOf(theme.bg) : '@bg') : bg];
  let worst = null;
  for (const c of colours) {
    const r = contrastRatio('@ink', c, theme);
    if (r != null && (worst == null || r < worst)) worst = r;
  }
  if (worst == null) return null;
  const level = worst < 4.5 ? 'issue' : worst < 7 ? 'warn' : 'ok';
  const f = `${worst.toFixed(1)}:1`;
  const text = level === 'ok'
    ? `Text in the theme colour reads well on this background (${f}).`
    : level === 'warn'
      ? `Text in the theme colour is readable here (${f}) but below the 7:1 we recommend.`
      : `Text in the theme colour will be hard to read on this background (${f}). Pick a lighter or darker colour, or change the text colour.`;
  return { ratio: worst, level, text };
}

function solidOf(bg) {
  if (isGradient(bg)) return bg.stops?.[0]?.[1] ?? '@bg';
  return bg;
}

export function bgSummary(bg, theme, assets = {}) {
  switch (bgKind(bg)) {
    case 'theme': return 'Follows the deck default';
    case 'solid': return isToken(bg) ? `Theme colour ${bg}` : String(bg).toUpperCase();
    case 'gradient': return `Gradient, ${bg.stops.length} stops`;
    default: {
      const a = assets?.[bg.img?.asset];
      const dim = bg.dim > 0 ? `, dimmed ${Math.round(bg.dim * 100)}%` : '';
      return `Image${a?.name ? `: ${a.name}` : ''}${dim}`;
    }
  }
}

export function applyToAllOps(doc, bg) {
  const ops = [];
  for (const s of doc.slides) {
    if (deepEqual(s.bg ?? null, bg ?? null)) continue;
    ops.push({ op: 'slide.set', id: s.id, bg: bg ?? null });
  }
  return ops;
}

export function themeWithBg(theme, bg) {
  return { ...theme, bg: bg ?? null };
}
