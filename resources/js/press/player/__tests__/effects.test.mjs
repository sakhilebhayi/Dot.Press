import test from 'node:test';
import assert from 'node:assert/strict';
import { effectFrames, planStep, springLinear, easingFor, reduceFrames, offstage } from '../effects.js';
import { EFFECTS, PHASES } from '../sequence.js';

const ctx = { el: { x: 400, y: 100, w: 300, h: 200 }, deck: { w: 1920, h: 1080 } };

test('every effect in every phase yields valid frames', () => {
    for (const fx of EFFECTS) for (const ph of PHASES) {
        const f = effectFrames({ fx, ph }, ctx);
        assert.ok(f.keyframes.length >= 2, `${fx} ${ph}`);
        assert.ok(!JSON.stringify(f).includes('NaN'));
    }
});

test('in effects start invisible or displaced and end at rest; out effects are the reverse', () => {
    for (const fx of ['fade', 'rise', 'drop', 'zoom', 'pop', 'wipe', 'slide-l', 'slide-r', 'grow']) {
        const inn = effectFrames({ fx, ph: 'in' }, ctx).keyframes;
        const out = effectFrames({ fx, ph: 'out' }, ctx).keyframes;
        const last = inn[inn.length - 1];
        assert.ok(last.transform === 'none' || last.transform === 'scale(1)' || last.opacity === 1 || /inset\(0 0% 0 0\)/.test(last.clipPath || ''), `${fx} in ends at rest`);
        const firstOut = out[0];
        assert.ok(firstOut.transform === 'none' || firstOut.transform === 'scale(1)' || firstOut.opacity === 1 || /inset\(0 0% 0 0\)/.test(firstOut.clipPath || ''), `${fx} out starts at rest`);
    }
});

test('slide effects travel exactly off stage', () => {
    assert.equal(offstage('l', ctx.el, ctx.deck), -700);
    assert.equal(offstage('r', ctx.el, ctx.deck), 1520);
    assert.match(effectFrames({ fx: 'slide-l', ph: 'in' }, ctx).keyframes[0].transform, /-700px/);
});

test('grow emphasis persists and starts from the current scale', () => {
    const f = effectFrames({ fx: 'grow', ph: 'emph' }, { ...ctx, fromScale: 1 });
    assert.equal(f.persistent, true);
    assert.match(f.keyframes[1].transform, /1\.15/);
    assert.equal(effectFrames({ fx: 'pulse', ph: 'emph' }, ctx).persistent, false);
});

test('easings: spring uses linear() when supported and a cubic approximation otherwise', () => {
    assert.match(easingFor('spring', true), /^linear\(/);
    assert.match(easingFor('spring', false), /^cubic-bezier/);
    assert.match(easingFor('out'), /^cubic-bezier/);
    const v = springLinear().slice(7, -1).split(',').map(Number);
    assert.equal(v[0], 0);
    assert.equal(v[v.length - 1], 1);
    assert.ok(Math.max(...v) > 1 && Math.max(...v) < 1.3, 'overshoots a little');
});

test('reduced motion: entrances and exits become a short fade, emphasis is skipped, no travel', () => {
    for (const fx of EFFECTS) {
        assert.equal(planStep({ fx, ph: 'emph', dur: 800, ease: 'spring' }, ctx, { reduced: true }), null);
        const p = planStep({ fx, ph: 'in', dur: 800, ease: 'spring' }, ctx, { reduced: true });
        assert.ok(p.options.duration <= 160);
        assert.deepEqual(p.keyframes, [{ opacity: 0 }, { opacity: 1 }]);
        assert.equal(p.options.easing, 'linear');
    }
    assert.equal(reduceFrames({ ph: 'emph' }, {}), null);
});

test('planStep keeps the configured duration and fill', () => {
    const p = planStep({ fx: 'rise', ph: 'in', dur: 420, ease: 'inout' }, ctx);
    assert.equal(p.options.duration, 420);
    assert.equal(p.options.fill, 'both');
});
