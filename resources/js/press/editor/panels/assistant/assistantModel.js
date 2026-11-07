/**
 * Logic of the Assistant panel (pure, node tested): which suggestions exist, what a request carries, how a proposal is
 * shown, what accepting it does, and the "AI draft" bookkeeping.
 *
 * Suggest response: {kind, mode: 'local'|'ai', degraded, degraded_reason, proposals: [{id, kind, summary, mode, origin, slide, element,
 * before, after, diff, ops, unsupported_numbers, content?, needs_composer?}], unavailable: [{kind, reason}], notes: [string], quota?}
 */
import { docText } from '../../core/doc.js';
import { elLabel, normalizeOps, slideNo } from '../intel/opsText.js';
import { diffFor } from '../intel/diff.js';

export const ASKS = [
  { id: 'improve', label: 'Improve the wording', hint: 'Tighten the text you have.', needs: 'text' },
  { id: 'simplify', label: 'Simplify', hint: 'Shorter sentences, less filler.', needs: 'text' },
  { id: 'bulletise', label: 'Split into bullets', hint: 'One point per sentence.', needs: 'text' },
  { id: 'professional_tone', label: 'More professional tone', hint: 'Needs a language model.', needs: 'text' },
  { id: 'assertion_title', label: 'Write a claim as the title', hint: 'A title that states the point.', needs: 'slide' },
  { id: 'paragraph_to_visual', label: 'Turn text into a visual', hint: 'A chart, figures or steps from what it says.', needs: 'text' },
  { id: 'comparison_slide', label: 'Make a comparison slide', hint: 'Two sides from the text.', needs: 'text' },
  { id: 'better_layout', label: 'Suggest a better layout', hint: 'A layout that fits what is on the slide.', needs: 'slide' },
  { id: 'summarise_section', label: 'Summarise this section', hint: 'A recap slide from the section.', needs: 'slide' },
  { id: 'improve_flow', label: 'Improve the flow', hint: 'Order and missing steps across the deck.', needs: 'deck' },
  { id: 'find_missing', label: 'Find missing information', hint: 'Questions the deck does not answer yet.', needs: 'deck' },
];

export const askLabel = (kind) => ASKS.find((a) => a.id === kind)?.label || kind;

/**
 * What the request is about. ctx: {slide, selected: [elements], selectionText}. Exactly one selected text element is sent as
 * the element; text highlighted inside a text box is sent as `text`.
 */
export function targetOf(ctx) {
  const sel = (ctx.selected || []).filter((e) => e);
  const text = (ctx.selectionText || '').trim();
  if (text) return { kind: 'text', element: sel.length === 1 ? sel[0].id : null, text, words: 'the text you highlighted' };
  if (sel.length === 1 && (sel[0].doc || sel[0].type === 'text')) return { kind: 'element', element: sel[0].id, text: '', words: `the text box ${elLabel(sel[0])}` };
  if (sel.length > 1) return { kind: 'slide', element: null, text: '', words: `this slide (${sel.length} items are selected, so all of its body text is used)` };
  return { kind: 'slide', element: null, text: '', words: 'the body text of this slide' };
}

export function requestFor(kind, ctx) {
  const t = targetOf(ctx);
  const ask = ASKS.find((a) => a.id === kind);
  const req = { kind };
  if (ask?.needs !== 'deck' && ctx.slide) req.slide = ctx.slide.id;
  if (ask?.needs === 'text') {
    if (t.element) req.element = t.element;
    if (t.text) req.text = t.text.slice(0, 8000);
  }
  return req;
}

export function isDeckAsk(kind) { return ASKS.find((a) => a.id === kind)?.needs === 'deck'; }

/** One plain sentence about what produced the answer, and why, when the model could not. */
export function degradedLine(result) {
  if (!result?.degraded) return '';
  const why = String(result.degraded_reason || '').trim().replace(/\.$/, '');
  return why ? `The language model could not answer (${why}), so local rules answered instead.` : 'The language model could not answer, so local rules answered instead.';
}

export function modeLine(aiMode) {
  return aiMode === 'ai'
    ? 'A language model is connected. Some suggestions use it, and those count against your daily AI quota. Anything it writes is marked "AI draft" until you mark it reviewed.'
    : 'No language model is connected, so suggestions come from local rules. They can tighten, split and rearrange your own words. They cannot rewrite, change tone or add facts.';
}

export function unsupportedLine(nums) {
  const list = (nums || []).filter(Boolean);
  if (!list.length) return '';
  return `These figures are not in your source: ${list.join(', ')}. Check them before you keep this.`;
}

/** The card for one proposal. `layouts` decides whether a layout change can be applied here. */
export function proposalCard(p, doc, { aiMode = 'local' } = {}) {
  const ops = normalizeOps(p.ops);
  return {
    id: p.id,
    kind: p.kind,
    title: p.summary,
    mode: p.mode === 'ai' ? 'ai' : 'local',
    diff: diffFor(p),
    after: p.after || '',
    unsupported: unsupportedLine(p.unsupported_numbers),
    ops,
    needsLayout: !!p.needs_composer,
    layoutId: p.content?.kind || ops.find((o) => o.op === 'slide.set')?.layout || null,
    slideNo: slideNo(doc, p.slide),
    canApply: ops.length > 0,
    copyOnly: ops.length === 0 && !!p.after && p.kind !== 'find_missing',
    isQuestion: p.kind === 'find_missing' && ops.length === 0,
    ai: p.mode === 'ai' || p.origin === 'ai',
    aiMode,
  };
}

/** The elements of a slide that an AI wrote and nobody has reviewed. */
export function aiDrafts(slide) {
  return (slide?.els || []).filter((e) => e.origin === 'ai').map((e) => ({
    id: e.id,
    label: elLabel(e),
    snippet: e.doc ? docText(e.doc).replace(/\s+/g, ' ').trim().slice(0, 80) : '',
    type: e.type,
  }));
}

export function aiDraftSlides(doc, exceptSlideId) {
  const out = [];
  doc.slides.forEach((s, i) => {
    if (s.id === exceptSlideId) return;
    const n = s.els.filter((e) => e.origin === 'ai').length;
    if (n) out.push({ id: s.id, no: i + 1, count: n });
  });
  return out;
}

/** "Mark as reviewed" = origin removed (null deletes the key; a missing origin means the person's own). */
export function reviewedOps(slideId, ids) {
  return ids.map((id) => ({ op: 'el.set', slide: String(slideId), id, p: { origin: null } }));
}
