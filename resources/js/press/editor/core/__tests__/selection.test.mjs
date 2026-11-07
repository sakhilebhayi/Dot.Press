import test from 'node:test';
import assert from 'node:assert/strict';
import {
  emptySelection, select, clickSelect, pickAt, marquee, selectAll, selectStep, prune, selectionBounds, layers, expandGroups, selected,
} from '../selection.js';
import { mkDoc, S, T, R } from './helpers.mjs';

const doc = mkDoc([S('s1', [
  T('a', 0, 0, 100, 100),
  R('b', 50, 50, 100, 100),
  T('c', 300, 0, 50, 50, { group: 'g' }),
  T('d', 400, 0, 50, 50, { group: 'g' }),
  R('lock', 600, 0, 100, 100, { lock: true }),
  R('hid', 800, 0, 100, 100, { hide: true }),
])]);
const empty = emptySelection('s1');

test('click replaces, shift or meta toggles', () => {
  let sel = clickSelect(doc, empty, 's1', 'a', {});
  assert.deepEqual(sel.ids, ['a']);
  sel = clickSelect(doc, sel, 's1', 'b', { shift: true });
  assert.deepEqual(sel.ids, ['a', 'b']);
  sel = clickSelect(doc, sel, 's1', 'a', { meta: true });
  assert.deepEqual(sel.ids, ['b']);
  sel = clickSelect(doc, sel, 's1', null, {});
  assert.deepEqual(sel.ids, []);
  assert.equal(clickSelect(doc, sel, 's1', null, { shift: true }), sel, 'shift click on empty canvas keeps the selection');
});

test('groups expand to all members and toggle as a unit', () => {
  let sel = clickSelect(doc, empty, 's1', 'c', {});
  assert.deepEqual(sel.ids, ['c', 'd']);
  sel = clickSelect(doc, sel, 's1', 'd', { shift: true });
  assert.deepEqual(sel.ids, []);
  assert.deepEqual(expandGroups(doc.slides[0], ['d']), ['c', 'd']);
});

test('selection is kept in z-order and identity is stable when nothing changes', () => {
  const s1 = select(doc, empty, 's1', ['b', 'a'], 'replace');
  assert.deepEqual(s1.ids, ['a', 'b']);
  assert.equal(select(doc, s1, 's1', ['b', 'a'], 'replace'), s1);
  assert.deepEqual(select(doc, s1, 's1', ['ghost'], 'add').ids, ['a', 'b']);
  assert.deepEqual(select(doc, s1, 's1', ['a'], 'remove').ids, ['b']);
});

test('pickAt takes the topmost pickable element and skips locked and hidden ones', () => {
  assert.equal(pickAt(doc, 's1', { x: 75, y: 75 }), 'b');
  assert.equal(pickAt(doc, 's1', { x: 10, y: 10 }), 'a');
  assert.equal(pickAt(doc, 's1', { x: 650, y: 50 }), null, 'locked');
  assert.equal(pickAt(doc, 's1', { x: 850, y: 50 }), null, 'hidden');
  assert.equal(pickAt(doc, 's1', { x: 1500, y: 900 }), null);
});

test('marquee selects what it touches (or contains) and expands groups', () => {
  assert.deepEqual(marquee(doc, 's1', { x: 40, y: 40, w: 20, h: 20 }), ['a', 'b']);
  assert.deepEqual(marquee(doc, 's1', { x: 290, y: -10, w: 30, h: 100 }), ['c', 'd']);
  assert.deepEqual(marquee(doc, 's1', { x: 0, y: 0, w: 120, h: 120 }, { contain: true }), ['a']);
  assert.deepEqual(marquee(doc, 's1', { x: 590, y: -10, w: 400, h: 200 }), []);
});

test('select all skips locked and hidden, next and previous cycle in z-order', () => {
  assert.deepEqual(selectAll(doc, 's1').ids, ['a', 'b', 'c', 'd']);
  let sel = selectStep(doc, empty, 1);
  assert.deepEqual(sel.ids, ['a']);
  sel = selectStep(doc, sel, 1);
  assert.deepEqual(sel.ids, ['b']);
  sel = selectStep(doc, select(doc, empty, 's1', ['d']), 1);
  assert.deepEqual(sel.ids, ['a'], 'wraps');
  assert.deepEqual(selectStep(doc, empty, -1).ids, ['c', 'd'], 'previous from empty starts at the top (the group expands)');
});

test('prune drops ids that vanished', () => {
  const sel = select(doc, empty, 's1', ['a', 'b']);
  const smaller = mkDoc([S('s1', [T('a', 0, 0, 1, 1)])]);
  assert.deepEqual(prune(smaller, sel).ids, ['a']);
  assert.equal(prune(doc, sel), sel);
  assert.deepEqual(prune(mkDoc([S('s9')]), sel), emptySelection(null));
});

test('selectionBounds: one element keeps its rotation, several give the rotated union', () => {
  const d = mkDoc([S('s1', [T('a', 100, 100, 200, 100, { rot: 90 }), T('b', 0, 0, 10, 10)])]);
  const one = selectionBounds(d, select(d, empty, 's1', ['a']));
  assert.deepEqual(one, { x: 100, y: 100, w: 200, h: 100, rot: 90, single: true });
  const many = selectionBounds(d, select(d, empty, 's1', ['a', 'b']));
  assert.equal(many.single, false);
  assert.equal(many.rot, 0);
  assert.ok(Math.abs(many.w - 250) < 0.01 && Math.abs(many.h - 250) < 0.01, JSON.stringify(many));
  assert.equal(selectionBounds(d, empty), null);
  assert.equal(selected(d, select(d, empty, 's1', ['b'])).length, 1);
});

test('layers lists top first and keeps locked and hidden elements', () => {
  const l = layers(doc.slides[0]);
  assert.equal(l[0].id, 'hid');
  assert.equal(l[0].hide, true);
  assert.equal(l.find((x) => x.id === 'lock').lock, true);
  assert.equal(l.length, 6);
  // the layer list may select a locked element
  assert.deepEqual(select(doc, empty, 's1', ['lock']).ids, ['lock']);
});
