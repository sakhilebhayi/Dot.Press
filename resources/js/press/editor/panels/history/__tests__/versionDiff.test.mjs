import test from 'node:test';
import assert from 'node:assert/strict';
import { cleanVersionName, compareDocs, describeRestore, describeSince, groupByDay, kindBead, kindLabel, stable, versionTitle } from '../versionDiff.js';

const el = (id, extra = {}) => ({ id, type: 'text', x: 0, y: 0, w: 10, h: 10, ...extra });
const sl = (id, title, els = [], extra = {}) => ({ id, title, notes: '', els, ...extra });
const doc = (slides, deck = {}) => ({ deck: { title: 'Deck', w: 1920, h: 1080, theme: { id: 'a' }, sections: [], ...deck }, slides });

test('identical documents give an identical diff, whatever the key order or id type', () => {
  const a = doc([sl(1, 'One', [el('a', { x: 1, y: 2 })])]);
  const b = doc([sl('1', 'One', [{ y: 2, x: 1, h: 10, w: 10, type: 'text', id: 'a' }])]);
  const d = compareDocs(a, b);
  assert.equal(d.identical, true);
  assert.deepEqual(describeSince(d), ['The deck is the same as this version.']);
  assert.deepEqual(describeRestore(d), ['Nothing would change. The deck already matches this version.']);
});

test('slides added and removed since the version', () => {
  const past = doc([sl(1, 'A'), sl(2, 'B'), sl(3, 'C')]);
  const now = doc([sl(1, 'A'), sl(3, 'C'), sl(4, 'D')]);
  const d = compareDocs(past, now);
  assert.deepEqual(d.added.map((s) => s.title), ['D']);
  assert.deepEqual(d.removed.map((s) => s.title), ['B']);
  assert.deepEqual(d.moved, []);
  assert.deepEqual(describeSince(d).slice(0, 2), ['1 slide added since', '1 slide removed since']);
  assert.deepEqual(describeRestore(d).slice(0, 2), ['Brings back 1 slide', 'Removes 1 slide made since']);
});

test('a slide that only shifted because of an insert is not reported as moved', () => {
  const past = doc([sl(1, 'A'), sl(2, 'B'), sl(3, 'C')]);
  const now = doc([sl(9, 'New'), sl(1, 'A'), sl(2, 'B'), sl(3, 'C')]);
  assert.deepEqual(compareDocs(past, now).moved, []);
});

test('a real reorder is reported with the old and new place', () => {
  const past = doc([sl(1, 'A'), sl(2, 'B'), sl(3, 'C'), sl(4, 'D')]);
  const now = doc([sl(1, 'A'), sl(3, 'C'), sl(4, 'D'), sl(2, 'B')]);
  const d = compareDocs(past, now);
  assert.equal(d.moved.length, 1);
  assert.deepEqual(d.moved[0], { id: '2', title: 'B', from: 1, to: 3 });
});

test('retitled slides, notes and element counts', () => {
  const past = doc([sl(1, 'Old', [el('a'), el('b')]), sl(2, 'Same', [], { notes: 'say hi' })]);
  const now = doc([sl(1, 'New', [el('a'), el('b'), el('c'), el('d')]), sl(2, 'Same', [], { notes: 'say hello' })]);
  const d = compareDocs(past, now);
  assert.deepEqual(d.retitled.map((s) => [s.from, s.to]), [['Old', 'New']]);
  assert.equal(d.notes.length, 1);
  assert.deepEqual(d.elements, { before: 2, after: 4, delta: 2 });
  assert.deepEqual(d.edited.map((s) => [s.added, s.removed, s.changed]), [[2, 0, 0]]);
  const lines = describeSince(d);
  assert.ok(lines.includes('1 slide retitled'));
  assert.ok(lines.includes('Elements 2 to 4 (+2)'));
  assert.ok(lines.includes('Speaker notes changed on 1 slide'));
});

test('an edited element with the same count is still seen', () => {
  const past = doc([sl(1, 'A', [el('a', { x: 1 })])]);
  const now = doc([sl(1, 'A', [el('a', { x: 99 })])]);
  const d = compareDocs(past, now);
  assert.equal(d.identical, false);
  assert.equal(d.elements.delta, 0);
  assert.deepEqual(d.edited.map((s) => s.changed), [1]);
  assert.deepEqual(describeSince(d), ['1 slide has edited elements']);
  assert.deepEqual(describeRestore(d), ['Undoes edits to elements on 1 slide']);
});

test('deck level changes: title, size, theme, sections', () => {
  const d = compareDocs(doc([sl(1, 'A')]), doc([sl(1, 'A')], { title: 'Renamed', w: 1280, h: 720, theme: { id: 'b' }, sections: [{ id: 's', title: 'S' }] }));
  assert.deepEqual(d.deck.title, { from: 'Deck', to: 'Renamed' });
  assert.deepEqual(d.deck.size, { from: '1920 by 1080', to: '1280 by 720' });
  assert.equal(d.deck.theme, true);
  assert.equal(d.deck.sections, true);
  assert.deepEqual(describeSince(d), ['Deck title changed', 'Slide size changed', 'Theme changed', 'Sections changed']);
});

test('untitled slides are named by their number', () => {
  const d = compareDocs(doc([sl(1, ''), sl(2, 'B')]), doc([sl(2, 'B')]));
  assert.equal(d.removed[0].title, 'Slide 1');
});

test('stable stringify ignores key order and undefined', () => {
  assert.equal(stable({ b: 1, a: [2, { d: 1, c: undefined }] }), stable({ a: [2, { d: 1 }], b: 1 }));
  assert.notEqual(stable({ a: 1 }), stable({ a: 2 }));
});

test('version titles, kinds and beads', () => {
  assert.equal(versionTitle({ label: ' Sent to board ', kind: 'manual' }), 'Sent to board');
  assert.equal(versionTitle({ label: null, kind: 'auto' }), 'Autosaved');
  assert.equal(versionTitle({ label: null, kind: 'approved' }), 'Approved');
  assert.equal(kindLabel('restore'), 'Safety copy before a restore');
  assert.equal(kindBead('approved').state, 'solid');
  assert.equal(kindBead('manual').state, 'current');
  assert.equal(kindBead('auto').state, 'hollow');
  assert.equal(cleanVersionName('  a   b  '), 'a b');
  assert.equal(cleanVersionName('x'.repeat(200)).length, 120);
});

test('versions group under Today and Yesterday, newest first', () => {
  const now = new Date(2026, 10, 7, 15, 0, 0).getTime();
  const at = (d, h) => new Date(2026, 10, d, h, 0, 0).toISOString();
  const groups = groupByDay([{ id: 3, created_at: at(7, 14) }, { id: 2, created_at: at(7, 9) }, { id: 1, created_at: at(6, 20) }, { id: 0, created_at: 'bad' }], now);
  assert.deepEqual(groups.map((g) => g.label), ['Today', 'Yesterday']);
  assert.deepEqual(groups[0].items.map((v) => v.id), [3, 2]);
});
