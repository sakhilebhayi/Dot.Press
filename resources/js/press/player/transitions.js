// Slide transitions (spec section 5). Pure planning: this file turns a transition description and the two slides
// into keyframes; runtime.js runs them.
//
// Direction: `dir` is the side the incoming slide enters from ("l" from the left edge, "r" from the right edge,
// "u" from the top, "d" from the bottom). The default is "r", so slides travel right to left as in a book.
// Going backwards plays the transition of the slide being left, with the direction flipped.
import { easingFor } from './effects.js';

export const TRANSITION_TYPES = ['fade', 'push', 'wipe', 'reveal', 'zoom', 'morph'];
export const DIRS = ['l', 'r', 'u', 'd'];
export const DEFAULT_TRANSITION_MS = 600;

export function normalizeTransition(t) {
    if (!t || typeof t !== 'object' || !TRANSITION_TYPES.includes(t.type)) return null;
    const dur = Math.round(+t.dur);
    return {
        type: t.type,
        dir: DIRS.includes(t.dir) ? t.dir : 'r',
        dur: Number.isFinite(dur) ? Math.min(4000, Math.max(0, dur)) : DEFAULT_TRANSITION_MS,
        ease: ['out', 'inout', 'spring'].includes(t.ease) ? t.ease : 'inout',
    };
}

export function oppositeDir(d) {
    return { l: 'r', r: 'l', u: 'd', d: 'u' }[d] || 'l';
}

/** Which transition runs for a move, or null for an instant cut. */
export function transitionFor(from, to, direction) {
    if (!from || !to) return null;
    if (direction >= 0) return normalizeTransition(to.transition);
    const t = normalizeTransition(from.transition);
    return t ? { ...t, dir: oppositeDir(t.dir) } : null;
}

function offsetFor(dir) {
    // translate, in percent of the layer, that places a layer just outside the stage on the side `dir`
    switch (dir) {
        case 'l':
            return [-100, 0];
        case 'u':
            return [0, -100];
        case 'd':
            return [0, 100];
        default:
            return [100, 0];
    }
}

function insetFor(dir, amount) {
    // a clip-path inset that clips `amount` percent from the side opposite `dir`: at 0 the whole slide shows,
    // at 100 nothing does, and shrinking it grows the visible area outward from the `dir` edge
    const a = `${amount}%`;
    switch (dir) {
        case 'l':
            return `inset(0 ${a} 0 0)`;
        case 'u':
            return `inset(0 0 ${a} 0)`;
        case 'd':
            return `inset(${a} 0 0 0)`;
        default:
            return `inset(0 0 0 ${a})`;
    }
}

/**
 * Keyframes for the two layers of a non-morph transition. Layer transforms are in percent of the layer, so they
 * do not depend on the screen size.
 * @returns {{ out: Keyframe[], in: Keyframe[], inFront: boolean }}
 *   `out` runs on the leaving layer, `in` on the entering layer. `inFront` says whether the entering layer is above.
 */
export function layerFrames(t) {
    const [ox, oy] = offsetFor(t.dir);
    switch (t.type) {
        case 'push':
            return {
                out: [{ transform: 'translate(0%, 0%)' }, { transform: `translate(${-ox}%, ${-oy}%)` }],
                in: [{ transform: `translate(${ox}%, ${oy}%)` }, { transform: 'translate(0%, 0%)' }],
                inFront: true,
            };
        case 'wipe':
            return { out: [{ opacity: 1 }, { opacity: 1 }], in: [{ clipPath: insetFor(t.dir, 100) }, { clipPath: insetFor(t.dir, 0) }], inFront: true };
        case 'reveal':
            // the leaving layer slides off the stage on top of the entering layer
            return { out: [{ transform: 'translate(0%, 0%)' }, { transform: `translate(${-ox}%, ${-oy}%)` }], in: [{ opacity: 1 }, { opacity: 1 }], inFront: false };
        case 'zoom':
            return {
                out: [{ transform: 'scale(1)', opacity: 1 }, { transform: 'scale(1.18)', opacity: 0 }],
                in: [{ transform: 'scale(0.84)', opacity: 0 }, { transform: 'scale(1)', opacity: 1 }],
                inFront: true,
            };
        default:
            return { out: [{ opacity: 1 }, { opacity: 1 }], in: [{ opacity: 0 }, { opacity: 1 }], inFront: true };
    }
}

/** Reduced motion: no travel, a short fade (or nothing for a zero duration). */
export function reducedFrames() {
    return { out: [{ opacity: 1 }, { opacity: 1 }], in: [{ opacity: 0 }, { opacity: 1 }], inFront: true };
}

export function transitionTiming(t, supportsLinear = true, reduced = false) {
    return { duration: reduced ? Math.min(160, t.dur) : t.dur, easing: reduced ? 'linear' : easingFor(t.ease, supportsLinear), fill: 'both' };
}

// ---- morph ----

const num = (v, d = 0) => (Number.isFinite(+v) ? +v : d);

function geom(el) {
    return { x: num(el.x), y: num(el.y), w: Math.max(1, num(el.w, 1)), h: Math.max(1, num(el.h, 1)), rot: num(el.rot), op: el.op == null ? 1 : Math.min(1, Math.max(0, num(el.op, 1))) };
}

/**
 * Elements present on both slides with the same morph key (`morph`, defaulting to `id`) and the same type.
 * @returns {{ key: string, aId: string, bId: string, a: object, b: object }[]}
 */
export function morphPairs(slideA, slideB) {
    const keyOf = (el) => (typeof el.morph === 'string' && el.morph ? el.morph : el.id);
    const mapA = new Map();
    for (const el of slideA?.els || []) if (el && !el.hide && typeof el.id === 'string') mapA.set(keyOf(el), el);
    const seen = new Set();
    const pairs = [];
    for (const el of slideB?.els || []) {
        if (!el || el.hide || typeof el.id !== 'string') continue;
        const key = keyOf(el);
        const a = mapA.get(key);
        if (!a || seen.has(key) || a.type !== el.type) continue;
        seen.add(key);
        pairs.push({ key, aId: a.id, bId: el.id, a: geom(a), b: geom(el) });
    }
    return pairs;
}

/** The shortest signed rotation from degrees a to b, as an absolute target near a. */
export function shortestAngle(a, b) {
    const d = ((((b - a) % 360) + 540) % 360) - 180;
    return a + d;
}

/**
 * FLIP keyframes for the entering element of a pair. The element's own resting transform is
 * `translate(x, y) rotate(rot)` with transform-origin at its centre, so the first frame is the pair's A geometry
 * expressed in B's box: the same centre, rotation and scale ratio.
 */
export function flipFrames(pair) {
    const { a, b } = pair;
    const rotA = shortestAngle(b.rot, a.rot);
    const acx = a.x + a.w / 2;
    const acy = a.y + a.h / 2;
    const from = {
        transform: `translate(${+(acx - b.w / 2).toFixed(3)}px, ${+(acy - b.h / 2).toFixed(3)}px) rotate(${+rotA.toFixed(3)}deg) scale(${+(a.w / b.w).toFixed(5)}, ${+(a.h / b.h).toFixed(5)})`,
        opacity: a.op,
    };
    const to = { transform: `translate(${+b.x.toFixed(3)}px, ${+b.y.toFixed(3)}px) rotate(${+b.rot.toFixed(3)}deg) scale(1, 1)`, opacity: b.op };
    return [from, to];
}

/** The same in reverse for the leaving element: from A's own box to B's geometry, fading out in the first part. */
export function flipOutFrames(pair) {
    const { a, b } = pair;
    const rotB = shortestAngle(a.rot, b.rot);
    const bcx = b.x + b.w / 2;
    const bcy = b.y + b.h / 2;
    return [
        { transform: `translate(${+a.x.toFixed(3)}px, ${+a.y.toFixed(3)}px) rotate(${+a.rot.toFixed(3)}deg) scale(1, 1)`, opacity: a.op },
        { transform: `translate(${+(bcx - a.w / 2).toFixed(3)}px, ${+(bcy - a.h / 2).toFixed(3)}px) rotate(${+rotB.toFixed(3)}deg) scale(${+(b.w / a.w).toFixed(5)}, ${+(b.h / a.h).toFixed(5)})`, opacity: 0 },
    ];
}

/** True when two paired elements look the same apart from their geometry, so a morph can move one element. */
export function samePairContent(a, b) {
    const strip = (el) => {
        const { id, x, y, w, h, rot, op, name, morph, anim, group, origin, lock, hide, ...rest } = el || {};
        return JSON.stringify(rest);
    };
    return strip(a) === strip(b);
}
