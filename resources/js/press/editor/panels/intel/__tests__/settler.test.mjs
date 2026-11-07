import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createSettler } from '../settler.js';

function clock() {
  let now = 0;
  let id = 0;
  const q = new Map();
  return {
    timers: { setTimeout: (f, ms) => { q.set(++id, { f, at: now + ms }); return id; }, clearTimeout: (h) => q.delete(h) },
    async advance(ms) {
      const end = now + ms;
      for (;;) {
        const next = [...q.entries()].filter(([, t]) => t.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
        if (!next) break;
        q.delete(next[0]);
        now = next[1].at;
        await next[1].f();
        await Promise.resolve();
      }
      now = end;
    },
    pending: () => q.size,
  };
}

test('runs once, after the quiet period, however many changes came in', async () => {
  const c = clock();
  let runs = 0;
  const s = createSettler({ run: async () => { runs++; }, delay: 2500, isSettled: () => true, timers: c.timers });
  s.touch(); await c.advance(1000); s.touch(); await c.advance(2000); s.touch();
  assert.equal(runs, 0);
  await c.advance(2499);
  assert.equal(runs, 0);
  await c.advance(2);
  assert.equal(runs, 1);
});

test('does not run while the deck is still saving, and runs soon after it has saved', async () => {
  const c = clock();
  let settled = false;
  let runs = 0;
  const s = createSettler({ run: async () => { runs++; }, delay: 2500, isSettled: () => settled, timers: c.timers });
  s.touch();
  await c.advance(5000);
  assert.equal(runs, 0);
  assert.equal(s.state.waiting, true);
  settled = true;
  s.settledChanged();
  await c.advance(700);
  assert.equal(runs, 1);
  assert.equal(s.state.waiting, false);
});

test('a change during a run schedules another run once it is quiet', async () => {
  const c = clock();
  let runs = 0;
  let release;
  const s = createSettler({ run: () => { runs++; return new Promise((r) => { release = r; }); }, delay: 100, isSettled: () => true, timers: c.timers });
  const first = s.now();
  s.touch();
  assert.equal(s.state.running, true);
  release();
  await first;
  await c.advance(150);
  assert.equal(runs, 2);
});

test('now() runs at once and cancels the wait; stop() ends everything', async () => {
  const c = clock();
  let runs = 0;
  const s = createSettler({ run: async () => { runs++; }, delay: 2500, isSettled: () => true, timers: c.timers });
  s.touch();
  await s.now();
  assert.equal(runs, 1);
  assert.equal(c.pending(), 0);
  s.touch();
  s.stop();
  await c.advance(10000);
  assert.equal(runs, 1);
  s.touch();
  assert.equal(c.pending(), 0);
});

test('a failing run does not wedge the settler', async () => {
  const c = clock();
  let n = 0;
  const s = createSettler({ run: async () => { n++; if (n === 1) throw new Error('x'); }, delay: 10, isSettled: () => true, timers: c.timers });
  await assert.rejects(s.now());
  await s.now();
  assert.equal(n, 2);
});

test('state callbacks report stale and running', async () => {
  const c = clock();
  const seen = [];
  const s = createSettler({ run: async () => {}, delay: 10, isSettled: () => true, timers: c.timers, onState: (st) => seen.push(st) });
  s.touch();
  assert.equal(seen.at(-1).stale, true);
  await c.advance(20);
  assert.equal(seen.at(-1).stale, false);
  assert.ok(seen.some((x) => x.running));
});
