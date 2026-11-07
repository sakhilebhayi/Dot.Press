import test from 'node:test';
import assert from 'node:assert/strict';
import {
  cadence, presenceMs, changesSafetyMs, needsChanges, requestsPerMinute, ACTIVE_WITH_OTHERS_MS, ACTIVE_ALONE_MS, IDLE_MS, LONG_IDLE_MS,
} from '../cadence.js';
import { createSync } from '../sync.js';
import { fakeClock, fakeServer, fakeStorage } from './fake.mjs';

test('presence is quick with company, slower alone, slower still when idle, silent when hidden', () => {
  assert.equal(presenceMs({ focused: true, hidden: false, idleMs: 0, others: 2 }), ACTIVE_WITH_OTHERS_MS);
  assert.equal(presenceMs({ focused: true, hidden: false, idleMs: 0, others: 0 }), ACTIVE_ALONE_MS);
  assert.equal(presenceMs({ focused: true, hidden: false, idleMs: 61_000, others: 3 }), IDLE_MS);
  assert.equal(presenceMs({ focused: true, hidden: false, idleMs: 301_000, others: 3 }), LONG_IDLE_MS);
  assert.equal(presenceMs({ focused: false, hidden: false, idleMs: 0, others: 3 }), null);
  assert.equal(presenceMs({ focused: true, hidden: true, idleMs: 0, others: 3 }), null);
});

test('the budget: requests per minute for one open tab', () => {
  const rpm = (s) => requestsPerMinute(s).presence;
  assert.equal(rpm({ focused: true, hidden: false, idleMs: 0, others: 1 }), 20);
  assert.equal(rpm({ focused: true, hidden: false, idleMs: 0, others: 0 }), 10);
  assert.equal(rpm({ focused: true, hidden: false, idleMs: 120_000, others: 0 }), 4);
  assert.equal(rpm({ focused: true, hidden: false, idleMs: 400_000, others: 0 }), 2);
  assert.equal(requestsPerMinute({ focused: false, hidden: true }).total, 2, 'a hidden tab makes one safety poll every 30 s');
  // even the busiest state stays at the old 3 s rate for presence alone, the changes feed no longer adds a second one
  assert.ok(requestsPerMinute({ focused: true, hidden: false, idleMs: 0, others: 5 }).total <= 24);
});

test('the changes feed is read when the log is ahead or the safety interval has passed', () => {
  assert.equal(needsChanges({ presenceSeq: 10, baseSeq: 10, sinceLastPollMs: 1000 }), false);
  assert.equal(needsChanges({ presenceSeq: 11, baseSeq: 10, sinceLastPollMs: 0 }), true);
  assert.equal(needsChanges({ presenceSeq: 10, baseSeq: 10, sinceLastPollMs: 15_000 }), true);
  assert.equal(needsChanges({ presenceSeq: undefined, baseSeq: 10, sinceLastPollMs: 100 }), false);
  assert.equal(changesSafetyMs({ focused: true, hidden: false }), 15_000);
  assert.equal(changesSafetyMs({ focused: true, hidden: true }), 30_000);
  assert.equal(typeof cadence.presenceMs, 'function');
});

async function pollsIn(minutes, { focused = true, aheadAt = [] } = {}) {
  const clock = fakeClock();
  const srv = fakeServer({ deck: { id: 'd', title: 'D', w: 1920, h: 1080, theme: null, sections: [], slide_order: ['1'], status: 'draft', visibility: 'team' }, slides: [{ id: '1', title: 'a', notes: '', section_id: null, hidden: false, layout: 'blank', bg: null, transition: null, rev: 0, els: [] }], seq: 0 });
  const sync = createSync({ transport: srv.transport, storage: fakeStorage(), clock, clientId: 'c', deckId: 'd', uuid: () => 'k', config: { pollMs: { focused: 15_000, hidden: 30_000 } } });
  sync.init(srv.snapshot());
  sync.setFocused(focused);
  sync.startPolling();
  const before = srv.pollCalls;
  await clock.advance(minutes * 60_000);
  return { polls: srv.pollCalls - before, srv, sync, clock };
}

test('with the safety interval a focused tab polls 4 times a minute and a hidden one twice (was 20 and 4)', async () => {
  assert.equal((await pollsIn(5, { focused: true })).polls / 5, 4);
  assert.equal((await pollsIn(5, { focused: false })).polls / 5, 2);
});

test('pollIfBehind reads the feed only when the log position is ahead, and restarts the safety wait', async () => {
  const { srv, sync, clock } = await pollsIn(0.01);
  const start = srv.pollCalls;
  await sync.pollIfBehind(0);
  assert.equal(srv.pollCalls, start, 'not behind: no request');
  srv.remote([{ op: 'slide.set', id: '1', title: 'moved' }]);
  await sync.pollIfBehind(srv.snapshot().seq);
  assert.equal(srv.pollCalls, start + 1);
  const s = srv.pollCalls;
  await clock.advance(14_000);
  assert.equal(srv.pollCalls, s, 'the safety timer was restarted by that read');
  await clock.advance(2000);
  assert.equal(srv.pollCalls, s + 1);
});
