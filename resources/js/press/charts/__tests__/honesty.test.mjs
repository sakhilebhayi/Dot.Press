import test from 'node:test';
import assert from 'node:assert/strict';
import { chartToSvg, sampleChart, DEFAULT_THEME, DEFAULT_DARK_THEME } from '../index.js';
import { contrast, parseColor } from '../util.js';
import { parseXml, walk, textContent, bboxOf } from './helpers.mjs';

const cls = (n, c) => (n.attrs.class || '').split(' ').includes(c);
const marks = (root, series) => {
  const out = [];
  walk(root, (n) => {
    if (cls(n, 'pc-mark') && n.name === 'path' && (series === undefined || n.attrs['data-series'] === String(series))) out.push(n);
  });
  return out;
};
const texts = (root) => {
  const out = [];
  walk(root, (n) => {
    if (n.name === 'text') out.push(textContent(n));
  });
  return out;
};

function col(values, opt = {}, type = 'column') {
  return { type, data: { categories: values.map((_, i) => 'c' + i), series: [{ name: 's', values }] }, opt };
}

test('bars are drawn exactly: heights are proportional to the values and start on the zero line', () => {
  const root = parseXml(chartToSvg(col([10, 20, 40]), { w: 640, h: 360 }));
  const [a, b, c] = marks(root, 0).map(bboxOf);
  assert.ok(Math.abs(b.h / a.h - 2) < 0.02, `${b.h / a.h}`);
  assert.ok(Math.abs(c.h / a.h - 4) < 0.02);
  assert.ok(Math.abs(a.y + a.h - (b.y + b.h)) < 0.01, 'all bars share one baseline');
  assert.equal(texts(root).includes('Value axis does not start at zero.'), false);
  assert.ok(texts(root).includes('0'), 'the axis shows zero');
});

test('horizontal bars start at zero and are proportional too', () => {
  const root = parseXml(chartToSvg(col([5, 10, 20], {}, 'bar'), { w: 640, h: 360 }));
  const [a, b, c] = marks(root, 0).map(bboxOf);
  assert.ok(Math.abs(b.w / a.w - 2) < 0.03);
  assert.ok(Math.abs(c.w / a.w - 4) < 0.03);
  assert.ok(Math.abs(a.x - b.x) < 0.01);
});

test('negative values hang below one zero line and are not clipped', () => {
  const root = parseXml(chartToSvg(col([10, -10, 5]), { w: 640, h: 360 }));
  const [a, b, c] = marks(root, 0).map(bboxOf);
  assert.ok(Math.abs(a.h - b.h) < 0.5);
  assert.ok(Math.abs(a.y + a.h - b.y) < 0.5, 'positive ends where the negative starts');
  assert.ok(Math.abs(c.h * 2 - a.h) < 0.5);
});

test('overriding the axis minimum draws a break mark and says so', () => {
  const root = parseXml(chartToSvg(col([91, 94, 97], { ax: { x: { show: true }, y: { show: true, min: 80, max: 100 } } }), { w: 640, h: 360 }));
  let breaks = 0;
  walk(root, (n) => {
    if (cls(n, 'pc-break')) breaks++;
  });
  assert.ok(breaks >= 1);
  assert.ok(texts(root).includes('Value axis does not start at zero.'));
  assert.ok(texts(root).includes('80'));
});

test('a line that sits far above zero is truncated and labelled, a line near zero is not', () => {
  const far = parseXml(chartToSvg(col([18.2, 18.4, 18.1, 17.9], {}, 'line'), { w: 640, h: 360 }));
  assert.ok(texts(far).includes('Value axis does not start at zero.'));
  const near = parseXml(chartToSvg(col([2, 18, 9, 14], {}, 'line'), { w: 640, h: 360 }));
  assert.equal(texts(near).includes('Value axis does not start at zero.'), false);
  const area = parseXml(chartToSvg(col([95, 96, 97, 98], {}, 'area'), { w: 640, h: 360 }));
  assert.equal(texts(area).includes('Value axis does not start at zero.'), false, 'areas always start at zero');
});

test('colour follows the series index, never the value or the rank', () => {
  const theme = DEFAULT_THEME;
  const mk = (a, b) => ({ type: 'column', data: { categories: ['x', 'y'], series: [{ name: 'A', values: a }, { name: 'B', values: b }] }, opt: {} });
  const fillOf = (svg, s) => marks(parseXml(svg), s)[0].attrs.fill;
  const one = chartToSvg(mk([10, 20], [1, 2]), { theme });
  const two = chartToSvg(mk([1, 2], [10, 20]), { theme });
  assert.equal(fillOf(one, 0), theme.c.chart[0]);
  assert.equal(fillOf(one, 1), theme.c.chart[1]);
  assert.equal(fillOf(two, 0), theme.c.chart[0]);
  assert.equal(fillOf(two, 1), theme.c.chart[1]);
  // pie slices too: reordering values keeps slice i the same colour
  const pie = (v) => chartToSvg({ type: 'pie', data: { categories: ['a', 'b', 'c'], series: [{ name: 's', values: v }] }, opt: {} }, { theme });
  assert.equal(marks(parseXml(pie([5, 1, 9])))[1].attrs.fill, marks(parseXml(pie([1, 5, 9])))[1].attrs.fill);
  // a custom series colour wins, theme tokens resolve
  const custom = chartToSvg({ type: 'column', data: { categories: ['x'], series: [{ name: 'A', values: [1], color: '@accent2' }] }, opt: {} }, { theme });
  assert.equal(fillOf(custom, 0), theme.c.accent2);
});

test('more than 8 series reuse the palette with a hatch pattern and distinct lightness', () => {
  const chart = { type: 'column', data: { categories: ['a'], series: Array.from({ length: 12 }, (_, i) => ({ name: 's' + i, values: [i + 1] })) }, opt: {} };
  const svg = chartToSvg(chart, { w: 640, h: 360 });
  const root = parseXml(svg);
  const fills = marks(root).map((m) => m.attrs.fill);
  assert.equal(fills.length, 12);
  fills.slice(0, 8).forEach((f, i) => assert.equal(f, DEFAULT_THEME.c.chart[i]));
  fills.slice(8).forEach((f) => assert.match(f, /^url\(#pc-hatch-/));
  assert.ok(/<pattern id="pc-hatch-/.test(svg));
  const lines = chartToSvg({ type: 'line', data: { categories: ['a', 'b', 'c'], series: chart.data.series.map((s, i) => ({ name: s.name, values: [i, i + 2, i + 1] })) }, opt: {} }, { w: 640, h: 360 });
  const dashes = new Set();
  walk(parseXml(lines), (n) => {
    if (cls(n, 'pc-mark') && n.name === 'path' && n.attrs['stroke-dasharray']) dashes.add(n.attrs['stroke-dasharray']);
  });
  assert.ok(dashes.size >= 2, 'lines past the fourth carry a dash');
});

test('label ink on a coloured mark has the best of white and near black', () => {
  for (const theme of [DEFAULT_THEME, DEFAULT_DARK_THEME]) {
    const chart = { type: 'column', data: { categories: ['a', 'b'], series: theme.c.chart.map((_, i) => ({ name: 's' + i, values: [5 + i, 2 + i] })) }, opt: { stacked: true, labels: 'value' } };
    const root = parseXml(chartToSvg(chart, { w: 960, h: 900, theme }));
    const inside = [];
    walk(root, (n) => {
      if (n.name === 'text' && cls(n, 'pc-label') && /^#(?:ffffff|0b0b0b)$/.test(n.attrs.fill)) inside.push(n.attrs.fill);
    });
    assert.ok(inside.length > 4, 'inside labels exist');
    const fills = marks(root).map((m) => m.attrs.fill);
    for (const f of fills) {
      const best = Math.max(contrast(f, '#ffffff'), contrast(f, '#0b0b0b'));
      assert.ok(best >= 4.5, `${f} can carry readable text`);
    }
  }
});

test('stacked segments are separated by a gap and only the outer end is rounded', () => {
  const chart = { type: 'column', data: { categories: ['a'], series: [{ name: 'x', values: [10] }, { name: 'y', values: [10] }, { name: 'z', values: [10] }] }, opt: { stacked: true } };
  const root = parseXml(chartToSvg(chart, { w: 640, h: 360 }));
  const [x, y, z] = marks(root).map(bboxOf);
  assert.ok(x.y - (y.y + y.h) > 0.5 || y.y - (x.y + x.h) > 0.5 || Math.abs(x.y - (y.y + y.h)) > 0.5, 'a visible gap between segments');
  assert.ok(Math.abs(x.h - z.h) < 4, 'segments of equal value have nearly equal height');
  const d = marks(root).map((m) => m.attrs.d);
  assert.ok(!/a/.test(d[0]) && !/a/.test(d[1]) && /a/.test(d[2]), 'only the top segment has rounded corners');
});

test('legend: none for one series, present for two or more, direct labels for 2 to 4 lines', () => {
  const one = parseXml(chartToSvg(sampleChart('bar'), { w: 640, h: 360 }));
  let legends = 0;
  walk(one, (n) => {
    if (cls(n, 'pc-legend')) legends++;
  });
  assert.equal(legends, 0);
  const two = parseXml(chartToSvg(sampleChart('line'), { w: 640, h: 360 }));
  legends = 0;
  let direct = 0;
  walk(two, (n) => {
    if (cls(n, 'pc-legend')) legends++;
    if (cls(n, 'pc-direct')) direct++;
  });
  assert.equal(legends, 1);
  assert.equal(direct, 2);
  const six = { type: 'line', data: { categories: ['a', 'b', 'c'], series: Array.from({ length: 6 }, (_, i) => ({ name: 's' + i, values: [i, i + 1, i + 2] })) }, opt: { legend: 'bottom' } };
  direct = 0;
  walk(parseXml(chartToSvg(six, { w: 640, h: 360 })), (n) => {
    if (cls(n, 'pc-direct')) direct++;
  });
  assert.equal(direct, 0, 'more than four series use the legend only');
});

test('identity is not colour alone: marker shapes differ per series on lines and scatter', () => {
  const root = parseXml(chartToSvg(sampleChart('line'), { w: 640, h: 360 }));
  const shapes = new Set();
  walk(root, (n) => {
    if (cls(n, 'pc-dot')) shapes.add(n.name + (n.attrs.d ? n.attrs.d.slice(0, 1) + (n.attrs.d.match(/L/g) || []).length : ''));
  });
  assert.ok(shapes.size >= 2, 'two series use two marker shapes: ' + [...shapes]);
});

test('pie: slices past 8 fold into Other only when allowed; tiny slices get leader labels', () => {
  const values = [30, 22, 15, 9, 6, 5, 4, 3, 2.5, 2, 1, 0.5];
  const mk = (other) => ({ type: 'pie', data: { categories: values.map((_, i) => 'Item ' + (i + 1)), series: [{ name: 'v', values }] }, opt: { other, labels: 'percent' } });
  assert.equal(marks(parseXml(chartToSvg(mk(false), { w: 640, h: 360 }))).length, 12);
  const folded = parseXml(chartToSvg(mk(true), { w: 640, h: 360 }));
  assert.equal(marks(folded).length, 8);
  assert.ok(texts(folded).includes('Other'));
  let leaders = 0;
  walk(parseXml(chartToSvg(mk(false), { w: 640, h: 360 })), (n) => {
    if (n.name === 'polyline') leaders++;
  });
  assert.ok(leaders >= 3, 'slices under 2 percent are labelled outside with leaders');
});

test('doughnut centre label shows the total or the custom text', () => {
  const c = sampleChart('doughnut');
  assert.ok(texts(parseXml(chartToSvg(c, { w: 640, h: 360 }))).includes('R 8.4m'));
  c.opt.center = undefined;
  assert.ok(texts(parseXml(chartToSvg(c, { w: 640, h: 360 }))).includes('100'));
});

test('waterfall: totals restart the running total and deltas are signed', () => {
  const svg = chartToSvg(sampleChart('waterfall'), { w: 960, h: 540 });
  const t = texts(parseXml(svg));
  for (const want of ['2.4', '+1.1', '+0.6', '-0.5', '-0.9', '-0.2', '2.5']) assert.ok(t.includes(want), want + ' in ' + t.join('|'));
});

test('kpi: arrow and sign carry direction, "good" can flip which colour a fall gets', () => {
  const mk = (good) => ({ type: 'kpi', data: { categories: [], series: [], kpis: [{ label: 'Churn', value: 2.8, delta: -0.6, good }] }, opt: {} });
  const fillOfArrow = (svg) => {
    let fill;
    walk(parseXml(svg), (n) => {
      if (n.name === 'path' && !cls(n, 'pc-mark') && n.attrs.fill && /^#/.test(n.attrs.fill) && n.attrs.d && n.attrs.d.endsWith('z')) fill = n.attrs.fill;
    });
    return fill;
  };
  assert.equal(fillOfArrow(chartToSvg(mk('down'), { w: 640, h: 360 })), DEFAULT_THEME.c.good);
  assert.equal(fillOfArrow(chartToSvg(mk(undefined), { w: 640, h: 360 })), DEFAULT_THEME.c.bad);
  assert.ok(texts(parseXml(chartToSvg(mk('down'), { w: 640, h: 360 }))).includes('-0.6%'));
});

test('map: scale legend, unknown codes noted, countries coloured by value with one hue', () => {
  const root = parseXml(chartToSvg(sampleChart('map'), { w: 960, h: 540 }));
  const fills = marks(root).map((m) => m.attrs.fill);
  assert.equal(fills.length, 13);
  const lum = (f) => {
    const c = parseColor(f);
    return 0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b;
  };
  const regions = sampleChart('map').data.regions;
  const sorted = regions.map((r, i) => ({ v: r.value, l: lum(fills[i]) })).sort((a, b) => a.v - b.v);
  for (let i = 1; i < sorted.length; i++) assert.ok(sorted[i].l <= sorted[i - 1].l + 0.5, 'darker means more');
  const t = texts(root);
  assert.ok(t.includes('7') && t.includes('42'), 'scale ends are the data range');
  const noted = chartToSvg(STRESS_MAP, { w: 640, h: 360 });
  assert.match(textContent(parseXml(noted)), /Not drawn at this map detail: SG, ZZ/);
});

const STRESS_MAP = { type: 'map', data: { categories: [], series: [], regions: [{ code: 'ZA', value: 1 }, { code: 'SG', value: 2 }, { code: 'ZZ', value: 3 }] }, opt: {} };

test('dark theme: ink, gridlines and marks come from the theme', () => {
  const svg = chartToSvg(sampleChart('column'), { w: 640, h: 360, theme: DEFAULT_DARK_THEME });
  const root = parseXml(svg);
  assert.equal(marks(root, 0)[0].attrs.fill, DEFAULT_DARK_THEME.c.chart[0]);
  const fills = new Set();
  walk(root, (n) => {
    if (n.name === 'text' && !n.attrs.class?.includes('pc-label')) fills.add(n.attrs.fill);
  });
  assert.ok(fills.has(DEFAULT_DARK_THEME.c.ink) && fills.has(DEFAULT_DARK_THEME.c.muted));
  assert.ok(!svg.includes(DEFAULT_THEME.c.muted), 'no light-theme colours leak into the dark chart');
});

test('the default palettes keep the colour-blind separation recorded in index.js', async () => {
  const mod = await import('/private/tmp/claude-501/bundled-skills/2.1.309/ec237c2241dae2ccd9c6612a1d1538df/dataviz/scripts/validate_palette.js').catch(() => null);
  if (!mod) return; // validator not installed on this machine
  for (const [theme, mode, surface] of [[DEFAULT_THEME, 'light', '#ffffff'], [DEFAULT_DARK_THEME, 'dark', '#111827']]) {
    const r = mod.validate(theme.c.chart, { mode, surface });
    const byName = Object.fromEntries(r.report.map((x) => [x[0], x]));
    assert.equal(byName['Lightness band'][1], true, mode + ' lightness');
    assert.notEqual(byName['CVD separation'][1], false, mode + ' colour-blind separation');
    assert.ok(!/fail/i.test(String(byName['Normal-vision floor'][1])) && byName['Normal-vision floor'][1] !== false, mode + ' normal vision');
  }
});
