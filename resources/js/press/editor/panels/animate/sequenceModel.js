/**
 * The animation sequence of a slide as the Animate panel edits it (pure, node tested, no DOM).
 * Ordering is the player's own (player/sequence.js sortSteps): by `ord` when a step has one, else by the element's z
 * index, then z index, then step index. Grouping follows spec section 7: `click` starts a click group, `with` and
 * `after` stay in the current one, steps before the first click form the entry group (group 0).
 *
 *   PHASES, FX_BY_PHASE, FX_LABEL, EASES, STARTS     what the controls offer
 *   sequenceRows(slide)                              rows in play order {key, elId, stepIndex, step, group, first, number}
 *   addMenuOptions()                                 SelectMenu options for "Add animation"
 *   newStep(el, slide, {ph, fx, on})                 a fresh step (gets an `ord` only when the slide already orders by it)
 *   addPatches(slide, elements, {ph, fx})            [{id, p}] adding one step to each element (the first on click, the rest with it)
 *   stepPatch(el, index, change) / removePatch / clearPatch   [{id, p}] for one element
 *   reorderPatches(slide, from, to)                  [{id, p}] after moving the row at `from` to `to`
 *   hiddenAnimated(slide)                            number of hidden elements whose steps do not play
 *   elementLabel(el), clicksSummary(slide)
 */
import { EASES, ON, PHASES, normalizeStep, sortSteps } from '../../../player/sequence.js';
import { elementLabel as morphLabel } from '../motion/morphModel.js';

export { PHASES, EASES, ON };
export const elementLabel = morphLabel;
export const MAX_STEPS = 20; // the server keeps at most 20 steps per element
export const PHASE_LABEL = { in: 'Entrance', out: 'Exit', emph: 'Emphasis' };
export const PHASE_WORD = { in: 'in', out: 'out', emph: 'emph' };
export const FX_LABEL = {
  fade: 'Fade', rise: 'Rise', drop: 'Drop', 'slide-l': 'Slide left', 'slide-r': 'Slide right', zoom: 'Zoom', wipe: 'Wipe', pop: 'Pop', pulse: 'Pulse', spin: 'Spin', grow: 'Grow',
};
// Only effects the player draws as something meaningful for the phase (effects.js): pulse and spin as an entrance are a plain fade, wipe and slides as emphasis are a shake.
export const FX_BY_PHASE = {
  in: ['fade', 'rise', 'drop', 'slide-l', 'slide-r', 'zoom', 'wipe', 'pop', 'grow'],
  out: ['fade', 'rise', 'drop', 'slide-l', 'slide-r', 'zoom', 'wipe', 'pop', 'grow'],
  emph: ['pulse', 'spin', 'grow', 'pop', 'zoom', 'fade', 'rise', 'drop'],
};
export const STARTS = [
  { value: 'click', label: 'On click', title: 'Starts when you press Next' },
  { value: 'with', label: 'With', title: 'Starts together with the step before it' },
  { value: 'after', label: 'After', title: 'Starts when the step before it ends' },
];
export const EASE_LABELS = [{ value: 'out', label: 'Settle' }, { value: 'inout', label: 'Smooth' }, { value: 'spring', label: 'Spring' }];
export const START_WORD = { click: 'on click', with: 'with previous', after: 'after previous' };

export function addMenuOptions() {
  const out = [];
  for (const ph of PHASES) for (const fx of FX_BY_PHASE[ph]) out.push({ value: `${ph}:${fx}`, label: FX_LABEL[fx], group: PHASE_LABEL[ph] });
  return out;
}
export const fxOptions = (ph) => (FX_BY_PHASE[ph] || FX_BY_PHASE.in).map((fx) => ({ value: fx, label: FX_LABEL[fx] }));

const defaultDur = (ph, fx) => (fx === 'spin' ? 900 : ph === 'emph' ? 600 : 500);

/** The slide's playable steps in play order, with click grouping. Hidden elements do not play. */
export function sequenceRows(slide) {
  const entries = [];
  (slide?.els || []).forEach((el, z) => {
    if (!el || typeof el.id !== 'string' || el.hide || !Array.isArray(el.anim)) return;
    el.anim.slice(0, 40).forEach((raw, i) => {
      const step = normalizeStep(raw, el.id, i);
      if (step) entries.push({ step, z, i, el, raw });
    });
  });
  const rows = [];
  let group = 0;
  let clicks = 0;
  sortSteps(entries).forEach((e, n) => {
    if (e.step.on === 'click') { clicks += 1; group = clicks; }
    const first = n === 0 || e.step.on === 'click';
    rows.push({
      key: `${e.el.id}:${e.i}`, elId: e.el.id, el: e.el, stepIndex: e.i, step: e.step, raw: e.raw, group, first,
      number: e.step.on === 'click' ? clicks : null,
    });
  });
  return rows;
}

export function clicksSummary(slide) {
  const rows = sequenceRows(slide);
  const clicks = rows.filter((r) => r.step.on === 'click').length;
  return { steps: rows.length, clicks, entry: rows.filter((r) => r.group === 0).length };
}

export function hiddenAnimated(slide) {
  return (slide?.els || []).filter((el) => el?.hide && Array.isArray(el.anim) && el.anim.length).length;
}

const usesOrd = (slide) => (slide?.els || []).some((el) => Array.isArray(el.anim) && el.anim.some((s) => s && s.ord !== undefined && s.ord !== null));
const maxOrd = (slide) => {
  let m = -1;
  for (const el of slide?.els || []) for (const s of el.anim || []) if (s && Number.isFinite(+s.ord)) m = Math.max(m, Math.round(+s.ord));
  return m;
};

function freshStepId(el) {
  const taken = new Set((el.anim || []).map((s) => s?.id));
  for (;;) {
    const id = `a${Math.random().toString(36).slice(2, 9)}`;
    if (!taken.has(id)) return id;
  }
}

export function newStep(el, slide, { ph = 'in', fx = 'fade', on = 'click' } = {}) {
  const step = { id: freshStepId(el), ph, fx, on, delay: 0, dur: defaultDur(ph, fx), ease: 'out' };
  if (usesOrd(slide)) step.ord = Math.min(9999, maxOrd(slide) + 1);
  return step;
}

/** Add one step to each element. The first starts on click, the others play with it. */
export function addPatches(slide, elements, { ph, fx }) {
  const patches = [];
  let ordNext = usesOrd(slide) ? maxOrd(slide) + 1 : null;
  elements.forEach((el, n) => {
    if ((el.anim || []).length >= MAX_STEPS) return;
    const step = { id: freshStepId(el), ph, fx, on: n === 0 ? 'click' : 'with', delay: 0, dur: defaultDur(ph, fx), ease: 'out' };
    if (ordNext !== null) { step.ord = Math.min(9999, ordNext); ordNext += 1; }
    patches.push({ id: el.id, p: { anim: [...(el.anim || []), step] } });
  });
  return patches;
}

export function stepPatch(el, index, change) {
  const anim = (el.anim || []).map((s, i) => (i === index ? { ...s, ...change } : s));
  return [{ id: el.id, p: { anim } }];
}
export function removePatch(el, index) {
  const anim = (el.anim || []).filter((_, i) => i !== index);
  return [{ id: el.id, p: { anim: anim.length ? anim : null } }];
}
export const clearPatch = (el) => [{ id: el.id, p: { anim: null } }];

/**
 * Move the row at `from` to `to` and write the new play order as `ord` on every playable step of the slide
 * (0, 1, 2 ... in play order), so the result does not depend on layering. Steps the player drops (unknown effect)
 * are left as they are. Returns only the elements whose steps changed.
 */
export function reorderPatches(slide, from, to) {
  const rows = sequenceRows(slide);
  if (from === to || from < 0 || to < 0 || from >= rows.length || to >= rows.length) return [];
  const order = rows.slice();
  const [moved] = order.splice(from, 1);
  order.splice(to, 0, moved);
  const ordOf = new Map(order.map((r, n) => [r.key, Math.min(9999, n)]));
  const patches = [];
  for (const el of slide.els) {
    if (!Array.isArray(el.anim)) continue;
    let changed = false;
    const anim = el.anim.map((s, i) => {
      const o = ordOf.get(`${el.id}:${i}`);
      if (o === undefined || s?.ord === o) return s;
      changed = true;
      return { ...s, ord: o };
    });
    if (changed) patches.push({ id: el.id, p: { anim } });
  }
  return patches;
}

/** Steps `with` or `after` cannot be first of the slide's clicks; say what a reorder did to the grouping. */
export function groupsAfterMove(slide, from, to) {
  const patched = new Map(reorderPatches(slide, from, to).map((p) => [p.id, p.p.anim]));
  return sequenceRows({ ...slide, els: slide.els.map((el) => (patched.has(el.id) ? { ...el, anim: patched.get(el.id) } : el)) });
}
