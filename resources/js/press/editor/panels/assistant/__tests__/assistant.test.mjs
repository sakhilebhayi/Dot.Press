import { test } from 'node:test';
import assert from 'node:assert/strict';
import { ASKS, targetOf, requestFor, degradedLine, modeLine, unsupportedLine, proposalCard, aiDrafts, aiDraftSlides, reviewedOps, isDeckAsk } from '../assistantModel.js';

const slide = { id: '5', els: [
  { id: 'a', type: 'text', origin: 'ai', doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Revenue grew a lot' }] }] } },
  { id: 'b', type: 'shape', origin: 'ai', name: 'Panel' },
  { id: 'c', type: 'text', origin: 'user' },
] };
const doc = { slides: [{ id: '4', els: [{ id: 'x', origin: 'ai' }] }, slide] };

test('every server suggestion kind has a button', () => {
  assert.deepEqual(ASKS.map((a) => a.id).sort(), ['assertion_title', 'better_layout', 'bulletise', 'comparison_slide', 'find_missing', 'improve', 'improve_flow', 'paragraph_to_visual', 'professional_tone', 'simplify', 'summarise_section']);
});

test('the request carries the slide and the single selected text box, or the highlighted text', () => {
  assert.deepEqual(requestFor('simplify', { slide, selected: [slide.els[0]] }), { kind: 'simplify', slide: '5', element: 'a' });
  assert.deepEqual(requestFor('simplify', { slide, selected: [] }), { kind: 'simplify', slide: '5' });
  assert.deepEqual(requestFor('improve', { slide, selected: [slide.els[0]], selectionText: ' tighten this ' }), { kind: 'improve', slide: '5', element: 'a', text: 'tighten this' });
  assert.deepEqual(requestFor('better_layout', { slide, selected: [slide.els[0]] }), { kind: 'better_layout', slide: '5' });
  assert.deepEqual(requestFor('improve_flow', { slide, selected: [] }), { kind: 'improve_flow' });
  assert.equal(isDeckAsk('find_missing'), true);
  assert.equal(isDeckAsk('simplify'), false);
});

test('several selected items count as the slide', () => {
  assert.equal(requestFor('simplify', { slide, selected: [slide.els[0], slide.els[2]] }).element, undefined);
  assert.match(targetOf({ slide, selected: [slide.els[0], slide.els[2]] }).words, /2 items are selected/);
});

test('the target is described in words', () => {
  assert.equal(targetOf({ slide, selected: [] }).words, 'the body text of this slide');
  assert.match(targetOf({ slide, selected: [slide.els[1]] }).words, /this slide|text box/);
  assert.equal(targetOf({ slide, selected: [], selectionText: 'x' }).words, 'the text you highlighted');
});

test('a degraded answer says why in plain words', () => {
  assert.equal(degradedLine({ degraded: false }), '');
  assert.equal(degradedLine({ degraded: true, degraded_reason: 'The daily AI quota is used up, so local rules answered.' }), 'The language model could not answer (The daily AI quota is used up, so local rules answered), so local rules answered instead.');
  assert.equal(degradedLine({ degraded: true }), 'The language model could not answer, so local rules answered instead.');
});

test('the mode line states plainly whether a model is connected', () => {
  assert.match(modeLine('local'), /No language model is connected/);
  assert.match(modeLine('ai'), /A language model is connected/);
});

test('unsupported numbers are named', () => {
  assert.equal(unsupportedLine([]), '');
  assert.equal(unsupportedLine(['18%', 'R4m']), 'These figures are not in your source: 18%, R4m. Check them before you keep this.');
});

test('a proposal card knows whether it can be applied, copied or is only a question', () => {
  const applicable = proposalCard({ id: 'p1', kind: 'simplify', summary: 'Tighten', mode: 'local', origin: 'local', slide: '5', before: 'a', after: 'b', diff: '[-a-]{+b+}', ops: [{ op: 'el.set', slide: 5, id: 'a', p: {} }], unsupported_numbers: [] }, doc);
  assert.equal(applicable.canApply, true);
  assert.equal(applicable.ops[0].slide, '5');
  assert.equal(applicable.slideNo, 2);
  assert.equal(applicable.ai, false);
  const copy = proposalCard({ id: 'p2', kind: 'improve', summary: 's', mode: 'local', before: 'a', after: 'b', ops: [] }, doc);
  assert.equal(copy.copyOnly, true);
  assert.equal(copy.canApply, false);
  const q = proposalCard({ id: 'p3', kind: 'find_missing', summary: 'Who is the buyer?', mode: 'local', before: '', after: '', ops: [] }, doc);
  assert.equal(q.isQuestion, true);
  const layout = proposalCard({ id: 'p4', kind: 'better_layout', summary: 'Use two columns', mode: 'local', ops: [{ op: 'slide.set', id: 5, layout: 'two' }], needs_composer: true, content: { kind: 'two' } }, doc);
  assert.equal(layout.needsLayout, true);
  assert.equal(layout.layoutId, 'two');
  const ai = proposalCard({ id: 'p5', kind: 'improve', summary: 's', mode: 'ai', origin: 'ai', unsupported_numbers: ['18%'], ops: [] }, doc);
  assert.equal(ai.ai, true);
  assert.match(ai.unsupported, /18%/);
});

test('AI drafts on the slide, and on other slides', () => {
  assert.deepEqual(aiDrafts(slide).map((d) => d.id), ['a', 'b']);
  assert.equal(aiDrafts(slide)[0].snippet, 'Revenue grew a lot');
  assert.equal(aiDrafts(null).length, 0);
  assert.deepEqual(aiDraftSlides(doc, '5'), [{ id: '4', no: 1, count: 1 }]);
});

test('marking reviewed removes the origin through el.set null', () => {
  assert.deepEqual(reviewedOps(5, ['a', 'b']), [
    { op: 'el.set', slide: '5', id: 'a', p: { origin: null } },
    { op: 'el.set', slide: '5', id: 'b', p: { origin: null } },
  ]);
});
