// Deck navigation as a pure state machine. A position is { slide, step }: `slide` indexes the slides array,
// `step` is the animation step (see sequence.js: 0 means the entry group has played, k means k clicks).
// Hidden slides are skipped by every movement but can be jumped to with an explicit index.
import { buildSequence } from './sequence.js';

export function createPlan(slides, deck = {}) {
    const list = Array.isArray(slides) ? slides : [];
    const seqs = list.map((s) => buildSequence(s));
    const visible = list.map((s, i) => (s && !s.hidden ? i : -1)).filter((i) => i >= 0);
    // A deck with only hidden slides still has to show something.
    const order = visible.length ? visible : list.map((_, i) => i);
    const pos = (slide, step) => ({ slide, step });
    const clicksOf = (i) => (seqs[i] ? seqs[i].clicks : 0);

    const api = {
        deck,
        slides: list,
        seqs,
        order,
        count: list.length,
        clicksOf,
        first: () => pos(order[0] ?? 0, 0),
        last: () => pos(order[order.length - 1] ?? 0, clicksOf(order[order.length - 1] ?? 0)),
        indexInOrder: (slide) => order.indexOf(slide),
        isLast: (p) => order.length === 0 || (p.slide === order[order.length - 1] && p.step >= clicksOf(p.slide)),
        isFirst: (p) => order.length === 0 || (p.slide === order[0] && p.step <= 0),
        /** Next click, or the next visible slide when the slide has no clicks left. */
        next(p) {
            if (p.step < clicksOf(p.slide)) return pos(p.slide, p.step + 1);
            return api.nextSlide(p);
        },
        /** Previous click, or the end of the previous visible slide. */
        prev(p) {
            if (p.step > 0) return pos(p.slide, p.step - 1);
            const i = nearestBefore(order, p.slide);
            return i < 0 ? pos(p.slide, 0) : pos(order[i], clicksOf(order[i]));
        },
        nextSlide(p) {
            const i = nearestAfter(order, p.slide);
            return i < 0 ? pos(p.slide, p.step) : pos(order[i], 0);
        },
        prevSlide(p) {
            const i = nearestBefore(order, p.slide);
            return i < 0 ? pos(p.slide, 0) : pos(order[i], 0);
        },
        /** Jump to a slide by 1 based number as printed on the thumbnails. Hidden targets land on the next visible slide. */
        jumpNumber(n) {
            if (!list.length) return pos(0, 0);
            const idx = Math.min(list.length, Math.max(1, Math.floor(+n) || 1)) - 1;
            if (order.includes(idx)) return pos(idx, 0);
            const after = nearestAfter(order, idx);
            return after >= 0 ? pos(order[after], 0) : pos(order[order.length - 1], 0);
        },
        goto(slide, step = 0) {
            const s = Math.min(list.length - 1, Math.max(0, Math.floor(+slide) || 0));
            return pos(s, Math.min(clicksOf(s), Math.max(0, Math.floor(+step) || 0)));
        },
        clamp(p) {
            return api.goto(p?.slide, p?.step);
        },
        /** 0..1 progress through the visible slides. */
        progress(p) {
            if (order.length <= 1) return 1;
            const i = Math.max(0, order.indexOf(p.slide));
            const c = clicksOf(p.slide);
            const within = c > 0 ? Math.min(1, p.step / (c + 1)) : 0;
            return Math.min(1, (i + within) / (order.length - 1 + 0.0001));
        },
        /** Where the slide shows as "n of total" counting visible slides. */
        number(p) {
            return { n: Math.max(0, order.indexOf(p.slide)) + 1, of: order.length };
        },
        /** Next position as the audience would see it after one Next press, or null at the end. */
        peekNext(p) {
            const n = api.next(p);
            return n.slide === p.slide && n.step === p.step ? null : n;
        },
    };
    return api;
}

function nearestAfter(order, slide) {
    for (let i = 0; i < order.length; i++) if (order[i] > slide) return i;
    return -1;
}

function nearestBefore(order, slide) {
    for (let i = order.length - 1; i >= 0; i--) if (order[i] < slide) return i;
    return -1;
}

export function samePos(a, b) {
    return !!a && !!b && a.slide === b.slide && a.step === b.step;
}
