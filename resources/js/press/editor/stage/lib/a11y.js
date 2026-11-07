/**
 * Words for keyboard and screen reader users. Pure.
 *
 *   TYPE_NAME                        element type -> plain name
 *   elementLabel(el, assets?)        'Text: Quarterly results', 'Picture: team on site', 'Shape: ellipse'
 *   elementPosition(el)              'at 120, 80, 640 by 360'
 *   selectionAnnouncement(els, i, n) 'Selected Text: Quarterly results. 2 of 7.'
 *   moveAnnouncement(el)             'Moved to 124, 80'
 *   resizeAnnouncement(el)           'Resized to 640 by 360'
 *   resizeByKey(el, key, {shift})    patch {w, h} for Alt+arrow (right and down grow, left and up shrink)
 *   slideAnnouncement(slide, i, n)   'Slide 3 of 12, Market size'
 *   stageLabel(slide, i, n)          accessible name of the slide area
 */
import { docToPlain } from '../../../render/doc.js';
import { round2 } from '../../core/util.js';

export const TYPE_NAME = {
  text: 'Text', shape: 'Shape', image: 'Picture', video: 'Video', audio: 'Audio', icon: 'Icon', chart: 'Chart', table: 'Table',
};

const trim = (s, n = 60) => (s.length > n ? `${s.slice(0, n - 1)}...` : s);

export function elementLabel(el, assets = {}) {
  const base = TYPE_NAME[el.type] || 'Element';
  let detail = '';
  if (el.name) detail = el.name;
  else if (el.type === 'text') detail = trim(docToPlain(el.doc).replace(/\s+/g, ' ').trim());
  else if (el.alt) detail = el.alt;
  else if (el.type === 'shape') {
    detail = el.kind || 'rect';
    const t = trim(docToPlain(el.doc).replace(/\s+/g, ' ').trim());
    if (t) detail += `, "${t}"`;
  } else if (el.type === 'image') detail = assets[el.src?.asset]?.name || '';
  else if (el.type === 'chart') detail = el.chart?.opt?.title || el.chart?.type || '';
  else if (el.type === 'icon') detail = el.name || '';
  else if (el.type === 'video' || el.type === 'audio') detail = assets[el.src?.asset]?.name || '';
  const flags = [el.lock ? 'locked' : '', el.hide ? 'hidden' : ''].filter(Boolean).join(', ');
  return `${base}${detail ? `: ${detail}` : ''}${flags ? ` (${flags})` : ''}`;
}

export const elementPosition = (el) => `at ${Math.round(el.x)}, ${Math.round(el.y)}, ${Math.round(el.w)} by ${Math.round(el.h)}`;

export function selectionAnnouncement(els, index = null, count = null) {
  if (!els.length) return 'Selection cleared';
  if (els.length === 1) {
    const where = index != null ? `. ${index + 1} of ${count}` : '';
    return `Selected ${elementLabel(els[0])}${where}`;
  }
  return `${els.length} elements selected`;
}

export const moveAnnouncement = (el) => `Moved to ${Math.round(el.x)}, ${Math.round(el.y)}`;
export const resizeAnnouncement = (el) => `Resized to ${Math.round(el.w)} by ${Math.round(el.h)}`;

export function resizeByKey(el, key, { shift = false } = {}) {
  const step = shift ? 10 : 1;
  const d = { ArrowRight: [step, 0], ArrowLeft: [-step, 0], ArrowDown: [0, step], ArrowUp: [0, -step] }[key];
  if (!d) return null;
  const w = round2(Math.max(8, el.w + d[0]));
  const h = round2(Math.max(el.type === 'shape' && (el.kind === 'line' || el.kind === 'arrow') ? 0 : 8, el.h + d[1]));
  if (w === el.w && h === el.h) return null;
  return { w, h };
}

export const slideAnnouncement = (slide, i, n) => `Slide ${i + 1} of ${n}${slide.title ? `, ${slide.title}` : ''}`;

export const stageLabel = (slide, i, n) => `Slide ${i + 1} of ${n}${slide.title ? `: ${slide.title}` : ''}. Tab moves between elements, Enter edits, arrow keys move, Alt with arrow keys resizes, Delete removes.`;
