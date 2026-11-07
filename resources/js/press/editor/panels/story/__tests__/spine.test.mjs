import { test } from 'node:test';
import assert from 'node:assert/strict';
import { beatRows, slideRange, beatName, evidenceWords, reorderRequest, mergeTargets, splitOptions, previewEdit } from '../spineModel.js';

const doc = { slides: Array.from({ length: 9 }, (_, i) => ({ id: String(i + 1), title: `Slide title ${i + 1}`, els: [] })) };
const beats = [
  { id: 'b_a', claim: 'We are growing', slides: ['1', '2', '3'], section_id: null, hollow: false, evidence: { charts: 2, numbers: 3 } },
  { id: 'b_b', claim: 'Costs are under control', slides: ['4', '5'], section_id: null, hollow: true, evidence: {} },
  { id: 'b_c', claim: '', slides: ['6', '7', '8'], section_id: null, hollow: false, evidence: { sources: 1 } },
  { id: 'b_d', claim: 'Next steps', slides: ['9'], section_id: null, hollow: false, evidence: {} },
];
const rows = beatRows(beats, doc);

test('rows carry slide numbers, claim and the hollow state', () => {
  assert.equal(rows[1].hollow, true);
  assert.deepEqual(rows[0].slides.map((s) => s.no), [1, 2, 3]);
});

test('slide ranges and names read naturally', () => {
  assert.equal(slideRange(rows[0]), 'slides 1 to 3');
  assert.equal(slideRange(rows[3]), 'slide 9');
  assert.equal(slideRange({ slides: [{ no: 2 }, { no: 5 }] }), 'slides 2, 5');
  assert.equal(beatName(rows[2]), 'Untitled beat (slides 6 to 8)');
  assert.equal(beatName(rows[0]), 'We are growing');
});

test('evidence in words: hollow beats say so, solid beats count what they hold', () => {
  assert.equal(evidenceWords(rows[0]), '2 charts, 3 figures');
  assert.equal(evidenceWords(rows[1]), 'no evidence yet');
  assert.equal(evidenceWords(rows[2]), '1 source');
  assert.equal(evidenceWords(rows[3]), 'nothing to prove');
});

test('dragging a beat becomes "place it after the beat that now precedes it"', () => {
  assert.deepEqual(reorderRequest(rows, 1, 3), { type: 'reorder', beat: 'b_b', after: 'b_d' });
  assert.deepEqual(reorderRequest(rows, 3, 0), { type: 'reorder', beat: 'b_d', after: null });
  assert.deepEqual(reorderRequest(rows, 0, 1), { type: 'reorder', beat: 'b_a', after: 'b_b' });
  assert.equal(reorderRequest(rows, 2, 2), null);
  assert.equal(reorderRequest(rows, 9, 0), null);
});

test('merge targets exclude the beat itself; split offers every slide but the first', () => {
  assert.deepEqual(mergeTargets(rows, 'b_a').map((o) => o.value), ['b_b', 'b_c', 'b_d']);
  assert.deepEqual(splitOptions(rows[0], doc), [{ value: '2', label: 'Slide 2: Slide title 2' }, { value: '3', label: 'Slide 3: Slide title 3' }]);
  assert.deepEqual(splitOptions(rows[3], doc), []);
});

test('reorder preview names the slides that move, as the brief asks', () => {
  // Beat b_b (slides 4, 5) goes after b_c (6 to 8): the server moves 6, 7, 8 up behind slide 3.
  const response = {
    ops: [{ op: 'slide.move', id: '6', after: '3' }, { op: 'slide.move', id: '7', after: '6' }, { op: 'slide.move', id: '8', after: '7' }],
    beats: [], adopted_sections: false,
  };
  const lines = previewEdit({ type: 'reorder', beat: 'b_b', after: 'b_c' }, response, rows, doc);
  assert.equal(lines[0], 'Moves "Costs are under control" after "Untitled beat (slides 6 to 8)".');
  assert.equal(lines[1], 'Moves slides 4 and 5 after slide 8');
  assert.equal(lines.length, 2);
});

test('rename preview shows old and new claim and says when sections get adopted', () => {
  const response = { ops: [{ op: 'section.add', id: 's' }], beats: [{ id: 'b_a', claim: 'Growth is steady', slides: ['1', '2', '3'] }], adopted_sections: true };
  const lines = previewEdit({ type: 'rename', beat: 'b_a', claim: 'Growth is steady' }, response, rows, doc);
  assert.equal(lines[0], 'Renames the claim "We are growing" to "Growth is steady".');
  assert.match(lines[1], /one section per beat/);
  assert.equal(lines.length, 2);
});

test('merge and split previews', () => {
  const merge = previewEdit({ type: 'merge', beat: 'b_d', into: 'b_c' }, { ops: [], beats: [], adopted_sections: false }, rows, doc);
  assert.match(merge[0], /Merges "Next steps" into "Untitled beat \(slides 6 to 8\)"\. Its slide joins the end of that beat\./);
  const split = previewEdit({ type: 'split', beat: 'b_a', at: '3' }, { ops: [], beats: [{ id: 'b_a', slides: ['1', '2'] }, { id: 'b_n', claim: 'Slide title 3', slides: ['3'] }], adopted_sections: false }, rows, doc);
  assert.match(split[0], /Splits "We are growing" at slide 3\. Slide 3 and the ones after it become a new beat "Slide title 3"\./);
});
