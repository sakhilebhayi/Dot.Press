import { test } from 'node:test';
import assert from 'node:assert/strict';
import { beatStatus, beadRows, coverageCount, detection, checkRows, checkSummary, transitionRows } from '../storyModel.js';

const doc = { slides: ['10', '11', '12', '13'].map((id) => ({ id, els: [] })) };
const beat = (id, covered, slides = [], optional = false) => ({ id, label: id.toUpperCase(), question: `${id}?`, optional, covered, slides });
const story = (fw, beats) => ({ framework: { id: 'situation-complication-resolution', name: 'Situation, complication, resolution', strength: 'strong', coverage: 0.75, forced: false, ...fw }, beats });

test('a beat is present, weak (slides but little said) or missing (no slide)', () => {
  assert.equal(beatStatus(beat('a', true, ['10'])), 'present');
  assert.equal(beatStatus(beat('b', false, ['10'])), 'weak');
  assert.equal(beatStatus(beat('c', false, [])), 'missing');
});

test('beat rows resolve slide numbers and drop slides that are gone', () => {
  const rows = beadRows(story({}, [beat('a', true, ['10', '12', 'gone'])]), doc);
  assert.deepEqual(rows[0].slides, [{ id: '10', no: 1 }, { id: '12', no: 3 }]);
  assert.equal(rows[0].word, 'present');
});

test('optional beats do not count against the deck', () => {
  const s = story({}, [beat('a', true), beat('b', false), beat('c', false, [], true)]);
  assert.deepEqual(coverageCount(s), { found: 1, total: 2 });
});

test('the headline says what the deck reads like and how many beats were found', () => {
  const s = story({}, [beat('a', true), beat('b', true), beat('c', false)]);
  const d = detection(s);
  assert.equal(d.headline, 'This reads like Situation, complication, resolution: 2 of 3 beats found.');
  assert.equal(d.weak, false);
  assert.match(d.body, /strong match/i);
});

test('a weak match says so and offers the free structure', () => {
  const d = detection(story({ strength: 'weak' }, [beat('a', true), beat('b', false)]));
  assert.equal(d.weak, true);
  assert.equal(d.offerFree, true);
  assert.match(d.body, /weak/i);
});

test('when the server fell back to the free structure the person is told nothing was forced', () => {
  const d = detection(story({ id: 'free', name: 'Free structure', strength: 'weak' }, [beat('open', true)]));
  assert.match(d.headline, /No standard structure fits/);
  assert.match(d.body, /None is forced/);
  assert.equal(d.offerFree, false);
});

test('a structure the person chose that fits badly still reports against it', () => {
  const d = detection(story({ forced: true, name: 'Training', strength: 'weak' }, [beat('a', false), beat('b', false)]));
  assert.equal(d.headline, 'You chose Training: 0 of 2 beats found.');
  assert.equal(d.offerFree, true);
});

test('no story means no sentence', () => {
  assert.deepEqual(detection(null), { headline: '', body: '', weak: false, offerFree: false });
});

test('checklist rows are pass or gap, with the slide to look at', () => {
  const rows = checkRows({ checks: [
    { id: 'purpose_early', label: 'Purpose stated early', status: 'ok', detail: 'ok', slides: ['10'] },
    { id: 'conclusion', label: 'A conclusion', status: 'weak', detail: 'thanks only', slides: ['13'] },
    { id: 'call_to_action', label: 'A call to action', status: 'missing', detail: 'none', slides: ['nope'] },
  ] }, doc);
  assert.deepEqual(rows.map((r) => r.word), ['pass', 'gap', 'gap']);
  assert.deepEqual(rows[1].slides, [{ id: '13', no: 4 }]);
  assert.deepEqual(rows[2].slides, []);
  assert.equal(checkSummary(rows), '1 of 3 pass');
  assert.equal(checkSummary([]), '');
});

test('weak transitions carry the question to answer', () => {
  const rows = transitionRows({ weak_transitions: [{ from: '10', to: '11', reason: 'r', question: 'How does A lead to B?' }] }, doc);
  assert.equal(rows[0].from.no, 1);
  assert.equal(rows[0].to.no, 2);
  assert.equal(rows[0].question, 'How does A lead to B?');
});
