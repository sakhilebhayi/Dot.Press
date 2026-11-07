import test from 'node:test';
import assert from 'node:assert/strict';
import { sampleChart } from '../../../../charts/index.js';
import {
  addColumn, addRow, addSeries, canSwap, cellText, dataSummary, gridView, modeOf, parseCell, pasteGrid, removeColumn, removeRow, removeSeries,
  renameSeries, setCell, setHeader, setSeriesKind, swapOrientation, toggleTotal, GRID_LIMITS,
} from '../grid.js';

const col = () => sampleChart('column');

test('modeOf maps types to grid shapes', () => {
  assert.equal(modeOf('column'), 'cat');
  assert.equal(modeOf('waterfall'), 'cat');
  assert.equal(modeOf('scatter'), 'points');
  assert.equal(modeOf('bubble'), 'points');
  assert.equal(modeOf('kpi'), 'kpi');
  assert.equal(modeOf('map'), 'map');
});

test('parseCell: numbers, gaps, decimal commas and non numbers', () => {
  assert.deepEqual(parseCell('12.5'), { value: 12.5, error: null });
  assert.deepEqual(parseCell('1,234'), { value: 1234, error: null });
  assert.deepEqual(parseCell('12,5'), { value: 12.5, error: null });
  assert.deepEqual(parseCell('R 1 200'), { value: 1200, error: null });
  assert.deepEqual(parseCell('(5)'), { value: -5, error: null });
  assert.deepEqual(parseCell('50%'), { value: 50, error: null });
  assert.deepEqual(parseCell(''), { value: null, error: null });
  assert.deepEqual(parseCell('n/a'), { value: null, error: null });
  const bad = parseCell('abc');
  assert.equal(bad.value, null);
  assert.match(bad.error, /not a number/);
  assert.equal(cellText(0), '0');
  assert.equal(cellText(null), '');
});

test('gridView of a category chart: categories in column 0, one column per series', () => {
  const v = gridView(col());
  assert.equal(v.mode, 'cat');
  assert.equal(v.rowCount, 4);
  assert.deepEqual(v.columns.map((c) => c.label), ['Category', 'Revenue', 'Costs']);
  assert.deepEqual(v.rows[0], ['Q1', '1.84', '1.52']);
});

test('setCell: edits a value, empties a gap, reports a non number and never mutates the input', () => {
  const c = col();
  const before = JSON.stringify(c);
  const r1 = setCell(c, {}, 1, 1, '9.5');
  assert.equal(r1.chart.data.series[0].values[1], 9.5);
  assert.equal(r1.error, null);
  const r2 = setCell(c, {}, 1, 1, '');
  assert.equal(r2.chart.data.series[0].values[1], null);
  assert.equal(r2.error, null);
  const r3 = setCell(c, {}, 1, 1, 'lots');
  assert.equal(r3.chart.data.series[0].values[1], null);
  assert.match(r3.error, /"lots" is not a number/);
  const r4 = setCell(c, {}, 0, 0, 'First quarter');
  assert.equal(r4.chart.data.categories[0], 'First quarter');
  assert.equal(JSON.stringify(c), before);
  assert.equal(r1.chart.opt, c.opt);
});

test('rows: add after, remove, keep waterfall totals pointing at the same rows, never remove the last', () => {
  let c = sampleChart('waterfall');
  assert.deepEqual(c.data.totals, [0, 6]);
  const a = addRow(c, {}, 2);
  assert.equal(a.ok, true);
  assert.equal(a.index, 3);
  assert.equal(a.chart.data.categories.length, 8);
  assert.deepEqual(a.chart.data.totals, [0, 7]);
  const r = removeRow(a.chart, {}, 0);
  assert.deepEqual(r.chart.data.totals, [6]);
  c = col();
  for (let i = 0; i < 3; i++) c = removeRow(c, {}, 0).chart;
  const last = removeRow(c, {}, 0);
  assert.equal(last.ok, false);
  assert.match(last.reason, /at least one row/);
});

test('row limit is 500 and series limit is 40', () => {
  let c = { type: 'column', data: { categories: Array.from({ length: 500 }, (_, i) => String(i)), series: [{ name: 'A', values: Array(500).fill(1) }] } };
  const full = addRow(c, {});
  assert.equal(full.ok, false);
  assert.match(full.reason, /500/);
  c = { type: 'column', data: { categories: ['a'], series: Array.from({ length: 40 }, (_, i) => ({ name: 'S' + i, values: [1] })) } };
  const wide = addColumn(c);
  assert.equal(wide.ok, false);
  assert.match(wide.reason, /40/);
  assert.equal(GRID_LIMITS.rows, 500);
  assert.equal(GRID_LIMITS.series, 40);
});

test('series: add with a unique name, rename, remove, header rename, kind', () => {
  let c = col();
  const a = addSeries(c);
  assert.equal(a.chart.data.series[2].name, 'Series 3');
  assert.deepEqual(a.chart.data.series[2].values, [null, null, null, null]);
  c = renameSeries(a.chart, 2, 'Profit');
  assert.equal(c.data.series[2].name, 'Profit');
  c = setHeader(c, {}, 1, '');
  assert.match(c.data.series[0].name, /^Series \d$/);
  c = setSeriesKind(c, 1, 'line');
  assert.equal(c.data.series[1].kind, 'line');
  c = setSeriesKind(c, 1, null);
  assert.equal(c.data.series[1].kind, undefined);
  const d = removeColumn(c, 3);
  assert.equal(d.chart.data.series.length, 2);
  const single = { type: 'column', data: { categories: ['a'], series: [{ name: 'only', values: [1] }] } };
  assert.equal(removeSeries(single, 0).ok, false);
  assert.equal(removeColumn(single, 0).ok, false);
});

test('toggleTotal marks and unmarks a waterfall row', () => {
  let c = sampleChart('waterfall');
  c = toggleTotal(c, 3);
  assert.deepEqual(c.data.totals, [0, 3, 6]);
  c = toggleTotal(c, 0);
  c = toggleTotal(c, 3);
  c = toggleTotal(c, 6);
  assert.equal(c.data.totals, undefined);
});

test('pasteGrid: TSV from a spreadsheet fills cells, extends rows and series, lists what it could not read', () => {
  const c = col();
  const r = pasteGrid(c, {}, 3, 1, '10\t20\t30\n40\t50\t60\n70\toops\t90');
  assert.equal(r.chart.data.categories.length, 6);
  assert.equal(r.chart.data.series.length, 3);
  assert.deepEqual(r.chart.data.series[0].values.slice(3), [10, 40, 70]);
  assert.deepEqual(r.chart.data.series[1].values.slice(3), [20, 50, null]);
  assert.equal(r.errors.length, 1);
  assert.deepEqual([r.errors[0].r, r.errors[0].c], [5, 2]);
  assert.equal(r.cells, 9);
});

test('pasteGrid: CSV with quotes and thousands separators; clips at the limits', () => {
  const c = col();
  const r = pasteGrid(c, {}, 0, 0, 'Q1,"1,200",5\nQ2,"2,400",6');
  assert.deepEqual(r.chart.data.categories.slice(0, 2), ['Q1', 'Q2']);
  assert.deepEqual(r.chart.data.series[0].values.slice(0, 2), [1200, 2400]);
  assert.equal(r.chart.data.series[1].values[1], 6);
  const big = Array.from({ length: 510 }, (_, i) => `r${i}\t${i}`).join('\n');
  const p = pasteGrid(c, {}, 0, 0, big);
  assert.equal(p.chart.data.categories.length, 500);
  assert.equal(p.clipped.rows, 10);
  const wide = pasteGrid(c, {}, 0, 0, Array.from({ length: 45 }, (_, i) => i).join('\t') + '\n');
  assert.equal(wide.chart.data.series.length, 40);
  assert.ok(wide.clipped.cols > 0);
});

test('points: edit x, y, size and label; an empty or non numeric x or y is refused and the value stays', () => {
  const c = sampleChart('bubble');
  const v = gridView(c, { series: 0 });
  assert.deepEqual(v.columns.map((x) => x.label), ['x', 'y', 'Size', 'Label']);
  assert.deepEqual(v.rows[0], ['12', '34', '800', 'Alpha']);
  const a = setCell(c, { series: 0 }, 0, 1, '99');
  assert.equal(a.chart.data.series[0].points[0].y, 99);
  const b = setCell(c, { series: 0 }, 0, 1, 'x');
  assert.match(b.error, /needs a number/);
  assert.equal(b.chart.data.series[0].points[0].y, 34);
  const r = setCell(c, { series: 0 }, 0, 2, '');
  assert.equal(r.chart.data.series[0].points[0].r, undefined);
  const neg = setCell(c, { series: 0 }, 0, 2, '-4');
  assert.match(neg.error, /negative/);
  const lab = setCell(c, { series: 0 }, 0, 3, '');
  assert.equal(lab.chart.data.series[0].points[0].label, undefined);
  const added = addRow(c, { series: 0 }, 4);
  assert.equal(added.chart.data.series[0].points.length, 6);
  assert.equal(added.chart.data.series[0].points[5].x, 67);
  const sc = gridView(sampleChart('scatter'), {});
  assert.deepEqual(sc.columns.map((x) => x.label), ['x', 'y', 'Label']);
});

test('kpi: label, value, change, unit, note and good direction', () => {
  let c = sampleChart('kpi');
  const v = gridView(c);
  assert.equal(v.mode, 'kpi');
  assert.equal(v.rowCount, 4);
  assert.deepEqual(v.rows[2], ['Churn', '2.8', '-0.6', '%', '', 'down']);
  c = setCell(c, {}, 1, 1, 'n/a').chart;
  assert.equal(c.data.kpis[1].value, null);
  c = setCell(c, {}, 1, 1, 'Gold').chart;
  assert.equal(c.data.kpis[1].value, 'Gold');
  c = setCell(c, {}, 1, 2, '').chart;
  assert.equal(c.data.kpis[1].delta, undefined);
  c = setCell(c, {}, 0, 5, 'down').chart;
  assert.equal(c.data.kpis[0].good, 'down');
  c = setCell(c, {}, 0, 5, 'up').chart;
  assert.equal(c.data.kpis[0].good, undefined);
  const a = addRow(c, {});
  assert.equal(a.chart.data.kpis.length, 5);
  let many = c;
  for (let i = 0; i < 30; i++) many = addRow(many, {}).chart;
  assert.equal(many.data.kpis.length, 24);
});

test('map: codes and names resolve, unknown and duplicate countries are refused, values must be numbers', () => {
  let c = sampleChart('map');
  const v = gridView(c);
  assert.equal(v.mode, 'map');
  assert.deepEqual(v.rows[0], ['ZA', 'South Africa', '42']);
  const a = setCell(c, {}, 0, 0, 'france');
  assert.equal(a.chart.data.regions[0].code, 'FR');
  assert.equal(a.error, null);
  assert.match(setCell(c, {}, 0, 0, 'brazil').error, /already in the list/);
  c = setCell(c, {}, 0, 0, 'Narnia').chart;
  assert.equal(c.data.regions[0].code, 'ZA');
  assert.match(setCell(c, {}, 0, 0, 'Narnia').error, /not a country/);
  const dup = setCell(c, {}, 1, 0, 'ZA');
  assert.match(dup.error, /already in the list/);
  assert.match(setCell(c, {}, 0, 2, 'x').error, /number/);
  assert.equal(setCell(c, {}, 0, 2, '7').chart.data.regions[0].value, 7);
  const add = addRow(c, {}, null, { code: 'fr' });
  assert.equal(add.chart.data.regions.at(-1).code, 'FR');
  assert.equal(addRow(add.chart, {}, null, { code: 'FR' }).ok, false);
  assert.equal(addRow(c, {}, null, { code: 'QQ' }).ok, false);
  const p = pasteGrid(c, {}, 13, 0, 'Kenya\t5\nFrance\t8\nSpain\t3');
  assert.equal(p.chart.data.regions.find((x) => x.code === 'FR')?.value, 8);
  assert.equal(p.chart.data.regions.find((x) => x.code === 'ES')?.value, 3);
  assert.equal(p.chart.data.regions.find((x) => x.code === 'KE')?.value, 18);
  assert.equal(p.errors.length, 1);
});

test('swapOrientation turns categories into series and says what it drops', () => {
  let c = col();
  c = { ...c, data: { ...c.data, series: [{ ...c.data.series[0], color: '#ff0000' }, c.data.series[1]] } };
  const s = swapOrientation(c);
  assert.equal(s.ok, true);
  assert.deepEqual(s.chart.data.categories, ['Revenue', 'Costs']);
  assert.equal(s.chart.data.series.length, 4);
  assert.equal(s.chart.data.series[0].name, 'Q1');
  assert.deepEqual(s.chart.data.series[0].values, [1.84, 1.52]);
  assert.deepEqual(s.dropped, [{ what: 'Series colours', count: 1 }]);
  const back = swapOrientation(s.chart);
  assert.deepEqual(back.chart.data.series.map((x) => x.values), c.data.series.map((x) => x.values));
  const many = { type: 'column', data: { categories: Array.from({ length: 41 }, (_, i) => String(i)), series: [{ name: 'A', values: Array(41).fill(1) }] } };
  assert.equal(canSwap(many).ok, false);
  assert.equal(canSwap(sampleChart('kpi')).ok, false);
});

test('dataSummary words', () => {
  assert.equal(dataSummary(col()), '4 rows, 2 series');
  assert.equal(dataSummary(sampleChart('map')), '13 countries');
  assert.equal(dataSummary(sampleChart('kpi')), '4 figures');
});

test('a full 500 by 40 paste fits exactly and is quick', () => {
  const tsv = Array.from({ length: 500 }, (_, i) => Array.from({ length: 41 }, (_, j) => (j === 0 ? `r${i}` : i * j)).join('\t')).join('\n');
  const t0 = Date.now();
  const r = pasteGrid({ type: 'line', data: { categories: ['a'], series: [{ name: 'A', values: [1] }] }, opt: {} }, {}, 0, 0, tsv);
  assert.equal(r.chart.data.categories.length, 500);
  assert.equal(r.chart.data.series.length, 40);
  assert.equal(r.cells, 500 * 41);
  assert.equal(r.errors.length, 0);
  assert.deepEqual(r.clipped, { rows: 0, cols: 0 });
  assert.ok(Date.now() - t0 < 1500);
  assert.equal(gridView(r.chart).rowCount, 500);
});

test('a cell that is refused keeps its draft out of the data: points keep the old number', () => {
  const c = sampleChart('scatter');
  const before = c.data.series[0].points[2].x;
  const r = setCell(c, { series: 0 }, 2, 0, '');
  assert.equal(r.changed, false);
  assert.equal(r.chart, c);
  assert.equal(c.data.series[0].points[2].x, before);
  assert.match(r.error, /x needs a number/);
});
