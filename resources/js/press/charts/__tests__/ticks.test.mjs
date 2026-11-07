import test from 'node:test';
import assert from 'node:assert/strict';
import { niceTicks } from '../index.js';

const mult = (ticks, step) => ticks.every((t) => Math.abs(t / step - Math.round(t / step)) < 1e-6);

test('ticks cover the range with nice steps', () => {
  const cases = [
    [0, 100, 5, [0, 25, 50, 75, 100]],
    [0, 97, 5, [0, 25, 50, 75, 100]],
    [3, 7, 5, [3, 4, 5, 6, 7]],
    [-50, 120, 6, [-50, 0, 50, 100, 150]],
    [0, 1, 5, [0, 0.25, 0.5, 0.75, 1]],
    [0, 5, 3, [0, 2.5, 5]],
    [0.1, 0.7, 5, [0, 0.2, 0.4, 0.6, 0.8]],
  ];
  for (const [min, max, count, want] of cases) assert.deepEqual(niceTicks(min, max, count).ticks, want, `${min}..${max}`);
});

test('ticks always contain the data and never exceed the requested count', () => {
  for (let k = 0; k < 400; k++) {
    const a = (Math.sin(k * 12.9898) * 43758.5453) % 1;
    const min = (a - 0.5) * Math.pow(10, (k % 9) - 3);
    const max = min + Math.abs(Math.cos(k * 4.1)) * Math.pow(10, (k % 7) - 2) + 1e-9;
    const count = 2 + (k % 8);
    const r = niceTicks(min, max, count);
    assert.ok(r.ticks.length >= 2 && r.ticks.length <= count + 1, `count ${r.ticks.length} vs ${count} for ${min}..${max}`);
    assert.ok(r.min <= min + 1e-9 && r.max >= max - 1e-9, `covers ${min}..${max}: ${r.min}..${r.max}`);
    assert.ok(mult(r.ticks, r.step), 'ticks are multiples of the step');
    r.ticks.forEach((t, i) => i && assert.ok(t > r.ticks[i - 1]));
    assert.equal(r.ticks[0], r.min);
    assert.equal(r.ticks[r.ticks.length - 1], r.max);
    for (const t of r.ticks) assert.ok(Number.isFinite(t));
  }
});

test('floating point noise is removed', () => {
  const r = niceTicks(0, 0.3, 4);
  assert.deepEqual(r.ticks, [0, 0.1, 0.2, 0.3]);
  assert.ok(r.ticks.every((t) => String(t).length < 6));
});

test('degenerate and bad input never throws and gives a usable axis', () => {
  assert.deepEqual(niceTicks(0, 0, 5).ticks, [0, 0.25, 0.5, 0.75, 1]);
  const flat = niceTicks(5, 5, 5);
  assert.ok(flat.min < 5 && flat.max > 5);
  const swapped = niceTicks(10, 0, 5);
  assert.equal(swapped.min, 0);
  assert.equal(swapped.max, 10);
  for (const bad of [[NaN, NaN, 5], [undefined, null, 5], [Infinity, 3, 5], [0, 10, NaN], [0, 10, -3], [0, 10, 1e9]]) {
    const r = niceTicks(...bad);
    assert.ok(Array.isArray(r.ticks) && r.ticks.length >= 2 && r.ticks.length <= 51, JSON.stringify(bad));
    assert.ok(r.ticks.every(Number.isFinite));
  }
  assert.equal(niceTicks(0, 1e15, 5).ticks.every(Number.isFinite), true);
  assert.equal(niceTicks(0, 1e-12, 5).ticks.every(Number.isFinite), true);
});

test('exact mode keeps the bounds and returns nice ticks inside them', () => {
  const r = niceTicks(80, 100, 5, { exact: true });
  assert.equal(r.min, 80);
  assert.equal(r.max, 100);
  assert.deepEqual(r.ticks, [80, 85, 90, 95, 100]);
  const odd = niceTicks(83, 97, 5, { exact: true });
  assert.equal(odd.min, 83);
  assert.ok(odd.ticks.every((t) => t >= 83 && t <= 97));
});
