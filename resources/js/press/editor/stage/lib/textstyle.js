/**
 * The style of a text box exactly as render/TextBox.vue computes it, so the in place editor lays text out
 * identically and nothing jumps when editing starts or ends. Pure.
 *
 *   textBoxStyle(el, theme, kind)   plain object of camelCase style keys ('text' draws fill, stroke and radius itself,
 *                                   'shape' leaves them to the shape)
 *   shapeTextDefaults(deck, el)     keys to add to a shape (or text) element that gets its first text
 */
import {
  colorCss, normalizeTheme, px, resolveFont, resolveFill, resolveWeight,
} from '../../../render/theme.js';

export function textBoxStyle(el, themeIn, kind = 'text') {
  const theme = normalizeTheme(themeIn);
  const font = resolveFont(el.font ?? '@body', theme);
  const size = (Number.isFinite(+el.size) && +el.size > 0 ? +el.size : 48) * (theme.t.scale || 1);
  const pad = Number.isFinite(+el.pad) ? Math.max(0, +el.pad) : kind === 'shape' ? 16 : 0;
  const lh = Number.isFinite(+el.lh) && +el.lh > 0 ? +el.lh : 1.25;
  const ls = Number.isFinite(+el.ls) ? +el.ls : 0;
  const ps = Number.isFinite(+el.ps) ? Math.max(0, +el.ps) : 0.4;
  const fx = ['outline', 'highlight'].includes(el.fx) ? el.fx : 'none';
  const color = colorCss(el.color ?? '@ink', 'var(--p-ink)');
  const align = ['left', 'center', 'right', 'justify'].includes(el.align) ? el.align : 'left';
  const justify = { top: 'flex-start', middle: 'center', bottom: 'flex-end' }[el.valign] || 'flex-start';
  const s = {
    justifyContent: justify,
    padding: px(pad),
    fontFamily: font.stack,
    fontSize: `calc(${px(size)} * var(--p-fit, 1))`,
    fontWeight: resolveWeight(el, theme),
    lineHeight: lh,
    letterSpacing: `${ls}em`,
    textAlign: align,
    color,
    '--p-ps': `${ps}em`,
  };
  if (fx === 'outline') {
    s.color = 'transparent';
    s.WebkitTextStroke = `${Math.max(1, +(size * 0.025).toFixed(2))}px ${color === 'transparent' ? 'var(--p-ink)' : color}`;
    s.paintOrder = 'stroke fill';
  }
  if (kind === 'text') {
    const fill = resolveFill(el.fill, theme);
    if (fill.kind !== 'none') s.background = fill.css;
    const r = Number.isFinite(+el.radius) ? Math.max(0, +el.radius) : 0;
    if (r) s.borderRadius = px(r);
    const sw = Number.isFinite(+el.sw) ? Math.max(0, +el.sw) : 0;
    if (el.stroke && sw > 0) s.boxShadow = `inset 0 0 0 ${px(sw)} ${colorCss(el.stroke, 'transparent')}`;
  }
  if (el.fit === 'shrink') s.overflow = 'hidden';
  return s;
}

export function shapeTextDefaults(deck, el) {
  const u = (deck.w || 1920) / 1920;
  const out = {};
  if (el.size == null) out.size = Math.round(44 * u * 100) / 100;
  if (el.font == null) out.font = '@body';
  if (el.color == null) out.color = '@ink';
  if (el.align == null) out.align = 'center';
  if (el.valign == null) out.valign = 'middle';
  if (el.pad == null) out.pad = Math.round(16 * u * 100) / 100;
  if (el.lh == null) out.lh = 1.25;
  return out;
}
