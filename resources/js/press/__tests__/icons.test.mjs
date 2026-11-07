import test from 'node:test';
import assert from 'node:assert/strict';
import { ICONS, ICON_IDS, getIcon, iconSvg } from '../icons.js';
import { FONTS, FONT_FAMILIES, fontStack, isKnownFont } from '../fonts.js';

test('icon ids are unique, well formed and every icon has paths', () => {
    assert.equal(new Set(ICON_IDS).size, ICONS.length);
    for (const i of ICONS) {
        assert.match(i.id, /^[a-z0-9-]+$/);
        assert.ok(i.d.length > 0 && i.d.every((d) => /^M/.test(d) && !/NaN|undefined/.test(d)), i.id);
        assert.ok(i.label && Array.isArray(i.tags));
    }
    assert.ok(ICONS.length >= 80);
});

test('required business icons exist', () => {
    const need = 'arrow-right arrow-left check cross plus minus user users building chart-bar chart-line chart-pie target flag calendar clock mail phone globe pin lock key shield star heart bolt lightbulb gear wrench truck factory hard-hat pickaxe cone document folder link search play pause camera image mic cloud database server code rocket trophy money percent scale handshake puzzle magnet compass layers grid list quote info warning question'.split(' ');
    for (const id of need) assert.ok(getIcon(id), id);
});

test('iconSvg is a closed, attribute safe svg', () => {
    const s = iconSvg('check', { color: '#ff0000"onload="x', sw: 2, size: 32 });
    assert.match(s, /^<svg /);
    assert.ok(!s.includes('onload="'));
    assert.equal((s.match(/"/g) || []).length % 2, 0);
    assert.equal(iconSvg('nope'), '');
    assert.match(iconSvg('check', { sw: 99 }), /stroke-width="6"/);
});

test('font list covers the requested families', () => {
    for (const f of ['Public Sans', 'Inter', 'IBM Plex Sans', 'IBM Plex Serif', 'IBM Plex Mono', 'Literata', 'DM Sans', 'Space Grotesk', 'Playfair Display', 'Manrope', 'Source Serif 4', 'Caveat']) assert.ok(isKnownFont(f), f);
    assert.equal(FONTS.length, FONT_FAMILIES.length);
    assert.equal(isKnownFont('constructor'), false);
    assert.equal(fontStack('Nope'), null);
});
