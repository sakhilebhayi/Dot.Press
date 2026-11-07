import test from 'node:test';
import assert from 'node:assert/strict';
import { chartToSvg, sampleChart, CHART_TYPES, describeChart, chartToTable } from '../index.js';
import { esc, cleanText } from '../util.js';
import { HOSTILE } from './fixtures.mjs';
import { parseXml, geometryProblems, walk, textContent, allAttributeValues } from './helpers.mjs';

test('the XML checker rejects broken markup and the geometry checker sees problems', () => {
  assert.throws(() => parseXml('<svg viewBox="0 0 10 10"><text>a < b</text></svg>'));
  assert.throws(() => parseXml('<svg viewBox="0 0 10 10"><text>a & b</text></svg>'));
  assert.throws(() => parseXml('<svg viewBox="0 0 10 10"><g></svg>'));
  assert.throws(() => parseXml('<svg viewBox="0 0 10 10" a="1" a="2"/>'));
  assert.throws(() => parseXml('<svg viewBox="0 0 10 10"><text x="a>b">x</text></svg>'), /bad attribute|text/);
  assert.doesNotThrow(() => parseXml('<svg viewBox="0 0 10 10"><text>a &amp; b &lt; c &#39;</text></svg>'));
  const bad = '<svg viewBox="0 0 100 50"><rect x="90" y="0" width="30" height="10"/><text x="95" y="20" font-size="10">Long label here</text><circle cx="5" cy="5" r="20"/></svg>';
  const problems = geometryProblems(bad);
  assert.ok(problems.length >= 3, problems.join(';'));
});

test('esc handles the characters XML forbids', () => {
  assert.equal(esc('<a href="x">&\'</a>'), '&lt;a href=&quot;x&quot;&gt;&amp;&#39;&lt;/a&gt;');
  assert.equal(esc('a\u0000b\u0008c\u001fd'), 'abcd');
  assert.equal(esc('\ud800x'), 'x');
  assert.equal(esc('\u{1F600}'), '\u{1F600}');
  assert.equal(esc(null), '');
  assert.equal(esc(42), '42');
  assert.equal(cleanText('  a \n\t b ', 20), 'a b');
  assert.equal(cleanText('x'.repeat(300), 10).length, 10);
  assert.equal(cleanText({ toString() { return 'no'; } }), '');
});

const hostileChart = (type) => ({
  type,
  data: {
    categories: HOSTILE.slice(0, 5),
    series: [
      { name: HOSTILE[1], values: [3, 5, 2, 8, 1], points: [{ x: 1, y: 2, label: HOSTILE[0] }], color: '"><script>' },
      { name: HOSTILE[2], values: [4, 1, 6, 2, 3], points: [{ x: 2, y: 3, r: 4, label: HOSTILE[7] }] },
    ],
    kpis: HOSTILE.slice(0, 4).map((h, i) => ({ label: h, value: i * 10, unit: HOSTILE[0], note: HOSTILE[1], delta: HOSTILE[3] })),
    regions: [{ code: 'ZA', value: 4 }, { code: '<s', value: 3 }],
    totals: [0, 4],
  },
  opt: { title: HOSTILE[0], sub: HOSTILE[1], center: HOSTILE[7], labels: 'value', legend: 'bottom', cur: '"><', colors: ['"x', '#fff" onload="x', 'javascript:alert(1)'], ax: { x: { title: HOSTILE[2], show: true }, y: { title: HOSTILE[3], show: true } } },
});

for (const t of CHART_TYPES) {
  test(`${t.id}: hostile strings are escaped and the output stays well formed`, () => {
    const chart = hostileChart(t.id);
    for (const [w, h] of [[640, 360], [320, 240]]) {
      const svg = chartToSvg(chart, { w, h, font: '"><script>alert(1)</script>', idPrefix: '"><x', debug: true });
      const root = parseXml(svg); // throws unless well formed
      let scripts = 0;
      walk(root, (n) => {
        if (n.name === 'script' || n.name === 'foreignObject' || n.name === 'image') scripts++;
        for (const k of Object.keys(n.attrs)) assert.doesNotMatch(k, /^on/i, 'event attribute ' + k);
      });
      assert.equal(scripts, 0);
      for (const v of allAttributeValues(root)) assert.doesNotMatch(v, /^javascript:/i);
      assert.doesNotMatch(svg, /<(script|svg onload|x )/i, 'raw hostile markup survived');
      assert.doesNotMatch(svg, /\u0000|\u0008|\u001f/);
      assert.equal(root.children[0].name, 'title');
    }
  });
}

test('hostile text is kept as readable text, not executed', () => {
  const svg = chartToSvg(hostileChart('column'), { w: 960, h: 540 });
  const root = parseXml(svg);
  assert.ok(textContent(root).includes('<script>alert(1)</script>'), 'title shows as plain text');
  assert.ok(svg.includes('&lt;script&gt;alert(1)&lt;/script&gt;'));
});

test('description and table twin carry the same hostile text as data', () => {
  const chart = hostileChart('column');
  const d = describeChart(chart);
  assert.equal(typeof d, 'string');
  assert.ok(d.length < 2000);
  const tbl = chartToTable(chart);
  assert.equal(tbl.header[0], 'Category');
  assert.equal(tbl.rows.length, 5);
});

test('ids stay unique per chart when two charts share a page', () => {
  const a = chartToSvg(sampleChart('map'), { idPrefix: 'a' });
  const b = chartToSvg(sampleChart('map'), { idPrefix: 'b' });
  const ids = (s) => [...s.matchAll(/ id="([^"]+)"/g)].map((m) => m[1]);
  const ia = ids(a);
  const ib = ids(b);
  assert.equal(new Set(ia).size, ia.length);
  assert.equal(ia.filter((x) => ib.includes(x)).length, 0);
  // an unsafe prefix falls back to the default
  assert.match(chartToSvg(sampleChart('column'), { idPrefix: '"><x' }), /id="pc-title"/);
});
