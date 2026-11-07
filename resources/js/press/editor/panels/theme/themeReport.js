/**
 * What a theme change will do to this deck, in numbers and in one plain sentence. Pure (node tested).
 *
 *   themeReport(doc, theme?) -> {following, keepOwn, partial, neutral, total, fontRoles, slides, sentence, detail}
 *     following   elements that use at least one theme token or a theme font role: they change with the theme
 *     keepOwn     elements whose colours are all hard coded: they keep their own colours
 *     partial     of the following ones, how many also hold a hard coded colour (that colour stays)
 *     neutral     elements with no colour at all (nothing to change)
 *   themeChanges(from, to) -> {colours: n, fonts: [{role, from, to}], radius, stroke, scale, background}
 *   changeSentence(changes)  one short line for the apply bar
 *   previewDocFor(doc, theme)  the document as it would look with the theme (core applyTheme), for drawing
 */
import { applyTheme, usageOf } from '../../core/theme.js';
import { cleanTheme, COLOR_ROLES } from './themeModel.js';

const noun = (n, one, many) => `${n} ${n === 1 ? one : many}`;

export function themeReport(doc, theme) {
  let following = 0;
  let keepOwn = 0;
  let partial = 0;
  let neutral = 0;
  let fontRoles = 0;
  const slides = [];
  for (const slide of doc.slides) {
    let on = 0;
    for (const el of slide.els) {
      const u = usageOf(el);
      const fontRole = u.fonts.some((f) => f === '@heading' || f === '@body');
      const follows = u.tokens.length > 0 || fontRole;
      if (fontRole) fontRoles++;
      if (follows) {
        following++;
        on++;
        if (u.hex.length) partial++;
      } else if (u.hex.length) keepOwn++;
      else neutral++;
    }
    if (on) slides.push({ slide: slide.id, elements: on });
  }
  const total = following + keepOwn + neutral;
  const sentence = `${noun(following, 'element follows', 'elements follow')} the theme, ${keepOwn} keep${keepOwn === 1 ? 's' : ''} ${keepOwn === 1 ? 'its' : 'their'} own colours.`;
  const detail = [];
  if (partial) detail.push(`${noun(partial, 'of the following elements also has', 'of the following elements also have')} a fixed colour, which stays.`);
  if (neutral) detail.push(`${noun(neutral, 'element has', 'elements have')} no colour to change.`);
  return { following, keepOwn, partial, neutral, total, fontRoles, slides, sentence, detail, themeId: theme?.id ?? null };
}

export function themeChanges(from, to) {
  const a = cleanTheme(from);
  const b = cleanTheme(to);
  let colours = 0;
  for (const { key } of COLOR_ROLES) if ((a.c[key] || '').toLowerCase() !== (b.c[key] || '').toLowerCase()) colours++;
  for (let i = 0; i < 8; i++) if ((a.c.chart[i] || '').toLowerCase() !== (b.c.chart[i] || '').toLowerCase()) colours++;
  const fonts = [];
  for (const role of ['heading', 'body']) if (a.t[role] !== b.t[role]) fonts.push({ role, from: a.t[role], to: b.t[role] });
  return {
    colours,
    fonts,
    radius: a.s.radius !== b.s.radius ? { from: a.s.radius, to: b.s.radius } : null,
    stroke: a.s.stroke !== b.s.stroke ? { from: a.s.stroke, to: b.s.stroke } : null,
    scale: a.t.scale !== b.t.scale ? { from: a.t.scale, to: b.t.scale } : null,
    background: JSON.stringify(a.bg) !== JSON.stringify(b.bg),
  };
}

/** A short list of what differs, in plain words (empty when the themes are the same). */
export function changeLines(changes) {
  const out = [];
  if (changes.colours) out.push(`${noun(changes.colours, 'colour changes', 'colours change')}.`);
  for (const f of changes.fonts) out.push(`The ${f.role} font changes from ${f.from} to ${f.to}.`);
  if (changes.radius) out.push(`Corner radius goes from ${changes.radius.from} to ${changes.radius.to}.`);
  if (changes.stroke) out.push(`Line thickness goes from ${changes.stroke.from} to ${changes.stroke.to}.`);
  if (changes.scale) out.push(`The type scale goes from ${changes.scale.from} to ${changes.scale.to}.`);
  if (changes.background) out.push('The default slide background changes.');
  return out;
}

/** One short line for the apply bar: "Changes 18 colours, the fonts and the corner radius." (empty when nothing differs). */
export function changeSentence(changes) {
  const parts = [];
  if (changes.colours) parts.push(noun(changes.colours, 'colour', 'colours'));
  if (changes.fonts.length === 2) parts.push('both fonts');
  else if (changes.fonts.length === 1) parts.push(`the ${changes.fonts[0].role} font`);
  const shape = [changes.radius && 'corner radius', changes.stroke && 'line thickness', changes.scale && 'type scale'].filter(Boolean);
  if (shape.length) parts.push(shape.length === 3 ? 'corner radius, line thickness and type scale' : shape.join(' and '));
  if (changes.background) parts.push('the default background');
  if (!parts.length) return '';
  const list = parts.length === 1 ? parts[0] : `${parts.slice(0, -1).join(', ')} and ${parts.at(-1)}`;
  return `Changes ${list}.`;
}

export function previewDocFor(doc, theme) {
  return applyTheme(doc, theme).doc;
}
