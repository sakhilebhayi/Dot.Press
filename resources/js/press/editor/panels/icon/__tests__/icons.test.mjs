import test from 'node:test';
import assert from 'node:assert/strict';
import { ICONS } from '../../../../icons.js';
import { searchIcons, gridStep, sizePatch, SW_RANGE } from '../lib/icons.js';

test('the built in set has 96 icons for the grid', () => {
  assert.equal(ICONS.length, 96);
});

test('an empty search returns every icon, a copy', () => {
  const all = searchIcons(ICONS, '');
  assert.equal(all.length, ICONS.length);
  assert.notEqual(all, ICONS);
  assert.equal(searchIcons(ICONS, '   ').length, ICONS.length);
});

test('search ranks an exact id first and finds by label and tag', () => {
  assert.equal(searchIcons(ICONS, 'check')[0].id, 'check');
  assert.ok(searchIcons(ICONS, 'check').some((i) => i.id === 'check-circle'));
  assert.ok(searchIcons(ICONS, 'forward').some((i) => i.id === 'arrow-right'), 'tags count');
  assert.ok(searchIcons(ICONS, 'ARROW').every((i) => /arrow/i.test(`${i.id} ${i.label} ${i.tags.join(' ')}`)));
  assert.deepEqual(searchIcons(ICONS, 'zzzzqqq'), []);
});

test('several words must all match', () => {
  const r = searchIcons(ICONS, 'arrow up');
  assert.ok(r.length >= 1);
  assert.ok(r.every((i) => /arrow/.test(`${i.id} ${i.label} ${i.tags}`.toLowerCase()) && /up/.test(`${i.id} ${i.label} ${i.tags}`.toLowerCase())));
});

test('grid movement keeps the column and stops at the edges', () => {
  assert.equal(gridStep(0, 'ArrowLeft', 6, 20), 0);
  assert.equal(gridStep(0, 'ArrowRight', 6, 20), 1);
  assert.equal(gridStep(19, 'ArrowRight', 6, 20), 19);
  assert.equal(gridStep(3, 'ArrowDown', 6, 20), 9);
  assert.equal(gridStep(17, 'ArrowDown', 6, 20), 17, 'no row below, stay');
  assert.equal(gridStep(3, 'ArrowUp', 6, 20), 3);
  assert.equal(gridStep(9, 'ArrowUp', 6, 20), 3);
  assert.equal(gridStep(9, 'Home', 6, 20), 0);
  assert.equal(gridStep(9, 'End', 6, 20), 19);
  assert.equal(gridStep(9, 'PageDown', 6, 20), 19);
  assert.equal(gridStep(9, 'PageUp', 6, 20), 0);
  assert.equal(gridStep(9, 'a', 6, 20), null);
  assert.equal(gridStep(0, 'ArrowDown', 6, 0), null);
  assert.equal(gridStep(99, 'ArrowLeft', 6, 20), 18, 'out of range index is clamped first');
});

test('size makes the icon square around its centre and keeps it on the slide', () => {
  const p = sizePatch({ x: 100, y: 100, w: 100, h: 100 }, 200, { w: 1920, h: 1080 });
  assert.deepEqual(p, { x: 50, y: 50, w: 200, h: 200 });
  const edge = sizePatch({ x: 1850, y: 10, w: 70, h: 70 }, 300, { w: 1920, h: 1080 });
  assert.equal(edge.x + edge.w <= 1920, true);
  assert.equal(edge.y >= 0, true);
  assert.equal(sizePatch({ x: 0, y: 0, w: 10, h: 10 }, 5000, { w: 1920, h: 1080 }).w, 1080);
  assert.equal(sizePatch({ x: 0, y: 0, w: 10, h: 10 }, 'x', { w: 1920, h: 1080 }).w, 8);
});

test('stroke width range is sane', () => {
  assert.ok(SW_RANGE.min > 0 && SW_RANGE.max <= 6 && SW_RANGE.step > 0);
});
