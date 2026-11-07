import test from 'node:test';
import assert from 'node:assert/strict';
import {
  gridSize, anchors, anchorAt, expandRange, canMerge, mergeRange, unmerge, setCell, insertRow, insertCol, deleteRow, deleteCol, nextCell,
} from '../lib/table.js';
import { tableModel } from '../../../render/table.js';

const cell = (t, extra = {}) => ({ t, ...extra });
const grid = (rows, extra = {}) => ({
  id: 't', type: 'table', x: 0, y: 0, w: 600, h: rows.length * 60, rowh: rows.map(() => 60), colw: Array.from({ length: rows[0].length }, () => 1 / rows[0].length), rows, ...extra,
});
const texts = (el) => el.rows.map((r) => r.map((c) => c.t).join('|'));
const sum = (a) => Math.round(a.reduce((x, y) => x + y, 0) * 1e4) / 1e4;

const base = grid([[cell('a'), cell('b'), cell('c')], [cell('d'), cell('e'), cell('f')], [cell('g'), cell('h'), cell('i')]]);

test('grid size and anchors without merges', () => {
  assert.deepEqual(gridSize(base), { rows: 3, cols: 3 });
  assert.equal(anchors(base).length, 9);
  assert.deepEqual(anchorAt(base, 1, 2), { r: 1, c: 2, rs: 1, cs: 1 });
});

test('anchors follow the renderer: covered cells are not drawn', () => {
  const t = grid([[cell('a', { span: [2, 2] }), cell(''), cell('c')], [cell(''), cell(''), cell('f')], [cell('g'), cell('h'), cell('i')]]);
  const a = anchors(t);
  assert.equal(a.length, 6);
  const drawn = tableModel(t).rows.reduce((n, r) => n + r.cells.length, 0);
  assert.equal(drawn, a.length, 'same count as the renderer');
  assert.deepEqual(anchorAt(t, 1, 1), { r: 0, c: 0, rs: 2, cs: 2 });
});

test('merge takes the text of the cells and expands over partial merges', () => {
  const m = mergeRange(base, { r1: 0, c1: 0, r2: 1, c2: 1 });
  assert.deepEqual(m.rows[0][0], { t: 'a\nb\nd\ne', span: [2, 2] });
  assert.equal(m.rows[0][1].t, '');
  assert.ok(canMerge(base, { r1: 0, c1: 0, r2: 0, c2: 1 }));
  assert.ok(!canMerge(base, { r1: 1, c1: 1, r2: 1, c2: 1 }));
  // a range that cuts through a merged cell grows to hold it
  const t = { ...base, rows: m.rows };
  assert.deepEqual(expandRange(t, { r1: 1, c1: 1, r2: 2, c2: 2 }), { r1: 0, c1: 0, r2: 2, c2: 2 });
  assert.equal(base.rows[0][0].t, 'a', 'input untouched');
  // reversed ranges work
  assert.deepEqual(expandRange(base, { r1: 2, c1: 2, r2: 1, c2: 1 }), { r1: 1, c1: 1, r2: 2, c2: 2 });
});

test('unmerge keeps the text in the first cell', () => {
  const m = { ...base, rows: mergeRange(base, { r1: 0, c1: 0, r2: 0, c2: 1 }).rows };
  const u = unmerge(m, 0, 1);
  assert.equal(u.rows[0][0].span, undefined);
  assert.equal(u.rows[0][0].t, 'a\nb');
  assert.equal(unmerge(base, 0, 0), null);
});

test('set cell', () => {
  assert.equal(setCell(base, 1, 1, { t: 'X' }).rows[1][1].t, 'X');
  assert.equal(setCell(base, 9, 9, { t: 'X' }), null);
  assert.equal(base.rows[1][1].t, 'e');
});

test('insert a row keeps the heights and grows merged cells it cuts through', () => {
  const r = insertRow(base, 1);
  assert.equal(r.rows.length, 4);
  assert.deepEqual(texts({ rows: r.rows }), ['a|b|c', '||', 'd|e|f', 'g|h|i']);
  assert.equal(r.rowh.length, 4);
  assert.equal(r.h, 240);
  const t = { ...base, rows: mergeRange(base, { r1: 0, c1: 0, r2: 1, c2: 0 }).rows };
  const cut = insertRow(t, 1);
  assert.deepEqual(cut.rows[0][0].span, [3, 1]);
  assert.equal(insertRow(base, 99).rows.length, 4, 'clamps to the end');
  assert.equal(insertRow(base, 0).rows[0][0].t, '');
});

test('insert a column rebalances the widths to exactly 1', () => {
  const r = insertCol(base, 3);
  assert.equal(r.rows[0].length, 4);
  assert.equal(sum(r.colw), 1);
  assert.equal(r.colw.length, 4);
  assert.ok(Math.abs(r.colw[3] - 0.25) < 1e-3);
  const t = { ...base, rows: mergeRange(base, { r1: 0, c1: 0, r2: 0, c2: 1 }).rows };
  assert.deepEqual(insertCol(t, 1).rows[0][0].span, [1, 3]);
});

test('delete a row or column and fix merged cells that touch it', () => {
  const d = deleteRow(base, 1);
  assert.deepEqual(texts({ rows: d.rows }), ['a|b|c', 'g|h|i']);
  assert.equal(d.h, 120);
  assert.equal(deleteRow(grid([[cell('only')]]), 0), null, 'never the last row');
  // a merged cell that started on the deleted row moves down and shrinks
  const t = { ...base, rows: mergeRange(base, { r1: 0, c1: 0, r2: 1, c2: 0 }).rows };
  const dr = deleteRow(t, 0);
  assert.equal(dr.rows[0][0].t, 'a\nd');
  assert.equal(dr.rows[0][0].span, undefined);
  // a merged cell the row passes through shrinks by one
  const t2 = { ...base, rows: mergeRange(base, { r1: 0, c1: 0, r2: 2, c2: 0 }).rows };
  assert.deepEqual(deleteRow(t2, 1).rows[0][0].span, [2, 1]);
  const dc = deleteCol(base, 0);
  assert.deepEqual(texts({ rows: dc.rows }), ['b|c', 'e|f', 'h|i']);
  assert.equal(sum(dc.colw), 1);
  assert.equal(deleteCol(grid([[cell('a')], [cell('b')]]), 0), null);
  const t3 = { ...base, rows: mergeRange(base, { r1: 0, c1: 0, r2: 0, c2: 2 }).rows };
  assert.deepEqual(deleteCol(t3, 1).rows[0][0].span, [1, 2]);
});

test('the renderer agrees with every edit: the drawn grid is always rectangular', () => {
  let t = base;
  const steps = [
    (x) => mergeRange(x, { r1: 0, c1: 0, r2: 1, c2: 1 }),
    (x) => insertRow(x, 1), (x) => insertCol(x, 1), (x) => deleteRow(x, 0), (x) => deleteCol(x, 2), (x) => insertRow(x, 99), (x) => deleteCol(x, 0),
  ];
  for (const s of steps) {
    const p = s(t);
    if (p) t = { ...t, ...p };
    const m = tableModel(t);
    const area = m.rows.reduce((n, r) => n + r.cells.reduce((k, c) => k + c.rowspan * c.colspan, 0), 0);
    assert.equal(area, gridSize(t).rows * gridSize(t).cols, 'drawn cells tile the grid');
    assert.equal(t.rowh.length, t.rows.length);
    assert.equal(t.colw.length, gridSize(t).cols);
  }
});

test('Tab order over drawn cells', () => {
  const t = { ...base, rows: mergeRange(base, { r1: 0, c1: 0, r2: 0, c2: 1 }).rows };
  assert.deepEqual(nextCell(t, 0, 0, 1), { r: 0, c: 2, rs: 1, cs: 1 });
  assert.deepEqual(nextCell(t, 0, 1, 1), { r: 0, c: 2, rs: 1, cs: 1 }, 'a covered position acts as its merged cell');
  assert.equal(nextCell(base, 2, 2, 1), null);
  assert.deepEqual(nextCell(base, 1, 0, -1), { r: 0, c: 2, rs: 1, cs: 1 });
});
