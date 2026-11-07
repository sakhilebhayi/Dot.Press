import test from 'node:test';
import assert from 'node:assert/strict';
import { resolveColor, resolveFont, resolveFill, themeVars, normalizeTheme, colorCss, resolveBackground, DEFAULT_THEME, resolveWeight, withAlpha } from '../theme.js';

const theme = normalizeTheme({ c: { accent: '#ff0000', chart: ['#111', '#222222'] }, t: { heading: 'Literata', body: 'Inter', hw: 800 }, s: { radius: 20 } });

test('resolveColor handles hex, short hex, tokens and rubbish', () => {
    assert.equal(resolveColor('#ABCDEF', theme), '#abcdef');
    assert.equal(resolveColor('#fff', theme), '#ffffff');
    assert.equal(resolveColor('#11223344', theme), '#11223344');
    assert.equal(resolveColor('@accent', theme), '#ff0000');
    assert.equal(resolveColor('@chart1', theme), '#111111');
    assert.equal(resolveColor('@chart2', theme), '#222222');
    assert.equal(resolveColor('@chart3', theme), '#111111', 'chart index wraps over a short palette');
    assert.equal(resolveColor('@nonsense', theme, '#000000'), '#000000');
    assert.equal(resolveColor('red', theme, null), null);
    assert.equal(resolveColor('url(javascript:alert(1))', theme, 'x'), 'x');
    assert.equal(resolveColor(42, theme, 'x'), 'x');
});

test('colorCss turns tokens into css variables and never passes arbitrary strings through', () => {
    assert.equal(colorCss('@accent2'), 'var(--p-accent2)');
    assert.equal(colorCss('@chart4'), 'var(--p-chart4)');
    assert.equal(colorCss('#abc'), '#aabbcc');
    assert.equal(colorCss('red; background:url(x)'), 'transparent');
    assert.equal(colorCss('@accent; x'), 'transparent');
});

test('resolveFont maps tokens and falls back for unknown or hostile names', () => {
    assert.equal(resolveFont('@heading', theme).family, 'Literata');
    assert.equal(resolveFont('@body', theme).family, 'Inter');
    assert.equal(resolveFont(undefined, theme).family, 'Inter');
    assert.equal(resolveFont('Playfair Display', theme).family, 'Playfair Display');
    assert.equal(resolveFont('Comic Sans; } body{display:none', theme).family, 'Inter');
    assert.match(resolveFont('Literata', theme).stack, /^"Literata", /);
});

test('resolveWeight uses the theme heading weight for headings', () => {
    assert.equal(resolveWeight({ font: '@heading' }, theme), 800);
    assert.equal(resolveWeight({ font: '@body' }, theme), 400);
    assert.equal(resolveWeight({ font: '@heading', weight: 500 }, theme), 500);
});

test('resolveFill: none, solid, token, gradient', () => {
    assert.deepEqual(resolveFill(null, theme), { kind: 'none', css: 'none' });
    assert.equal(resolveFill('@accent', theme).css, 'var(--p-accent)');
    assert.equal(resolveFill('#123456', theme).color, '#123456');
    const g = resolveFill({ g: 'linear', a: 90, stops: [[0, '#112233'], [1, '@accent']] }, theme);
    assert.equal(g.kind, 'linear');
    assert.equal(g.angle, 90);
    assert.equal(g.css, 'linear-gradient(90deg, #112233 0%, var(--p-accent) 100%)');
    assert.equal(g.stops[1].color, '#ff0000');
    assert.equal(resolveFill({ g: 'linear', a: 90, stops: [[0, 'bogus']] }, theme).kind, 'none');
    assert.equal(resolveFill({ g: 'linear', a: -90, stops: [[0, '#000'], [2, '#fff']] }, theme).angle, 270);
    assert.equal(resolveFill({ g: 'linear', a: 0, stops: [[0.5, '#000']] }, theme).kind, 'solid');
});

test('themeVars exposes tokens, chart colours, fonts and radius', () => {
    const v = themeVars(theme);
    assert.equal(v['--p-accent'], '#ff0000');
    assert.equal(v['--p-chart1'], '#111111');
    assert.equal(v['--p-radius'], '20px');
    assert.match(v['--p-font-heading'], /^"Literata"/);
    assert.equal(v['--p-heading-weight'], '800');
});

test('normalizeTheme survives garbage', () => {
    const t = normalizeTheme({ c: { bg: 'nope', chart: 'x' }, t: { hw: 'abc', scale: 99 }, s: { radius: -4 } });
    assert.equal(t.c.bg, DEFAULT_THEME.c.bg);
    assert.equal(t.t.scale, 2);
    assert.equal(t.t.hw, DEFAULT_THEME.t.hw);
    assert.equal(t.s.radius, 0);
    assert.ok(t.c.chart.length >= 1);
    assert.equal(normalizeTheme(null).id, 'slate');
});

test('resolveBackground: theme default, fill, image with dim', () => {
    assert.equal(resolveBackground(null, theme).fill.css, 'var(--p-bg)');
    assert.equal(resolveBackground('@surface', theme).fill.css, 'var(--p-surface)');
    const img = resolveBackground({ img: { asset: 3 }, fit: 'cover', dim: 4 }, theme);
    assert.equal(img.kind, 'image');
    assert.equal(img.dim, 1);
});

test('withAlpha', () => {
    assert.equal(withAlpha('#ff0000', 0.5), '#ff000080');
});
