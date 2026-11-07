import test from 'node:test';
import assert from 'node:assert/strict';
import { buildSequence } from '../../../../player/sequence.js';
import {
  addMenuOptions, addPatches, clearPatch, clicksSummary, groupsAfterMove, hiddenAnimated, newStep, removePatch, reorderPatches, sequenceRows, stepPatch,
} from '../sequenceModel.js';

const step = (o) => ({ id: `s${Math.random()}`, ph: 'in', fx: 'fade', on: 'click', delay: 0, dur: 500, ease: 'out', ...o });
const el = (id, ...anim) => ({ id, type: 'shape', x: 0, y: 0, w: 10, h: 10, ...(anim.length ? { anim } : {}) });
const slide = (...els) => ({ id: 'S', els });
const play = (s) => buildSequence(s).groups.flatMap((g) => g.items).map((i) => `${i.elId}:${i.fx}`);
const apply = (s, patches) => ({ ...s, els: s.els.map((e) => { const p = patches.find((x) => x.id === e.id); return p ? { ...e, ...p.p } : e; }) });

test('rows are in play order with click numbers and groups', () => {
  const s = slide(el('a', step({ fx: 'fade' })), el('b', step({ on: 'with', fx: 'rise' })), el('c', step({ fx: 'zoom' }), step({ on: 'after', fx: 'pop' })));
  const rows = sequenceRows(s);
  assert.deepEqual(rows.map((r) => `${r.elId}:${r.step.fx}:${r.group}:${r.number}`), ['a:fade:1:1', 'b:rise:1:null', 'c:zoom:2:2', 'c:pop:2:null']);
  assert.deepEqual(rows.map((r) => r.first), [true, false, true, false]);
  assert.deepEqual(clicksSummary(s), { steps: 4, clicks: 2, entry: 0 });
});

test('steps before the first click form the entry group 0', () => {
  const rows = sequenceRows(slide(el('a', step({ on: 'with' })), el('b', step({}))));
  assert.deepEqual(rows.map((r) => r.group), [0, 1]);
});

test('rows agree with what the player plays, with and without ord', () => {
  const plain = slide(el('a', step({ fx: 'fade' })), el('b', step({ fx: 'rise' })), el('c', step({ fx: 'zoom' })));
  assert.deepEqual(sequenceRows(plain).map((r) => `${r.elId}:${r.step.fx}`), play(plain));
  const ordered = slide(el('a', step({ fx: 'fade', ord: 2 })), el('b', step({ fx: 'rise', ord: 0 })), el('c', step({ fx: 'zoom', ord: 1 })));
  assert.deepEqual(sequenceRows(ordered).map((r) => `${r.elId}:${r.step.fx}`), play(ordered));
  assert.deepEqual(play(ordered), ['b:rise', 'c:zoom', 'a:fade']);
});

test('hidden elements and unplayable steps are not rows', () => {
  const s = slide({ ...el('h', step({})), hide: true }, el('a', step({ fx: 'explode' }), step({ fx: 'pop' })));
  assert.deepEqual(sequenceRows(s).map((r) => `${r.elId}:${r.stepIndex}`), ['a:1']);
  assert.equal(hiddenAnimated(s), 1);
});

test('moving a row writes ord on every step and the new order plays', () => {
  const s = slide(el('a', step({ fx: 'fade' })), el('b', step({ fx: 'rise' })), el('c', step({ fx: 'zoom' })));
  const patches = reorderPatches(s, 2, 0); // c to the top
  const next = apply(s, patches);
  assert.deepEqual(play(next), ['c:zoom', 'a:fade', 'b:rise']);
  assert.deepEqual(sequenceRows(next).map((r) => r.step.ord), [0, 1, 2]);
  // layering (array order of the elements) is untouched
  assert.deepEqual(next.els.map((e) => e.id), ['a', 'b', 'c']);
});

test('a second move keeps working on the ord model', () => {
  let s = slide(el('a', step({ fx: 'fade' })), el('b', step({ fx: 'rise' })), el('c', step({ fx: 'zoom' })));
  s = apply(s, reorderPatches(s, 2, 0));
  s = apply(s, reorderPatches(s, 0, 2));
  assert.deepEqual(play(s), ['a:fade', 'b:rise', 'c:zoom']);
});

test('moving within one element reorders its own steps', () => {
  let s = slide(el('a', step({ fx: 'fade' }), step({ fx: 'rise', on: 'after' })));
  s = apply(s, reorderPatches(s, 1, 0));
  assert.deepEqual(play(s), ['a:rise', 'a:fade']);
});

test('no change, bad indexes and unchanged ords produce no patches', () => {
  const s = slide(el('a', step({})), el('b', step({})));
  assert.deepEqual(reorderPatches(s, 1, 1), []);
  assert.deepEqual(reorderPatches(s, 5, 0), []);
  const ordered = apply(s, reorderPatches(s, 1, 0));
  assert.deepEqual(reorderPatches(ordered, 0, 0), []);
});

test('only elements whose steps changed are patched', () => {
  const s = slide(el('a', step({ ord: 0 })), el('b', step({ ord: 1 })), el('c', step({ ord: 2 })), el('d'));
  const patches = reorderPatches(s, 0, 1);
  assert.deepEqual(patches.map((p) => p.id).sort(), ['a', 'b']);
});

test('moving a "with" row to the top makes it the entry group', () => {
  const s = slide(el('a', step({ fx: 'fade' })), el('b', step({ fx: 'rise', on: 'with' })));
  const rows = groupsAfterMove(s, 1, 0);
  assert.deepEqual(rows.map((r) => `${r.elId}:${r.group}`), ['b:0', 'a:1']);
});

test('a new step has no ord on a plain slide and the next ord on an ordered one', () => {
  const plain = slide(el('a', step({})), el('b'));
  assert.equal('ord' in newStep(plain.els[1], plain, { ph: 'in', fx: 'rise' }), false);
  const ordered = slide(el('a', step({ ord: 4 })), el('b'));
  assert.equal(newStep(ordered.els[1], ordered, { ph: 'in', fx: 'rise' }).ord, 5);
});

test('adding to several elements: first on click, the rest together; the 20 step cap is honoured', () => {
  const s = slide(el('a'), el('b'), el('c'));
  const patches = addPatches(s, s.els, { ph: 'in', fx: 'zoom' });
  assert.deepEqual(patches.map((p) => p.p.anim[0].on), ['click', 'with', 'with']);
  const full = { ...el('a'), anim: Array.from({ length: 20 }, () => step({})) };
  assert.deepEqual(addPatches(slide(full), [full], { ph: 'in', fx: 'fade' }), []);
});

test('step edits: change one, remove one (the last removal clears the key), clear all', () => {
  const e = el('a', step({ id: 'x' }), step({ id: 'y' }));
  assert.equal(stepPatch(e, 1, { dur: 900 })[0].p.anim[1].dur, 900);
  assert.equal(stepPatch(e, 1, { dur: 900 })[0].p.anim[0].dur, 500);
  assert.equal(removePatch(e, 0)[0].p.anim.length, 1);
  assert.equal(removePatch(el('a', step({})), 0)[0].p.anim, null);
  assert.equal(clearPatch(e)[0].p.anim, null);
});

test('the add menu groups entrance, exit and emphasis with only meaningful effects', () => {
  const o = addMenuOptions();
  assert.deepEqual([...new Set(o.map((x) => x.group))], ['Entrance', 'Exit', 'Emphasis']);
  assert.equal(o.some((x) => x.value === 'in:spin'), false);
  assert.equal(o.some((x) => x.value === 'emph:pulse'), true);
  assert.equal(new Set(o.map((x) => x.value)).size, o.length);
});
