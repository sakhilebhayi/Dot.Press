import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizeOps, opLines, moveSummary, reorderSummary, orderAfterMoves, elSetChanges, isSafeFix, chunkOps, slideNo, elLabel } from '../opsText.js';

const doc = {
  slides: Array.from({ length: 9 }, (_, i) => ({ id: String(i + 1), els: i === 0 ? [{ id: 'e1', type: 'text', size: 18, name: 'Title' }, { id: 'e2', type: 'text', doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Hello there world' }] }] } }] : [] })),
};

test('numeric ids from the server become strings', () => {
  const ops = normalizeOps([
    { op: 'slide.move', id: 4, after: 8 },
    { op: 'slide.set', id: 5, section_id: 12 },
    { op: 'el.set', slide: 3, id: 'e1', p: { size: 20 } },
    { op: 'section.add', id: 'sec_x', title: 'A', after: null },
    { op: 'slide.add', tid: 't_1', after: 7, slide: { title: 'x', section_id: 3 } },
  ]);
  assert.deepEqual(ops[0], { op: 'slide.move', id: '4', after: '8' });
  assert.equal(ops[1].section_id, '12');
  assert.equal(ops[2].slide, '3');
  assert.equal(ops[2].id, 'e1');
  assert.equal(ops[3].after, null);
  assert.equal(ops[4].after, '7');
  assert.equal(ops[4].slide.section_id, '3');
  assert.deepEqual(normalizeOps(null), []);
});

test('slide moves are described by the slides that really move, with the current numbering', () => {
  // The server moves 6, 7 and 8 up behind slide 3; the person should hear that slides 4 and 5 go after 8.
  const ops = [{ op: 'slide.move', id: '6', after: '3' }, { op: 'slide.move', id: '7', after: '6' }, { op: 'slide.move', id: '8', after: '7' }];
  assert.deepEqual(orderAfterMoves(['1', '2', '3', '4', '5', '6', '7', '8', '9'], ops), ['1', '2', '3', '6', '7', '8', '4', '5', '9']);
  assert.deepEqual(moveSummary(ops, doc), ['Moves slides 4 and 5 after slide 8']);
});

test('a single slide moved to the start', () => {
  assert.deepEqual(reorderSummary(['a', 'b', 'c'], ['c', 'a', 'b']), ['Moves slide 3 to the start']);
  assert.deepEqual(reorderSummary(['a', 'b', 'c'], ['a', 'b', 'c']), []);
});

test('two separate moves are two sentences', () => {
  assert.deepEqual(reorderSummary(['a', 'b', 'c', 'd', 'e', 'f'], ['b', 'a', 'c', 'e', 'd', 'f']).length, 2);
});

test('el.set changes read the current value for the before column', () => {
  const ch = elSetChanges({ op: 'el.set', slide: '1', id: 'e1', p: { size: 24, lh: 1.2 } }, doc);
  assert.deepEqual(ch, [
    { key: 'size', label: 'font size', before: '18', after: '24' },
    { key: 'lh', label: 'line height', before: 'none', after: '1.2' },
  ]);
  assert.equal(elSetChanges({ op: 'el.set', slide: '1', id: 'e2', p: { doc: null } }, doc)[0].before, '"Hello there world"');
});

test('op lines are plain sentences', () => {
  const lines = opLines([
    { op: 'el.set', slide: '1', id: 'e1', p: { size: 24 } },
    { op: 'el.add', slide: '2', el: { type: 'text', name: 'Source' } },
    { op: 'slide.add', tid: 't', after: '3', slide: { title: 'The ask' } },
    { op: 'slide.add', tid: 't2', after: null, slide: {} },
    { op: 'section.add', id: 's', title: 'Costs' },
    { op: 'el.remove', slide: '1', id: 'e2' },
  ], doc);
  assert.deepEqual(lines, [
    'Slide 1: change the font size of "Title"',
    'Slide 2: add a text box "Source"',
    'Add a slide "The ask" after slide 3',
    'Add a slide at the start',
    'Add the section "Costs"',
    'Slide 1: remove "Hello there world"',
  ]);
  assert.equal(opLines(Array.from({ length: 20 }, (_, i) => ({ op: 'section.add', id: `s${i}`, title: `T${i}` })), doc, { max: 5 }).at(-1), 'and 15 more changes');
});

test('identical consecutive lines are counted', () => {
  const same = { op: 'el.set', slide: '1', id: 'e1', p: { size: 24 } };
  assert.deepEqual(opLines([same, same, same], doc), ['Slide 1: change the font size of "Title" (3 times)']);
});

test('safe fixes touch looks, never content', () => {
  assert.equal(isSafeFix({ ops: [{ op: 'el.set', slide: '1', id: 'a', p: { size: 20, x: 10 } }] }), true);
  assert.equal(isSafeFix({ ops: [{ op: 'el.set', slide: '1', id: 'a', p: { doc: {} } }] }), false);
  assert.equal(isSafeFix({ ops: [{ op: 'el.set', slide: '1', id: 'a', p: { alt: null } }] }), false);
  assert.equal(isSafeFix({ ops: [{ op: 'el.remove', slide: '1', id: 'a' }] }), false);
  assert.equal(isSafeFix({ ops: [] }), false);
  assert.equal(isSafeFix(null), false);
});

test('helpers: slide numbers, labels and chunks', () => {
  assert.equal(slideNo(doc, 3), 3);
  assert.equal(slideNo(doc, 'nope'), null);
  assert.equal(elLabel({ type: 'image' }), 'the image');
  assert.equal(elLabel(undefined), 'an element that is no longer there');
  assert.deepEqual(chunkOps(Array.from({ length: 5 }, (_, i) => i), 2), [[0, 1], [2, 3], [4]]);
});

import { fixPreview } from '../opsText.js';

test('fix preview: few property changes become before and after rows, anything else becomes lines', () => {
  const p = fixPreview([{ op: 'el.set', slide: 1, id: 'e1', p: { size: 24 } }], doc);
  assert.deepEqual(p.rows, [{ target: 'Slide 1, "Title"', label: 'font size', before: '18', after: '24' }]);
  assert.deepEqual(p.lines, []);
  const q = fixPreview([{ op: 'el.set', slide: 1, id: 'e1', p: { size: 24 } }, { op: 'el.remove', slide: 1, id: 'e2' }], doc);
  assert.equal(q.rows.length, 0);
  assert.equal(q.lines.length, 2);
  const many = fixPreview(Array.from({ length: 8 }, () => ({ op: 'el.set', slide: 1, id: 'e1', p: { x: 1 } })), doc);
  assert.equal(many.rows.length, 0);
});
