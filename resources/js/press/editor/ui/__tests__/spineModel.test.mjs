import test from 'node:test';
import assert from 'node:assert/strict';
import { buildRows, dropGap, dropTarget, moveOps, stepTarget, rangeSelect, addSectionOps, slidesOfSection } from '../spineModel.js';

const S = (id, section_id = null) => ({ id, section_id });
const ids = (rows) => rows.map((r) => (r.kind === 'slide' ? r.slide.id : `[${r.id}]`));

const slides = [S('a', 'x'), S('b', 'x'), S('c', 'y'), S('d', 'y'), S('e', null)];
const sections = [{ id: 'x', title: 'Open' }, { id: 'y', title: 'Evidence' }, { id: 'z', title: 'Later' }];

test('rows: a header per run, numbers are deck positions, empty sections go last', () => {
  const rows = buildRows(slides, sections);
  assert.deepEqual(ids(rows), ['[x]', 'a', 'b', '[y]', 'c', 'd', '[null]', 'e', '[z]']);
  assert.equal(rows.find((r) => r.kind === 'slide' && r.slide.id === 'd').number, 4);
  assert.equal(rows[0].count, 2);
  assert.equal(rows[8].empty, true);
  assert.equal(rows[6].title, 'No section');
});

test('rows: slides before the first section have no header; collapsed sections hide their slides but keep the count', () => {
  const rows = buildRows([S('p'), S('q', 'x'), S('r', 'x')], sections, new Set(['x']));
  assert.deepEqual(ids(rows), ['p', '[x]', '[y]', '[z]']);
  assert.equal(rows[1].count, 2);
  assert.equal(rows[1].collapsed, true);
});

test('dropGap finds the gap a pointer falls in', () => {
  const mids = [10, 30, 50];
  assert.equal(dropGap(mids, 0), 0);
  assert.equal(dropGap(mids, 20), 1);
  assert.equal(dropGap(mids, 40), 2);
  assert.equal(dropGap(mids, 99), 3);
  assert.equal(dropGap([], 5), 0);
});

test('dropTarget: between slides, under a header, at the very top and at the end', () => {
  const rows = buildRows(slides, sections);
  // gap 3 is between b and the [y] header: it stays at the end of section x
  assert.deepEqual(dropTarget(rows, 3, ['e'], slides), { after: 'b', sectionId: 'x' });
  // gap 4 is directly under the [y] header: first of section y, after b
  assert.deepEqual(dropTarget(rows, 4, ['e'], slides), { after: 'b', sectionId: 'y' });
  // gap 5 between c and d
  assert.deepEqual(dropTarget(rows, 5, ['a'], slides), { after: 'c', sectionId: 'y' });
  // gap 0 is above everything
  assert.deepEqual(dropTarget(rows, 0, ['e'], slides), { after: null, sectionId: null });
  // the end of the list is after e in no section
  assert.deepEqual(dropTarget(rows, rows.length - 1, ['a'], slides), { after: 'e', sectionId: null });
});

test('dropTarget ignores the slides being moved and joins a collapsed section at its end', () => {
  const rows = buildRows(slides, sections, new Set(['y']));
  // rows: [x] a b [y] [null] e [z]; gap 4 sits straight under the collapsed [y] header
  assert.deepEqual(dropTarget(rows, 4, ['a'], slides), { after: 'd', sectionId: 'y' });
  const all = buildRows(slides, sections);
  assert.deepEqual(dropTarget(all, 3, ['b'], slides), { after: 'a', sectionId: 'x' });
});

test('moveOps chains the block in deck order and changes the section where needed', () => {
  const ops = moveOps(slides, ['d', 'a'], { after: 'e', sectionId: null });
  assert.deepEqual(ops, [
    { op: 'slide.move', id: 'a', after: 'e' },
    { op: 'slide.set', id: 'a', section_id: null },
    { op: 'slide.move', id: 'd', after: 'a' },
    { op: 'slide.set', id: 'd', section_id: null },
  ]);
});

test('moveOps is empty when nothing changes and only sets the section when the order holds', () => {
  assert.deepEqual(moveOps(slides, ['b'], { after: 'a', sectionId: 'x' }), []);
  assert.deepEqual(moveOps(slides, ['b'], { after: 'a', sectionId: 'y' }), [{ op: 'slide.set', id: 'b', section_id: 'y' }]);
  assert.deepEqual(moveOps(slides, ['a'], { after: null, sectionId: 'x' }), []);
});

test('moveOps to the top', () => {
  assert.deepEqual(moveOps(slides, ['c'], { after: null, sectionId: 'x' }), [
    { op: 'slide.move', id: 'c', after: null },
    { op: 'slide.set', id: 'c', section_id: 'x' },
  ]);
});

test('stepTarget moves a block one slide up or down and takes the neighbour section', () => {
  assert.deepEqual(stepTarget(slides, ['c'], -1), { after: 'a', sectionId: 'x' });
  assert.deepEqual(stepTarget(slides, ['b'], 1), { after: 'c', sectionId: 'y' });
  assert.deepEqual(stepTarget(slides, ['a'], -1), null);
  assert.deepEqual(stepTarget(slides, ['e'], 1), null);
  assert.deepEqual(stepTarget(slides, ['b', 'c'], -1), { after: null, sectionId: 'x' });
  assert.deepEqual(stepTarget(slides, ['b', 'c'], 1), { after: 'd', sectionId: 'y' });
  // moving a whole block of two up above a
  assert.deepEqual(moveOps(slides, ['b', 'c'], stepTarget(slides, ['b', 'c'], -1)).filter((o) => o.op === 'slide.move'), [
    { op: 'slide.move', id: 'b', after: null }, { op: 'slide.move', id: 'c', after: 'b' },
  ]);
});

test('rangeSelect and slidesOfSection', () => {
  assert.deepEqual(rangeSelect(slides, 'b', 'd'), ['b', 'c', 'd']);
  assert.deepEqual(rangeSelect(slides, 'd', 'b'), ['b', 'c', 'd']);
  assert.deepEqual(rangeSelect(slides, 'zz', 'b'), ['b']);
  assert.deepEqual(slidesOfSection(slides, 'y'), ['c', 'd']);
  assert.deepEqual(slidesOfSection(slides, null), ['e']);
});

test('addSectionOps starts a section at the slide and takes the rest of its run', () => {
  assert.deepEqual(addSectionOps(slides, 'b', 'n1', 'New'), [
    { op: 'section.add', id: 'n1', title: 'New' },
    { op: 'slide.set', id: 'b', section_id: 'n1' },
  ]);
  assert.deepEqual(addSectionOps(slides, 'c', 'n1', 'New').length, 3);
  assert.deepEqual(addSectionOps(slides, 'nope', 'n1', 'New'), []);
});

import { gridGap } from '../spineModel.js';

test('gridGap: left half drops before a cell, right half after, headers take the line', () => {
  const cell = (c, r) => ({ left: c * 100, right: c * 100 + 90, top: 40 + r * 70, bottom: 40 + r * 70 + 60, header: false });
  const rects = [{ left: 0, right: 200, top: 0, bottom: 30, header: true }, cell(0, 0), cell(1, 0), cell(0, 1)];
  assert.equal(gridGap(rects, 10, 10), 0); // upper half of the header
  assert.equal(gridGap(rects, 10, 28), 1); // lower half of the header
  assert.equal(gridGap(rects, 20, 60), 1); // left half of the first cell
  assert.equal(gridGap(rects, 80, 60), 2); // right half of the first cell
  assert.equal(gridGap(rects, 190, 60), 3); // right half of the second cell
  assert.equal(gridGap(rects, 20, 120), 3); // second line, left half
  assert.equal(gridGap(rects, 500, 500), 4); // far below: after the last cell
  assert.equal(gridGap([], 5, 5), 0);
});
