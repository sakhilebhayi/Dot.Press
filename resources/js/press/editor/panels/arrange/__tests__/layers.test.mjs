import test from 'node:test';
import assert from 'node:assert/strict';
import {
  elementLabel, layerUnits, renamePatch, reorderOps, unitToggle, unitsToOrder,
} from '../layersModel.js';

const el = (id, extra = {}) => ({ id, type: 'shape', kind: 'rect', x: 0, y: 0, w: 10, h: 10, ...extra });
const slide = (...els) => ({ id: '9', els });
const ids = (units) => units.map((u) => u.id);

test('the list is front first', () => {
  const s = slide(el('a'), el('b'), el('c'));
  assert.deepEqual(ids(layerUnits(s)), ['c', 'b', 'a']);
});

test('labels: name, then text, then kind', () => {
  assert.equal(elementLabel(el('a', { name: '  Hero  ' })), 'Hero');
  assert.equal(elementLabel({ id: 't', type: 'text', doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Quarterly plan' }] }] } }), 'Quarterly plan');
  assert.equal(elementLabel(el('a', { kind: 'arrow-r' })), 'Arrow right');
  assert.equal(elementLabel({ id: 'i', type: 'image' }), 'Image');
  const long = { id: 't', type: 'text', doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'x'.repeat(100) }] }] } };
  assert.ok(elementLabel(long).length <= 40);
});

test('grouped elements are one unit at the position of the topmost member', () => {
  const s = slide(el('a'), el('g1', { group: 'G' }), el('b'), el('g2', { group: 'G' }), el('c'));
  const units = layerUnits(s);
  assert.deepEqual(ids(units), ['c', 'G', 'b', 'a']);
  assert.equal(units[1].kind, 'group');
  assert.deepEqual(units[1].ids, ['g2', 'g1']);
  assert.equal(units[1].label, 'Group of 2');
});

test('a group of one element is shown as the element', () => {
  const units = layerUnits(slide(el('a', { group: 'solo' })));
  assert.equal(units[0].kind, 'el');
});

test('selection: a group row is selected when all members are, partial when some are', () => {
  const s = slide(el('g1', { group: 'G' }), el('g2', { group: 'G' }), el('x'));
  let u = layerUnits(s, { selected: ['g1', 'g2'] });
  assert.equal(u.find((r) => r.id === 'G').selected, true);
  u = layerUnits(s, { selected: ['g1'] });
  assert.equal(u.find((r) => r.id === 'G').partial, true);
  assert.equal(u.find((r) => r.id === 'G').selected, false);
  assert.equal(layerUnits(s, { selected: ['x'] }).find((r) => r.id === 'x').selected, true);
});

test('collapsed state and lock/hide summary of a group', () => {
  const s = slide(el('g1', { group: 'G', lock: true }), el('g2', { group: 'G' }));
  const g = layerUnits(s, { collapsed: ['G'] })[0];
  assert.equal(g.collapsed, true);
  assert.equal(g.lock, false);
  assert.equal(g.anyLock, true);
  assert.equal(unitToggle(g, 'lock'), true);
  const all = layerUnits(slide(el('g1', { group: 'G', lock: true }), el('g2', { group: 'G', lock: true })))[0];
  assert.equal(unitToggle(all, 'lock'), false);
});

test('unitsToOrder round trips and keeps group members together', () => {
  const s = slide(el('a'), el('g1', { group: 'G' }), el('g2', { group: 'G' }), el('c'));
  const units = layerUnits(s);
  assert.deepEqual(unitsToOrder(units), ['a', 'g1', 'g2', 'c']);
});

test('reorderOps moves a row to the front', () => {
  const s = slide(el('a'), el('b'), el('c'));
  const units = layerUnits(s); // c b a
  const ops = reorderOps(s, units, 2, 0); // a to the front
  assert.deepEqual(ops, [{ op: 'el.order', slide: '9', ids: ['b', 'c', 'a'] }]);
});

test('reorderOps moves a whole group as a block, and ignores no-ops and bad indexes', () => {
  const s = slide(el('a'), el('g1', { group: 'G' }), el('g2', { group: 'G' }), el('c'));
  const units = layerUnits(s); // c G a
  const ops = reorderOps(s, units, 1, 2);
  assert.deepEqual(ops[0].ids, ['g1', 'g2', 'a', 'c']);
  assert.deepEqual(reorderOps(s, units, 1, 1), []);
  assert.deepEqual(reorderOps(s, units, 5, 0), []);
  assert.deepEqual(reorderOps(s, units, 0, -1), []);
});

test('renamePatch trims, clears and skips unchanged', () => {
  assert.deepEqual(renamePatch(el('a'), '  Logo  mark '), { id: 'a', p: { name: 'Logo mark' } });
  assert.deepEqual(renamePatch(el('a', { name: 'x' }), ''), { id: 'a', p: { name: null } });
  assert.equal(renamePatch(el('a', { name: 'x' }), 'x'), null);
  assert.equal(renamePatch(el('a'), '   '), null);
});
