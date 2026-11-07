import test from 'node:test';
import assert from 'node:assert/strict';
import {
  DEFAULT_THEME, resolveColor, resolveFill, contrastRatio, nearestToken, resolveFont, resolveWeight, isToken, isHex,
  mapColors, mapFonts, usageOf, applyTheme, parseColor, COLOR_TOKENS,
} from '../theme.js';
import { mkDoc, S, T, R } from './helpers.mjs';

const dark = {
  ...DEFAULT_THEME, id: 'ink', c: { ...DEFAULT_THEME.c, bg: '#0b0f14', ink: '#f3f4f6', accent: '#38bdf8', chart: ['#38bdf8', '#fbbf24', '#34d399', '#a78bfa', '#f472b6', '#22d3ee', '#a3e635', '#94a3b8'] },
  t: { heading: 'Fraunces', body: 'Inter', hw: 600, scale: 1 },
};

test('tokens resolve through the theme, hex stays', () => {
  assert.equal(resolveColor('@accent', DEFAULT_THEME), '#2563eb');
  assert.equal(resolveColor('@accent', dark), '#38bdf8');
  assert.equal(resolveColor('@chart3', DEFAULT_THEME), '#15803d');
  assert.equal(resolveColor('#FF0000'), '#ff0000');
  assert.equal(resolveColor('#f00'), '#ff0000');
  assert.equal(resolveColor('#11223380'), '#11223380');
  assert.equal(resolveColor('@nope'), null);
  assert.equal(resolveColor('red'), null);
  assert.equal(COLOR_TOKENS.length, 18);
  assert.ok(isToken('@chart8') && !isToken('@chart9') && isHex('#abc') && !isHex('abc'));
  assert.deepEqual(parseColor('#ff000080'), { r: 255, g: 0, b: 0, a: 128 / 255 });
});

test('gradients resolve their stops', () => {
  const g = { g: 'linear', a: 90, stops: [[0, '#112233'], [1, '@accent']] };
  assert.deepEqual(resolveFill(g, DEFAULT_THEME).stops, [[0, '#112233'], [1, '#2563eb']]);
  assert.equal(resolveFill('@ink', DEFAULT_THEME), '#111827');
});

test('contrast ratio follows WCAG', () => {
  assert.equal(contrastRatio('#000000', '#ffffff'), 21);
  assert.equal(contrastRatio('#ffffff', '#ffffff'), 1);
  assert.ok(contrastRatio('@ink', '@bg', DEFAULT_THEME) > 15);
  assert.ok(contrastRatio('@muted', '@bg', DEFAULT_THEME) >= 4.5);
  assert.equal(contrastRatio('nope', '#fff'), null);
});

test('nearestToken finds the closest theme colour', () => {
  assert.equal(nearestToken('#2563eb', DEFAULT_THEME).token, '@accent');
  assert.equal(nearestToken('#2563eb', DEFAULT_THEME).distance, 0);
  assert.equal(nearestToken('#fefefe', DEFAULT_THEME).token, '@bg');
  assert.equal(nearestToken('#101828', DEFAULT_THEME).token, '@ink');
  assert.equal(nearestToken('xyz', DEFAULT_THEME), null);
});

test('font roles resolve to families and heading weight', () => {
  assert.equal(resolveFont('@heading', dark), 'Fraunces');
  assert.equal(resolveFont('@body', dark), 'Inter');
  assert.equal(resolveFont('Georgia', dark), 'Georgia');
  assert.equal(resolveFont(undefined, dark), 'Inter');
  assert.equal(resolveWeight({ font: '@heading' }, dark), 600);
  assert.equal(resolveWeight({ font: '@body' }, dark), 400);
  assert.equal(resolveWeight({ font: '@heading', weight: 300 }, dark), 300);
});

test('mapColors reaches fills, gradients, shadows, text marks, table cells and chart colours, sharing what does not change', () => {
  const el = {
    id: 'a', type: 'text', color: '@ink', fill: { g: 'linear', a: 0, stops: [[0, '#112233'], [1, '@accent']] }, shadow: { x: 0, y: 1, blur: 2, c: '#000000' },
    doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'x', marks: [{ type: 'textStyle', attrs: { color: '@accent' } }] }] }] },
  };
  const seen = [];
  const out = mapColors(el, (c) => { seen.push(c); return c.toUpperCase(); });
  assert.deepEqual(seen.sort(), ['#000000', '#112233', '@accent', '@accent', '@ink'].sort());
  assert.equal(out.color, '@INK');
  assert.equal(out.doc.content[0].content[0].marks[0].attrs.color, '@ACCENT');
  assert.equal(mapColors(el, (c) => c), el, 'identity mapping returns the same element');
  const tb = { id: 't', type: 'table', rows: [[{ t: 'a', fill: '@surface' }, { t: 'b' }]] };
  assert.equal(mapColors(tb, () => '#fff').rows[0][0].fill, '#fff');
  const ch = { id: 'c', type: 'chart', chart: { data: { series: [{ name: 's', color: '@chart1' }] }, opt: { colors: ['@chart2'] } } };
  const mc = mapColors(ch, () => '#000');
  assert.equal(mc.chart.data.series[0].color, '#000');
  assert.equal(mc.chart.opt.colors[0], '#000');
});

test('usageOf lists tokens, hex colours and fonts', () => {
  const u = usageOf({ id: 'a', type: 'text', color: '@ink', fill: '#FF0000', font: '@heading' });
  assert.deepEqual(u, { tokens: ['@ink'], hex: ['#ff0000'], fonts: ['@heading'] });
});

test('applyTheme replaces the theme, leaves hex alone, and reports who follows it', () => {
  const doc = mkDoc([
    S('s1', [T('a', 0, 0, 1, 1, { color: '@ink', font: '@heading' }), R('b', 0, 0, 1, 1, { fill: '#ff0000' }), R('c', 0, 0, 1, 1, { fill: '@accent', stroke: '@line' })]),
    S('s2', [T('d', 0, 0, 1, 1, { color: '#111111' })]),
  ]);
  const r = applyTheme(doc, dark);
  assert.equal(r.doc.deck.theme, dark);
  assert.equal(r.doc.slides, doc.slides, 'elements are not rewritten');
  assert.deepEqual(r.ops, [{ op: 'deck.set', theme: dark }]);
  assert.equal(r.report.elementsWithTokens, 2);
  assert.equal(r.report.elementsWithHex, 2);
  assert.equal(r.report.elementsWithFontTokens, 1);
  assert.equal(r.report.byToken['@ink'], 1);
  assert.deepEqual(r.report.slides, [{ slide: 's1', elements: ['a', 'c'] }]);
  assert.equal(mapFonts({ id: 'x', type: 'text', font: '@body' }, () => 'Z').font, 'Z');
});
