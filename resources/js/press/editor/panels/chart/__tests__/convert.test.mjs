import test from 'node:test';
import assert from 'node:assert/strict';
import { chartToSvg, normalizeChart, sampleChart, CHART_TYPES } from '../../../../charts/index.js';
import { convertType, typeChoices, typeNotes } from '../convert.js';

const rendered = (chart) => chartToSvg(chart, { w: 240, h: 150, debug: true });

test('every type either converts to something that renders, or says why not', () => {
  for (const from of CHART_TYPES) {
    const chart = sampleChart(from.id);
    for (const to of CHART_TYPES) {
      const r = convertType(chart, to.id);
      if (r.ok) {
        assert.equal(r.chart.type, to.id, `${from.id} to ${to.id}`);
        assert.ok(rendered(r.chart).startsWith('<svg'), `${from.id} to ${to.id} renders`);
      } else {
        assert.ok(r.why.length > 10, `${from.id} to ${to.id} has a reason`);
        assert.equal(r.chart, chart);
      }
    }
  }
});

test('category types convert freely and lose nothing; the input is untouched', () => {
  const c = sampleChart('waterfall');
  const before = JSON.stringify(c);
  const col = convertType(c, 'column');
  assert.equal(col.ok, true);
  assert.deepEqual(col.dropped, []);
  assert.deepEqual(col.chart.data.totals, [0, 6], 'total marks stay in the data');
  assert.equal(JSON.stringify(c), before);
  const back = convertType(col.chart, 'waterfall');
  assert.deepEqual(back.chart.data, c.data);
});

test('to combo gives each series a kind; to waterfall marks rows named like totals and says so', () => {
  const combo = convertType(sampleChart('column'), 'combo');
  assert.deepEqual(combo.chart.data.series.map((s) => s.kind), ['bar', 'line']);
  assert.ok(combo.notes.some((n) => /bars/.test(n)));
  const c = normalizeChart({ type: 'column', data: { categories: ['Opening', 'Sales', 'Costs', 'Closing'], series: [{ name: 'P', values: [10, 5, -3, 12] }] } });
  const w = convertType(c, 'waterfall');
  assert.deepEqual(w.chart.data.totals, [0, 3]);
  assert.ok(w.notes.some((n) => /totals/.test(n)));
});

test('pie needs positive numbers; radar needs 3 rows', () => {
  const neg = normalizeChart({ type: 'column', data: { categories: ['a', 'b'], series: [{ name: 'x', values: [-1, -2] }] } });
  assert.equal(convertType(neg, 'pie').ok, false);
  assert.equal(convertType(neg, 'radar').ok, false);
  const multi = convertType(sampleChart('column'), 'pie');
  assert.ok(multi.notes.some((n) => /first series only/.test(n)));
});

test('text categories cannot become scatter: the reason names the problem', () => {
  const r = convertType(sampleChart('column'), 'scatter');
  assert.equal(r.ok, false);
  assert.match(r.why, /"Q1" is text/);
  const choices = typeChoices(sampleChart('column'));
  assert.equal(choices.find((x) => x.id === 'scatter').ok, false);
  assert.equal(choices.find((x) => x.id === 'bar').ok, true);
  assert.equal(choices.find((x) => x.id === 'column').current, true);
});

test('numeric categories become scatter x values; empty cells are reported', () => {
  const c = normalizeChart({ type: 'column', data: { categories: ['1', '2', '3', '4'], series: [{ name: 'y', values: [3, null, 5, 8] }, { name: 'z', values: [1, 2, 3, 4] }] } });
  const s = convertType(c, 'scatter');
  assert.equal(s.ok, true);
  assert.deepEqual(s.chart.data.series[0].points, [{ x: 1, y: 3 }, { x: 3, y: 5 }, { x: 4, y: 8 }]);
  assert.deepEqual(s.dropped, [{ what: 'Empty cells', count: 1, detail: 'a point needs both numbers' }]);
  const b = convertType(c, 'bubble');
  assert.equal(b.ok, true);
  assert.deepEqual(b.chart.data.series[0].points[0], { x: 1, y: 3, r: 1 });
  const one = normalizeChart({ type: 'column', data: { categories: ['1', '2'], series: [{ name: 'y', values: [3, 4] }] } });
  assert.match(convertType(one, 'bubble').why, /second series/);
});

test('points to columns: labels become categories, x values and sizes are reported as dropped', () => {
  const r = convertType(sampleChart('bubble'), 'column');
  assert.equal(r.ok, true);
  assert.deepEqual(r.chart.data.categories, ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon']);
  assert.deepEqual(r.chart.data.series[0].values, [34, 22, 41, 18, 36]);
  assert.deepEqual(r.dropped.map((d) => d.what), ['x values', 'Bubble sizes']);
  const s = convertType(sampleChart('scatter'), 'line');
  assert.equal(s.chart.data.categories[0], '120');
  assert.deepEqual(s.dropped, []);
  assert.equal(convertType(sampleChart('scatter'), 'bubble').ok, true);
  assert.ok(convertType(sampleChart('scatter'), 'bubble').notes.length > 0);
  assert.equal(convertType(sampleChart('scatter'), 'map').ok, false);
});

test('kpi to columns reports changes, units and text values', () => {
  const k = sampleChart('kpi');
  const r = convertType(k, 'column');
  assert.equal(r.ok, true);
  assert.deepEqual(r.chart.data.categories, ['Revenue', 'Active customers', 'Churn', 'Net promoter score']);
  assert.deepEqual(r.dropped.map((d) => [d.what, d.count]), [['Changes', 4], ['Units, notes and good-direction choices', 2]]);
  assert.equal(convertType(k, 'map').ok, false);
  assert.equal(convertType(k, 'scatter').ok, false);
});

test('columns to kpi keep the first series and report the rest', () => {
  const r = convertType(sampleChart('column'), 'kpi');
  assert.equal(r.ok, true);
  assert.deepEqual(r.chart.data.kpis.map((x) => x.label), ['Q1', 'Q2', 'Q3', 'Q4']);
  assert.equal(r.chart.data.series.length, 0);
  assert.deepEqual(r.dropped, [{ what: 'Series', count: 1, detail: 'Costs' }]);
});

test('columns to map match codes and names, list the rest, refuse when nothing matches', () => {
  const c = normalizeChart({ type: 'bar', data: { categories: ['South Africa', 'US', 'Atlantis', 'ZA'], series: [{ name: 'Sales', values: [5, 3, 9, 2] }] } });
  const r = convertType(c, 'map');
  assert.equal(r.ok, true);
  assert.deepEqual(r.chart.data.regions, [{ code: 'ZA', value: 5 }, { code: 'US', value: 3 }]);
  assert.equal(r.dropped[0].count, 2);
  assert.match(r.dropped[0].detail, /Atlantis/);
  assert.equal(convertType(sampleChart('column'), 'map').ok, false);
  const back = convertType(sampleChart('map'), 'bar');
  assert.equal(back.chart.data.categories[0], 'South Africa');
});

test('typeNotes mention one-series types and combo', () => {
  assert.ok(typeNotes(sampleChart('column')).length === 0);
  const pie = { ...sampleChart('column'), type: 'pie' };
  assert.ok(typeNotes(pie).some((n) => /first series only/.test(n)));
  assert.ok(typeNotes(sampleChart('combo')).some((n) => /bar or line/i.test(n)));
});
