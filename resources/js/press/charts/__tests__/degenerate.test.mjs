import test from 'node:test';
import assert from 'node:assert/strict';
import { chartToSvg, normalizeChart, chartToTable, describeChart, sampleChart, CHART_TYPES, CHART_IDS, LIMITS } from '../index.js';
import { geometryProblems, parseXml, textContent, walk } from './helpers.mjs';

const TYPES = CHART_TYPES.map((t) => t.id);

test('CHART_TYPES lists every spec type once with metadata', () => {
  assert.deepEqual([...TYPES].sort(), [...CHART_IDS].sort());
  for (const t of CHART_TYPES) {
    assert.ok(t.id && t.label && t.desc && t.needs);
    assert.doesNotMatch(t.desc + t.needs + t.label, /[–—]/);
  }
});

test('normalizeChart never throws and always returns a complete chart', () => {
  const junk = [undefined, null, 0, 1, 'x', [], {}, { type: 5 }, { type: 'nope', data: 3, opt: [] }, { data: { categories: 'abc', series: 'abc' } }, { data: { series: [null, 5, 'x', { values: 'no' }, { values: [1, 2], name: {} }] } }, { opt: { legend: 'sideways', labels: 7, ax: 5, colors: 'red', dec: 9, fmt: 'bogus' } }, JSON.parse('{"__proto__":{"polluted":true},"type":"bar"}')];
  for (const j of junk) {
    const c = normalizeChart(j);
    assert.ok(CHART_IDS.includes(c.type));
    assert.ok(Array.isArray(c.data.categories) && Array.isArray(c.data.series));
    assert.ok(['none', 'bottom', 'right', 'top'].includes(c.opt.legend));
    assert.ok(['none', 'value', 'percent'].includes(c.opt.labels));
    assert.ok(['num', 'pct', 'cur', 'compact'].includes(c.opt.fmt));
    for (const s of c.data.series) {
      assert.equal(s.values.length, c.data.categories.length);
      assert.ok(typeof s.name === 'string' && s.name.length > 0);
      assert.ok(s.values.every((v) => v === null || Number.isFinite(v)));
    }
    assert.equal({}.polluted, undefined);
    chartToSvg(j, { debug: true });
  }
});

test('normalizeChart fills defaults, is idempotent and keeps unknown input out', () => {
  const c = normalizeChart({ type: 'column', data: { categories: ['a', 'b'], series: [{ name: 's', values: [1, '2', 'x'] }] } });
  assert.deepEqual(c.data.categories, ['a', 'b', '3'], 'categories are padded to the longest series');
  assert.deepEqual(c.data.series[0].values, [1, 2, null]);
  assert.equal(c.opt.legend, 'none');
  assert.equal(c.opt.grid, true);
  assert.equal(c.opt.cur, 'R');
  assert.equal(c.src.kind, 'manual');
  assert.deepEqual(normalizeChart(c), c);
  for (const t of TYPES) {
    const s = sampleChart(t);
    assert.deepEqual(normalizeChart(s), s, t + ' sample is already normal');
  }
  assert.equal(normalizeChart({ type: 'pie', data: { categories: ['a'], series: [{ values: [1] }] } }).opt.legend, 'right');
  assert.equal(normalizeChart({ type: 'line', data: { categories: ['a'], series: [{ values: [1] }, { values: [2] }] } }).opt.legend, 'bottom');
});

test('absurd sizes are clamped and absurd data is bounded', () => {
  const big = chartToSvg(sampleChart('column'), { w: 1e9, h: 1e9 });
  assert.match(big, /viewBox="0 0 4000 4000"/);
  const tiny = chartToSvg(sampleChart('column'), { w: 1, h: 1 });
  assert.match(tiny, /viewBox="0 0 120 90"/);
  const bad = chartToSvg(sampleChart('column'), { w: NaN, h: 'x' });
  assert.match(bad, /viewBox="0 0 960 540"/);
  const lots = { type: 'line', data: { categories: Array.from({ length: 5000 }, (_, i) => 'c' + i), series: Array.from({ length: 200 }, (_, s) => ({ name: 's' + s, values: Array.from({ length: 5000 }, (_, i) => (i * s) % 97) })) }, opt: { labels: 'value' } };
  const n = normalizeChart(lots);
  assert.equal(n.data.categories.length, LIMITS.categories);
  assert.equal(n.data.series.length, LIMITS.series);
  const t0 = Date.now();
  const svg = chartToSvg(lots, { w: 960, h: 540, debug: true });
  assert.ok(Date.now() - t0 < 5000, 'large input renders in a few seconds at most');
  assert.ok(svg.length < 8_000_000);
  assert.deepEqual(geometryProblems(svg), []);
  const scatter = { type: 'scatter', data: { categories: [], series: [{ name: 'p', values: [], points: Array.from({ length: 50000 }, (_, i) => ({ x: i, y: i % 13 })) }] }, opt: { labels: 'value' } };
  assert.equal(normalizeChart(scatter).data.series[0].points.length, LIMITS.points);
  chartToSvg(scatter, { debug: true });
  const big2 = { type: 'map', data: { categories: [], series: [], regions: Array.from({ length: 5000 }, (_, i) => ({ code: String.fromCharCode(65 + (i % 26)) + String.fromCharCode(65 + ((i / 26) | 0) % 26), value: i })) }, opt: {} };
  assert.ok(normalizeChart(big2).data.regions.length <= LIMITS.regions);
});

for (const type of TYPES) {
  test(`${type}: empty, all-zero, single point and all-null data render without throwing`, () => {
    const cases = [
      { type, data: { categories: [], series: [] }, opt: {} },
      { type, data: { categories: ['a', 'b', 'c'], series: [{ name: 's', values: [0, 0, 0] }] }, opt: { labels: 'value' } },
      { type, data: { categories: ['only'], series: [{ name: 's', values: [5] }] }, opt: { labels: 'value' } },
      { type, data: { categories: ['a', 'b'], series: [{ name: 's', values: [null, null] }] }, opt: {} },
      { type, data: { categories: ['a', 'b', 'c'], series: [{ name: 's', values: [-3, -1, -2] }, { name: 't', values: [3, 1, 2] }] }, opt: { labels: 'value', stacked: true } },
      { type, data: { categories: ['a', 'b', 'c'], series: [{ name: 's', values: [1e12, 2e12, 3e12] }] }, opt: { labels: 'value', fmt: 'compact' } },
      { type, data: { categories: ['a', 'b', 'c'], series: [{ name: 's', values: [1e-9, 2e-9, 3e-9] }] }, opt: { labels: 'value' } },
    ];
    for (const c of cases) {
      for (const [w, h] of [[640, 360], [320, 240], [120, 90]]) {
        const svg = chartToSvg(c, { w, h, debug: true });
        assert.doesNotMatch(svg, /NaN|undefined|Infinity/);
        parseXml(svg);
        assert.deepEqual(geometryProblems(svg), [], `${type} ${JSON.stringify(c.data).slice(0, 60)} ${w}x${h}`);
      }
      assert.equal(typeof describeChart(c), 'string');
      const tbl = chartToTable(c);
      assert.ok(Array.isArray(tbl.header) && Array.isArray(tbl.rows));
    }
  });
}

test('empty charts say so in words', () => {
  const e = chartToSvg({ type: 'line', data: { categories: [], series: [] }, opt: { title: 'Nothing' } }, { w: 640, h: 360 });
  assert.match(textContent(parseXml(e)), /No data to chart yet/);
  const z = chartToSvg({ type: 'pie', data: { categories: ['a'], series: [{ name: 's', values: [0] }] }, opt: {} }, { w: 640, h: 360 });
  assert.match(textContent(parseXml(z)), /No values above zero/);
  const r = chartToSvg({ type: 'radar', data: { categories: ['a', 'b'], series: [{ name: 's', values: [1, 2] }] }, opt: {} }, { w: 640, h: 360 });
  assert.match(textContent(parseXml(r)), /at least 3 categories/);
  const zero = chartToSvg({ type: 'column', data: { categories: ['a'], series: [{ name: 's', values: [0] }] }, opt: {} }, { w: 640, h: 360 });
  assert.match(textContent(parseXml(zero)), /All values are zero/);
});

test('determinism: the same input gives the same output, every time', () => {
  for (const t of TYPES) {
    const a = chartToSvg(sampleChart(t), { w: 640, h: 360, idPrefix: 'd' });
    const b = chartToSvg(sampleChart(t), { w: 640, h: 360, idPrefix: 'd' });
    assert.equal(a, b, t);
    const frozen = JSON.parse(JSON.stringify(sampleChart(t)));
    const before = JSON.stringify(frozen);
    chartToSvg(frozen, { w: 640, h: 360 });
    assert.equal(JSON.stringify(frozen), before, 'chartToSvg does not mutate its input');
  }
  const x = chartToSvg(sampleChart('column'), { idPrefix: 'a' });
  const y = chartToSvg(sampleChart('column'), { idPrefix: 'b' });
  assert.equal(x.replace(/\ba-/g, 'b-'), y, 'only the ids differ');
});

test('chartToTable twin for every type, raw and formatted', () => {
  for (const t of TYPES) {
    const tbl = chartToTable(sampleChart(t));
    assert.ok(tbl.header.length >= 2, t);
    assert.ok(tbl.rows.length >= 1, t);
    tbl.rows.forEach((r) => assert.equal(r.length, tbl.header.length, t + ' row width'));
    const fm = chartToTable(sampleChart(t), { format: true });
    fm.rows.forEach((r) => r.forEach((c) => assert.ok(typeof c === 'string' || typeof c === 'number')));
  }
  const col = chartToTable(sampleChart('column'));
  assert.deepEqual(col.header, ['Category', 'Revenue', 'Costs']);
  assert.deepEqual(col.rows[0], ['Q1', 1.84, 1.52]);
  assert.deepEqual(chartToTable(sampleChart('column'), { format: true }).rows[0], ['Q1', '1.84', '1.52']);
  const pie = chartToTable(sampleChart('pie'));
  assert.deepEqual(pie.header, ['Slice', 'Share of spend', 'Share']);
  assert.equal(pie.rows[0][2], '54%');
  const wf = chartToTable(sampleChart('waterfall'));
  assert.deepEqual(wf.header, ['Step', 'Change', 'Running total']);
  assert.equal(wf.rows[wf.rows.length - 1][2], 2.5);
  const map = chartToTable(sampleChart('map'));
  assert.deepEqual(map.rows[0].slice(0, 2), ['ZA', 'South Africa']);
  const fun = chartToTable(sampleChart('funnel'));
  assert.equal(fun.rows[1][2], '33%');
});

test('describeChart gives plain sentences for every type', () => {
  for (const t of TYPES) {
    const d = describeChart(sampleChart(t));
    assert.ok(d.length > 30 && d.length < 600, t + ': ' + d);
    assert.doesNotMatch(d, /undefined|NaN|\[object|[–—]/);
    assert.match(d, /\.$/);
  }
  assert.match(describeChart(sampleChart('line')), /Line chart titled Monthly active users with 12 categories/);
  assert.match(describeChart(sampleChart('map')), /South Africa 42/);
  const desc = [];
  walk(parseXml(chartToSvg(sampleChart('doughnut'), { w: 640, h: 360 })), (n) => {
    if (n.name === 'desc') desc.push(textContent(n));
  });
  assert.equal(desc[0], describeChart(sampleChart('doughnut')));
});
