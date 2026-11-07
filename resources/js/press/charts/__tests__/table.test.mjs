import test from 'node:test';
import assert from 'node:assert/strict';
import { parseTable, tableToChartData, suggestChartType, looksTemporal, normalizeChart, chartToSvg } from '../index.js';

test('parseTable: comma CSV with quoted fields, thousands separators and blank lines', () => {
  const p = parseTable('Month,Sales,Cost\n\nJan,"1,200.50",900\r\nFeb,1300,950\n');
  assert.equal(p.delimiter, ',');
  assert.deepEqual(p.rows, [['Month', 'Sales', 'Cost'], ['Jan', 1200.5, 900], ['Feb', 1300, 950]]);
});

test('parseTable: semicolon CSV with decimal commas, spaces as thousands and percentages', () => {
  const p = parseTable('Region;Omzet;Marge\nNoord;1 234,5;12,5%\nSuid;2 000,75;8%');
  assert.equal(p.delimiter, ';');
  assert.equal(p.decimal, ',');
  assert.deepEqual(p.rows[1], ['Noord', 1234.5, 12.5]);
  assert.equal(p.hints.percent, true);
});

test('parseTable: TSV paste from a spreadsheet with currency and accounting negatives', () => {
  const p = parseTable('Item\tQty\tPrice\nApple\t1,234\tR 12.50\nPear\t(5)\t$3\n');
  assert.equal(p.delimiter, '\t');
  assert.deepEqual(p.rows.slice(1), [['Apple', 1234, 12.5], ['Pear', -5, 3]]);
  assert.equal(p.hints.currency, 'R');
});

test('parseTable: quotes with delimiters, doubled quotes and newlines inside a cell', () => {
  const p = parseTable('Name,Quote\n"Smith, J","He said ""hi"""\n"Two\nlines",x\nLee,');
  assert.deepEqual(p.rows[1], ['Smith, J', 'He said "hi"']);
  assert.deepEqual(p.rows[2], ['Two lines', 'x']);
  assert.deepEqual(p.rows[3], ['Lee', null]);
});

test('parseTable: missing-value markers, BOM, ragged rows and pipes', () => {
  const p = parseTable('﻿a|b|c\n1|n/a|3\n4|-|\n');
  assert.equal(p.delimiter, '|');
  assert.deepEqual(p.rows, [['a', 'b', 'c'], [1, null, 3], [4, null, null]]);
  const ragged = parseTable('a,b,c\n1,2\n3,4,5,6\n');
  assert.equal(ragged.rows.length, 3);
});

test('parseTable: ambiguous 1,234 is a thousand in comma-free tabs and a decimal in semicolon files', () => {
  assert.equal(parseTable('x\ty\na\t1,234\n').rows[1][1], 1234);
  assert.equal(parseTable('x;y\na;1,234\nb;2,5\n').rows[1][1], 1.234);
  assert.equal(parseTable('x\ty\na\t1.234,5\nb\t2.500,25\n').rows[1][1], 1234.5);
  assert.equal(parseTable('x\ty\na\t1,234.5\nb\t2,500.25\n').rows[1][1], 1234.5);
});

test('parseTable: spaced columns pasted from a web page and degenerate input', () => {
  const p = parseTable('Name   Score\nAnn    10\nBob    12');
  assert.equal(p.delimiter, 'spaces');
  assert.deepEqual(p.rows, [['Name', 'Score'], ['Ann', 10], ['Bob', 12]]);
  assert.deepEqual(parseTable('').rows, []);
  assert.deepEqual(parseTable(null).rows, []);
  assert.deepEqual(parseTable('   \n\n').rows, []);
  assert.equal(parseTable('single').rows.length, 1);
  assert.ok(parseTable('a,b\n' + '1,2\n'.repeat(100000)).rows.length <= 5000);
  const dates = parseTable('Date,Val\n2024-01,5\n12/05/2024,6\nQ1 2024,7');
  assert.deepEqual(dates.rows.map((r) => r[0]), ['Date', '2024-01', '12/05/2024', 'Q1 2024']);
});

test('tableToChartData: header detection, categories and series', () => {
  const rows = parseTable('Month,Sales,Cost\nJan,100,80\nFeb,120,85\nMar,130,90').rows;
  const d = tableToChartData(rows);
  assert.deepEqual(d.categories, ['Jan', 'Feb', 'Mar']);
  assert.deepEqual(d.series, [{ name: 'Sales', values: [100, 120, 130] }, { name: 'Cost', values: [80, 85, 90] }]);
  // no header row at all
  const d2 = tableToChartData([['Jan', 1], ['Feb', 2]]);
  assert.deepEqual(d2.categories, ['Jan', 'Feb']);
  assert.equal(d2.series[0].name, 'Series 1');
  // years are categories, not a series
  const d3 = tableToChartData(parseTable('Year,Revenue\n2022,10\n2023,12\n2024,15').rows);
  assert.deepEqual(d3.categories, ['2022', '2023', '2024']);
  assert.equal(d3.series.length, 1);
  // explicit flags
  const d4 = tableToChartData([['a', 1], ['b', 2]], { firstColHeader: false, firstRowHeader: false });
  assert.deepEqual(d4.categories, ['1', '2']);
  // text cells in a number column become gaps
  const d5 = tableToChartData([['k', 'v'], ['a', 1], ['b', 'x'], ['c', 3]]);
  assert.deepEqual(d5.series[0].values, [1, null, 3]);
});

test('tableToChartData: orientation rows swaps series and categories', () => {
  const d = tableToChartData([['', '2023', '2024'], ['Rev', 1, 2], ['Cost', 3, 4]], { orientation: 'rows' });
  assert.deepEqual(d.categories, ['2023', '2024']);
  assert.deepEqual(d.series, [{ name: 'Rev', values: [1, 2] }, { name: 'Cost', values: [3, 4] }]);
});

test('tableToChartData: scatter, bubble, map, kpi and waterfall shapes', () => {
  const sc = tableToChartData(parseTable('x,y\n1,2\n2,4\n3,5').rows, { type: 'scatter' });
  assert.deepEqual(sc.series[0].points, [{ x: 1, y: 2 }, { x: 2, y: 4 }, { x: 3, y: 5 }]);
  const bu = tableToChartData(parseTable('Name,x,y,size\nA,1,2,30\nB,2,4,10').rows, { type: 'bubble' });
  assert.deepEqual(bu.series[0].points, [{ x: 1, y: 2, r: 30, label: 'A' }, { x: 2, y: 4, r: 10, label: 'B' }]);
  const map = tableToChartData(parseTable('Country,Customers\nSouth Africa,40\nNG,22\nKenya,11\nAtlantis,5').rows, { type: 'map' });
  assert.deepEqual(map.regions, [{ code: 'ZA', value: 40 }, { code: 'NG', value: 22 }, { code: 'KE', value: 11 }]);
  const kpi = tableToChartData(parseTable('Metric,Value,Change,Note\nRevenue,8420000,12.4,vs last quarter\nChurn,2.8,-0.6,').rows, { type: 'kpi' });
  assert.equal(kpi.kpis.length, 2);
  assert.deepEqual(kpi.kpis[0], { label: 'Revenue', value: 8420000, delta: 12.4, note: 'vs last quarter' });
  const wf = tableToChartData(parseTable('Step,Amount\nOpening,100\nSales,50\nCosts,-30\nTotal,120').rows, { type: 'waterfall' });
  assert.deepEqual(wf.totals, [0, 3]);
  assert.deepEqual(tableToChartData([], { type: 'map' }).regions ?? [], []);
  assert.deepEqual(tableToChartData(null), { categories: [], series: [] });
});

test('tableToChartData output always renders', () => {
  const messy = parseTable('Q;A;B\nQ1;1 200,5;3\nQ2;n/a;4\nQ3;900;\n').rows;
  for (const type of ['column', 'line', 'pie', 'scatter', 'bubble', 'map', 'kpi', 'waterfall', 'funnel', 'radar']) {
    const data = tableToChartData(messy, { type });
    const svg = chartToSvg({ type, data, opt: {} }, { w: 400, h: 300, debug: true });
    assert.match(svg, /^<svg /);
    assert.doesNotMatch(svg, /NaN/);
    assert.ok(normalizeChart({ type, data }).type === type);
  }
});

test('suggestChartType gives a type and a plain reason', () => {
  const cases = [
    [{ categories: ['Jan', 'Feb', 'Mar', 'Apr'], series: [{ name: 's', values: [1, 2, 3, 4] }] }, 'line'],
    [{ categories: ['2021', '2022', '2023'], series: [{ name: 's', values: [5, 6, 9] }] }, 'line'],
    [{ categories: ['Q1 2024', 'Q2 2024', 'Q3 2024', 'Q4 2024'], series: [{ name: 's', values: [5, 6, 9, 4] }] }, 'line'],
    [{ categories: ['Retail', 'Wholesale', 'Online'], series: [{ name: 's', values: [48, 27, 25] }] }, 'doughnut'],
    [{ categories: ['A', 'B', 'C'], series: [{ name: 's', values: [0.5, 0.3, 0.2] }] }, 'doughnut'],
    [{ categories: ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N'], series: [{ name: 's', values: [3, 5, 2, 8, 4, 6, 7, 3, 2, 9, 4, 6, 5, 1] }] }, 'column'],
    [{ categories: ['Department of Health', 'Department of Education', 'Public Works'], series: [{ name: 's', values: [300, 250, 120] }] }, 'bar'],
    [{ categories: ['1', '2', '3', '4', '5', '6', '7', '8'], series: [{ name: 's', values: [2, 4, 5, 9, 10, 13, 15, 15] }] }, 'scatter'],
    [{ categories: ['East', 'West'], series: [{ name: 'a', values: [5, 6] }, { name: 'b', values: [3, 9] }] }, 'column'],
    [{ categories: ['Visited', 'Signed up', 'Subscribed'], series: [{ name: 's', values: [1000, 300, 50] }] }, 'funnel'],
    [{ categories: ['Opening', 'Sales', 'Costs', 'Total'], series: [{ name: 's', values: [100, 50, -30, 120] }] }, 'waterfall'],
    [{ categories: [], series: [], regions: [{ code: 'ZA', value: 1 }] }, 'map'],
    [{ categories: [], series: [], kpis: [{ label: 'a', value: 1 }] }, 'kpi'],
    [{ categories: [], series: [{ name: 's', values: [], points: [{ x: 1, y: 2 }] }] }, 'scatter'],
    [{ categories: [], series: [{ name: 's', values: [], points: [{ x: 1, y: 2, r: 3 }] }] }, 'bubble'],
    [{ categories: ['ZA', 'US', 'BR', 'NG', 'KE', 'IN'], series: [{ name: 's', values: [5, 9, 3, 2, 4, 6] }] }, 'map'],
    [{ categories: Array.from({ length: 60 }, (_, i) => 'Item ' + i), series: [{ name: 's', values: Array.from({ length: 60 }, (_, i) => i) }] }, 'line'],
  ];
  for (const [data, want] of cases) {
    const r = suggestChartType(data);
    assert.equal(r.type, want, `${JSON.stringify(data).slice(0, 70)} -> ${r.type} (${r.why})`);
    assert.ok(typeof r.why === 'string' && r.why.length > 15 && !/[–—]/.test(r.why));
  }
  assert.equal(suggestChartType({ categories: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'], series: [{ name: 'a', values: [5, 6, 7, 8, 9, 10] }, { name: 'b', values: [4, 5, 6, 7, 8, 9] }] }).stacked, true);
  for (const junk of [null, undefined, 5, 'x', {}, { series: 'no' }, { categories: [1, 2], series: [null, 3] }]) {
    const r = suggestChartType(junk);
    assert.ok(typeof r.type === 'string' && typeof r.why === 'string');
  }
});

test('looksTemporal', () => {
  assert.equal(looksTemporal(['Jan', 'Feb', 'Mar']), true);
  assert.equal(looksTemporal(['2024-01-05', '2024-01-06', '2024-01-07']), true);
  assert.equal(looksTemporal(['Mon', 'Tue', 'Wed']), true);
  assert.equal(looksTemporal(['W1', 'W2', 'W3']), true);
  assert.equal(looksTemporal(['FY24', 'FY25', 'FY26']), true);
  assert.equal(looksTemporal(['Apples', 'Pears', 'Plums']), false);
  assert.equal(looksTemporal(['Jan']), false);
});

test('normalizeChart keeps a KPI figure that carries a sign or a unit as the text it was typed as', () => {
  const kpis = normalizeChart({ type: 'kpi', data: { kpis: [
    { label: 'Cost per tonne', value: 'R412' }, { label: 'Availability', value: '91%' }, { label: 'Output', value: '1.2m' },
    { label: 'Plain', value: '1 234' }, { label: 'Tonnes', value: '1,053' }, { label: 'Number', value: 412 },
  ] } }).data.kpis;
  assert.deepEqual(kpis.map((k) => k.value), ['R412', '91%', '1.2m', 1234, 1053, 412]);
});
