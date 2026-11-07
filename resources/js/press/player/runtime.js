// DOM side of playback: runs the keyframes the pure modules planned with the Web Animations API.
// Each run returns a handle { finished, finish(), cancel() }. `finish()` jumps to the end state immediately,
// which is what lets the player skip around without ever showing a half played step. Callers commit the end
// state through Vue first, wait for it to render, then call release() so no animation is left holding styles.
import { planStep } from './effects.js';
import { flipFrames, flipOutFrames, layerFrames, morphPairs, reducedFrames, samePairContent, transitionTiming } from './transitions.js';

function settle(anims) {
    return Promise.allSettled(anims.map((a) => a.finished)).then(() => undefined);
}

function handle(anims, extra = {}) {
    let done = false;
    const finished = settle(anims).then(() => {
        done = true;
    });
    return {
        finished,
        get done() {
            return done;
        },
        finish() {
            for (const a of anims) {
                try {
                    a.finish();
                } catch {
                    /* an animation that never started has nothing to finish */
                }
            }
        },
        cancel() {
            for (const a of anims) a.cancel();
            extra.onCancel?.();
        },
        release() {
            for (const a of anims) a.cancel();
            extra.onCancel?.();
        },
        anims,
    };
}

/**
 * Play (or un-play) one click group.
 * @param {Map<string, Element>} nodes element wrappers by element id (SlideView.nodes())
 * @param {{ items: object[], duration: number }} group
 * @param {{ slide: object, deck: object, reduced: boolean, supportsLinear: boolean, reverse?: boolean, fromState?: Map }} opts
 */
export function playGroup(nodes, group, opts) {
    const els = new Map((opts.slide?.els || []).map((e) => [e.id, e]));
    const anims = [];
    for (const item of group.items) {
        const wrapper = nodes.get(item.elId);
        const node = wrapper?.querySelector(':scope > .p-fx');
        if (!node || !node.animate) continue;
        const plan = planStep(item, { el: els.get(item.elId), deck: opts.deck, fromScale: opts.fromState?.get(item.elId)?.scale || 1 }, { reduced: opts.reduced, supportsLinear: opts.supportsLinear });
        if (!plan) continue;
        const timing = { ...plan.options };
        if (opts.reduced) timing.delay = 0;
        else if (opts.reverse) {
            timing.direction = 'reverse';
            timing.delay = Math.max(0, group.duration - item.end);
        } else timing.delay = item.start;
        anims.push(node.animate(plan.keyframes, timing));
    }
    return handle(anims);
}

/**
 * Run a slide transition between two layer elements.
 * @param {{ outEl: HTMLElement, inEl: HTMLElement, outNodes: Map, inNodes: Map, from: object, to: object, t: object, deck: object, reduced: boolean, supportsLinear: boolean }} o
 */
export function runTransition(o) {
    const { outEl, inEl, t } = o;
    const anims = [];
    const cleanups = [];
    const style = (el, prop, value) => {
        const prev = el.style[prop];
        el.style[prop] = value;
        cleanups.push(() => (el.style[prop] = prev));
    };
    inEl.style.visibility = 'visible';
    cleanups.push(() => (inEl.style.visibility = ''));
    const timing = transitionTiming(t, o.supportsLinear, o.reduced);

    if (t.type === 'morph' && !o.reduced) {
        style(inEl, 'zIndex', '2');
        style(outEl, 'zIndex', '1');
        const pairs = morphPairs(o.from, o.to);
        const pairedIn = new Set(pairs.map((p) => p.bId));
        const pairedOut = new Set(pairs.map((p) => p.aId));
        const inBg = inEl.querySelector('.ps-bg');
        if (inBg) anims.push(inBg.animate([{ opacity: 0 }, { opacity: 1 }], { ...timing, easing: 'linear' }));
        const fadeTiming = { ...timing, easing: 'linear' };
        for (const [id, node] of o.inNodes) {
            if (pairedIn.has(id)) continue;
            anims.push(node.animate([{ opacity: 0 }, { opacity: node.style.opacity === '' ? 1 : +node.style.opacity }], { ...fadeTiming, delay: Math.round(t.dur * 0.3), duration: Math.max(1, Math.round(t.dur * 0.7)) }));
        }
        for (const [id, node] of o.outNodes) {
            if (pairedOut.has(id)) continue;
            anims.push(node.animate([{ opacity: node.style.opacity === '' ? 1 : +node.style.opacity }, { opacity: 0 }], { ...fadeTiming, duration: Math.max(1, Math.round(t.dur * 0.5)) }));
        }
        const bEls = new Map((o.to?.els || []).map((e) => [e.id, e]));
        const aEls = new Map((o.from?.els || []).map((e) => [e.id, e]));
        for (const pair of pairs) {
            const bNode = o.inNodes.get(pair.bId);
            const aNode = o.outNodes.get(pair.aId);
            if (!bNode || !aNode) continue;
            const same = samePairContent(aEls.get(pair.aId), bEls.get(pair.bId));
            const frames = flipFrames(pair);
            if (same) {
                style(aNode, 'visibility', 'hidden');
                anims.push(bNode.animate(frames, timing));
            } else {
                // content changed: the old element travels and fades out underneath while the new one fades in on top
                anims.push(aNode.animate(flipOutFrames(pair), timing));
                anims.push(bNode.animate([{ ...frames[0], opacity: 0 }, frames[1]], { ...timing, easing: timing.easing }));
            }
        }
        return handle(anims, { onCancel: () => cleanups.splice(0).forEach((fn) => fn()) });
    }

    const frames = o.reduced || t.type === 'morph' ? reducedFrames() : layerFrames(t);
    style(inEl, 'zIndex', frames.inFront ? '2' : '1');
    style(outEl, 'zIndex', frames.inFront ? '1' : '2');
    anims.push(inEl.animate(frames.in, timing));
    anims.push(outEl.animate(frames.out, timing));
    return handle(anims, { onCancel: () => cleanups.splice(0).forEach((fn) => fn()) });
}
