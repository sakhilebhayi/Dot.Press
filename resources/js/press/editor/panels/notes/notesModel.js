/**
 * Speaker notes logic (pure, node tested, no DOM).
 *
 *   DEFAULT_WPM, MIN_WPM, MAX_WPM, MAX_NOTES   speaking pace limits and the server's 20000 character cap
 *   clampPace(n)                               a usable words per minute (default 130)
 *   wordCount(text)                            words (letters and digits, with inner apostrophes and hyphens)
 *   speakingSeconds(words, wpm)                whole seconds
 *   formatClock(seconds) / formatSpoken(seconds)   "1:20" and "1 min 20 s"
 *   paragraphs(text)                           blocks split on blank lines, for the teleprompter
 *   notesValue(text)                           what to store: null for empty or blank text
 *   deckNotes(slides, wpm)                     {words, seconds, withNotes, total, missing:[{id, number, title}], perSlide}
 *   slideLabel(slide)                          title, else first line of text, else "Untitled slide"
 */
import { docText } from '../../core/doc.js';

export const DEFAULT_WPM = 130;
export const MIN_WPM = 60;
export const MAX_WPM = 240;
export const MAX_NOTES = 20000;

export function clampPace(n) {
  const v = Math.round(+n);
  if (!Number.isFinite(v)) return DEFAULT_WPM;
  return Math.min(MAX_WPM, Math.max(MIN_WPM, v));
}

const WORD = /[\p{L}\p{N}][\p{L}\p{N}'’-]*/gu;
export function wordCount(text) {
  if (typeof text !== 'string' || !text) return 0;
  return (text.match(WORD) || []).length;
}

export function speakingSeconds(words, wpm = DEFAULT_WPM) {
  if (!words) return 0;
  return Math.round((words / clampPace(wpm)) * 60);
}

export function formatClock(seconds) {
  const s = Math.max(0, Math.round(seconds));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const r = s % 60;
  const pad = (n) => String(n).padStart(2, '0');
  return h > 0 ? `${h}:${pad(m)}:${pad(r)}` : `${m}:${pad(r)}`;
}

export function formatSpoken(seconds) {
  const s = Math.max(0, Math.round(seconds));
  if (s < 60) return `${s} s`;
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const r = s % 60;
  if (h > 0) return `${h} h ${m} min`;
  return r ? `${m} min ${r} s` : `${m} min`;
}

export function paragraphs(text) {
  if (typeof text !== 'string') return [];
  return text.replace(/\r\n?/g, '\n').split(/\n[ \t]*\n+/).map((p) => p.trim()).filter(Boolean);
}

export function notesValue(text) {
  return typeof text === 'string' && text.trim() !== '' ? text.slice(0, MAX_NOTES) : null;
}

export function slideLabel(slide) {
  const t = typeof slide?.title === 'string' ? slide.title.trim() : '';
  if (t) return t.length > 60 ? `${t.slice(0, 59)}…` : t;
  for (const el of slide?.els || []) {
    if (el?.type === 'text' && el.doc) {
      let text = '';
      try { text = docText(el.doc).replace(/\s+/g, ' ').trim(); } catch { text = ''; }
      if (text) return text.length > 60 ? `${text.slice(0, 59)}…` : text;
    }
  }
  return 'Untitled slide';
}

/** Hidden slides are skipped when presenting, so they count for nothing here. Numbers count every slide, as the Spine does. */
export function deckNotes(slides, wpm = DEFAULT_WPM) {
  const list = Array.isArray(slides) ? slides : [];
  let words = 0;
  let withNotes = 0;
  let total = 0;
  const missing = [];
  const perSlide = new Map();
  list.forEach((s, i) => {
    if (!s || s.hidden) return;
    total += 1;
    const w = wordCount(s.notes);
    perSlide.set(s.id, w);
    words += w;
    if (w > 0) withNotes += 1;
    else missing.push({ id: s.id, number: i + 1, title: slideLabel(s) });
  });
  return { words, seconds: speakingSeconds(words, wpm), withNotes, total, missing, perSlide };
}
