import test from 'node:test';
import assert from 'node:assert/strict';
import { SHAPE_KINDS, shapePath, lineGeometry, svgGradientCoords, imagePlacement, normalizeCrop, maskClip, adjustFilter, dashArray, dropShadow } from '../geometry.js';

test('every closed shape kind produces a path; lines produce none', () => {
    for (const k of SHAPE_KINDS) {
        const d = shapePath(k, 300, 200, 12);
        if (k === 'line' || k === 'arrow') assert.equal(d, null);
        else {
            assert.match(d, /^M/, k);
            assert.ok(!/NaN|undefined|Infinity/.test(d), `${k}: ${d}`);
        }
    }
});

test('degenerate sizes do not produce NaN', () => {
    for (const k of SHAPE_KINDS) {
        const d = shapePath(k, 0, 0, 5);
        if (d) assert.ok(!/NaN|Infinity/.test(d), k);
    }
});

test('rect radius is clamped to half the short side', () => {
    assert.match(shapePath('rect', 100, 40, 1000), /A20 20/);
    assert.equal(shapePath('rect', 100, 40, 0), 'M0 0H100V40H0Z');
});

test('lines: arrow ends shorten the stroke and add a mark', () => {
    const g = lineGeometry(400, 0, 4, { s: 'none', e: 'arrow' });
    assert.equal(g.x1, 0);
    assert.ok(g.x2 < 400);
    assert.equal(g.marks.length, 1);
    const both = lineGeometry(400, 0, 4, { s: 'dot', e: 'arrow' });
    assert.equal(both.marks.length, 2);
    assert.ok(both.x1 > 0);
    assert.equal(lineGeometry(400, 0, 4, {}, 'arrow').ends.e, 'arrow', 'kind arrow defaults to an arrow head');
    assert.equal(lineGeometry(400, 0, 4, {}, 'line').marks.length, 0);
    assert.equal(lineGeometry(400, 0, 4, { e: 'bogus' }).ends.e, 'none');
    const tiny = lineGeometry(6, 0, 20, { e: 'arrow' });
    assert.ok(tiny.x2 >= tiny.x1);
});

test('svgGradientCoords matches css angles', () => {
    const toTop = svgGradientCoords(0, 200, 100);
    assert.equal(toTop.x1, 100); assert.equal(toTop.x2, 100);
    assert.ok(toTop.y1 > toTop.y2);
    const toRight = svgGradientCoords(90, 200, 100);
    assert.equal(toRight.y1, 50); assert.equal(toRight.x1, 0); assert.equal(toRight.x2, 200);
});

test('imagePlacement: fill, cover, contain with a crop', () => {
    const box = { w: 400, h: 300 };
    const nat = { w: 2000, h: 1000 };
    const fill = imagePlacement({ crop: { x: 0, y: 0, w: 1, h: 1 }, nat, box, fit: 'fill' });
    assert.deepEqual(fill, { left: 0, top: 0, width: 400, height: 300 });
    const cover = imagePlacement({ crop: { x: 0, y: 0, w: 1, h: 1 }, nat, box, fit: 'cover' });
    assert.equal(cover.height, 300);
    assert.equal(cover.width, 600);
    assert.equal(cover.left, -100);
    const contain = imagePlacement({ crop: { x: 0, y: 0, w: 1, h: 1 }, nat, box, fit: 'contain' });
    assert.equal(contain.width, 400);
    assert.equal(contain.height, 200);
    assert.equal(contain.top, 50);
    const half = imagePlacement({ crop: { x: 0.5, y: 0, w: 0.5, h: 1 }, nat, box, fit: 'fill' });
    assert.equal(half.width, 800);
    assert.equal(half.left, -400);
    // the crop window always lies inside the image
    const c = normalizeCrop({ x: 0.9, y: -3, w: 0.5, h: 9 });
    assert.ok(c.x + c.w <= 1 + 1e-9 && c.y >= 0 && c.h <= 1);
});

test('imagePlacement works without natural size', () => {
    const p = imagePlacement({ crop: null, nat: null, box: { w: 100, h: 50 }, fit: 'cover' });
    assert.ok(Number.isFinite(p.width) && p.width > 0);
});

test('masks', () => {
    assert.equal(maskClip('none'), null);
    assert.equal(maskClip('circle'), 'circle(closest-side at 50% 50%)');
    assert.match(maskClip('round', 20), /round 20px/);
    for (const m of ['ellipse', 'triangle', 'star', 'hexagon']) assert.ok(maskClip(m), m);
    assert.ok(!/NaN/.test(maskClip('star')));
    assert.equal(maskClip('evil(); x'), null);
});

test('adjustFilter: zero means untouched, values map to css', () => {
    assert.equal(adjustFilter({ br: 0, ct: 0, sat: 0, blur: 0, gray: 0, sepia: 0, hue: 0 }), null);
    assert.equal(adjustFilter(null), null);
    assert.equal(adjustFilter({ br: 20, ct: -10, blur: 3, hue: 90 }), 'brightness(1.2) contrast(0.9) hue-rotate(90deg) blur(3px)');
    assert.equal(adjustFilter({ gray: 100 }), 'grayscale(1)');
    assert.ok(adjustFilter({ br: 9999 }).includes('brightness(2)'));
    assert.equal(adjustFilter({ br: 'x' }), null);
});

test('dash arrays and shadows', () => {
    assert.equal(dashArray('solid', 2).array, null);
    assert.equal(dashArray('dash', 2).array, '6 4');
    assert.equal(dashArray('dot', 2).cap, 'round');
    assert.equal(dropShadow({ x: 2, y: 4, blur: 8 }, '#000000'), 'drop-shadow(2px 4px 8px #000000)');
    assert.equal(dropShadow(null, '#000'), null);
});
