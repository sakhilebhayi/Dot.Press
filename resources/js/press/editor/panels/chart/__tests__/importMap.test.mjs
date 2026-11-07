import test from 'node:test';
import assert from 'node:assert/strict';
import { applyImport, cellsFromApi, cellsFromText, fileSrc, importedLabel, mapImport } from '../importMap.js';
import { sampleChart } from '../../../../charts/index.js';

const CSV = 'Month,Sales,Cost\nJan,100,80\nFeb,120,90\nMar,150,95';

test('pasted CSV: header row and category column are guessed, series come from number columns', () => {
  const m = mapImport(cellsFromText(CSV), { type: 'column' });
  assert.equal(m.ok, true);
  assert.deepEqual(m.guess, { row: true, col: true });
  assert.deepEqual(m.data.categories, ['Jan', 'Feb', 'Mar']);
  assert.deepEqual(m.data.series.map((s) => s.name), ['Sales', 'Cost']);
  assert.deepEqual(m.roles.map((r) => r.role), ['category', 'series', 'series']);
  assert.deepEqual(m.preview.header, ['Month', 'Sales', 'Cost']);
  assert.equal(m.stats.series, 2);
});

test('headers can be forced off', () => {
  const m = mapImport(cellsFromText('1,2\n3,4\n5,6'), { type: 'column', firstRowHeader: false, firstColHeader: false });
  assert.equal(m.guess.row, false);
  assert.deepEqual(m.data.series.map((s) => s.name), ['Series 1', 'Series 2']);
  const forced = mapImport(cellsFromText('1,2\n3,4\n5,6'), { type: 'column', firstRowHeader: true, firstColHeader: false });
  assert.deepEqual(forced.data.series.map((s) => s.name), ['1', '2']);
  assert.equal(forced.data.series[0].values.length, 2);
});

test('orientation rows: each table row becomes a series', () => {
  const t = 'Series,Q1,Q2,Q3\nNorth,5,6,7\nSouth,2,3,4';
  const m = mapImport(cellsFromText(t), { type: 'column', orientation: 'rows' });
  assert.equal(m.ok, true);
  assert.deepEqual(m.data.categories, ['Q1', 'Q2', 'Q3']);
  assert.deepEqual(m.data.series.map((s) => s.name), ['North', 'South']);
  assert.deepEqual(m.guess, { row: true, col: true });
});

test('text columns are skipped and text in number columns is counted', () => {
  const t = 'Region,Sales,Notes,Cost\nN,10,ok,5\nS,abc,fine,6\nE,30,bad,7';
  const m = mapImport(cellsFromText(t), { type: 'column' });
  assert.deepEqual(m.stats.skipped, ['Notes']);
  assert.equal(m.stats.textCells, 1);
  assert.ok(m.warnings.some((w) => /"Notes"/.test(w)));
  assert.ok(m.warnings.some((w) => /1 cell in a number column is text/.test(w)));
  assert.equal(m.data.series[0].values[1], null);
});

test('rows from the server API are strings and are read like pasted cells', () => {
  const rows = [['Item', 'Price'], ['Apple', '1,234.50'], ['Pear', '(5)'], ['Fig', '']];
  const cells = cellsFromApi(rows);
  assert.deepEqual(cells[1], ['Apple', 1234.5]);
  assert.deepEqual(cells[2], ['Pear', -5]);
  assert.deepEqual(cells[3], ['Fig', null]);
  const m = mapImport(cells, { type: 'bar' });
  assert.deepEqual(m.data.series[0].values, [1234.5, -5, null]);
  assert.deepEqual(cellsFromApi([['a\tb', 'c "q"'], ['1', '2']])[0], ['a b', 'c "q"']);
});

test('limits: more than 500 rows or 40 series warns', () => {
  const rows = [['k', 'v'], ...Array.from({ length: 520 }, (_, i) => ['r' + i, i])];
  const m = mapImport(rows, { type: 'column' });
  assert.equal(m.data.categories.length, 500);
  assert.ok(m.warnings.some((w) => /520 rows/.test(w)));
  const wide = [Array.from({ length: 46 }, (_, i) => 'c' + i), Array.from({ length: 46 }, (_, i) => i)];
  const w = mapImport([['k', ...wide[0]], ['a', ...wide[1]], ['b', ...wide[1]]], { type: 'column' });
  assert.equal(w.data.series.length, 40);
  assert.ok(w.warnings.some((x) => /more than 40/.test(x)));
});

test('type specific mapping: scatter, kpi and map; and failures explain themselves', () => {
  const sc = mapImport(cellsFromText('x,y\n1,2\n2,4\n3,9'), { type: 'scatter' });
  assert.equal(sc.ok, true);
  assert.equal(sc.data.series[0].points.length, 3);
  const k = mapImport(cellsFromText('Metric,Value,Change\nRevenue,100,4.5\nChurn,2,-1'), { type: 'kpi' });
  assert.equal(k.data.kpis.length, 2);
  const map = mapImport(cellsFromText('Country,Users\nSouth Africa,10\nFrance,4'), { type: 'map' });
  assert.deepEqual(map.data.regions, [{ code: 'ZA', value: 10 }, { code: 'FR', value: 4 }]);
  const bad = mapImport(cellsFromText('Country,Users\nNarnia,10\nOz,4'), { type: 'map' });
  assert.equal(bad.ok, false);
  assert.match(bad.why, /country code or name/);
  const empty = mapImport([], { type: 'column' });
  assert.equal(empty.ok, false);
  const words = mapImport(cellsFromText('a,b\nc,d'), { type: 'column' });
  assert.equal(words.ok, false);
  assert.match(words.why, /No column of numbers/);
});

test('applyImport replaces the data, keeps the formatting and records the source', () => {
  const chart = sampleChart('column');
  const m = mapImport(cellsFromText(CSV), { type: 'column' });
  const src = fileSrc('sales.csv', new Date('2026-11-07T10:00:00Z'));
  assert.deepEqual(src, { kind: 'file', name: 'sales.csv', at: '2026-11-07T10:00:00.000Z' });
  const out = applyImport(chart, m.data, src);
  assert.equal(out.opt, chart.opt);
  assert.equal(out.data.categories.length, 3);
  assert.deepEqual(out.src, src);
  assert.equal(importedLabel(out.src), 'Imported from sales.csv on 7 Nov 2026');
  assert.equal(importedLabel({ kind: 'manual' }), '');
  assert.deepEqual(applyImport(chart, m.data, null).src, { kind: 'manual' });
});
