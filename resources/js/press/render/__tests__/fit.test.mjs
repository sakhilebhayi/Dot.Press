import test from 'node:test';
import assert from 'node:assert/strict';
import { fitFactor, fitKey, createFitCache, FIT_MIN } from '../fit.js';

// Content height grows linearly with the factor: height = 1000 * factor
const linear = (f) => 1000 * f;

test('fits at full size when it already fits', () => {
    assert.equal(fitFactor(linear, 1200), 1);
    assert.equal(fitFactor(linear, 1000), 1);
});

test('finds the largest factor that fits', () => {
    const f = fitFactor(linear, 500);
    assert.ok(f <= 0.5 + 0.001 && f > 0.49, `got ${f}`);
    assert.ok(linear(f) <= 500.5);
});

test('never goes below the minimum and does not loop forever', () => {
    let calls = 0;
    const f = fitFactor((x) => { calls++; return 1e9 * x; }, 10);
    assert.equal(f, FIT_MIN);
    assert.ok(calls <= 12);
});

test('search cost is bounded (no per frame work)', () => {
    let calls = 0;
    fitFactor((x) => { calls++; return 777 * x; }, 300);
    assert.ok(calls <= 12, `measure called ${calls} times`);
});

test('zero or negative space returns the minimum', () => {
    assert.equal(fitFactor(linear, 0), FIT_MIN);
    assert.equal(fitFactor(linear, -5), FIT_MIN);
});

test('stepwise content (lines wrap) still converges to a fitting factor', () => {
    const stepped = (x) => Math.ceil(x * 12) * 60;
    const f = fitFactor(stepped, 400);
    assert.ok(stepped(f) <= 400.5);
    assert.ok(stepped(f + 0.05) > 400.5 || f >= 0.99);
});

test('fitKey changes with every input and with the font epoch', () => {
    const base = { docKey: 'a', w: 1, h: 2, pad: 3, size: 4, weight: 5, font: 'x', lh: 1, ls: 0, ps: 0 };
    const k = fitKey(base, 0);
    assert.notEqual(k, fitKey({ ...base, size: 5 }, 0));
    assert.notEqual(k, fitKey({ ...base, w: 2 }, 0));
    assert.notEqual(k, fitKey(base, 1));
    assert.equal(k, fitKey({ ...base }, 0));
});

test('LRU cache evicts the oldest', () => {
    const c = createFitCache(2);
    c.set('a', 1); c.set('b', 2); c.get('a'); c.set('c', 3);
    assert.equal(c.get('b'), undefined);
    assert.equal(c.get('a'), 1);
    assert.equal(c.size, 2);
});
