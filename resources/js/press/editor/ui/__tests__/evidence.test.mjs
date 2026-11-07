import test from 'node:test';
import assert from 'node:assert/strict';
import { slideEvidence, slideTitle, isClaimTitle, wordCount, evidenceSummary } from '../evidence.js';
import { textDoc } from '../../core/doc.js';

const text = (id, str, o = {}) => ({ id, type: 'text', x: 0, y: 0, w: 100, h: 40, hide: false, doc: textDoc(str), ...o });
const slide = (title, els = [], o = {}) => ({ id: 's1', title, els, hidden: false, ...o });

test('a topic title with no evidence is hollow and says why', () => {
  const ev = slideEvidence(slide('Output by week'));
  assert.equal(ev.state, 'hollow');
  assert.equal(ev.claim, false);
  assert.equal(ev.evidence, false);
  assert.equal(ev.reasons.length, 2);
  assert.match(ev.reasons[1], /No chart, table, picture or figure yet/);
});

test('a claim title plus a chart is solid', () => {
  const ev = slideEvidence(slide('Output beat plan in six of eight weeks', [{ id: 'c', type: 'chart', hide: false }]));
  assert.equal(ev.state, 'solid');
  assert.deepEqual(ev.reasons, []);
});

test('tables, pictures and videos count as evidence, hidden ones do not', () => {
  for (const type of ['table', 'image', 'video']) {
    assert.equal(slideEvidence(slide('Three things drove the result', [{ id: 'x', type, hide: false }])).state, 'solid', type);
  }
  assert.equal(slideEvidence(slide('Three things drove the result', [{ id: 'x', type: 'image', hide: true }])).state, 'hollow');
});

test('a text element with a number or a quotation mark is evidence', () => {
  assert.equal(slideEvidence(slide('Costs fell across every site', [text('t', 'Unit cost fell from 41 to 36')])).evidence, true);
  assert.equal(slideEvidence(slide('Costs fell across every site', [text('t', 'The foreman said “we never saw this before”')])).evidence, true);
  assert.equal(slideEvidence(slide('Costs fell across every site', [text('t', 'The foreman said "never"')])).evidence, true);
});

test('an apostrophe is not a quotation mark, plain prose is not evidence', () => {
  assert.equal(slideEvidence(slide('Costs fell across every site', [text('t', "It's what we'd expect")])).evidence, false);
});

test('the title element and placeholders never prove themselves', () => {
  const s = slide('Q3 output beat plan by 4 percent', [
    text('a', 'Q3 output beat plan by 4 percent', { role: 'title' }),
    text('b', 'Q3 2026', { role: 'subtitle' }),
    text('c', 'Page 4', { role: 'number' }),
    text('d', 'Q3 output beat plan by 4 percent'),
  ]);
  assert.equal(slideEvidence(s).evidence, false);
  assert.equal(slideEvidence(s).claim, true);
});

test('claim rule: at least four words and a verb or a number', () => {
  assert.equal(isClaimTitle('Three things drove the result'), true);
  assert.equal(isClaimTitle('Revenue doubled in Asia'), true); // -ed word
  assert.equal(isClaimTitle('Top 5 risks for 2027 planning'), true); // number
  assert.equal(isClaimTitle('Drove the result'), false); // three words
  assert.equal(isClaimTitle('Q3 revenue'), false);
  assert.equal(isClaimTitle('Our mining operations overview'), false); // noun phrase, no verb, no number
  assert.equal(isClaimTitle(''), false);
});

test('the title falls back to the title placeholder text', () => {
  const s = slide('', [text('a', 'Customers are renewing earlier', { role: 'title' })]);
  assert.equal(slideTitle(s), 'Customers are renewing earlier');
  assert.equal(slideEvidence(s).claim, true);
  assert.equal(slideTitle(slide('')), '');
  assert.match(slideEvidence(slide('')).reasons[0], /no title yet/);
});

test('word count and the summary sentence', () => {
  assert.equal(wordCount("It's  a  trade-off, 3.4 grams"), 5);
  const hollow = slideEvidence(slide('Output by week'));
  assert.match(evidenceSummary(hollow, true), /Hidden from the presentation/);
  assert.equal(evidenceSummary(slideEvidence(slide('Costs fell across every site', [{ id: 'c', type: 'chart' }]))), 'Claim with evidence');
});
