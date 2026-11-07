import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeTransition, transitionFor, layerFrames, morphPairs, flipFrames, flipOutFrames, shortestAngle, oppositeDir, TRANSITION_TYPES } from '../transitions.js';

const deck = { w: 1920, h: 1080 };

test('normalizeTransition defaults and rejects unknown types', () => {
    assert.equal(normalizeTransition(null), null);
    assert.equal(normalizeTransition({ type: 'spin' }), null);
    assert.deepEqual(normalizeTransition({ type: 'push' }), { type: 'push', dir: 'r', dur: 600, ease: 'inout' });
    assert.equal(normalizeTransition({ type: 'fade', dur: 99999 }).dur, 4000);
    assert.equal(normalizeTransition({ type: 'fade', dir: 'x' }).dir, 'r');
});

test('forward uses the entered slide, backward replays the left slide reversed', () => {
    const a = { transition: { type: 'fade', dir: 'l' } };
    const b = { transition: { type: 'push', dir: 'r' } };
    assert.equal(transitionFor(a, b, 1).type, 'push');
    const back = transitionFor(b, a, -1);
    assert.equal(back.type, 'push');
    assert.equal(back.dir, 'l');
    assert.equal(transitionFor({}, {}, 1), null);
    assert.equal(oppositeDir('u'), 'd');
});

test('every non-morph type has frames for both layers', () => {
    for (const type of TRANSITION_TYPES.filter((t) => t !== 'morph')) for (const dir of ['l', 'r', 'u', 'd']) {
        const f = layerFrames({ type, dir }, deck);
        assert.ok(f.in.length === 2 && f.out.length === 2, `${type} ${dir}`);
        assert.ok(!JSON.stringify(f).includes('NaN'));
    }
});

test('push moves both layers by one full slide, in opposite signs', () => {
    const f = layerFrames({ type: 'push', dir: 'r' }, deck);
    assert.equal(f.in[0].transform, 'translate(1920px, 0px)');
    assert.equal(f.out[1].transform, 'translate(-1920px, 0px)');
    const up = layerFrames({ type: 'push', dir: 'd' }, deck);
    assert.equal(up.in[0].transform, 'translate(0px, 1080px)');
});

test('wipe reveals from the entering side', () => {
    const f = layerFrames({ type: 'wipe', dir: 'r' }, deck);
    assert.equal(f.in[0].clipPath, 'inset(0 0 0 100%)');
    assert.equal(f.in[1].clipPath, 'inset(0 0 0 0%)');
});

test('reveal keeps the entering layer underneath', () => {
    assert.equal(layerFrames({ type: 'reveal', dir: 'r' }, deck).inFront, false);
});

const A = { els: [
    { id: 'title', type: 'text', x: 100, y: 100, w: 800, h: 100, rot: 0, op: 1 },
    { id: 'old', type: 'shape', x: 0, y: 0, w: 10, h: 10 },
    { id: 'pic', type: 'image', morph: 'hero', x: 0, y: 0, w: 200, h: 100, rot: 350 },
    { id: 'gone', type: 'text', hide: true, x: 0, y: 0, w: 1, h: 1 },
] };
const B = { els: [
    { id: 'title', type: 'text', x: 200, y: 300, w: 400, h: 50, rot: 0, op: 0.5 },
    { id: 'new', type: 'shape', x: 0, y: 0, w: 10, h: 10 },
    { id: 'hero2', type: 'image', morph: 'hero', x: 1000, y: 500, w: 400, h: 200, rot: 10 },
    { id: 'gone', type: 'text', x: 0, y: 0, w: 1, h: 1 },
    { id: 'x', type: 'shape', morph: 'title', x: 0, y: 0, w: 1, h: 1 },
] };

test('morphPairs pairs by morph key or id, same type only, ignores hidden elements', () => {
    const pairs = morphPairs(A, B);
    assert.deepEqual(pairs.map((p) => [p.key, p.aId, p.bId]), [['title', 'title', 'title'], ['hero', 'pic', 'hero2']]);
});

test('flip keyframes start at the A geometry and end exactly at the B resting transform', () => {
    const [t] = morphPairs(A, B);
    const [from, to] = flipFrames(t);
    // A box centre is (500, 150); B box is 400x50 so the translate puts its centre there
    assert.equal(from.transform, 'translate(300px, 125px) rotate(0deg) scale(2, 2)');
    assert.equal(from.opacity, 1);
    assert.equal(to.transform, 'translate(200px, 300px) rotate(0deg) scale(1, 1)');
    assert.equal(to.opacity, 0.5);
    const out = flipOutFrames(t);
    assert.equal(out[0].transform, 'translate(100px, 100px) rotate(0deg) scale(1, 1)');
    assert.equal(out[1].opacity, 0);
});

test('rotation takes the short way round', () => {
    assert.equal(shortestAngle(10, 350), -10);
    assert.equal(shortestAngle(350, 10), 370);
    const pair = morphPairs(A, B)[1];
    const [from, to] = flipFrames(pair);
    assert.match(from.transform, /rotate\(-10deg\)/);
    assert.match(to.transform, /rotate\(10deg\)/);
});
