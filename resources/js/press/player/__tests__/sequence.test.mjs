import test from 'node:test';
import assert from 'node:assert/strict';
import { buildSequence, GROW_SCALE } from '../sequence.js';

const step = (o) => ({ ph: 'in', fx: 'fade', on: 'click', delay: 0, dur: 500, ease: 'out', ...o });
const slide = (...els) => ({ els });
const el = (id, ...anim) => ({ id, type: 'text', x: 0, y: 0, w: 10, h: 10, anim });

test('a slide without animations has no clicks and everything visible', () => {
    const s = buildSequence(slide(el('a'), el('b')));
    assert.equal(s.clicks, 0);
    assert.equal(s.hasAnimations, false);
    assert.deepEqual([...s.stateAt(0).values()].map((v) => v.visible), [true, true]);
});

test('elements with an in step start hidden and appear at their click', () => {
    const s = buildSequence(slide(el('a', step({})), el('b', step({})), el('c')));
    assert.equal(s.clicks, 2);
    const vis = (k) => [...s.stateAt(k)].map(([id, v]) => `${id}:${v.visible ? 1 : 0}`).join(' ');
    assert.equal(vis(-1), 'a:0 b:0 c:1');
    assert.equal(vis(0), 'a:0 b:0 c:1');
    assert.equal(vis(1), 'a:1 b:0 c:1');
    assert.equal(vis(2), 'a:1 b:1 c:1');
    assert.equal(vis(99), 'a:1 b:1 c:1');
    assert.equal(vis(Infinity), 'a:1 b:1 c:1');
});

test('steps merge in z-order, not in authoring order across elements', () => {
    const s = buildSequence(slide(el('bottom', step({ fx: 'rise' })), el('top', step({ fx: 'zoom' }))));
    assert.deepEqual(s.groups[1].items.map((i) => i.elId), ['bottom']);
    assert.deepEqual(s.groups[2].items.map((i) => i.elId), ['top']);
});

test('with starts together, after starts when the previous ends, both inside the click group', () => {
    const s = buildSequence(slide(
        el('a', step({ dur: 400 })),
        el('b', step({ on: 'with', delay: 100, dur: 300 })),
        el('c', step({ on: 'after', dur: 200 })),
        el('d', step({ on: 'after', delay: 50, dur: 100 })),
    ));
    assert.equal(s.clicks, 1);
    const g = s.groups[1].items;
    assert.deepEqual(g.map((i) => [i.elId, i.start, i.end]), [['a', 0, 400], ['b', 100, 400], ['c', 400, 600], ['d', 650, 750]]);
    assert.equal(s.groups[1].duration, 750);
});

test('leading with or after steps form the entry group, which plays by itself', () => {
    const s = buildSequence(slide(el('a', step({ on: 'with' })), el('b', step({ on: 'after' })), el('c', step({}))));
    assert.equal(s.clicks, 1);
    assert.equal(s.groups[0].items.length, 2);
    assert.equal(s.stateAt(-1).get('a').visible, false);
    assert.equal(s.stateAt(0).get('a').visible, true);
    assert.equal(s.stateAt(0).get('b').visible, true);
    assert.equal(s.stateAt(0).get('c').visible, false);
    assert.equal(s.stateAt(1).get('c').visible, true);
});

test('out steps hide, emphasis keeps visibility, grow persists', () => {
    const s = buildSequence(slide(el('a', step({}), step({ ph: 'emph', fx: 'pulse' }), step({ ph: 'emph', fx: 'grow' }), step({ ph: 'out', fx: 'fade' }))));
    assert.equal(s.clicks, 4);
    assert.equal(s.stateAt(1).get('a').visible, true);
    assert.equal(s.stateAt(2).get('a').scale, 1);
    assert.equal(s.stateAt(3).get('a').scale, GROW_SCALE);
    assert.equal(s.stateAt(4).get('a').visible, false);
});

test('an element that only has an out step starts visible', () => {
    const s = buildSequence(slide(el('a', step({ ph: 'out' }))));
    assert.equal(s.stateAt(0).get('a').visible, true);
    assert.equal(s.stateAt(1).get('a').visible, false);
});

test('hidden elements and junk steps are ignored', () => {
    const s = buildSequence({ els: [{ id: 'h', hide: true, anim: [step({})] }, el('a', { fx: 'nope' }, null, step({ dur: -5, delay: 9e9 }), 'x'), null, { anim: [] }] });
    assert.equal(s.clicks, 1);
    assert.equal(s.groups[1].items[0].dur, 0);
    assert.equal(s.groups[1].items[0].delay, 30000);
    assert.deepEqual(s.elementIds, ['a']);
});

test('bad input does not throw', () => {
    for (const v of [null, undefined, {}, { els: 'x' }, { els: [1, 2] }]) assert.equal(buildSequence(v).clicks, 0);
});

test('determinism: replaying groups one by one equals stateAt for every k', () => {
    const s = buildSequence(slide(
        el('a', step({}), step({ ph: 'out', on: 'click' })),
        el('b', step({ on: 'with' }), step({ ph: 'emph', fx: 'grow' })),
        el('c', step({}), step({ on: 'with', ph: 'out', fx: 'zoom' })),
        el('d'),
    ));
    const live = new Map(s.elementIds.map((id) => [id, s.stateAt(-1).get(id)]));
    const clone = (m) => JSON.stringify([...m]);
    for (let k = 0; k <= s.clicks; k++) {
        for (const item of s.groups[k].items) {
            const st = live.get(item.elId);
            if (item.ph === 'in') st.visible = true;
            else if (item.ph === 'out') st.visible = false;
            else if (item.fx === 'grow') st.scale = GROW_SCALE;
        }
        assert.equal(clone(live), clone(s.stateAt(k)), `step ${k}`);
    }
});

test('ord absent: order is exactly z-order then step index (unchanged behaviour)', () => {
    const s = buildSequence(slide(
        el('a', step({ fx: 'fade' }), step({ fx: 'rise', on: 'with' })),
        el('b', step({ fx: 'zoom' })),
        el('c', step({ fx: 'pop' })),
    ));
    assert.deepEqual(s.groups.flatMap((g) => g.items).map((i) => `${i.elId}:${i.fx}`), ['a:fade', 'a:rise', 'b:zoom', 'c:pop']);
    assert.ok(s.groups.flatMap((g) => g.items).every((i) => !('ord' in i)));
});

test('ord orders steps across elements regardless of layering', () => {
    const s = buildSequence(slide(
        el('bottom', step({ ord: 2 })),
        el('mid', step({ ord: 0 })),
        el('top', step({ ord: 1 })),
    ));
    assert.deepEqual(s.groups.slice(1).map((g) => g.items[0].elId), ['mid', 'top', 'bottom']);
    // layering is untouched: stateAt still uses element ids, not positions
    assert.equal(s.stateAt(1).get('mid').visible, true);
    assert.equal(s.stateAt(1).get('bottom').visible, false);
});

test('ord ties fall back to z index, then step index; steps without ord use their z index as key', () => {
    const s = buildSequence(slide(
        el('a', step({ ord: 1 })),
        el('b', step({ ord: 1 }), step({ ord: 1, fx: 'rise', on: 'with' })),
        el('c', step({})),
    ));
    // c has no ord so its key is its z index (2); a and b share key 1
    assert.deepEqual(s.groups.flatMap((g) => g.items).map((i) => `${i.elId}:${i.fx}`), ['a:fade', 'b:fade', 'b:rise', 'c:fade']);
});

test('ord is clamped to an integer 0..9999 and bad values are ignored', () => {
    const s = buildSequence(slide(el('a', step({ ord: 5.6 }), step({ ord: -3, fx: 'rise' }), step({ ord: 'x', fx: 'zoom' }), step({ ord: 99999, fx: 'pop' }))));
    const items = s.groups.flatMap((g) => g.items);
    assert.deepEqual(items.map((i) => [i.fx, i.ord]), [['rise', 0], ['zoom', undefined], ['fade', 6], ['pop', 9999]]);
});
