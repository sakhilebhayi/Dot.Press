import test from 'node:test';
import assert from 'node:assert/strict';
import { createPlan, samePos } from '../state.js';

const click = { id: 's', ph: 'in', fx: 'fade', on: 'click' };
const sl = (id, o = {}) => ({ id, els: [], ...o });
const withClicks = (id, n, o = {}) => sl(id, { els: Array.from({ length: n }, (_, i) => ({ id: `e${i}`, type: 'text', anim: [click] })), ...o });

const plan = createPlan([sl('a'), withClicks('b', 2), sl('c', { hidden: true }), sl('d'), withClicks('e', 1)]);

test('next walks clicks then slides and skips hidden slides', () => {
    let p = plan.first();
    const seen = [];
    while (true) {
        seen.push(`${p.slide}.${p.step}`);
        const n = plan.next(p);
        if (samePos(n, p)) break;
        p = n;
    }
    assert.deepEqual(seen, ['0.0', '1.0', '1.1', '1.2', '3.0', '4.0', '4.1']);
});

test('prev retraces exactly and lands on the final state of the previous slide', () => {
    let p = plan.last();
    const seen = [];
    while (true) {
        seen.push(`${p.slide}.${p.step}`);
        const n = plan.prev(p);
        if (samePos(n, p)) break;
        p = n;
    }
    assert.deepEqual(seen, ['4.1', '4.0', '3.0', '1.2', '1.1', '1.0', '0.0']);
});

test('next and prev are inverses everywhere', () => {
    let p = plan.first();
    while (!plan.isLast(p)) {
        const n = plan.next(p);
        // going back from the start of a slide lands on the end of the previous one, so compare via next
        assert.ok(samePos(plan.next(plan.prev(n)), n));
        p = n;
    }
});

test('first and last flags', () => {
    assert.ok(plan.isFirst(plan.first()));
    assert.ok(plan.isLast(plan.last()));
    assert.ok(!plan.isLast(plan.first()));
});

test('jumpNumber is 1 based and hidden slides land on the next visible one', () => {
    assert.deepEqual(plan.jumpNumber(2), { slide: 1, step: 0 });
    assert.deepEqual(plan.jumpNumber(3), { slide: 3, step: 0 });
    assert.deepEqual(plan.jumpNumber(99), { slide: 4, step: 0 });
    assert.deepEqual(plan.jumpNumber(0), { slide: 0, step: 0 });
    assert.deepEqual(plan.jumpNumber('x'), { slide: 0, step: 0 });
});

test('goto clamps step and slide', () => {
    assert.deepEqual(plan.goto(1, 99), { slide: 1, step: 2 });
    assert.deepEqual(plan.goto(-4, -4), { slide: 0, step: 0 });
    assert.deepEqual(plan.goto(99, 0), { slide: 4, step: 0 });
});

test('a deck of only hidden slides still plays', () => {
    const p = createPlan([sl('a', { hidden: true }), sl('b', { hidden: true })]);
    assert.deepEqual(p.first(), { slide: 0, step: 0 });
    assert.deepEqual(p.next(p.first()), { slide: 1, step: 0 });
});

test('empty deck is safe', () => {
    const p = createPlan([]);
    assert.deepEqual(p.first(), { slide: 0, step: 0 });
    assert.ok(p.isLast(p.first()));
    assert.equal(p.progress({ slide: 0, step: 0 }), 1);
});

test('progress rises monotonically and number counts visible slides', () => {
    let p = plan.first();
    let last = -1;
    while (true) {
        const v = plan.progress(p);
        assert.ok(v >= last);
        last = v;
        const n = plan.next(p);
        if (samePos(n, p)) break;
        p = n;
    }
    assert.ok(last > 0.95 && last <= 1);
    assert.deepEqual(plan.number({ slide: 3, step: 0 }), { n: 3, of: 4 });
});

test('peekNext is null at the end', () => {
    assert.equal(plan.peekNext(plan.last()), null);
    assert.deepEqual(plan.peekNext(plan.first()), { slide: 1, step: 0 });
});
