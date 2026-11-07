import test from 'node:test';
import assert from 'node:assert/strict';
import { createToasts } from '../toasts.js';

function clock() {
  let now = 0;
  let id = 0;
  const timers = new Map();
  return {
    setTimeout: (fn, ms) => { const h = ++id; timers.set(h, { fn, at: now + ms }); return h; },
    clearTimeout: (h) => timers.delete(h),
    advance(ms) {
      now += ms;
      for (const [h, t] of [...timers]) if (t.at <= now) { timers.delete(h); t.fn(); }
    },
    count: () => timers.size,
  };
}

test('one toast is shown at a time and the next appears when it expires', () => {
  const c = clock();
  const t = createToasts({ ...c, defaultMs: 1000 });
  t.push('First');
  t.push('Second', { kind: 'ok' });
  assert.equal(t.current().message, 'First');
  assert.equal(t.pending(), 1);
  c.advance(999);
  assert.equal(t.current().message, 'First');
  c.advance(2);
  assert.equal(t.current().message, 'Second');
  assert.equal(t.current().kind, 'ok');
  c.advance(1001);
  assert.equal(t.current(), null);
  assert.equal(c.count(), 0);
});

test('errors stay until dismissed, actions live longer', () => {
  const c = clock();
  const t = createToasts({ ...c, defaultMs: 1000 });
  t.push('Could not save', { kind: 'error' });
  c.advance(60000);
  assert.equal(t.current().message, 'Could not save');
  t.dismiss();
  assert.equal(t.current(), null);
  t.push('Slide deleted', { action: { label: 'Undo', run() {} } });
  c.advance(5000);
  assert.equal(t.current().message, 'Slide deleted');
  c.advance(3500);
  assert.equal(t.current(), null);
});

test('an identical message replaces the one on screen and restarts its timer', () => {
  const c = clock();
  const t = createToasts({ ...c, defaultMs: 1000 });
  const a = t.push('Saved');
  c.advance(800);
  const b = t.push('Saved');
  assert.equal(a, b);
  assert.equal(t.pending(), 0);
  c.advance(800);
  assert.equal(t.current().message, 'Saved');
  c.advance(300);
  assert.equal(t.current(), null);
});

test('dismissing a waiting toast leaves the one on screen; unknown kinds become info; the queue is capped', () => {
  const c = clock();
  const t = createToasts({ ...c, defaultMs: 1000 });
  const first = t.push('one');
  const second = t.push('two', { kind: 'weird' });
  t.dismiss(second);
  assert.equal(t.current().id, first);
  assert.equal(t.pending(), 0);
  assert.equal(t.push('x', { kind: 'weird' }) > 0, true);
  assert.equal(t.current().message, 'one');
  for (let i = 0; i < 20; i++) t.push(`m${i}`);
  assert.ok(t.pending() <= 7);
});

test('subscribers hear every change', () => {
  const c = clock();
  const t = createToasts({ ...c, defaultMs: 1000 });
  const seen = [];
  const off = t.subscribe((cur) => seen.push(cur?.message ?? null));
  t.push('a');
  c.advance(1001);
  off();
  t.push('b');
  assert.deepEqual(seen, ['a', null]);
});

test('a warning pre-empts an information toast that is still on screen, an error is never pre-empted', () => {
  const c = clock();
  const t = createToasts({ ...c, defaultMs: 1000 });
  t.push('Slide deleted', { action: { label: 'Undo', run() {} } });
  t.push('A deck needs at least one slide', { kind: 'warn' });
  assert.equal(t.current().message, 'A deck needs at least one slide');
  assert.equal(t.pending(), 0);
  c.advance(1001);
  assert.equal(t.current(), null);
  t.push('Could not save', { kind: 'error' });
  t.push('Something else', { kind: 'warn' });
  assert.equal(t.current().message, 'Could not save');
  assert.equal(t.pending(), 1);
});
