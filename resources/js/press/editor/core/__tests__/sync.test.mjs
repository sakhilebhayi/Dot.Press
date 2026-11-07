import test from 'node:test';
import assert from 'node:assert/strict';
import { createSync } from '../sync.js';
import { createDoc } from '../doc.js';
import {
  fakeClock, fakeStorage, fakeServer, netError, httpError,
} from './fake.mjs';
import { S, T, R, content } from './helpers.mjs';

const initial = () => ({
  deck: { id: 'd1', title: 'Deck', w: 1920, h: 1080, theme: { id: 'slate' }, status: 'draft', visibility: 'private', sections: [], slide_order: ['1', '2'] },
  slides: [S('1', [T('a', 10, 10, 100, 50), T('b', 200, 10, 100, 50)]), S('2', [R('c', 0, 0, 10, 10)])],
  seq: 0,
});
const set = (id, p, slide = '1') => ({ op: 'el.set', slide, id, p });

function boot(over = {}) {
  const clock = fakeClock();
  const server = over.server || fakeServer(initial());
  const storage = over.storage || fakeStorage();
  let n = 0;
  const sync = createSync({
    transport: over.transport || server.transport, storage, clock, clientId: over.clientId || 'tab1', deckId: 'd1',
    uuid: () => `key-${over.clientId || 'tab1'}-${++n}`, config: over.config,
  });
  sync.init(server.snapshot());
  return { sync, server, clock, storage };
}
const elOf = (doc, slide, id) => doc.slides.find((s) => s.id === slide).els.find((e) => e.id === id);

test('edits apply to the view at once and are saved 600 ms after the last edit', async () => {
  const { sync, server, clock } = boot();
  sync.dispatch([set('a', { x: 50 })]);
  assert.equal(elOf(sync.getView(), '1', 'a').x, 50);
  assert.equal(sync.state.status, 'saving');
  assert.equal(server.calls.length, 0);
  await clock.advance(599);
  assert.equal(server.calls.length, 0);
  await clock.advance(2);
  assert.equal(server.calls.length, 1);
  assert.equal(server.calls[0].client, 'tab1');
  assert.equal(server.calls[0].key, 'key-tab1-1');
  assert.equal(sync.state.status, 'saved');
  assert.equal(sync.state.pending, 0);
  assert.equal(elOf(sync.getBase(), '1', 'a').x, 50, 'base now holds the confirmed change');
  assert.equal(sync.getBase().seq, 1);
  assert.equal(elOf(server.snapshot(), '1', 'a').x, 50);
});

test('continuous editing is flushed at the 2.5 s max wait and batches several edits', async () => {
  const { sync, server, clock } = boot();
  for (let i = 1; i <= 8; i++) {
    sync.dispatch([set('a', { x: i })]);
    await clock.advance(400);
  }
  assert.ok(server.calls.length >= 1, 'flushed during the burst');
  assert.ok(server.calls[0].ops.length >= 5 && server.calls[0].ops.length <= 7, `first batch ${server.calls[0].ops.length}`);
  await clock.advance(700);
  assert.equal(sync.state.pending, 0);
  assert.equal(elOf(server.snapshot(), '1', 'a').x, 8);
});

test('flush() on demand, blur, and unloading with a beacon', async () => {
  const { sync, server, clock } = boot();
  sync.dispatch([set('a', { x: 5 })]);
  await sync.flush();
  assert.equal(server.calls.length, 1);
  sync.dispatch([set('a', { x: 6 })]);
  await sync.blur();
  assert.equal(server.calls.length, 2);

  const beacons = [];
  const b = boot({ transport: { ...server.transport, beacon: (p) => { beacons.push(p); return true; } } });
  b.sync.dispatch([set('a', { x: 9 })]);
  await b.sync.flush({ unloading: true });
  assert.equal(beacons.length, 1);
  assert.deepEqual(beacons[0].ops, [set('a', { x: 9 })]);
  assert.equal(beacons[0].key, 'key-tab1-1');
  await b.sync.persisted();
  const saved = await b.sync.recover({ self: true });
  assert.equal(saved.inflight.key, 'key-tab1-1', 'the same key is persisted, so a resend after reload is harmless');
});

test('network failure and recovery: pending ops are kept, retried with backoff and the same key', async () => {
  const { sync, server, clock } = boot();
  server.failNext(netError(), 'before', 3);
  sync.dispatch([set('a', { x: 77 })]);
  await clock.advance(700);
  assert.equal(sync.state.status, 'offline');
  assert.equal(sync.state.pending, 1);
  assert.ok(sync.state.lastError);
  assert.equal(elOf(sync.getView(), '1', 'a').x, 77, 'the view keeps the edit');
  // calls at 600 ms, then retries after 750, 1500 and 3000 ms (backoff 1 s, 2 s, 4 s at jitter 0.75)
  assert.equal(server.calls.length, 1);
  await clock.advance(600); // t = 1300
  assert.equal(server.calls.length, 1);
  await clock.advance(100); // t = 1400, retry at 1350
  assert.equal(server.calls.length, 2);
  await clock.advance(1400); // t = 2800, next retry at 2850
  assert.equal(server.calls.length, 2);
  await clock.advance(100);
  assert.equal(server.calls.length, 3);
  await clock.advance(2900); // t = 5800, next retry at 5850
  assert.equal(server.calls.length, 3);
  await clock.advance(100);
  assert.equal(server.calls.length, 4);
  assert.equal(sync.state.status, 'saved');
  assert.deepEqual([...new Set(server.calls.map((c) => c.key))], ['key-tab1-1']);
  assert.equal(elOf(server.snapshot(), '1', 'a').x, 77);
});

test('going offline pauses sending, coming online sends at once', async () => {
  const { sync, server, clock } = boot();
  sync.setOnline(false);
  sync.dispatch([set('a', { x: 3 })]);
  await clock.advance(5000);
  assert.equal(server.calls.length, 0);
  assert.equal(sync.state.status, 'offline');
  sync.setOnline(true);
  await clock.advance(10);
  assert.equal(server.calls.length, 1);
  assert.equal(sync.state.status, 'saved');
});

test('5xx is an error state with retries; 429 honours retryAfter', async () => {
  const { sync, server, clock } = boot();
  server.failNext(httpError(503), 'before');
  sync.dispatch([set('a', { x: 1 })]);
  await clock.advance(700);
  assert.equal(sync.state.status, 'error');
  assert.equal(sync.state.lastError.status, 503);
  await clock.advance(800);
  assert.equal(sync.state.status, 'saved');

  server.failNext(httpError(429, { retryAfter: 10 }), 'before');
  sync.dispatch([set('a', { x: 2 })]);
  await clock.advance(700);
  const calls = server.calls.length;
  assert.equal(sync.state.status, 'error');
  await clock.advance(9000);
  assert.equal(server.calls.length, calls, 'waits for Retry-After');
  await clock.advance(1500);
  assert.equal(server.calls.length, calls + 1);
  assert.equal(sync.state.status, 'saved');
  assert.equal(elOf(server.snapshot(), '1', 'a').x, 2);
});

test('backoff grows to a ceiling of 30 s', async () => {
  const { sync, server, clock } = boot();
  server.failNext(netError(), 'before', 12);
  sync.dispatch([set('a', { x: 1 })]);
  const times = [];
  let seen = 0;
  for (let i = 0; i < 1200 && seen < 12; i++) {
    await clock.advance(500);
    if (server.calls.length !== seen) { seen = server.calls.length; times.push(clock.now()); }
  }
  assert.equal(server.calls.length, 12);
  const gaps = times.slice(1).map((t, i) => t - times[i]);
  assert.ok(gaps[0] < gaps[2] && gaps[2] < gaps[4], `growing: ${gaps}`);
  assert.ok(Math.max(...gaps) <= 30_000 + 500, `ceiling: ${gaps}`);
  assert.ok(gaps[gaps.length - 1] >= 20_000, 'stays near the ceiling');
  assert.equal(sync.state.pending, 1);
});

test('duplicate delivery: the response is lost, the retry reuses the key and the server applies it once', async () => {
  const { sync, server, clock } = boot();
  server.failNext(netError(), 'after'); // processed, answer lost
  sync.dispatch([{ op: 'deck.resize', w: 960, h: 540, mode: 'fit' }]);
  await clock.advance(700);
  assert.equal(server.calls.length, 1);
  assert.equal(server.seq, 1, 'the server applied it');
  await clock.advance(1000);
  assert.equal(server.calls.length, 2);
  assert.equal(server.calls[0].key, server.calls[1].key);
  assert.equal(server.seq, 1, 'the retry applied nothing');
  assert.equal(server.snapshot().deck.w, 960);
  assert.equal(sync.state.status, 'saved');
  assert.equal(sync.getBase().deck.w, 960);
  assert.equal(elOf(sync.getBase(), '1', 'b').x, 100, 'resized once, not twice');
});

test('out of order: the feed delivers our own change before the answer arrives, nothing is applied twice', async () => {
  const server = fakeServer(initial());
  let open;
  const gate = new Promise((r) => { open = r; });
  const transport = {
    ...server.transport,
    postOps: async (req) => { const res = await server.transport.postOps(req); await gate; return res; }, // stored, answer delayed
  };
  const { sync, clock } = boot({ server, transport });
  sync.dispatch([{ op: 'deck.resize', w: 960, h: 540, mode: 'fit' }, set('a', { x: 8 })]);
  await clock.advance(700);
  assert.equal(server.seq, 1, 'the server has it');
  assert.equal(sync.getBase().seq, 0, 'but we have not heard back');
  await sync.pollNow(); // the feed shows our own entry first, with the deck.reload the server logs after a resize
  assert.equal(sync.getBase().deck.w, 960);
  assert.equal(elOf(sync.getBase(), '1', 'b').x, 100, 'resize applied exactly once');
  open();
  await clock.advance(10);
  assert.equal(sync.state.pending, 0);
  assert.equal(sync.state.status, 'saved');
  assert.equal(elOf(sync.getBase(), '1', 'b').x, 100);
  assert.deepEqual(content(sync.getView()), content(createDoc(server.snapshot())));
});

test('a duplicate answer for a batch we already finished is ignored', async () => {
  const server = fakeServer(initial());
  const answers = [];
  const transport = { ...server.transport, postOps: async (req) => { const r = await server.transport.postOps(req); answers.push(r); return r; } };
  const { sync, clock } = boot({ server, transport });
  sync.dispatch([set('a', { x: 5 })]);
  await clock.advance(700);
  const base = sync.getBase();
  const view = sync.getView();
  await sync.flush(); // nothing pending: no request, no change
  assert.equal(answers.length, 1);
  assert.equal(sync.getBase(), base);
  assert.equal(sync.getView(), view);
});

test('a remote change to a different element merges into base and the pending edit stays on top', async () => {
  const { sync, server, clock } = boot();
  server.holdResponses(); // keep our edit unacknowledged
  sync.dispatch([set('a', { x: 500 })]);
  await clock.advance(700); // inflight
  sync.dispatch([set('a', { y: 400 })]); // queued
  server.remote([set('b', { x: 999 }), set('a', { x: 1, w: 321 })]);
  await sync.pollNow();
  const v = sync.getView();
  assert.equal(elOf(v, '1', 'b').x, 999, 'remote edit arrived');
  assert.equal(elOf(v, '1', 'a').w, 321, 'remote edit to another key of the same element arrived');
  assert.equal(elOf(v, '1', 'a').x, 500, 'our pending edit wins on the same key (it will be later on the server)');
  assert.equal(elOf(v, '1', 'a').y, 400);
  assert.equal(elOf(sync.getBase(), '1', 'a').x, 1);
});

test('same key edited remotely and locally: after saving, the later (ours) wins on the server and everywhere', async () => {
  const { sync, server, clock } = boot();
  server.remote([set('a', { x: 111 })]);
  sync.dispatch([set('a', { x: 222 })]);
  await clock.advance(700);
  assert.equal(server.snapshot().slides[0].els[0].x, 222);
  await sync.pollNow();
  assert.equal(elOf(sync.getBase(), '1', 'a').x, 222);
  assert.equal(sync.getBase().seq, server.seq);
  assert.deepEqual(content(sync.getView()), content(createDoc(server.snapshot())));
});

test('a remote delete of an element with pending edits: the view drops it, the send is harmless, nothing throws', async () => {
  const { sync, server, clock } = boot();
  const release = server.holdResponses();
  sync.dispatch([set('a', { x: 5 })]);
  await clock.advance(700);
  sync.dispatch([set('a', { y: 6 })]);
  server.remote([{ op: 'el.remove', slide: '1', id: 'a' }]);
  await sync.pollNow();
  assert.equal(elOf(sync.getView(), '1', 'a'), undefined);
  assert.equal(sync.state.pending, 2, 'pending ops are never dropped locally');
  release();
  await clock.advance(3000);
  assert.equal(sync.state.pending, 0);
  assert.equal(sync.state.status, 'saved');
  const skippedSeen = [];
  sync.onSkipped((l) => skippedSeen.push(...l));
  sync.dispatch([set('a', { x: 1 })]); // locally skipped, not even queued
  assert.equal(sync.state.pending, 0);
  assert.deepEqual(content(sync.getView()), content(createDoc(server.snapshot())));
});

test('ops the server reports as skipped are dropped from base and reported', async () => {
  const { sync, server, clock } = boot();
  const release = server.holdResponses();
  sync.dispatch([set('a', { x: 5 })]);
  await clock.advance(700);
  server.remote([{ op: 'el.remove', slide: '1', id: 'a' }]);
  release();
  const seen = [];
  sync.onSkipped((l) => seen.push(...l));
  await clock.advance(10);
  // server had removed a before processing our batch, so it skipped the set
  assert.equal(seen.length, 1);
  assert.equal(seen[0].reason, 'missing_element');
  await sync.pollNow();
  assert.deepEqual(content(sync.getBase()), content(createDoc(server.snapshot())));
});

test('temporary slide ids are replaced by the real ones, in the queue, the view and the id map event', async () => {
  const { sync, server, clock } = boot();
  const maps = [];
  sync.onIdMap((m) => maps.push(m));
  const release = server.holdResponses();
  sync.dispatch([{ op: 'slide.add', tid: 'tmp1', after: '2', slide: { title: 'New' } }]);
  await clock.advance(700);
  sync.dispatch([{ op: 'el.add', slide: 'tmp1', el: T('n', 0, 0, 10, 10) }]); // queued while in flight
  assert.ok(sync.getView().slides.some((s) => s.id === 'tmp1'));
  release();
  await clock.advance(10);
  await clock.advance(1000);
  assert.deepEqual(maps[0], { tmp1: '101' });
  assert.ok(!sync.getView().slides.some((s) => s.id === 'tmp1'));
  assert.equal(elOf(sync.getView(), '101', 'n').w, 10);
  assert.equal(sync.state.pending, 0);
  assert.deepEqual(content(sync.getBase()), content(createDoc(server.snapshot())));
});

test('a slide.add with a real (all digit) id from an undo is re-sent under a temporary id', async () => {
  const { sync, server, clock } = boot();
  const maps = [];
  sync.onIdMap((m) => maps.push(m));
  sync.dispatch([{ op: 'slide.remove', id: '2' }]);
  await clock.advance(700);
  const r = sync.dispatch([{ op: 'slide.add', tid: '2', after: '1', slide: { els: [R('c', 0, 0, 10, 10)] } }, set('c', { x: 3 }, '2')]);
  assert.deepEqual(r.skipped, []);
  const alias = maps[0];
  assert.ok(alias['2'] && /^s/.test(alias['2']));
  await clock.advance(700);
  assert.equal(server.calls[1].ops[0].tid, alias['2']);
  assert.equal(server.calls[1].ops[1].slide, alias['2']);
  await clock.advance(10);
  assert.deepEqual(content(sync.getView()), content(createDoc(server.snapshot())));
  assert.equal(server.snapshot().slides.length, 2);
});

test('slide.dup is sent as slide.add with explicit elements so element ids agree with the server', async () => {
  const { sync, server, clock } = boot();
  sync.dispatch([{ op: 'slide.dup', id: '1', tid: 'tmp9' }]);
  const queued = sync.pendingOps();
  assert.equal(queued[0].op, 'slide.add');
  assert.equal(queued[0].slide.els.length, 2);
  await clock.advance(700);
  const copy = server.snapshot().slides[1];
  const mine = sync.getView().slides.find((s) => s.id === copy.id);
  assert.deepEqual(mine.els.map((e) => e.id), copy.els.map((e) => e.id));
});

test('stale answer: the document is fetched and pending ops are re-applied on top', async () => {
  const { sync, server, clock } = boot();
  sync.startPolling();
  const release = server.holdResponses();
  sync.dispatch([set('a', { x: 5 })]);
  await clock.advance(700);
  sync.dispatch([set('a', { y: 6 })]);
  server.remote([set('b', { x: 321 }), { op: 'slide.set', id: '2', title: 'Remote' }]);
  server.minSeq = 99;
  await clock.advance(3100);
  assert.equal(sync.getBase().seq, server.seq);
  const v = sync.getView();
  assert.equal(elOf(v, '1', 'b').x, 321);
  assert.equal(v.slides[1].title, 'Remote');
  assert.equal(elOf(v, '1', 'a').y, 6, 'queued edit re-applied on the new base');
  server.minSeq = 0;
  release();
  await clock.advance(5000);
  assert.equal(sync.state.pending, 0);
  assert.deepEqual(content(sync.getView()), content(createDoc(server.snapshot())));
  sync.stopPolling();
});

test('a deck.reload entry (version restore, or a resize by someone else) reloads the whole document', async () => {
  const { sync, server } = boot();
  server.remote([{ op: 'deck.resize', w: 1280, h: 720, mode: 'fit' }]);
  await sync.pollNow();
  assert.equal(sync.getBase().deck.w, 1280);
  assert.equal(elOf(sync.getBase(), '1', 'b').x, 133.33);
  assert.equal(sync.getBase().seq, server.seq);
});

test('polling: 3 s while focused, 15 s while hidden', async () => {
  const { sync, server, clock } = boot();
  sync.startPolling();
  await clock.advance(3000);
  assert.equal(server.pollCalls, 1);
  await clock.advance(3000);
  assert.equal(server.pollCalls, 2);
  sync.setFocused(false);
  const before = server.pollCalls;
  await clock.advance(14_000);
  assert.equal(server.pollCalls, before);
  await clock.advance(1100);
  assert.equal(server.pollCalls, before + 1);
  server.remote([set('b', { x: 1 })]);
  const seen = [];
  sync.onChange((v, m) => seen.push(m.source));
  await clock.advance(15_000);
  assert.ok(seen.includes('remote'));
  sync.stopPolling();
});

test('our own echoes in the feed are not applied twice, even when the answer comes later', async () => {
  const { sync, server, clock } = boot();
  sync.dispatch([{ op: 'deck.resize', w: 960, h: 540, mode: 'fit' }]);
  await clock.advance(700);
  await sync.pollNow();
  await sync.pollNow();
  assert.equal(elOf(sync.getBase(), '1', 'b').x, 100);
  assert.equal(sync.getBase().deck.w, 960);
});

test('a write by another client between our base and our answer is merged through the feed in order', async () => {
  const { sync, server, clock } = boot();
  server.remote([set('b', { x: 777 })]); // seq 1, not yet seen by us
  sync.dispatch([set('a', { x: 5 })]);
  await clock.advance(700); // our batch gets seq 2: a gap
  await clock.advance(50);
  assert.equal(sync.getBase().seq, 2);
  assert.equal(elOf(sync.getBase(), '1', 'b').x, 777);
  assert.equal(elOf(sync.getBase(), '1', 'a').x, 5);
  assert.deepEqual(content(sync.getView()), content(createDoc(server.snapshot())));
  assert.equal(sync.state.pending, 0);
});

test('crash and recover: unsent ops come back from storage and are sent with the original key', async () => {
  const server = fakeServer(initial());
  const storage = fakeStorage();
  const a = boot({ server, storage, clientId: 'tab1' });
  a.sync.dispatch([set('a', { x: 41 })]);
  a.server.holdResponses(); // the first batch never gets an answer: the tab "crashes"
  await a.clock.advance(700);
  a.sync.dispatch([set('b', { x: 42 })]); // queued, never sent
  await a.sync.persisted();
  a.sync.destroy();

  const b = boot({ server: fakeServer(initial()), storage, clientId: 'tab2' });
  await b.clock.advance(20_000); // long enough for tab1's heartbeat to be considered dead
  const rec = await b.sync.recover();
  assert.ok(rec);
  assert.equal(rec.ops.length, 2);
  assert.equal(rec.baseSeq, 0);
  assert.equal(rec.inflight.key, 'key-tab1-1');
  assert.equal(rec.clientId, 'tab1');
  const n = await b.sync.restore(rec);
  assert.equal(n, 2);
  assert.equal(elOf(b.sync.getView(), '1', 'a').x, 41);
  await b.clock.advance(3000);
  assert.equal(b.server.calls[0].key, 'key-tab1-1', 'the in-flight batch keeps its key');
  assert.equal(elOf(b.server.snapshot(), '1', 'a').x, 41);
  assert.equal(elOf(b.server.snapshot(), '1', 'b').x, 42);
  await b.sync.persisted();
  assert.equal(await b.sync.recover(), null, 'nothing left after a clean save');
  assert.equal(storage.m.has('press:sync:d1:tab1'), false);
});

test('recover ignores a record that a live tab is still updating, and returns null when nothing is stored', async () => {
  const storage = fakeStorage();
  const empty = boot({ storage, clientId: 'tab2' });
  assert.equal(await empty.sync.recover(), null);
  const server = fakeServer(initial());
  const live = boot({ server, storage, clientId: 'tab1' });
  live.server.holdResponses();
  live.sync.dispatch([set('a', { x: 2 })]);
  await live.sync.persisted();
  const other = boot({ storage, clientId: 'tab2' });
  assert.equal(await other.sync.recover(), null, 'tab1 saved a moment ago');
});

test('storage failures never break saving', async () => {
  const bad = { get: () => { throw new Error('quota'); }, set: () => { throw new Error('quota'); }, remove: () => {} };
  const { sync, server, clock } = boot({ storage: bad });
  sync.dispatch([set('a', { x: 4 })]);
  await clock.advance(700);
  assert.equal(elOf(server.snapshot(), '1', 'a').x, 4);
  assert.equal(sync.state.status, 'saved');
});

test('a batch the server keeps refusing is bisected, then stops saving with a fatal error until discarded', async () => {
  const { sync, server, clock } = boot();
  const bad = set('a', { x: 666 });
  const transport = {
    ...server.transport,
    postOps: async (req) => {
      if (req.ops.some((o) => o.p && o.p.x === 666)) throw httpError(422);
      return server.transport.postOps(req);
    },
  };
  const b = boot({ server, transport });
  b.sync.dispatch([set('b', { x: 1 }), bad, set('b', { y: 2 })]);
  await b.clock.advance(700);
  await b.clock.advance(100);
  assert.equal(b.sync.state.status, 'error');
  assert.equal(b.sync.state.fatal, true);
  assert.equal(elOf(b.server.snapshot(), '1', 'b').x, 1, 'the good ops before it were saved');
  const dropped = b.sync.discardInflight();
  assert.deepEqual(dropped, [bad]);
  await b.clock.advance(1000);
  assert.equal(b.sync.state.status, 'saved');
  assert.equal(elOf(b.server.snapshot(), '1', 'b').y, 2);
});

test('401 pauses saving until retry()', async () => {
  const { sync, server, clock } = boot();
  server.failNext(httpError(419), 'before');
  sync.dispatch([set('a', { x: 4 })]);
  await clock.advance(700);
  assert.equal(sync.state.needsAuth, true);
  assert.equal(sync.state.status, 'error');
  await clock.advance(60_000);
  assert.equal(server.calls.length, 1);
  await sync.retry();
  await clock.advance(10);
  assert.equal(sync.state.status, 'saved');
});

test('state and change events fire, and unsubscribe works', async () => {
  const { sync, clock } = boot();
  const states = [];
  const changes = [];
  const off = sync.onState((s) => states.push(s.status));
  sync.onChange((v, m) => changes.push(m.source));
  sync.dispatch([set('a', { x: 4 })]);
  await clock.advance(700);
  assert.deepEqual([...new Set(states)], ['saving', 'saved']);
  assert.ok(changes.includes('local') && changes.includes('ack'));
  off();
  const n = states.length;
  sync.dispatch([set('a', { x: 5 })]);
  assert.equal(states.length, n);
});

test('the base is never mutated: documents handed out are stable snapshots', async () => {
  const { sync, clock } = boot();
  const v0 = sync.getView();
  const frozen = JSON.stringify(v0);
  sync.dispatch([set('a', { x: 4 })]);
  await clock.advance(700);
  assert.equal(JSON.stringify(v0), frozen);
});

test('discardRecovery removes a stored record without sending anything', async () => {
  const storage = fakeStorage();
  const a = boot({ storage, clientId: 'tab1' });
  a.server.holdResponses();
  a.sync.dispatch([set('a', { x: 2 })]);
  await a.sync.persisted();
  a.sync.destroy();
  const b = boot({ storage, clientId: 'tab2' });
  await b.clock.advance(20_000);
  const rec = await b.sync.recover();
  assert.ok(rec);
  await b.sync.discardRecovery(rec);
  assert.equal(await b.sync.recover(), null);
  assert.equal(b.server.calls.length, 0);
});

test('a live tab never offers its own queue back as unsent changes from an earlier session', async () => {
  const storage = fakeStorage();
  const server = fakeServer(initial());
  const dead = boot({ server, storage, clientId: 'tab0' });
  dead.server.holdResponses();
  dead.sync.dispatch([set('a', { x: 5 })]);
  await dead.clock.advance(700);
  await dead.sync.persisted();
  dead.sync.destroy();
  const live = boot({ server: fakeServer(initial()), storage, clientId: 'tab1' });
  live.server.holdResponses();
  live.sync.dispatch([set('b', { x: 6 })]);
  await live.clock.advance(20_000);
  await live.sync.persisted();
  const rec = await live.sync.recover();
  assert.ok(rec, 'the dead tab record is still found');
  assert.equal(rec.clientId, 'tab0', 'and it is not shadowed by this tab, whose record is newer');
  const none = boot({ storage: fakeStorage(), clientId: 'tab2' });
  none.server.holdResponses();
  none.sync.dispatch([set('b', { x: 7 })]);
  await none.clock.advance(20_000);
  await none.sync.persisted();
  assert.equal(await none.sync.recover(), null, 'own pending ops are not a recovery');
});
