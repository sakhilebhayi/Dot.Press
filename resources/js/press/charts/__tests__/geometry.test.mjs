import test from 'node:test';
import assert from 'node:assert/strict';
import { chartToSvg, sampleChart, CHART_TYPES, DEFAULT_THEME, DEFAULT_DARK_THEME } from '../index.js';
import { STRESS } from './fixtures.mjs';
import { parseXml, geometryProblems, walk } from './helpers.mjs';

const SIZES = [
  [640, 360],
  [960, 540],
  [320, 240],
  [1920, 1080],
  [200, 150],
];
const THEMES = [
  ['light', DEFAULT_THEME],
  ['dark', DEFAULT_DARK_THEME],
];

for (const t of CHART_TYPES) {
  test(`${t.id}: sample is well formed, finite and inside the viewBox at every size and theme`, () => {
    for (const [w, h] of SIZES) {
      for (const [name, theme] of THEMES) {
        const svg = chartToSvg(sampleChart(t.id), { w, h, theme, debug: true });
        assert.doesNotMatch(svg, /NaN|undefined|Infinity|null/, `${t.id} ${w}x${h} ${name} has a bad number`);
        const root = parseXml(svg);
        assert.equal(root.name, 'svg');
        assert.equal(root.attrs.viewBox, `0 0 ${w} ${h}`);
        const problems = geometryProblems(svg);
        assert.deepEqual(problems, [], `${t.id} ${w}x${h} ${name}: ${problems.slice(0, 5).join('; ')}`);
      }
    }
  });
}

STRESS.forEach((c, i) => {
  test(`stress ${i}: ${c.name} stays finite, well formed and inside the viewBox`, () => {
    for (const [w, h] of SIZES.slice(0, 3)) {
      const svg = chartToSvg(c.chart, { w, h, debug: true });
      assert.doesNotMatch(svg, /NaN|undefined|Infinity/);
      const problems = geometryProblems(svg);
      assert.deepEqual(problems, [], `${c.name} ${w}x${h}: ${problems.slice(0, 5).join('; ')}`);
    }
  });
});

test('every chart has a title, a description, no scripts and no external references', () => {
  for (const t of CHART_TYPES) {
    const svg = chartToSvg(sampleChart(t.id), { w: 640, h: 360, idPrefix: 'x1' });
    const root = parseXml(svg);
    const names = [];
    const urls = [];
    walk(root, (n) => {
      names.push(n.name);
      for (const [k, v] of Object.entries(n.attrs)) {
        if (/^on/i.test(k)) assert.fail(`event handler ${k}`);
        if ((k === 'href' || k === 'xlink:href' || k === 'src') && !/^#/.test(v)) urls.push(v);
        if (/url\(/.test(v) && !/url\(#[\w-]+\)/.test(v)) urls.push(v);
      }
    });
    assert.ok(names.includes('title'), t.id + ' title');
    assert.ok(names.includes('desc'), t.id + ' desc');
    assert.ok(!names.includes('script'));
    assert.ok(!names.includes('image'));
    assert.ok(!names.includes('foreignObject'));
    assert.deepEqual(urls, []);
    assert.doesNotMatch(svg, /https?:\/\/(?!www\.w3\.org\/2000\/svg)/);
    assert.match(root.attrs['aria-labelledby'], /^x1-title x1-desc$/);
    assert.equal(root.attrs.role, 'img');
  }
});

test('marks are separate elements with pc-mark, --i, data-series and data-index', () => {
  const svg = chartToSvg(sampleChart('column'), { w: 640, h: 360 });
  const root = parseXml(svg);
  const marks = [];
  walk(root, (n) => {
    if ((n.attrs.class || '').split(' ').includes('pc-mark')) marks.push(n);
  });
  assert.equal(marks.length, 8, 'two series of four columns');
  marks.forEach((m) => {
    assert.match(m.attrs.style, /^--i:\d+$/);
    assert.ok(m.attrs['data-series'] !== undefined && m.attrs['data-index'] !== undefined);
    assert.ok(m.children.some((c) => c.name === 'title'), 'each mark has a title for tooltips');
  });
  assert.deepEqual(marks.map((m) => m.attrs['--i']), marks.map(() => undefined));
  // staggered by category: the two bars of a category share an index
  const idx = marks.map((m) => Number(m.attrs.style.slice(4)));
  assert.deepEqual([...new Set(idx)].sort(), [0, 1, 2, 3]);
});

test('animate and reduced options set classes and never script', () => {
  const a = chartToSvg(sampleChart('line'), { w: 640, h: 360, animate: true });
  assert.match(a, /class="[^"]*pc-anim/);
  const r = chartToSvg(sampleChart('line'), { w: 640, h: 360, animate: true, reduced: true });
  assert.doesNotMatch(r, /pc-anim/);
  assert.match(r, /pc-reduced/);
  const plain = chartToSvg(sampleChart('line'), { w: 640, h: 360 });
  assert.doesNotMatch(plain, /pc-anim/);
});
