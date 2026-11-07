// Text fitting (`fit: "shrink"`). The player scales type down so the text fits the box.
// The search is pure: callers pass a `measure(factor)` function that returns the content height at that scale.
// Results are cached by a key so the search runs once per distinct text and box, never per frame.

export const FIT_MIN = 0.25;
const EPS = 0.5;
const MAX_ITER = 9;

/**
 * Largest factor in [min, 1] at which measure(factor) <= available height.
 * Assumes the content height does not decrease as the factor grows (true for text at a fixed width).
 */
export function fitFactor(measure, available, { min = FIT_MIN, max = 1 } = {}) {
    if (!(available > 0)) return min;
    if (measure(max) <= available + EPS) return max;
    if (measure(min) > available + EPS) return min;
    let lo = min;
    let hi = max;
    for (let i = 0; i < MAX_ITER && hi - lo > 0.004; i++) {
        const mid = (lo + hi) / 2;
        if (measure(mid) <= available + EPS) lo = mid;
        else hi = mid;
    }
    return +lo.toFixed(4);
}

/** Key over everything that changes the measured height. `epoch` is bumped when fonts finish loading. */
export function fitKey(parts, epoch = 0) {
    return [epoch, parts.docKey, parts.w, parts.h, parts.pad, parts.size, parts.weight, parts.font, parts.lh, parts.ls, parts.ps].join('|');
}

/** A tiny LRU cache for fit factors. */
export function createFitCache(limit = 800) {
    const map = new Map();
    return {
        get(key) {
            if (!map.has(key)) return undefined;
            const v = map.get(key);
            map.delete(key);
            map.set(key, v);
            return v;
        },
        set(key, value) {
            map.delete(key);
            map.set(key, value);
            if (map.size > limit) map.delete(map.keys().next().value);
        },
        get size() {
            return map.size;
        },
        clear() {
            map.clear();
        },
    };
}

export const fitCache = createFitCache();
