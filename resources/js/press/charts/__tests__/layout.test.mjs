import test from 'node:test';
import assert from 'node:assert/strict';
import { chartToSvg, sampleChart, CHART_TYPES } from '../index.js';
import { STRESS } from './fixtures.mjs';
import { parseXml, textBoxesWithClass, boxesInGroup, marksBoxes, overlaps, walk } from './helpers.mjs';

const SIZES = [[640, 360], [960, 540], [320, 240]];

function labelOverlaps(svg) {
  const root = parseXml(svg);
  const labels = textBoxesWithClass(root, 'pc-label');
  const bad = [];
  for (let i = 0; i < labels.length; i++) for (let j = i + 1; j < labels.length; j++) if (overlaps(labels[i].box, labels[j].box, 1.5)) bad.push(`"${labels[i].text}" over "${labels[j].text}"`);
  return bad;
}

for (const t of CHART_TYPES) {
  test(`${t.id}: data labels never overlap each other`, () => {
    for (const [w, h] of SIZES) {
      const chart = sampleChart(t.id);
      if (!['pie', 'doughnut', 'map', 'kpi'].includes(t.id)) chart.opt.labels = 'value';
      const bad = labelOverlaps(chartToSvg(chart, { w, h }));
      assert.deepEqual(bad, [], `${t.id} ${w}x${h}`);
    }
  });
}

STRESS.forEach((c, i) => {
  test(`stress ${i} ${c.name}: data labels never overlap each other`, () => {
    for (const [w, h] of SIZES) assert.deepEqual(labelOverlaps(chartToSvg(c.chart, { w, h })), [], `${c.name} ${w}x${h}`);
  });
});

test('the legend never overlaps the plot marks for bottom, top and right placement', () => {
  for (const type of ['column', 'bar', 'line', 'area', 'combo', 'scatter']) {
    for (const legend of ['bottom', 'top', 'right']) {
      for (const [w, h] of SIZES) {
        const chart = sampleChart(type === 'scatter' ? 'scatter' : type);
        chart.opt.legend = legend;
        if (chart.data.series.length < 2) chart.data.series.push({ name: 'Second series with a long name', values: chart.data.series[0].values.map((v) => (v === null ? null : v * 0.7)), points: (chart.data.series[0].points || []).map((p) => ({ ...p, y: p.y * 0.7 })) });
        const root = parseXml(chartToSvg(chart, { w, h }));
        const lg = boxesInGroup(root, 'pc-legend');
        assert.ok(lg.length > 0, `${type} ${legend} has a legend`);
        const marks = marksBoxes(root);
        for (const l of lg) for (const m of marks) assert.ok(!overlaps(l, m, 1), `${type} ${legend} ${w}x${h}: legend ${JSON.stringify(l)} over mark ${JSON.stringify(m)}`);
      }
    }
  }
});

test('title and legend stay inside the picture and the plot never starts above the title', () => {
  const chart = sampleChart('column');
  chart.opt.title = 'A very long title that keeps going and going until it has to be shortened for sure';
  chart.opt.sub = 'And a long subtitle that also keeps going and going and going for quite a while longer';
  const root = parseXml(chartToSvg(chart, { w: 320, h: 240 }));
  const marks = marksBoxes(root);
  const titleBoxes = boxesInGroup(root, 'pc-chrome').filter((b) => b.y < 60);
  assert.ok(titleBoxes.length >= 2);
  for (const b of titleBoxes) assert.ok(b.x >= -1 && b.x + b.w <= 321, 'title fits the width');
  const lowestTitle = Math.max(...titleBoxes.map((b) => b.y + b.h));
  for (const m of marks) assert.ok(m.y >= lowestTitle - 2, 'marks start below the title block');
});

test('right legend falls back to the bottom on narrow charts', () => {
  const chart = sampleChart('line');
  chart.opt.legend = 'right';
  const svg = chartToSvg(chart, { w: 300, h: 240 });
  const root = parseXml(svg);
  const lg = boxesInGroup(root, 'pc-legend');
  const maxX = Math.max(...lg.map((b) => b.x + b.w));
  assert.ok(maxX <= 300);
  const minY = Math.min(...lg.map((b) => b.y));
  assert.ok(minY > 150, 'legend sits near the bottom');
});

test('many series: legend keeps every item or says how many were left out', () => {
  const chart = { type: 'column', data: { categories: ['a', 'b'], series: Array.from({ length: 30 }, (_, i) => ({ name: 'Series number ' + (i + 1), values: [i, 30 - i] })) }, opt: { legend: 'bottom' } };
  const svg = chartToSvg(chart, { w: 640, h: 360 });
  const root = parseXml(svg);
  const lg = boxesInGroup(root, 'pc-legend');
  assert.ok(lg.length > 0);
  const texts = [];
  walk(root, (n) => {
    if (n.name === 'text') texts.push(n.text);
  });
  assert.ok(texts.some((t) => /^\+\d+ more$/.test(t)) || texts.filter((t) => /^Series number/.test(t)).length === 30);
});
