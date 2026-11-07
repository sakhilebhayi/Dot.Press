import test from 'node:test';
import assert from 'node:assert/strict';
import { skippedNotice } from '../skippedNotice.js';
import { createSync } from '../../core/sync.js';

test('nothing skipped says nothing', () => {
  assert.equal(skippedNotice([]), null);
  assert.equal(skippedNotice(null), null);
  assert.equal(skippedNotice('x'), null);
});

test('one skipped change is named in plain words', () => {
  const n = skippedNotice([{ op: 'el.set', index: 0, reason: 'element not found' }]);
  assert.equal(n.count, 1);
  assert.match(n.message, /^1 change not saved/);
  assert.match(n.message, /deleted by someone else/);
  assert.equal(n.kind, 'warn');
});

test('several reasons are grouped, counted once each and capped', () => {
  const n = skippedNotice([
    { op: 'el.set', reason: 'element not found' },
    { op: 'el.set', reason: 'element not found' },
    { op: 'el.add', reason: 'unknown asset' },
    { op: 'el.add', reason: 'slide too large' },
    { op: 'el.add', reason: 'link must be https' },
    { op: 'el.add', reason: 'something odd and very very long '.repeat(10) },
  ]);
  assert.equal(n.count, 6);
  assert.match(n.message, /^6 changes not saved/);
  assert.match(n.message, /and 3 more reasons/);
  assert.ok(n.message.length < 400);
  assert.ok(skippedNotice([{ reason: 'element not found' }]).message.length <= 52, 'one reason fits a one line toast');
  assert.ok(!/—|–/.test(n.message));
});

test('a text op that the server refuses for size gets the size reason', () => {
  const n = skippedNotice([{ op: 'el.set', reason: 'text too large' }]);
  assert.match(n.message, /too big/);
});

test('unknown reasons are shown trimmed, never as markup', () => {
  const n = skippedNotice([{ op: 'x', reason: '<img src=x onerror=alert(1)> bad' }]);
  assert.ok(!n.message.includes('<'));
});

test('the sync engine tells subscribers which ops the server skipped, and the notice names them', async () => {
  const doc = { deck: { id: 1, title: 'D', w: 1920, h: 1080, rev: 0, slide_order: ['1'], sections: [], theme: null }, slides: [{ id: '1', title: '', notes: null, els: [], rev: 0 }], seq: 0, assets: {} };
  const sent = [];
  const sync = createSync({
    clientId: 'c1', deckId: 1,
    storage: { get: () => null, set() {}, remove() {} },
    clock: { now: () => 0, setTimeout: (f) => { return setTimeout(f, 0); }, clearTimeout: (h) => clearTimeout(h), random: () => 0.5 },
    transport: {
      postOps: async (b) => { sent.push(b); return { seq: 0, deck_rev: 0, slides: {}, ids: {}, skipped: [{ op: 'el.add', index: 0, reason: 'unknown asset' }] }; },
      getChanges: async () => ({ seq: 0, changes: [] }),
      getDocument: async () => doc,
    },
  });
  sync.init(doc);
  let got = null;
  sync.onSkipped((l) => { got = l; });
  sync.dispatch([{ op: 'el.add', slide: '1', el: { id: 'a', type: 'shape', kind: 'rect', x: 0, y: 0, w: 10, h: 10 } }]);
  await sync.flush();
  assert.equal(sent.length, 1);
  assert.ok(Array.isArray(got) && got.length === 1);
  assert.match(skippedNotice(got).message, /library/);
  sync.destroy();
});
