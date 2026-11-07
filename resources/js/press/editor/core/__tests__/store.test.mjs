import test from 'node:test';
import assert from 'node:assert/strict';
import { createStore } from '../store.js';
import { createSync } from '../sync.js';
import { createDoc, newText, newShape } from '../doc.js';
import { SAMPLE_LAYOUTS } from '../layouts.js';
import { DEFAULT_THEME } from '../theme.js';
import { S, T, R, mulberry32, content } from './helpers.mjs';
import { fakeClock, fakeStorage, fakeServer } from './fake.mjs';

function mk(slides, o = {}) {
  const doc = createDoc({ deck: { id: 'd1', slide_order: slides.map((s) => s.id) }, slides });
  const store = createStore({ doc, rng: mulberry32(11), ...o });
  return store;
}
const els = (store, i = 0) => store.getState().doc.slides[i].els;
const ids = (store, i = 0) => els(store, i).map((e) => e.id);
const key = (k, mods = {}) => ({ key: k, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false, ...mods });

test('state is immutable: identity changes only when something really changed', () => {
  const store = mk([S('s1', [T('a', 0, 0, 10, 10)]), S('s2')]);
  const s0 = store.getState();
  let calls = 0;
  store.subscribe(() => { calls++; });
  store.clearSelection();
  assert.equal(store.getState(), s0, 'no change, same state object');
  store.select(['a']);
  const s1 = store.getState();
  assert.notEqual(s1, s0);
  assert.equal(s1.doc, s0.doc, 'untouched parts keep identity');
  store.patch([{ id: 'a', p: { x: 5 } }]);
  const s2 = store.getState();
  assert.notEqual(s2.doc, s1.doc);
  assert.equal(s2.doc.slides[1], s1.doc.slides[1]);
  assert.equal(s2.selection, s1.selection);
  assert.ok(calls >= 2);
});

test('dispatch applies at once, undo and redo restore the document and the selection', () => {
  const store = mk([S('s1', [T('a', 0, 0, 10, 10), R('b', 20, 0, 10, 10)])]);
  store.select(['a']);
  store.moveSelection(5, 7);
  assert.deepEqual([els(store)[0].x, els(store)[0].y], [5, 7]);
  assert.equal(store.getState().canUndo, true);
  store.clearSelection();
  store.undo();
  assert.deepEqual([els(store)[0].x, els(store)[0].y], [0, 0]);
  assert.deepEqual(store.getState().selection.ids, ['a'], 'selection comes back with the undone edit');
  assert.equal(store.getState().canRedo, true);
  store.redo();
  assert.equal(els(store)[0].x, 5);
  assert.equal(store.undo() && store.undo(), false, 'nothing left to undo');
});

test('nudge by arrow keys: 1 and 10 units, repeated nudges are one undo step', () => {
  const store = mk([S('s1', [T('a', 100, 100, 10, 10)])]);
  store.select(['a']);
  for (let i = 0; i < 5; i++) assert.equal(store.handleKey(key('ArrowRight'), 'canvas'), true);
  store.handleKey(key('ArrowDown', { shiftKey: true }), 'canvas');
  assert.deepEqual([els(store)[0].x, els(store)[0].y], [105, 110]);
  store.undo();
  assert.deepEqual([els(store)[0].x, els(store)[0].y], [100, 100]);
});

test('keys that nothing handles return false so the browser keeps them', () => {
  const store = mk([S('s1', [T('a', 0, 0, 10, 10)])]);
  assert.equal(store.handleKey(key('q'), 'canvas'), false);
  assert.equal(store.handleKey(key('Delete'), 'canvas'), false, 'delete with nothing selected does nothing');
});

test('copy, cut, paste, duplicate, delete', () => {
  const store = mk([S('s1', [T('a', 10, 10, 50, 50), R('b', 100, 10, 50, 50)]), S('s2')]);
  store.select(['a', 'b']);
  const copied = store.copy();
  assert.ok(copied.text.startsWith('{'));
  store.setSlide('s2');
  const r = store.paste();
  assert.equal(r.ids.length, 2);
  assert.deepEqual(els(store, 1).map((e) => e.x), [10, 100], 'same positions on another slide');
  assert.deepEqual(store.getState().selection.ids, r.ids, 'the pasted elements are selected');
  store.paste();
  assert.deepEqual(els(store, 1).slice(2).map((e) => e.x), [34, 124], 'pasting again steps further');
  const before = els(store, 1).length;
  store.duplicate();
  assert.equal(els(store, 1).length, before + 2);
  store.deleteSelection();
  assert.equal(els(store, 1).length, before);
  store.setSlide('s1');
  store.select(['a']);
  store.cut();
  assert.deepEqual(ids(store), ['b']);
  store.undo();
  assert.deepEqual(ids(store), ['a', 'b']);
});

test('paste of text and of an image url from the system clipboard', () => {
  const store = mk([S('s1')]);
  const t = store.paste({ text: 'Hello there' });
  assert.equal(els(store)[0].type, 'text');
  assert.equal(els(store)[0].doc.content[0].content[0].text, 'Hello there');
  store.paste({ text: 'https://example.com/photo.jpg' });
  assert.equal(els(store)[1].type, 'image');
  assert.equal(els(store)[1].src.url, 'https://example.com/photo.jpg');
  assert.equal(store.paste({}), null, 'nothing to paste');
  assert.equal(t.ids.length, 1);
});

test('paste and conform: preview first, then paste with the chosen mode', () => {
  const a = mk([S('s1', [R('x', 0, 0, 10, 10, { fill: '#2563eb' })])]);
  a.select(['x']);
  const text = a.copy().text;
  const dark = { ...DEFAULT_THEME, id: 'ink', c: { ...DEFAULT_THEME.c, accent: '#38bdf8' } };
  const b = mk([S('t1')]);
  b.setTheme(dark);
  const report = b.pastePreview({ text });
  assert.equal(report.changes[0].to, '@accent');
  assert.equal(els(b).length, 0, 'preview changes nothing');
  b.paste({ text });
  assert.equal(els(b)[0].fill, '@accent');
  b.undo();
  b.paste({ text, mode: 'none' });
  assert.equal(els(b)[0].fill, '#2563eb');
});

test('group and ungroup keep z-order contiguous and select the whole group', () => {
  const store = mk([S('s1', [T('a', 0, 0, 5, 5), T('b', 10, 0, 5, 5), T('c', 20, 0, 5, 5), T('d', 30, 0, 5, 5)])]);
  store.select(['a', 'c']);
  assert.equal(store.group(), true);
  const g = els(store).find((e) => e.id === 'a').group;
  assert.ok(g);
  assert.deepEqual(ids(store), ['b', 'a', 'c', 'd'], 'members are contiguous, at the top member position');
  store.clearSelection();
  store.clickElement('a');
  assert.deepEqual(store.getState().selection.ids, ['a', 'c']);
  store.ungroup();
  assert.ok(els(store).every((e) => e.group === undefined));
  store.undo();
  assert.equal(els(store).find((e) => e.id === 'c').group, g);
});

test('layer commands move selected elements one step or to the ends', () => {
  const store = mk([S('s1', [T('a', 0, 0, 5, 5), T('b', 0, 0, 5, 5), T('c', 0, 0, 5, 5), T('d', 0, 0, 5, 5)])]);
  store.select(['b']);
  store.forward();
  assert.deepEqual(ids(store), ['a', 'c', 'b', 'd']);
  store.backward(); store.backward();
  assert.deepEqual(ids(store), ['b', 'a', 'c', 'd']);
  store.front();
  assert.deepEqual(ids(store), ['a', 'c', 'd', 'b']);
  store.back();
  assert.deepEqual(ids(store), ['b', 'a', 'c', 'd']);
  store.select(['a', 'c']);
  store.forward();
  assert.deepEqual(ids(store), ['b', 'd', 'a', 'c']);
  assert.equal(store.forward(), false, 'already on top');
  assert.equal(store.layer('back', ['d']), true);
  assert.deepEqual(ids(store), ['d', 'b', 'a', 'c']);
});

test('lock and hide toggle and the elements leave canvas picking', () => {
  const store = mk([S('s1', [T('a', 0, 0, 5, 5), T('b', 10, 0, 5, 5)])]);
  store.select(['a']);
  store.toggleLock();
  assert.equal(els(store)[0].lock, true);
  store.selectAll();
  assert.deepEqual(store.getState().selection.ids, ['b']);
  store.toggleHide(['b']);
  assert.equal(els(store)[1].hide, true);
  store.toggleLock(['a']);
  assert.equal(els(store)[0].lock, false);
});

test('flip, align, distribute, tidy, match size go through dispatch and undo', () => {
  const store = mk([S('s1', [R('a', 100, 100, 50, 50), R('b', 300, 200, 80, 80), R('c', 700, 120, 50, 50)])]);
  store.selectAll();
  store.align('top');
  assert.deepEqual(els(store).map((e) => e.y), [100, 100, 100]);
  store.undo();
  assert.deepEqual(els(store).map((e) => e.y), [100, 200, 120]);
  store.distribute('h');
  const gap1 = els(store)[1].x - (els(store)[0].x + els(store)[0].w);
  const gap2 = els(store)[2].x - (els(store)[1].x + els(store)[1].w);
  assert.ok(Math.abs(gap1 - gap2) < 0.02);
  store.matchSize('w');
  assert.deepEqual(els(store).map((e) => e.w), [50, 50, 50]);
  store.align('center', { to: 'slide' });
  assert.ok(els(store).every((e) => e.x === 935));
  store.select(['a']);
  assert.equal(store.flip('h'), true);
  assert.equal(els(store)[0].rot, 0);
  store.flip('v');
  assert.equal(els(store)[0].rot, 180);
});

test('slides: add, duplicate, delete, move, navigate; deleting keeps a valid current slide', () => {
  const store = mk([S('s1', [T('a', 1, 1, 1, 1)]), S('s2')]);
  const t = store.addSlide({ layout: 'bullets' });
  assert.equal(store.getState().slideId, t);
  assert.equal(store.getState().doc.slides[1].id, t, 'after the current slide');
  assert.equal(store.getState().doc.slides[1].layout, 'bullets');
  store.setSlide('s1');
  const dup = store.duplicateSlide();
  assert.equal(store.getState().slideId, dup);
  assert.equal(els(store, 1).length, 1);
  assert.notEqual(els(store, 1)[0].id, 'a');
  store.moveSlide(dup, null);
  assert.equal(store.getState().doc.slides[0].id, dup);
  store.nextSlide();
  assert.equal(store.getState().slideId, 's1');
  store.prevSlide();
  assert.equal(store.getState().slideId, dup);
  store.deleteSlide(dup);
  assert.notEqual(store.getState().slideId, dup);
  assert.ok(store.getState().doc.slides.some((s) => s.id === store.getState().slideId));
  const n = store.getState().doc.slides.length;
  store.undo();
  assert.equal(store.getState().doc.slides.length, n + 1);
});

test('the last slide cannot be deleted', () => {
  const store = mk([S('s1')]);
  assert.equal(store.deleteSlide(), false);
  assert.equal(store.getState().doc.slides.length, 1);
});

test('layout, theme and resize commands', () => {
  const store = mk([S('s1', [T('t1', 10, 10, 50, 20), T('t2', 10, 100, 50, 20)])]);
  const report = store.applyLayout(SAMPLE_LAYOUTS.titleBody);
  assert.equal(report.matched.length, 2);
  assert.equal(store.getState().doc.slides[0].layout, 'bullets');
  assert.equal(els(store)[0].role, 'title');
  store.undo();
  assert.equal(store.getState().doc.slides[0].layout, 'blank');
  assert.equal(els(store)[0].x, 10);
  const th = { ...DEFAULT_THEME, id: 'other' };
  store.setTheme(th);
  assert.equal(store.getState().doc.deck.theme.id, 'other');
  store.undo();
  assert.equal(store.getState().doc.deck.theme.id, 'slate');
  store.resizeDeck(1280, 720, 'fit');
  assert.equal(store.getState().doc.deck.w, 1280);
  assert.equal(els(store)[0].x, 6.67);
  store.undo();
  assert.equal(store.getState().doc.deck.w, 1920);
  assert.equal(els(store)[0].x, 10);
});

test('transaction makes several dispatches one undo step', () => {
  const store = mk([S('s1', [T('a', 0, 0, 5, 5), T('b', 0, 0, 5, 5)])]);
  store.transaction('Both', () => {
    store.patch([{ id: 'a', p: { x: 1 } }]);
    store.patch([{ id: 'b', p: { x: 2 } }]);
  });
  assert.equal(store.getState().undoLabel, 'Both');
  store.undo();
  assert.deepEqual(els(store).map((e) => e.x), [0, 0]);
});

test('view state: zoom steps, fit, grid, guides, pan', () => {
  const store = mk([S('s1')]);
  store.setViewport(1000, 600);
  assert.equal(store.getState().zoomMode, 'fit');
  assert.ok(Math.abs(store.getState().zoom - Math.min((1000 - 48) / 1920, (600 - 48) / 1080)) < 1e-9);
  store.zoomIn();
  assert.equal(store.getState().zoomMode, 'manual');
  assert.equal(store.getState().zoom, 0.5);
  store.zoomOut();
  assert.equal(store.getState().zoom, 0.33);
  store.zoomOut();
  assert.equal(store.getState().zoom, 0.25);
  store.zoom100();
  assert.equal(store.getState().zoom, 1);
  store.zoomFit();
  assert.equal(store.getState().zoomMode, 'fit');
  store.toggleGrid(); store.toggleGuides();
  assert.deepEqual([store.getState().grid, store.getState().guides], [true, false]);
  store.setPan(5, 6);
  assert.deepEqual(store.getState().pan, { x: 5, y: 6 });
  store.setZoom(99);
  assert.equal(store.getState().zoom, 4);
});

test('selection follows remote deletions', () => {
  let remote;
  const store = mk([S('s1', [T('a', 0, 0, 5, 5), T('b', 0, 0, 5, 5)]), S('s2')]);
  store.select(['a', 'b']);
  // emulate a remote change by dispatching without history through the engine hook
  remote = (ops) => store.dispatch(ops, { record: false });
  remote([{ op: 'el.remove', slide: 's1', id: 'a' }]);
  assert.deepEqual(store.getState().selection.ids, ['b']);
  remote([{ op: 'slide.remove', id: 's1' }]);
  assert.equal(store.getState().slideId, 's2');
  assert.deepEqual(store.getState().selection, { slide: 's2', ids: [] });
});

test('end to end with the sync engine: edits reach the server, temp slide ids are remapped in state and history', async () => {
  const server = fakeServer({
    deck: { id: 'd1', title: 'D', w: 1920, h: 1080, theme: { id: 'slate' }, sections: [], slide_order: ['1'] },
    slides: [S('1', [T('a', 0, 0, 100, 50)])], seq: 0,
  });
  const clock = fakeClock();
  const sync = createSync({ transport: server.transport, storage: fakeStorage(), clock, clientId: 'tab1', deckId: 'd1', uuid: (() => { let n = 0; return () => `k${++n}`; })() });
  sync.init(server.snapshot());
  const store = createStore({ sync, rng: mulberry32(5) });
  assert.equal(store.getState().sync.status, 'saved');
  store.select(['a']);
  store.moveSelection(10, 0);
  store.addSlide();
  assert.equal(store.getState().sync.status, 'saving');
  await clock.advance(700);
  assert.equal(store.getState().sync.status, 'saved');
  assert.equal(server.snapshot().slides[1].id, '101');
  assert.equal(store.getState().slideId, '101', 'current slide id remapped');
  assert.equal(store.getState().doc.slides[1].id, '101');
  // undoing the slide creation uses the remapped id and reaches the server
  store.undo();
  await clock.advance(700);
  assert.equal(server.snapshot().slides.length, 1);
  store.redo();
  await clock.advance(700);
  assert.equal(server.snapshot().slides.length, 2, 'redo re-adds under a temporary id which the server maps again');
  assert.deepEqual(content(store.getState().doc), content(createDoc(server.snapshot())));
});

test('destroy stops listening', () => {
  const store = mk([S('s1')]);
  let n = 0;
  store.subscribe(() => { n++; });
  store.destroy();
  store.toggleGrid();
  assert.equal(n, 0);
});
