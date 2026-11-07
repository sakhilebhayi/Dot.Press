import test from 'node:test';
import assert from 'node:assert/strict';
import { chipModel, chipPlacement, nextStep } from '../lib/beadChip.js';
import { slideEvidence } from '../../ui/evidence.js';
import { textDoc } from '../../core/doc.js';

const slide = (title, els = []) => ({ id: 's1', title, els });
const chart = { id: 'c', type: 'chart', hide: false };

test('a topic title with no evidence is hollow and asks for both', () => {
  const m = chipModel(slide('Output by week'));
  assert.equal(m.state, 'hollow');
  assert.match(m.message, /^Add a chart, table or figure, and say what is true in the title$/);
});

test('a claim without evidence asks for evidence only', () => {
  const m = chipModel(slide('Three things drove the result'));
  assert.equal(m.state, 'hollow');
  assert.equal(m.message, 'Add a chart, table or figure');
});

test('evidence without a claim asks for the claim only', () => {
  const m = chipModel(slide('Output by week', [chart]));
  assert.equal(m.state, 'hollow');
  assert.match(m.message, /^Say what is true in the title/);
});

test('a slide with no title asks for one', () => {
  assert.match(chipModel(slide('', [chart])).message, /^Write the claim/);
  assert.match(chipModel(slide('')).message, /add a chart, table or figure$/);
});

test('a solid slide says so and agrees with the evidence function', () => {
  const s = slide('Output beat plan in six of eight weeks', [chart]);
  assert.equal(slideEvidence(s).state, 'solid');
  assert.deepEqual(chipModel(s), { state: 'solid', word: 'solid', message: 'Claim with evidence' });
});

test('the chip never disagrees with the Spine bead', () => {
  const cases = [
    slide('Q3 production beat plan by 4 percent'),
    slide('Safety and cost at a glance', [chart]),
    slide('Costs fell 3 percent', [{ id: 't', type: 'text', role: 'body', hide: false, doc: textDoc('3.4 grams per tonne') }]),
  ];
  for (const s of cases) assert.equal(chipModel(s).state, slideEvidence(s).state);
});

test('nextStep is empty when nothing is missing', () => {
  assert.equal(nextStep({ title: 'x', evidence: true, claim: true }), '');
});

test('placement: above the slide when there is room, below when there is not, inside as the last resort', () => {
  const deck = { w: 1920, h: 1080 };
  const above = chipPlacement({ x: 24, y: 85 }, 0.575, deck, { w: 1104, h: 700 });
  assert.equal(above.where, 'above');
  assert.ok(above.top >= 4 && above.top + 32 <= 85);
  const below = chipPlacement({ x: 24, y: 10 }, 0.4, deck, { w: 900, h: 700 });
  assert.equal(below.where, 'below');
  assert.equal(below.top, 10 + 1080 * 0.4 + 8);
  const inside = chipPlacement({ x: 24, y: 10 }, 1, deck, { w: 900, h: 700 });
  assert.equal(inside.where, 'inside');
  assert.ok(inside.left >= 8 && inside.top >= 8);
});

test('placement keeps the chip inside the stage horizontally', () => {
  const p = chipPlacement({ x: 5000, y: 90 }, 1, { w: 1920, h: 1080 }, { w: 1000, h: 700 });
  assert.ok(p.left <= 880);
  assert.ok(chipPlacement({ x: -300, y: 90 }, 1, { w: 1920, h: 1080 }, { w: 1000, h: 700 }).left >= 8);
});
