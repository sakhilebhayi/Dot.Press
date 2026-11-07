import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseServerDiff, wordDiff, diffFor, changed } from '../diff.js';

test('the server word diff is split into same, removed and added runs', () => {
  const d = parseServerDiff('We [-really-] {+truly+} believe in this');
  assert.equal(d.kind, 'words');
  assert.deepEqual(d.segments, [
    { t: 'same', text: 'We' }, { t: 'del', text: 'really' }, { t: 'add', text: 'truly' }, { t: 'same', text: 'believe in this' },
  ]);
});

test('a new slide outline comes through as lines with their sign', () => {
  const d = parseServerDiff('+ New slide: Costs\n+ Cut 3%\n  kept');
  assert.equal(d.kind, 'lines');
  assert.deepEqual(d.lines, [{ sign: '+', text: 'New slide: Costs' }, { sign: '+', text: 'Cut 3%' }, { sign: ' ', text: '  kept' }]);
});

test('a layout change diff keeps its removed and added ids', () => {
  assert.deepEqual(parseServerDiff('[-bullets-]{+two+}').segments, [{ t: 'del', text: 'bullets' }, { t: 'add', text: 'two' }]);
});

test('wordDiff finds the common words and marks the rest', () => {
  const s = wordDiff('In order to win we utilize the plan', 'To win we use the plan');
  assert.ok(s.some((x) => x.t === 'del' && /order/.test(x.text)));
  assert.ok(s.some((x) => x.t === 'add' && /use/.test(x.text)));
  assert.ok(s.some((x) => x.t === 'same' && /win we/.test(x.text)));
  assert.equal(changed(s), true);
});

test('wordDiff of equal text has nothing changed, and of empty text is a plain add or remove', () => {
  assert.equal(changed(wordDiff('same words', 'same words')), false);
  assert.deepEqual(wordDiff('', 'new text'), [{ t: 'add', text: 'new text' }]);
  assert.deepEqual(wordDiff('old text', ''), [{ t: 'del', text: 'old text' }]);
});

test('wordDiff keeps line breaks of bullet lists', () => {
  const s = wordDiff('one\ntwo', 'one\nthree');
  const text = s.map((x) => x.text).join('|');
  assert.match(text, /one/);
  assert.ok(s.some((x) => x.t === 'add' && x.text === 'three'));
  assert.ok(s.some((x) => x.t === 'del' && x.text === 'two'));
});

test('huge texts fall back to a whole replacement instead of freezing', () => {
  const big = Array.from({ length: 900 }, (_, i) => `w${i}`).join(' ');
  const s = wordDiff(big, `${big} extra`);
  assert.equal(s.length, 2);
});

test('diffFor prefers the server diff', () => {
  assert.equal(diffFor({ diff: '[-a-]{+b+}', before: 'x', after: 'y' }).segments[0].text, 'a');
  assert.equal(diffFor({ before: 'x', after: 'y' }).segments.length, 2);
});
