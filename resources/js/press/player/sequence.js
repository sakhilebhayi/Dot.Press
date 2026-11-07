// Playback sequence builder (spec section 7). Pure, no DOM.
//
// The slide's sequence is the `anim` steps of its elements merged in z-order (array order, bottom first; within one
// element in the order written) and grouped into clicks. A step may carry an optional integer `ord` (0..9999): the
// merge then sorts by `ord` when present (else the element's z index), then by z index, then by step index, so users
// can reorder the sequence without changing layering. Without any `ord` the order is exactly the z-order above.
//   click  starts a new group (one press of Next plays one group)
//   with   starts together with the previous step
//   after  starts when the previous step ends
// Steps before the first `click` belong to group 0, the "entry group", which plays by itself when the slide appears.
//
// Step numbering used everywhere in the player:
//   -1   nothing has happened yet (initial state: elements that have an `in` step are hidden)
//    0   the entry group has played (the state a viewer sees when a slide has settled)
//    k   k click groups have played
//   groups.length - 1 === clicks is the final step.
//
// stateAt(k) is a pure function of the slide, so jumping to slide N step K produces exactly the picture
// that playing there produces.

export const PHASES = ['in', 'out', 'emph'];
export const EFFECTS = ['fade', 'rise', 'drop', 'slide-l', 'slide-r', 'zoom', 'wipe', 'pop', 'pulse', 'spin', 'grow'];
export const ON = ['click', 'with', 'after'];
export const EASES = ['out', 'inout', 'spring'];
export const GROW_SCALE = 1.15;

const clampInt = (v, lo, hi, def) => {
    const n = Math.round(+v);
    return Number.isFinite(n) ? Math.min(hi, Math.max(lo, n)) : def;
};

/** Clean one raw step or return null when it cannot be played. */
export function normalizeStep(raw, elId, index) {
    if (!raw || typeof raw !== 'object') return null;
    const ph = PHASES.includes(raw.ph) ? raw.ph : 'in';
    if (!EFFECTS.includes(raw.fx)) return null;
    const out = {
        id: typeof raw.id === 'string' && raw.id ? raw.id : `${elId}:${index}`,
        elId,
        ph,
        fx: raw.fx,
        on: ON.includes(raw.on) ? raw.on : 'click',
        delay: clampInt(raw.delay, 0, 30000, 0),
        dur: clampInt(raw.dur, 0, 20000, 500),
        ease: EASES.includes(raw.ease) ? raw.ease : 'out',
    };
    if (raw.ord !== undefined && raw.ord !== null && Number.isFinite(+raw.ord)) out.ord = clampInt(raw.ord, 0, 9999, 0);
    return out;
}

/** Sort key of a step: `ord` when present, else the element's z index. */
export const orderKey = (step, z) => (step.ord !== undefined ? step.ord : z);

/** Play order of normalised steps carrying their z index (`z`) and step index (`i`). Stable, pure. */
export function sortSteps(entries) {
    return entries.slice().sort((a, b) => orderKey(a.step, a.z) - orderKey(b.step, b.z) || a.z - b.z || a.i - b.i);
}

/**
 * @param {{ els?: object[] }} slide
 * @returns {{
 *   clicks: number,
 *   groups: { index: number, items: object[], duration: number }[],
 *   hasAnimations: boolean,
 *   stateAt: (k: number) => Map<string, { visible: boolean, scale: number }>,
 *   elementIds: string[]
 * }}
 */
export function buildSequence(slide) {
    const els = Array.isArray(slide?.els) ? slide.els : [];
    const entries = [];
    const startsHidden = new Set();
    const elementIds = [];
    els.forEach((el, z) => {
        if (!el || typeof el !== 'object' || typeof el.id !== 'string' || el.hide) return;
        elementIds.push(el.id);
        if (!Array.isArray(el.anim)) return;
        el.anim.slice(0, 40).forEach((raw, i) => {
            const step = normalizeStep(raw, el.id, i);
            if (!step) return;
            entries.push({ step, z, i });
            if (step.ph === 'in') startsHidden.add(el.id);
        });
    });
    const flat = sortSteps(entries).map((e) => e.step);

    const groups = [{ index: 0, items: [], duration: 0 }];
    let prev = null;
    for (const step of flat) {
        let group = groups[groups.length - 1];
        let base = 0;
        if (step.on === 'click') {
            group = { index: groups.length, items: [], duration: 0 };
            groups.push(group);
        } else if (prev && step.on === 'with') {
            base = prev.base;
        } else if (prev && step.on === 'after') {
            base = prev.base + prev.delay + prev.dur;
        }
        const item = { ...step, group: group.index, base, start: base + step.delay, end: base + step.delay + step.dur };
        group.items.push(item);
        group.duration = Math.max(group.duration, item.end);
        prev = item;
    }

    const clicks = groups.length - 1;

    const stateAt = (k) => {
        const upto = k === Infinity || k > clicks ? clicks : Math.floor(k);
        const out = new Map();
        for (const id of elementIds) out.set(id, { visible: !startsHidden.has(id), scale: 1 });
        for (let g = 0; g <= upto; g++) {
            for (const item of groups[g].items) {
                const s = out.get(item.elId);
                if (!s) continue;
                if (item.ph === 'in') s.visible = true;
                else if (item.ph === 'out') s.visible = false;
                else if (item.fx === 'grow') s.scale = GROW_SCALE;
            }
        }
        return out;
    };

    return { clicks, groups, hasAnimations: flat.length > 0, stateAt, elementIds };
}

/** Total time one group takes, in ms. */
export function groupDuration(group) {
    return group ? group.duration : 0;
}
