import test from 'node:test';
import assert from 'node:assert/strict';
import { newTable } from '../../../core/doc.js';
import {
  targetCells, readCells, cellsPatch, structurePatch, countPatch, moveEdge, setWidth, evenColumns, fractionsText, MAX_ROWS, MAX_COLS,
} from '../lib/cells.js';
import {
  textToGrid, fileRowsToGrid, importPatch, tableChart, gridText, MAX_IMPORT_ROWS, MAX_IMPORT_COLS,
} from '../lib/import.js';
import { gridSize, anchors } from '../../../stage/lib/table.js';

const deck = { w: 1920, h: 1080 };
const make = (rows = 3, cols = 3) => ({ ...newTable(deck, { rows, cols }), id: 't' });
const sum = (a) => Math.round(a.reduce((x, y) => x + y, 0) * 1e4) / 1e4;

// ---- import ------------------------------------------------------------------------------------------------

test('pasted tab separated text keeps each cell exactly as written', () => {
  const { grid, delimiter } = textToGrid('Region\tSales\tShare\nNorth\tR1 200,50\t12%\nSouth\t980\t8.5%\n');
  assert.equal(delimiter, '\t');
  assert.deepEqual(grid, [['Region', 'Sales', 'Share'], ['North', 'R1 200,50', '12%'], ['South', '980', '8.5%']]);
});

test('csv with quotes, semicolons and ragged rows', () => {
  assert.deepEqual(textToGrid('a,b\n"x, y",2\n"say ""hi""",3').grid, [['a', 'b'], ['x, y', '2'], ['say "hi"', '3']]);
  assert.deepEqual(textToGrid('a;b;c\n1;2;3').grid, [['a', 'b', 'c'], ['1', '2', '3']]);
  assert.deepEqual(textToGrid('a,b,c\n1,2\n3').grid, [['a', 'b', 'c'], ['1', '2', ''], ['3', '', '']]);
});

test('empty, blank lines and a trailing delimiter are tidied', () => {
  assert.deepEqual(textToGrid('').grid, []);
  assert.deepEqual(textToGrid('  \n \n').grid, []);
  assert.deepEqual(textToGrid('a\tb\t\n1\t2\t\n\n').grid, [['a', 'b'], ['1', '2']]);
  assert.deepEqual(textToGrid('﻿a,b\r\n1,2\r\n').grid, [['a', 'b'], ['1', '2']]);
});

test('very large pastes are clipped and say so', () => {
  const big = Array.from({ length: MAX_IMPORT_ROWS + 20 }, (_, i) => `r${i},${i}`).join('\n');
  const r = textToGrid(big);
  assert.equal(r.grid.length, MAX_IMPORT_ROWS);
  assert.equal(r.clipped, true);
  const wide = [Array.from({ length: MAX_IMPORT_COLS + 5 }, (_, i) => `h${i}`).join(','), Array.from({ length: MAX_IMPORT_COLS + 5 }, (_, i) => i).join(',')].join('\n');
  const w = textToGrid(wide);
  assert.equal(w.grid[0].length, MAX_IMPORT_COLS);
  assert.equal(w.clipped, true);
  assert.equal(textToGrid('a,b\n1,2').clipped, false);
});

test('file rows from the server become a rectangular grid', () => {
  const { grid } = fileRowsToGrid([['Name', 'Qty'], ['Bolt', 4], ['Nut'], [], ['', '']]);
  assert.deepEqual(grid, [['Name', 'Qty'], ['Bolt', '4'], ['Nut', '']]);
  assert.deepEqual(fileRowsToGrid(null).grid, []);
  assert.deepEqual(fileRowsToGrid([[null, 'x']]).grid, [['', 'x']]);
});

test('importPatch replaces the cells and keeps the table where it is', () => {
  const el = make(3, 3);
  const { grid } = textToGrid('a,b,c\n1,2,3\n4,5,6\n7,8,9');
  const p = importPatch(el, grid, { header: true });
  assert.equal(p.rows.length, 4);
  assert.deepEqual(p.rows[1].map((c) => c.t), ['1', '2', '3']);
  assert.deepEqual(p.colw, el.colw, 'same column count keeps the widths');
  assert.equal(p.rowh.length, 4);
  assert.equal(p.h, p.rowh.reduce((a, b) => a + b, 0));
  assert.equal(p.header, true);
  assert.equal('x' in p || 'w' in p || 'font' in p, false);
  assert.equal(importPatch(el, grid, { header: false }).header, false);
});

test('importPatch makes even columns when the count changes, and new rows take the average height', () => {
  const el = make(2, 3);
  const p = importPatch(el, [['a', 'b'], ['c', 'd'], ['e', 'f']]);
  assert.deepEqual(p.colw, evenColumns(2));
  assert.equal(sum(p.colw), 1);
  assert.equal(p.rowh[2], p.rowh[0]);
});

test('importPatch refuses an empty grid', () => {
  assert.equal(importPatch(make(), []), null);
  assert.equal(importPatch(make(), [[]]), null);
});

test('a cell never carries more than the table can draw', () => {
  const { grid } = textToGrid('a,b\n' + 'x'.repeat(900) + ',2');
  assert.equal(grid[1][0].length, 500);
});

// ---- chart -------------------------------------------------------------------------------------------------

test('a table of numbers becomes chart data with a suggested type', () => {
  const el = make(4, 3);
  const g = [['Quarter', 'Sales', 'Costs'], ['Q1', '100', '60'], ['Q2', '140', '70'], ['Q3', '120', '80']];
  Object.assign(el, importPatch(el, g, { header: true }));
  const r = tableChart(el);
  assert.equal(r.ok, true);
  assert.deepEqual(r.data.categories, ['Q1', 'Q2', 'Q3']);
  assert.deepEqual(r.data.series.map((s) => s.name), ['Sales', 'Costs']);
  assert.deepEqual(r.data.series[0].values, [100, 140, 120]);
  assert.ok(r.type && r.why);
});

test('a table without numbers or without text says why not', () => {
  const el = make(2, 2);
  assert.equal(tableChart(el).ok, false);
  const words = { ...make(2, 2), ...importPatch(make(2, 2), [['a', 'b'], ['c', 'd']]) };
  const r = tableChart(words);
  assert.equal(r.ok, false);
  assert.match(r.message, /numbers/);
});

test('gridText is tab separated and flattens line breaks', () => {
  const el = make(2, 2);
  el.rows[0][0].t = 'a\nb';
  el.rows[1][1].t = 'z';
  assert.equal(gridText(el), 'a b\t\n\tz');
});

// ---- cells -------------------------------------------------------------------------------------------------

test('targets: selection, header row, first column, all', () => {
  const el = make(3, 3);
  assert.equal(targetCells(el, 'all').length, 9);
  assert.deepEqual(targetCells(el, 'header'), [{ r: 0, c: 0 }, { r: 0, c: 1 }, { r: 0, c: 2 }]);
  assert.deepEqual(targetCells(el, 'firstCol'), [{ r: 0, c: 0 }, { r: 1, c: 0 }, { r: 2, c: 0 }]);
  assert.deepEqual(targetCells(el, 'selection', { r1: 1, c1: 1, r2: 2, c2: 2 }), [{ r: 1, c: 1 }, { r: 1, c: 2 }, { r: 2, c: 1 }, { r: 2, c: 2 }]);
  assert.equal(targetCells(el, 'selection', null).length, 9, 'no selection falls back to every cell');
});

test('a merged cell counts once, and a selection grows to hold whole merges', () => {
  const el = make(3, 3);
  el.rows[0][0] = { t: 'wide', span: [1, 2] };
  assert.equal(targetCells(el, 'all').length, 8);
  const sel = targetCells(el, 'selection', { r1: 0, c1: 1, r2: 0, c2: 2 });
  assert.deepEqual(sel, [{ r: 0, c: 0 }, { r: 0, c: 2 }]);
});

test('cell values read as common, mixed or undefined', () => {
  const el = make(2, 2);
  el.rows[0][0].b = true;
  assert.equal(readCells(el, targetCells(el, 'all'), 'b'), 'mixed');
  assert.equal(readCells(el, [{ r: 0, c: 0 }], 'b'), true);
  assert.equal(readCells(el, [{ r: 1, c: 1 }], 'b'), null);
  assert.equal(readCells(el, [], 'b'), undefined);
});

test('cellsPatch sets and clears keys, and is null when nothing changes', () => {
  const el = make(2, 2);
  const p = cellsPatch(el, [{ r: 0, c: 0 }, { r: 0, c: 1 }], { b: true, al: 'c', fill: '@accent' });
  assert.equal(p.rows[0][0].b, true);
  assert.equal(p.rows[0][1].al, 'c');
  assert.equal(p.rows[1][0].b, undefined);
  assert.equal(el.rows[0][0].b, undefined, 'the element is not mutated');
  const el2 = { ...el, ...p };
  assert.equal(cellsPatch(el2, [{ r: 0, c: 0 }], { b: true }), null);
  const off = cellsPatch(el2, [{ r: 0, c: 0 }], { b: false, fill: null });
  assert.equal('b' in off.rows[0][0], false);
  assert.equal('fill' in off.rows[0][0], false);
  assert.equal(off.rows[0][0].t, '');
  assert.equal(cellsPatch(el, [], { b: true }), null);
});

// ---- structure ---------------------------------------------------------------------------------------------

test('rows and columns are added and deleted at the selected cell', () => {
  const el = make(3, 3);
  const sel = { r1: 1, c1: 1, r2: 1, c2: 1 };
  assert.equal(gridSize({ ...el, ...structurePatch(el, 'rowBelow', sel) }).rows, 4);
  const above = structurePatch(el, 'rowAbove', sel);
  el.rows[1][0].t = 'mark';
  const above2 = structurePatch(el, 'rowAbove', sel);
  assert.equal(above2.rows[2][0].t, 'mark', 'the old row moved down');
  assert.equal(above2.rows[1][0].t, '');
  assert.ok(above.rowh.length === 4);
  const right = structurePatch(el, 'colRight', sel);
  assert.equal(right.rows[0].length, 4);
  assert.equal(sum(right.colw), 1);
  const left = structurePatch(el, 'colLeft', sel);
  assert.equal(left.rows[0].length, 4);
  const delRow = structurePatch(el, 'delRow', sel);
  assert.equal(delRow.rows.length, 2);
  const delCol = structurePatch(el, 'delCol', sel);
  assert.equal(delCol.rows[0].length, 2);
  assert.equal(sum(delCol.colw), 1);
});

test('without a selection rows and columns go at the end', () => {
  const el = make(2, 2);
  el.rows[1][1].t = 'end';
  const p = structurePatch(el, 'rowBelow', null);
  assert.equal(p.rows.length, 3);
  assert.equal(p.rows[1][1].t, 'end');
  assert.equal(structurePatch(el, 'delRow', null).rows.length, 1);
  assert.equal(structurePatch(el, 'delCol', null).rows[0].length, 1);
});

test('a multi row selection deletes all of its rows, never the last one', () => {
  const el = make(5, 2);
  assert.equal(structurePatch(el, 'delRow', { r1: 1, c1: 0, r2: 3, c2: 0 }).rows.length, 2);
  const one = make(1, 1);
  assert.equal(structurePatch(one, 'delRow', null), null);
  assert.equal(structurePatch(one, 'delCol', null), null);
  assert.equal(structurePatch(make(2, 2), 'delRow', { r1: 0, c1: 0, r2: 1, c2: 1 }).rows.length, 1, 'stops at one row');
  assert.equal(structurePatch(el, 'nonsense', null), null);
});

test('limits stop growth', () => {
  const tall = { ...make(1, 1), rows: Array.from({ length: MAX_ROWS }, () => [{ t: '' }]), rowh: Array.from({ length: MAX_ROWS }, () => 60) };
  assert.equal(structurePatch(tall, 'rowBelow', null), null);
  const wide = { ...make(1, 1), rows: [Array.from({ length: MAX_COLS }, () => ({ t: '' }))], colw: evenColumns(MAX_COLS) };
  assert.equal(structurePatch(wide, 'colRight', null), null);
});

test('count patches grow or trim at the end', () => {
  const el = make(3, 3);
  assert.equal(countPatch(el, 'rows', 5).rows.length, 5);
  assert.equal(countPatch(el, 'rows', 1).rows.length, 1);
  assert.equal(countPatch(el, 'cols', 6).rows[0].length, 6);
  assert.equal(sum(countPatch(el, 'cols', 6).colw), 1);
  assert.equal(countPatch(el, 'cols', 3), null);
  assert.equal(countPatch(el, 'rows', 0).rows.length, 1, 'never below one');
  assert.equal(countPatch(el, 'rows', 9999).rows.length, MAX_ROWS);
  assert.equal(countPatch(el, 'cols', 9999).rows[0].length, MAX_COLS);
});

// ---- column widths -----------------------------------------------------------------------------------------

test('moving an edge trades width between neighbours and keeps the sum', () => {
  const w = [0.25, 0.25, 0.5];
  const m = moveEdge(w, 0, 0.1);
  assert.deepEqual(m.slice(0, 2), [0.35, 0.15]);
  assert.equal(m[2], 0.5);
  assert.equal(sum(m), 1);
  const stop = moveEdge(w, 0, 0.9, 0.05);
  assert.equal(stop[1], 0.05);
  assert.equal(sum(stop), 1);
  assert.equal(moveEdge(w, 0, -0.9, 0.05)[0], 0.05);
  assert.equal(moveEdge(w, 2, 0.1), w);
  assert.equal(moveEdge(w, -1, 0.1), w);
});

test('setting one width shares the change in proportion', () => {
  const w = [0.2, 0.3, 0.5];
  const s = setWidth(w, 0, 0.5);
  assert.equal(s[0], 0.5);
  assert.equal(sum(s), 1);
  assert.ok(Math.abs(s[1] / s[2] - 0.3 / 0.5) < 0.01);
  assert.ok(setWidth(w, 0, 0.999)[0] <= 1 - 0.04 * 2 + 1e-9);
  assert.equal(setWidth([1], 0, 0.5)[0], 1);
});

test('even columns sum to exactly one', () => {
  for (let n = 1; n <= 12; n += 1) assert.equal(sum(evenColumns(n)), 1);
  assert.equal(fractionsText([0.3333, 0.3333, 0.3334]), '33% 33% 33%');
});

test('anchors still agree with the grid after a patch', () => {
  const el = make(3, 3);
  const p = structurePatch(el, 'colRight', null);
  assert.equal(anchors({ ...el, ...p }).length, 12);
});
