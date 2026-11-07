// Element animation effects for the Web Animations API. Pure: this file only builds keyframes and options;
// runtime.js applies them. Effects animate the inner `.p-fx` wrapper, never the positioned element, so they never
// fight the element's own transform.
import { GROW_SCALE } from './sequence.js';

export const EASING = {
    out: 'cubic-bezier(0.23, 1, 0.32, 1)',
    inout: 'cubic-bezier(0.77, 0, 0.175, 1)',
    springFallback: 'cubic-bezier(0.34, 1.56, 0.64, 1)',
};

let springCache = null;
/** A damped spring sampled into a CSS linear() easing. Overshoots slightly and settles. */
export function springLinear(samples = 28) {
    if (springCache && samples === 28) return springCache;
    const pts = [];
    for (let i = 0; i <= samples; i++) {
        const t = i / samples;
        pts.push(i === samples ? 1 : +(1 - Math.exp(-6 * t) * Math.cos(8 * t)).toFixed(4));
    }
    const css = `linear(${pts.join(', ')})`;
    if (samples === 28) springCache = css;
    return css;
}

/** @param {'out'|'inout'|'spring'} ease @param {boolean} supportsLinear */
export function easingFor(ease, supportsLinear = true) {
    if (ease === 'inout') return EASING.inout;
    if (ease === 'spring') return supportsLinear ? springLinear() : EASING.springFallback;
    return EASING.out;
}

function slideFrames(isIn, dx) {
    const away = { transform: `translateX(${dx}px)`, opacity: 1 };
    const rest = { transform: 'none', opacity: 1 };
    return isIn ? [away, rest] : [rest, away];
}

function tf(t) {
    return { transform: t };
}

/** Distance in slide units an element needs to travel to be fully off the stage on the given side. */
export function offstage(side, el, deck) {
    const x = +el?.x || 0;
    const w = +el?.w || 0;
    const dw = +deck?.w || 1920;
    return side === 'l' ? -(x + w) : dw - x;
}

/**
 * Keyframes and timing for one animation step.
 * @param {{ fx: string, ph: 'in'|'out'|'emph', dur: number, ease: string }} step
 * @param {{ el?: object, deck?: {w:number,h:number}, fromScale?: number }} ctx
 * @returns {{ keyframes: Keyframe[], persistent: boolean }|null} null when the step has no visible motion
 */
export function effectFrames(step, ctx = {}) {
    const { fx, ph } = step;
    const D = 64; // travel distance for rise and drop, in slide units
    const isIn = ph === 'in';
    const isOut = ph === 'out';
    const fadeIn = () => [{ opacity: 0 }, { opacity: 1 }];
    const fadeOut = () => [{ opacity: 1 }, { opacity: 0 }];
    // For `out` the frames run from rest to the away state.
    const enter = (away) => (isIn ? [{ ...away, opacity: 0 }, { transform: 'none', opacity: 1 }] : [{ transform: 'none', opacity: 1 }, { ...away, opacity: 0 }]);

    if (isIn || isOut) {
        switch (fx) {
            case 'fade':
                return { keyframes: isIn ? fadeIn() : fadeOut(), persistent: false };
            case 'rise':
                return { keyframes: enter(tf(`translateY(${D}px)`)), persistent: false };
            case 'drop':
                return { keyframes: enter(tf(`translateY(${-D}px)`)), persistent: false };
            case 'slide-l': {
                const dx = offstage('l', ctx.el, ctx.deck);
                return { keyframes: slideFrames(isIn, dx), persistent: false };
            }
            case 'slide-r': {
                const dx = offstage('r', ctx.el, ctx.deck);
                return { keyframes: slideFrames(isIn, dx), persistent: false };
            }
            case 'zoom':
                return { keyframes: enter(tf('scale(0.4)')), persistent: false };
            case 'wipe':
                return {
                    keyframes: isIn
                        ? [{ clipPath: 'inset(0 100% 0 0)', opacity: 1 }, { clipPath: 'inset(0 0% 0 0)', opacity: 1 }]
                        : [{ clipPath: 'inset(0 0% 0 0)', opacity: 1 }, { clipPath: 'inset(0 0 0 100%)', opacity: 1 }],
                    persistent: false,
                };
            case 'pop':
                return {
                    keyframes: isIn
                        ? [{ transform: 'scale(0)', opacity: 0 }, { transform: 'scale(1.08)', opacity: 1, offset: 0.7 }, { transform: 'scale(1)', opacity: 1 }]
                        : [{ transform: 'scale(1)', opacity: 1 }, { transform: 'scale(1.08)', opacity: 1, offset: 0.3 }, { transform: 'scale(0)', opacity: 0 }],
                    persistent: false,
                };
            case 'grow':
                return { keyframes: enter(tf('scale(0.6)')), persistent: false };
            case 'pulse':
            case 'spin':
            default:
                // pulse and spin are emphasis effects; used as in or out they behave like a fade.
                return { keyframes: isIn ? fadeIn() : fadeOut(), persistent: false };
        }
    }

    // emphasis
    switch (fx) {
        case 'pulse':
            return { keyframes: [{ transform: 'scale(1)' }, { transform: 'scale(1.1)', offset: 0.45 }, { transform: 'scale(1)' }], persistent: false };
        case 'spin':
            return { keyframes: [{ transform: 'rotate(0deg)' }, { transform: 'rotate(360deg)' }], persistent: false };
        case 'grow':
            return { keyframes: [{ transform: `scale(${ctx.fromScale || 1})` }, { transform: `scale(${GROW_SCALE})` }], persistent: true };
        case 'fade':
            return { keyframes: [{ opacity: 1 }, { opacity: 0.35, offset: 0.5 }, { opacity: 1 }], persistent: false };
        case 'rise':
        case 'drop': {
            const d = fx === 'rise' ? -16 : 16;
            return { keyframes: [{ transform: 'translateY(0)' }, { transform: `translateY(${d}px)`, offset: 0.5 }, { transform: 'translateY(0)' }], persistent: false };
        }
        case 'zoom':
        case 'pop':
            return { keyframes: [{ transform: 'scale(1)' }, { transform: 'scale(1.18)', offset: 0.5 }, { transform: 'scale(1)' }], persistent: false };
        default:
            return { keyframes: [{ transform: 'translateX(0)' }, { transform: 'translateX(14px)', offset: 0.25 }, { transform: 'translateX(-14px)', offset: 0.75 }, { transform: 'translateX(0)' }], persistent: false };
    }
}

/**
 * Under prefers-reduced-motion all motion goes away. Entrances and exits become an instant change (duration 0),
 * or a short fade when `fade` is true. Emphasis effects are skipped entirely.
 */
export function reduceFrames(step, frames, { fade = true } = {}) {
    if (step.ph === 'emph') return null;
    if (!fade) return null;
    return { keyframes: step.ph === 'in' ? [{ opacity: 0 }, { opacity: 1 }] : [{ opacity: 1 }, { opacity: 0 }], persistent: false, dur: 160 };
}

/** Build the final WAAPI call arguments for a step. */
export function planStep(step, ctx = {}, { reduced = false, supportsLinear = true } = {}) {
    let frames = effectFrames(step, ctx);
    let dur = step.dur;
    if (reduced) {
        const r = reduceFrames(step, frames);
        if (!r) return null;
        frames = r;
        dur = Math.min(r.dur, step.dur || r.dur);
    }
    return {
        keyframes: frames.keyframes,
        options: { duration: dur, delay: 0, easing: reduced ? 'linear' : easingFor(step.ease, supportsLinear), fill: 'both' },
        persistent: frames.persistent,
    };
}
