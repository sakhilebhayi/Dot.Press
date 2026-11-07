import test from 'node:test';
import assert from 'node:assert/strict';
import { sampleChart } from '../../../../charts/index.js';
import { axisWarnings, colorCollisions, controlsFor, numberSample, resetFormatting, seriesColorState, setOpt, setSeriesColor, setSliceColor } from '../format.js';

test('number formats: number, percent, currency with symbol, compact, and decimals', () => {
  assert.equal(numberSample({ fmt: 'num' }, 12.5), '12.5');
  assert.equal(numberSample({ fmt: 'num' }, 1234.5), '1,235');
  assert.equal(numberSample({ fmt: 'num', dec: 0 }, 1234.5), '1,235');
  assert.equal(numberSample({ fmt: 'num', dec: 2 }, 1234.5), '1,234.50');
  assert.equal(numberSample({ fmt: 'pct', dec: 1 }, 12.5), '12.5%');
  assert.equal(numberSample({ fmt: 'cur', cur: '$', dec: 2 }, 1234.5), '$1,234.50');
  assert.equal(numberSample({ fmt: 'cur', cur: '€', dec: 0 }, 1234), '€1,234');
  assert.equal(numberSample({ fmt: 'cur' }, 1234), 'R1,234');
  assert.equal(numberSample({ fmt: 'compact', dec: 1 }, 2_400_000), '2.4M');
  assert.equal(numberSample({ fmt: 'num', dec: 1 }, -3.25), '-3.3');
});

test('axis: a bar or column that does not start at zero gets a plain warning; a line only a note', () => {
  const col = setOpt(sampleChart('column'), 'ax.y.min', 50);
  const w = axisWarnings(col);
  assert.equal(w.length, 1);
  assert.equal(w[0].kind, 'warn');
  assert.match(w[0].text, /starts at 50 instead of 0/);
  assert.deepEqual(axisWarnings(setOpt(sampleChart('column'), 'ax.y.min', 0)), []);
  assert.deepEqual(axisWarnings(setOpt(sampleChart('column'), 'ax.y.min', -10)), []);
  assert.equal(axisWarnings(setOpt(sampleChart('line'), 'ax.y.min', 500))[0].kind, 'info');
  const area = axisWarnings(setOpt(sampleChart('area'), 'ax.y.min', 5));
  assert.equal(area[0].kind, 'warn');
});

test('axis: min above max is an error; a max below the data warns', () => {
  const c = setOpt(setOpt(sampleChart('column'), 'ax.y.min', 10), 'ax.y.max', 5);
  assert.ok(axisWarnings(c).some((w) => w.kind === 'error'));
  const cut = setOpt(sampleChart('column'), 'ax.y.max', 2);
  const w = axisWarnings(cut);
  assert.ok(w.some((x) => /below the largest value \(3\.12\)/.test(x.text)));
});

test('setOpt writes nested keys immutably and removes on empty', () => {
  const c = sampleChart('column');
  const a = setOpt(c, 'ax.y.title', 'Rand');
  assert.equal(a.opt.ax.y.title, 'Rand');
  assert.equal(c.opt.ax.y.title, undefined);
  assert.equal(a.opt.ax.x, c.opt.ax.x);
  const b = setOpt(a, 'ax.y.title', '');
  assert.equal('title' in b.opt.ax.y, false);
  assert.equal(setOpt(c, 'legend', 'top').opt.legend, 'top');
});

test('resetFormatting restores defaults and keeps content', () => {
  let c = sampleChart('column');
  c = setOpt(c, 'legend', 'top');
  c = setOpt(c, 'fmt', 'cur');
  c = setOpt(c, 'cur', '$');
  c = setOpt(c, 'dec', 3);
  c = setOpt(c, 'grid', false);
  c = setOpt(c, 'ax.y.min', 10);
  c = setOpt(c, 'ax.y.title', 'Rand');
  c = setOpt(c, 'ax.x.show', false);
  c = setSeriesColor(c, 0, '#ff0000');
  const r = resetFormatting(c);
  assert.equal(r.chart.opt.legend, undefined);
  assert.equal(r.chart.opt.fmt, 'num');
  assert.equal(r.chart.opt.cur, undefined);
  assert.equal(r.chart.opt.dec, undefined);
  assert.equal(r.chart.opt.grid, true);
  assert.deepEqual(r.chart.opt.ax, { x: { show: true }, y: { show: true, title: 'Rand' } });
  assert.equal(r.chart.opt.title, c.opt.title, 'title text is content');
  assert.equal(r.chart.opt.sub, c.opt.sub);
  assert.equal(r.chart.data.series[0].color, undefined);
  assert.equal(r.chart.data.series[0].values, c.data.series[0].values);
  for (const w of ['legend', 'number format', 'currency symbol', 'decimals', 'gridlines', 'axis range', 'axis visibility', 'colours']) assert.ok(r.changed.includes(w), w);
});

test('series colours: set, clear and read back', () => {
  let c = sampleChart('column');
  c = setSeriesColor(c, 1, '@chart3');
  assert.deepEqual(seriesColorState(c), { colors: [null, '@chart3'], overrides: 1 });
  c = setSeriesColor(c, 1, null);
  assert.deepEqual(seriesColorState(c), { colors: [null, null], overrides: 0 });
  const p = setSliceColor(sampleChart('pie'), 2, '#00ff00', 5);
  assert.deepEqual(p.opt.colors, ['', '', '#00ff00']);
  assert.equal(setSliceColor(p, 2, '', 5).opt.colors, undefined);
});

test('controlsFor: a pie has no axes, a map no stacking, a column has both', () => {
  assert.equal(controlsFor('pie').axes, false);
  assert.equal(controlsFor('pie').sliceColors, true);
  assert.equal(controlsFor('column').axes, true);
  assert.equal(controlsFor('column').stacked, true);
  assert.equal(controlsFor('map').stacked, false);
  assert.equal(controlsFor('kpi').legend, false);
  assert.equal(controlsFor('doughnut').center, true);
  assert.equal(controlsFor('line').smooth, true);
  assert.equal(controlsFor('funnel').singleColor, true);
});

test('colorCollisions flags two series that were given nearly the same colour', () => {
  let c = sampleChart('column');
  assert.deepEqual(colorCollisions(c), []);
  c = setSeriesColor(c, 1, '#2563ec');
  const hits = colorCollisions(c);
  assert.equal(hits.length, 1);
  assert.deepEqual([hits[0].a, hits[0].b], ['Revenue', 'Costs']);
  assert.ok(hits[0].distance < 5);
  c = setSeriesColor(c, 1, '@chart3');
  assert.deepEqual(colorCollisions(c), []);
});
