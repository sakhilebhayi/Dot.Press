import test from 'node:test';
import assert from 'node:assert/strict';
import { clampPace, deckNotes, formatClock, formatSpoken, notesValue, paragraphs, slideLabel, speakingSeconds, wordCount, DEFAULT_WPM } from '../notesModel.js';

test('word counting handles punctuation, apostrophes, hyphens, numbers and accents', () => {
  assert.equal(wordCount(''), 0);
  assert.equal(wordCount(null), 0);
  assert.equal(wordCount('Revenue grew 12% in Q3.'), 5);
  assert.equal(wordCount("Don't split well-known words"), 4);
  assert.equal(wordCount('Café déjà vu\n\nsecond   paragraph'), 5);
  assert.equal(wordCount('... --- !!!'), 0);
});

test('speaking time at 130 words per minute, rounded to whole seconds', () => {
  assert.equal(DEFAULT_WPM, 130);
  assert.equal(speakingSeconds(130, 130), 60);
  assert.equal(speakingSeconds(65, 130), 30);
  assert.equal(speakingSeconds(0, 130), 0);
  assert.equal(speakingSeconds(100, 100), 60);
});

test('pace is clamped and a bad value falls back to the default', () => {
  assert.equal(clampPace(10), 60);
  assert.equal(clampPace(900), 240);
  assert.equal(clampPace('abc'), 130);
  assert.equal(clampPace(149.6), 150);
  assert.equal(speakingSeconds(120, 0), 60 * 120 / 60);
});

test('durations read well as a clock and in words', () => {
  assert.equal(formatClock(80), '1:20');
  assert.equal(formatClock(5), '0:05');
  assert.equal(formatClock(3725), '1:02:05');
  assert.equal(formatSpoken(45), '45 s');
  assert.equal(formatSpoken(80), '1 min 20 s');
  assert.equal(formatSpoken(120), '2 min');
  assert.equal(formatSpoken(3900), '1 h 5 min');
});

test('paragraphs split on blank lines only', () => {
  assert.deepEqual(paragraphs('One\ntwo\n\n  \nThree\r\n\r\nFour'), ['One\ntwo', 'Three', 'Four']);
  assert.deepEqual(paragraphs('   '), []);
});

test('empty or blank notes store as null', () => {
  assert.equal(notesValue('   \n'), null);
  assert.equal(notesValue('Hello'), 'Hello');
  assert.equal(notesValue('x'.repeat(30000)).length, 20000);
});

test('slide labels: title, then first text, then a fallback', () => {
  assert.equal(slideLabel({ title: ' Market ' }), 'Market');
  const el = { type: 'text', doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Growth plan' }] }] } };
  assert.equal(slideLabel({ title: '', els: [el] }), 'Growth plan');
  assert.equal(slideLabel({ els: [] }), 'Untitled slide');
});

test('deck totals count visible slides, list the ones without notes with their number, and follow the pace', () => {
  const slides = [
    { id: 'a', title: 'One', notes: 'one two three four five six' },
    { id: 'b', title: 'Two', notes: null },
    { id: 'c', title: 'Hidden', hidden: true, notes: null },
    { id: 'd', title: 'Four', notes: '   ' },
    { id: 'e', title: 'Five', notes: 'seven eight' },
  ];
  const r = deckNotes(slides, 60);
  assert.equal(r.words, 8);
  assert.equal(r.seconds, 8);
  assert.equal(r.withNotes, 2);
  assert.equal(r.total, 4);
  assert.deepEqual(r.missing, [{ id: 'b', number: 2, title: 'Two' }, { id: 'd', number: 4, title: 'Four' }]);
  assert.equal(r.perSlide.get('a'), 6);
  assert.equal(deckNotes(slides, 120).seconds, 4);
});
