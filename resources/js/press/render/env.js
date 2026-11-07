// Small browser environment signals shared by the renderer and the player: reduced motion, font loading epoch,
// and one ResizeObserver for every SlideView on the page (a deck of 100 thumbnails needs one observer, not 100).
import { ref } from 'vue';

export const reducedMotion = ref(false);
export const fontEpoch = ref(0);

if (typeof window !== 'undefined') {
    if (window.matchMedia) {
        const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
        reducedMotion.value = mq.matches;
        const on = (e) => {
            reducedMotion.value = e.matches;
        };
        if (mq.addEventListener) mq.addEventListener('change', on);
        else if (mq.addListener) mq.addListener(on);
    }
    if (document.fonts) {
        // Text measured before a font arrived is measured wrong. Bump the epoch so fitted text measures again.
        document.fonts.addEventListener?.('loadingdone', () => {
            fontEpoch.value++;
        });
        document.fonts.ready?.then(() => {
            fontEpoch.value++;
        });
    }
}

let observer = null;
const callbacks = new WeakMap();

/** Observe an element's content box; the callback gets (width, height). Returns an unobserve function. */
export function observeSize(el, cb) {
    if (typeof ResizeObserver === 'undefined') {
        const r = el.getBoundingClientRect();
        cb(r.width, r.height);
        return () => {};
    }
    if (!observer) {
        observer = new ResizeObserver((entries) => {
            for (const entry of entries) {
                const fn = callbacks.get(entry.target);
                if (!fn) continue;
                const box = entry.contentBoxSize?.[0];
                fn(box ? box.inlineSize : entry.contentRect.width, box ? box.blockSize : entry.contentRect.height);
            }
        });
    }
    callbacks.set(el, cb);
    observer.observe(el);
    const r = el.getBoundingClientRect();
    cb(r.width, r.height);
    return () => {
        callbacks.delete(el);
        observer?.unobserve(el);
    };
}

export function supportsLinearEasing() {
    try {
        return typeof CSS !== 'undefined' && CSS.supports('animation-timing-function', 'linear(0, 1)');
    } catch {
        return false;
    }
}

let uid = 0;
export function nextUid() {
    return ++uid;
}
