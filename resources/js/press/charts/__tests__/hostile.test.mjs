import test from 'node:test';
import assert from 'node:assert/strict';
import { chartToSvg, sampleChart, CHART_TYPES } from '../index.js';

// The chart SVG is inserted with v-html in the editor, the player and the offline package. Every string a person can
// type into a chart must come out as text, whatever the chart type.
const EVIL = [
  '"><script>alert(1)</script>',
  "'><img src=x onerror=alert(2)>",
  '</text><script>alert(3)</script>',
  '<svg onload=alert(4)>',
  'a" onmouseover="alert(5)" x="',
  '&lt;b&gt;&amp;',
  ']]><![CDATA[x',
];

function poison(chart) {
  const c = JSON.parse(JSON.stringify(chart));
  c.data.categories = (c.data.categories || []).map((_, i) => EVIL[i % EVIL.length]);
  c.data.series = (c.data.series || []).map((s, i) => ({ ...s, name: EVIL[(i + 1) % EVIL.length] }));
  if (c.data.kpis) c.data.kpis = c.data.kpis.map((k, i) => ({ ...k, label: EVIL[i % 7], value: EVIL[(i + 2) % 7], delta: EVIL[(i + 3) % 7], unit: EVIL[(i + 4) % 7], note: EVIL[(i + 5) % 7] }));
  if (c.data.regions) c.data.regions = c.data.regions.map((r, i) => ({ ...r, code: EVIL[i % 7] }));
  c.opt = { ...(c.opt || {}), title: EVIL[0], sub: EVIL[1], cur: EVIL[2], center: EVIL[3], ax: { x: { title: EVIL[4] }, y: { title: EVIL[5] } } };
  c.src = { kind: 'file', name: EVIL[6] };
  return c;
}

function assertInert(svg, label) {
  assert.ok(typeof svg === 'string' && svg.startsWith('<svg'), `${label}: an svg`);
  assert.ok(!/<script/i.test(svg), `${label}: a script element`);
  assert.ok(!/<img/i.test(svg), `${label}: an img element`);
  // Attribute names only: a quoted value may hold the words (escaped), a name may not.
  for (const tag of svg.matchAll(/<[a-zA-Z][^>]*>/g)) {
    const bare = tag[0].replace(/"[^"]*"/g, '""');
    for (const name of bare.matchAll(/\s([^\s=/>]+)=/g)) {
      assert.ok(!/^on/i.test(name[1]), `${label}: an event attribute ${name[1]}`);
    }
  }
  // every angle bracket opens a tag this engine writes itself
  const known = /^\/?(svg|g|path|rect|circle|ellipse|line|polyline|polygon|text|tspan|title|desc|defs|linearGradient|radialGradient|stop|pattern|clipPath|style|use|mask|filter|marker|symbol)$/;
  for (const m of svg.matchAll(/<(\/?[^\s>/]*)/g)) {
    assert.ok(known.test(m[1]), `${label}: unexpected tag <${m[1]}`);
  }
}

for (const { id } of CHART_TYPES) {
  test(`hostile strings stay text in a ${id} chart`, () => {
    const svg = chartToSvg(poison(sampleChart(id)), { w: 960, h: 540, idPrefix: 'evil' });
    assertInert(svg, id);
    assert.ok(svg.includes('&lt;script&gt;'), `${id}: the words are still shown, as text`);
  });
}

test('a hostile font family and id prefix cannot open an attribute', () => {
  const svg = chartToSvg(sampleChart('column'), { w: 960, h: 540, idPrefix: 'x" onload="alert(1)', font: 'Arial" onload="alert(2)' });
  assertInert(svg, 'font');
  assert.ok(!svg.includes('onload="alert'), 'attribute break out');
});

test('a hostile series colour is not copied into the markup', () => {
  const c = sampleChart('column');
  c.data.series[0].color = 'red" onload="alert(1)';
  c.opt = { ...(c.opt || {}), colors: ['url(javascript:alert(1))'] };
  const svg = chartToSvg(c, { w: 960, h: 540 });
  assertInert(svg, 'colour');
  assert.ok(!svg.includes('javascript:'));
});
