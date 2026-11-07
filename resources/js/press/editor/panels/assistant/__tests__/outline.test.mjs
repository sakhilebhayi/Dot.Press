import { test } from 'node:test';
import assert from 'node:assert/strict';
import { outlineRows, renameRow, setBullets, bulletText, removeRow, moveRow, replaceBeat, withHeaders, outlineStats, outlineRequest, formError, rowWords, hasBullets } from '../outlineModel.js';

const model = (title, extra = {}) => ({ title, kind: 'bullets', slots: { title, bullets: ['a', 'b'] }, needs: [], ...extra });
const resp = {
  beats: [
    { id: 'title', label: 'Title', question: 'Called?', slides: [model('Deck', { kind: 'title', slots: { title: 'Deck', bullets: [] } })] },
    { id: 'problem', label: 'The problem', question: 'What is wrong?', slides: [model('P1'), model('P2')] },
    { id: 'ask', label: 'The ask', question: 'What do you want?', slides: [model('Ask', { needs: [{ slot: 'bullets', label: 'Needs your evidence', question: 'q' }], needs_evidence: true })] },
  ],
};

test('the outline becomes a flat list of editable rows with unique keys', () => {
  const rows = outlineRows(resp);
  assert.equal(rows.length, 4);
  assert.equal(new Set(rows.map((r) => r.key)).size, 4);
  assert.deepEqual(rows.map((r) => r.model.title), ['Deck', 'P1', 'P2', 'Ask']);
  rows[1].model.title = 'changed';
  assert.equal(resp.beats[1].slides[0].title, 'P1', 'the server answer is never mutated');
});

test('rename changes the title and the title slot, nothing else', () => {
  const rows = outlineRows(resp);
  const next = renameRow(rows, rows[1].key, 'Costs rose 12%');
  assert.equal(next[1].model.title, 'Costs rose 12%');
  assert.equal(next[1].model.slots.title, 'Costs rose 12%');
  assert.equal(next[1].model.slots.bullets.length, 2);
  assert.equal(rows[1].model.title, 'P1');
  assert.equal(next[2], rows[2]);
});

test('bullets are edited as lines, markers and blanks are dropped', () => {
  const rows = outlineRows(resp);
  const next = setBullets(rows, rows[1].key, '- first point\n\n* second\n• third\n  fourth  ');
  assert.deepEqual(next[1].model.slots.bullets, ['first point', 'second', 'third', 'fourth']);
  assert.equal(bulletText(next[1]), 'first point\nsecond\nthird\nfourth');
  assert.equal(hasBullets(rows[0]), false);
  assert.equal(hasBullets(rows[1]), true);
});

test('delete and reorder', () => {
  const rows = outlineRows(resp);
  assert.deepEqual(removeRow(rows, rows[2].key).map((r) => r.model.title), ['Deck', 'P1', 'Ask']);
  assert.deepEqual(moveRow(rows, 3, 1).map((r) => r.model.title), ['Deck', 'Ask', 'P1', 'P2']);
});

test('a regenerated beat replaces only that beat, in place', () => {
  const rows = outlineRows(resp);
  const fresh = { beats: [{ id: 'problem', label: 'The problem', question: 'What is wrong?', slides: [model('New P')] }] };
  const next = replaceBeat(rows, 'problem', fresh);
  assert.deepEqual(next.map((r) => r.model.title), ['Deck', 'New P', 'Ask']);
  assert.equal(replaceBeat(rows, 'nope', fresh), rows);
  assert.equal(replaceBeat(rows, 'problem', { beats: [] }), rows);
});

test('beat headers appear where the beat changes', () => {
  const rows = moveRow(outlineRows(resp), 3, 1);
  assert.deepEqual(withHeaders(rows).map((r) => r.header), ['Title', 'The ask', 'The problem', null]);
});

test('stats count slides and slides that need evidence', () => {
  assert.deepEqual(outlineStats(outlineRows(resp)), { slides: 4, needs: 1 });
});

test('words for a row', () => {
  assert.equal(rowWords({ model: { slots: { chart: {}, bullets: ['a'], kpis: [{}, {}] } } }), 'chart, 2 figures, 1 points');
});

test('the outline request: brief from the first line of pasted text, length clamped, empty fields left out', () => {
  assert.deepEqual(outlineRequest({ brief: '', source: '\n  Investor pitch for SolarGrid\nmore text', framework: 'auto', audience: '', length: 3 }), { brief: 'Investor pitch for SolarGrid', source: '\n  Investor pitch for SolarGrid\nmore text'.trim(), length: 5 });
  assert.deepEqual(outlineRequest({ brief: 'Board update', source: '', framework: 'training', audience: ' Board ', length: 99 }), { brief: 'Board update', framework: 'training', audience: 'Board', length: 40 });
});

test('the form needs something to work from', () => {
  assert.match(formError({ brief: '', source: '' }), /few words/);
  assert.equal(formError({ brief: 'Board update', source: '' }), '');
  assert.equal(formError({ brief: '', source: 'Some notes here' }), '');
});
