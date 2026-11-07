/**
 * Slide transition logic for the Motion panel (pure, node tested, no DOM).
 *
 * A slide's `transition` is the one that plays when the audience ARRIVES at that slide (player/transitions.js:
 * moving forward plays the incoming slide's transition, moving back plays the leaving slide's, direction flipped).
 * So the panel edits the transition into the current slide and previews it between the previous slide and this one.
 *
 *   TRANSITION_OPTIONS, DIR_OPTIONS, EASE_OPTIONS   what the picker offers
 *   isDirectional(type)                              push, wipe and reveal take a direction
 *   readChoice(slide)                                {type: 'none'|..., dir, dur, ease} for the controls
 *   changeTransition(slide, change)                  next `transition` value (null for none) after one control change
 *   sameTransition(a, b)
 *   targetSlideIds(doc, slide, scope)                'all' | 'section' | 'this' -> slide ids
 *   transitionOps(doc, ids, transition)              slide.set ops, skipping slides that already have it
 *   previewPair(doc, slideId)                        {from, to, fallback} the two slides the preview plays between
 *   previewCycle(transition, {reduced})              timing of one preview loop in ms
 */
import { DEFAULT_TRANSITION_MS, DIRS, normalizeTransition } from '../../../player/transitions.js';

export const TRANSITION_OPTIONS = [
  { type: 'none', label: 'None', hint: 'A straight cut' },
  { type: 'fade', label: 'Fade', hint: 'The new slide dissolves in' },
  { type: 'push', label: 'Push', hint: 'Both slides travel together' },
  { type: 'wipe', label: 'Wipe', hint: 'The new slide is drawn across' },
  { type: 'reveal', label: 'Reveal', hint: 'The old slide moves away' },
  { type: 'zoom', label: 'Zoom', hint: 'The new slide scales into place' },
  { type: 'morph', label: 'Morph', hint: 'Matching elements travel' },
];
export const DIR_OPTIONS = [
  { value: 'l', label: 'Left', title: 'Enters from the left' },
  { value: 'r', label: 'Right', title: 'Enters from the right' },
  { value: 'u', label: 'Top', title: 'Enters from the top' },
  { value: 'd', label: 'Bottom', title: 'Enters from the bottom' },
];
export const EASE_OPTIONS = [
  { value: 'out', label: 'Settle' },
  { value: 'inout', label: 'Smooth' },
  { value: 'spring', label: 'Spring' },
];
export const MIN_DUR = 100;
export const MAX_DUR = 4000;

const DIRECTIONAL = new Set(['push', 'wipe', 'reveal']);
export const isDirectional = (type) => DIRECTIONAL.has(type);
export const optionFor = (type) => TRANSITION_OPTIONS.find((o) => o.type === type) || TRANSITION_OPTIONS[0];

export function readChoice(slide) {
  const t = normalizeTransition(slide?.transition);
  if (!t) return { type: 'none', dir: 'r', dur: DEFAULT_TRANSITION_MS, ease: 'inout' };
  return { ...t };
}

/** @param {{type?: string, dir?: string, dur?: number, ease?: string}} change */
export function changeTransition(slide, change = {}) {
  const cur = readChoice(slide);
  const next = { ...cur, ...change };
  if (next.type === 'none') return null;
  const dur = Math.round(+next.dur);
  return {
    type: next.type,
    dir: DIRS.includes(next.dir) ? next.dir : 'r',
    dur: Number.isFinite(dur) ? Math.min(MAX_DUR, Math.max(MIN_DUR, dur)) : DEFAULT_TRANSITION_MS,
    ease: ['out', 'inout', 'spring'].includes(next.ease) ? next.ease : 'inout',
  };
}

export function sameTransition(a, b) {
  const x = a ? normalizeTransition(a) : null;
  const y = b ? normalizeTransition(b) : null;
  if (!x || !y) return !x && !y;
  return x.type === y.type && x.dir === y.dir && x.dur === y.dur && x.ease === y.ease;
}

export function targetSlideIds(doc, slide, scope) {
  const slides = doc?.slides || [];
  if (scope === 'all') return slides.map((s) => s.id);
  if (scope === 'section') {
    if (!slide) return [];
    return slides.filter((s) => (s.section_id ?? null) === (slide.section_id ?? null)).map((s) => s.id);
  }
  return slide ? [slide.id] : [];
}

export function sectionTitle(doc, slide) {
  if (!slide?.section_id) return null;
  return (doc?.deck?.sections || []).find((s) => s.id === slide.section_id)?.title || null;
}

export function transitionOps(doc, ids, transition) {
  const want = new Set(ids);
  const ops = [];
  for (const s of doc?.slides || []) {
    if (!want.has(s.id) || sameTransition(s.transition, transition)) continue;
    ops.push({ op: 'slide.set', id: s.id, transition: transition ? { ...transition } : null });
  }
  return ops;
}

/**
 * The slides a preview plays between. The transition belongs to the slide that is arriving, so the pair is
 * (previous slide, this slide). The first slide has no previous slide; its preview then uses (this, next) so the
 * motion can still be judged, and says so (`fallback: true`). A one slide deck has no pair.
 * Hidden slides are skipped, as they are when presenting.
 */
export function previewPair(doc, slideId) {
  const slides = doc?.slides || [];
  const i = slides.findIndex((s) => s.id === slideId);
  if (i < 0) return null;
  const visible = (s) => s && !s.hidden;
  let prev = null;
  for (let k = i - 1; k >= 0; k -= 1) if (visible(slides[k])) { prev = slides[k]; break; }
  if (prev) return { from: prev, to: slides[i], fallback: false, arriving: slides[i] };
  for (let k = i + 1; k < slides.length; k += 1) if (visible(slides[k])) return { from: slides[i], to: slides[k], fallback: true, arriving: slides[k] };
  return null;
}

export const HOLD_BEFORE = 700;
export const HOLD_AFTER = 1000;

/** One loop of the preview: hold the first slide, run the transition, hold the second, cut back. */
export function previewCycle(transition, { reduced = false } = {}) {
  if (reduced) return { instant: true, holdA: HOLD_BEFORE, run: 0, holdB: HOLD_AFTER, total: HOLD_BEFORE + HOLD_AFTER };
  const t = normalizeTransition(transition);
  const run = t ? t.dur : 0;
  return { instant: run === 0, holdA: HOLD_BEFORE, run, holdB: HOLD_AFTER, total: HOLD_BEFORE + run + HOLD_AFTER };
}

/** Plain-language summary for the picker footer and the live region. */
export function describeTransition(t) {
  if (!t) return 'No transition. Slides cut.';
  const o = optionFor(t.type);
  const dir = isDirectional(t.type) ? ` from the ${DIR_OPTIONS.find((d) => d.value === t.dir)?.label.toLowerCase()}` : '';
  return `${o.label}${dir}, ${(t.dur / 1000).toFixed(t.dur % 100 === 0 ? 1 : 2)} s.`;
}
