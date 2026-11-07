import test from 'node:test';
import assert from 'node:assert/strict';
import { makeFormatter, parseNumber, textWidth, fitText, wrapText, compactNum, pctShare, mix, inkOn, contrast, stepDecimals, tickFormatter, toNum } from '../util.js';
import { resolveTheme, seriesStyle, DEFAULT_THEME, DEFAULT_DARK_THEME, resolveColor } from '../theme.js';

test('number formats: num, pct, cur, compact and decimals', () => {
  const num = makeFormatter({ fmt: 'num' });
  assert.equal(num(1234567), '1,234,567');
  assert.equal(num(12.345), '12.35');
  assert.equal(num(0.2), '0.2');
  assert.equal(num(-1234.5), '-1,235');
  assert.equal(num(0), '0');
  assert.equal(num(NaN), '');
  assert.equal(num(null), '');
  assert.equal(makeFormatter({ fmt: 'num', dec: 2 })(1234.5), '1,234.50');
  assert.equal(makeFormatter({ fmt: 'pct' })(12.5), '12.5%');
  assert.equal(makeFormatter({ fmt: 'pct', dec: 0 })(12.5), '13%');
  assert.equal(makeFormatter({ fmt: 'cur', cur: 'R' })(1234.5), 'R1,234.50');
  assert.equal(makeFormatter({ fmt: 'cur', cur: 'R' })(-20), '-R20');
  assert.equal(makeFormatter({ fmt: 'cur', cur: '$', dec: 0 })(1999.6), '$2,000');
  assert.equal(makeFormatter({ fmt: 'cur' })(5), 'R5');
  const c = makeFormatter({ fmt: 'compact' });
  assert.equal(c(1530000), '1.53M');
  assert.equal(c(999), '999');
  assert.equal(c(12500), '12.5K');
  assert.equal(c(2400000000), '2.4B');
  assert.equal(c(-4200), '-4.2K');
  assert.equal(compactNum(999999), '1M');
  assert.equal(makeFormatter({ fmt: 'compact', dec: 1 })(3280), '3.3K');
  assert.equal(makeFormatter({ fmt: 'num' })(-0.0001, {}), '0');
});

test('share and axis formatters', () => {
  assert.equal(pctShare(0.4246), '42%');
  assert.equal(pctShare(0.054), '5.4%');
  assert.equal(pctShare(0.004), '<1%');
  assert.equal(pctShare(1), '100%');
  assert.equal(stepDecimals(2.5), 1);
  assert.equal(stepDecimals(0.25), 2);
  assert.equal(stepDecimals(5), 0);
  const f = tickFormatter({ fmt: 'num' }, 0.5);
  assert.equal(f(1), '1.0');
  assert.equal(tickFormatter({ fmt: 'compact' }, 1000)(12000), '12K');
  assert.equal(tickFormatter({ fmt: 'cur', cur: 'R' }, 5000000)(15000000), 'R15M');
});

test('parseNumber handles thousands, decimal commas, percent, currency and accounting negatives', () => {
  const cases = [
    ['1,234.5', '.', 1234.5],
    ['1 234,5', ',', 1234.5],
    ['1.234,5', ',', 1234.5],
    ['R 1 234,50', ',', 1234.5],
    ['R1,234', '.', 1234],
    ['$1,000', '.', 1000],
    ['12%', '.', 12],
    ['(1,200)', '.', -1200],
    ['−5', '.', -5],
    ['-R 12', '.', -12],
    ['+7', '.', 7],
    ['1e3', '.', 1000],
    ['12 15', '.', null],
    ['abc', '.', null],
    ['', '.', null],
    ['1.2.3', '.', null],
    ['--5', '.', null],
  ];
  for (const [input, dec, want] of cases) assert.equal(parseNumber(input, dec), want, `${input} (${dec})`);
  assert.equal(parseNumber(5), 5);
  assert.equal(parseNumber(NaN), null);
  assert.equal(parseNumber(null), null);
  assert.equal(toNum('5'), 5);
  assert.equal(toNum({}), null);
});

test('text width estimator is monotone, scales with size and handles odd characters', () => {
  assert.ok(textWidth('Hello', 20) > textWidth('Hello', 10));
  assert.ok(Math.abs(textWidth('Hello', 20) - 2 * textWidth('Hello', 10)) < 1e-9);
  assert.ok(textWidth('WWWW', 12) > textWidth('iiii', 12));
  assert.ok(textWidth('abc', 12, 700) > textWidth('abc', 12, 400));
  assert.equal(textWidth('', 12), 0);
  assert.ok(textWidth('中文', 12) > textWidth('ab', 12));
  assert.ok(Number.isFinite(textWidth('😀 ́\u0000', 12)));
  assert.ok(textWidth('Total 1234', 10) < 80);
});

test('fitText and wrapText respect the width', () => {
  for (const w of [20, 50, 100, 300]) {
    const t = fitText('A very long label indeed, longer than most', 12, w);
    assert.ok(textWidth(t, 12) <= w + 0.001 || t === '', `${w}: ${t}`);
  }
  assert.equal(fitText('short', 12, 200), 'short');
  assert.equal(fitText('anything', 12, 2), '');
  const lines = wrapText('Quarterly recurring revenue growth by region', 12, 110, 3);
  assert.ok(lines.length >= 2 && lines.length <= 3);
  lines.forEach((l) => assert.ok(textWidth(l, 12) <= 110.001, l));
  const broken = wrapText('Supercalifragilisticexpialidocious', 12, 60, 2);
  broken.forEach((l) => assert.ok(textWidth(l, 12) <= 60.001));
  assert.deepEqual(wrapText('', 12, 100), []);
  assert.equal(wrapText('a b c d e f g h i j k l m n o p', 12, 40, 2).length, 2);
});

test('colour helpers: mix, contrast and the ink chosen on a fill', () => {
  assert.equal(mix('#000000', '#ffffff', 0.5), '#808080');
  assert.equal(mix('nonsense', '#ffffff', 0.5), 'nonsense');
  assert.equal(inkOn('#ffffff'), '#0b0b0b');
  assert.equal(inkOn('#111827'), '#ffffff');
  for (const c of [...DEFAULT_THEME.c.chart, ...DEFAULT_DARK_THEME.c.chart]) {
    const ink = inkOn(c);
    assert.ok(contrast(c, ink) >= 4.5 || contrast(c, ink) >= Math.max(contrast(c, '#ffffff'), contrast(c, '#0b0b0b')) - 1e-9, c + ' label contrast');
  }
  // amber needs dark text, blue needs white text
  assert.equal(inkOn('#f59e0b'), '#0b0b0b');
  assert.equal(inkOn('#2563eb'), '#ffffff');
});

test('theme: defaults fill gaps, tokens resolve, bad colours fall back', () => {
  const t = resolveTheme({ c: { ink: 'red', bg: '#000', chart: ['#123456', 'nope'] }, t: { body: 'Lora' } });
  assert.equal(t.c.ink, DEFAULT_THEME.c.ink, 'named colours are not valid tokens');
  assert.equal(t.c.bg, '#000000');
  assert.equal(t.c.chart[0], '#123456');
  assert.equal(t.c.chart[1], DEFAULT_THEME.c.chart[1]);
  assert.equal(t.t.body, 'Lora');
  assert.equal(t.dark, true);
  assert.equal(resolveTheme(null).c.accent, DEFAULT_THEME.c.accent);
  assert.equal(resolveTheme('garbage').c.accent, DEFAULT_THEME.c.accent);
  assert.equal(resolveColor('@chart1', t, null), '#123456');
  assert.equal(resolveColor('@accent', t, null), t.c.accent);
  assert.equal(resolveColor('@nope', t, '#abc'), '#abc');
  assert.equal(resolveColor('#ABCDEF', t, null), '#abcdef');
});

test('series style: fixed order by index, palette reuse past 8 with a pattern and a dash', () => {
  const theme = resolveTheme(DEFAULT_THEME);
  const first = [...Array(8)].map((_, i) => seriesStyle(i, theme).color);
  assert.deepEqual(first, DEFAULT_THEME.c.chart);
  const ninth = seriesStyle(8, theme);
  assert.notEqual(ninth.color, first[0]);
  assert.equal(ninth.pattern, 'hatch');
  assert.ok(ninth.dash);
  assert.notEqual(seriesStyle(16, theme).color, ninth.color);
  const markers = new Set([...Array(8)].map((_, i) => seriesStyle(i, theme).marker));
  assert.equal(markers.size, 8, 'eight distinct marker shapes');
  assert.equal(seriesStyle(2, theme, '@accent').color, DEFAULT_THEME.c.accent);
  assert.equal(seriesStyle(2, theme, '#ff0000').color, '#ff0000');
});
