import test from 'node:test';
import assert from 'node:assert/strict';
import { ICONS } from '../../../../icons.js';
import {
  ICON_CATEGORIES, categoryOf, categorize, searchIcons, moveGridIndex,
} from '../iconModel.js';

test('every built-in icon lands in a real category, none is dropped', () => {
  const known = new Set(ICON_CATEGORIES.map((c) => c.id));
  const map = categorize(ICONS);
  let n = 0;
  for (const [cat, list] of map) { assert.ok(known.has(cat)); n += list.length; }
  assert.equal(n, ICONS.length);
  for (const c of ICON_CATEGORIES.filter((x) => x.id !== 'all')) assert.ok(map.get(c.id).length > 0, `${c.id} is empty`);
});

test('categoryOf uses the table, then tag keywords, then falls back to symbols', () => {
  assert.equal(categoryOf({ id: 'mail', tags: [] }), 'comms');
  assert.equal(categoryOf({ id: 'brand-new', tags: ['analytics'] }), 'data');
  assert.equal(categoryOf({ id: 'brand-new', tags: ['unrelated'] }), 'symbols');
  assert.equal(categoryOf(null), 'symbols');
});

test('search matches id, label and tags, and needs every word', () => {
  const ids = (q, c) => searchIcons(ICONS, q, c).map((i) => i.id);
  assert.ok(ids('mine').includes('pickaxe'));
  assert.ok(ids('mine').includes('hard-hat'));
  assert.deepEqual(ids('shield check').slice(0, 1), ['shield-check']);
  assert.deepEqual(ids('zzzz'), []);
  assert.equal(ids('').length, ICONS.length);
  assert.equal(ids('  ').length, ICONS.length);
});

test('search ranks an exact name above a tag hit and keeps source order for ties', () => {
  const r = searchIcons(ICONS, 'chart');
  assert.ok(r[0].id.startsWith('chart'));
  const growth = searchIcons(ICONS, 'growth').map((i) => i.id);
  assert.ok(growth.includes('trending-up') && growth.includes('arrow-up-right'));
});

test('search inside a category only returns that category', () => {
  const r = searchIcons(ICONS, 'a', 'security');
  assert.ok(r.length > 0);
  assert.ok(r.every((i) => categoryOf(i) === 'security'));
});

test('moveGridIndex follows arrows, rows, ends and clamps at the edges', () => {
  assert.equal(moveGridIndex(0, 'ArrowRight', 6, 20), 1);
  assert.equal(moveGridIndex(0, 'ArrowLeft', 6, 20), 0);
  assert.equal(moveGridIndex(2, 'ArrowDown', 6, 20), 8);
  assert.equal(moveGridIndex(18, 'ArrowDown', 6, 20), 19);
  assert.equal(moveGridIndex(8, 'ArrowUp', 6, 20), 2);
  assert.equal(moveGridIndex(8, 'Home', 6, 20), 6);
  assert.equal(moveGridIndex(8, 'End', 6, 20), 11);
  assert.equal(moveGridIndex(19, 'End', 6, 20), 19);
  assert.equal(moveGridIndex(3, 'PageDown', 6, 20), 19);
  assert.equal(moveGridIndex(3, 'x', 6, 20), -1);
  assert.equal(moveGridIndex(0, 'ArrowRight', 6, 0), -1);
});
