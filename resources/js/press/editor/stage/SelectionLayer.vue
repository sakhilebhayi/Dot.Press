<script setup>
// The selection layer: outlines, handles, smart guides, the size readout, the marquee, the drawing preview, other people's
// selections. It is drawn in screen pixels on top of the sheet, so lines stay one pixel wide and handles keep their size at any
// zoom. It never receives the pointer (the stage hit tests in code), so it can not get in the way of text selection or links.
import { computed } from 'vue';
import { corners, rotatePoint, centerOf } from '../core/geometry.js';
import { handleLayout, screenBox, selectionFrame, lineEnds } from './lib/handles.js';
import { isLine } from './lib/hit.js';

const props = defineProps({
    origin: { type: Object, required: true },
    zoom: { type: Number, required: true },
    size: { type: Object, required: true },
    /** selected elements with the live gesture patches already applied */
    elements: { type: Array, default: () => [] },
    /** every element of the slide, to draw hover, marquee and presence outlines */
    slideEls: { type: Array, default: () => [] },
    live: { type: Object, required: true },
    hover: { type: Object, default: () => ({}) },
    showHandles: { type: Boolean, default: true },
    dragging: { type: Boolean, default: false },
    coarse: { type: Boolean, default: false },
    presence: { type: Array, default: () => [] },
    userGuides: { type: Array, default: () => [] },
    hiddenIds: { type: Array, default: () => [] },
});

const pt = (p) => ({ x: props.origin.x + p.x * props.zoom, y: props.origin.y + p.y * props.zoom });
const poly = (box) => corners(box).map(pt).map((p) => `${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' ');

const frame = computed(() => selectionFrame(props.elements.filter((e) => !props.hiddenIds.includes(e.id))));
const layout = computed(() => {
    const f = frame.value;
    if (!f || f.line) return null;
    return handleLayout(screenBox(f.box, props.origin, props.zoom), { coarse: props.coarse });
});
const boxPoints = computed(() => (layout.value ? layout.value.corners.map((p) => `${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' ') : ''));
const topMid = computed(() => {
    const l = layout.value;
    if (!l) return null;
    return { x: (l.corners[0].x + l.corners[1].x) / 2, y: (l.corners[0].y + l.corners[1].y) / 2 };
});
const allLocked = computed(() => props.elements.length > 0 && props.elements.every((e) => e.lock));
const handlesOn = computed(() => props.showHandles && !allLocked.value && !props.dragging);
const handleList = computed(() => (layout.value ? Object.entries(layout.value.handles) : []));
const memberOutlines = computed(() => (props.elements.length > 1 ? props.elements.filter((e) => !props.hiddenIds.includes(e.id)).map((e) => ({ id: e.id, points: poly(e) })) : []));
const lineSel = computed(() => {
    const f = frame.value;
    if (!f?.line) return null;
    const ends = lineEnds(f.el);
    return { s: pt(ends.s), e: pt(ends.e) };
});
const hoverPoly = computed(() => {
    const id = props.hover?.id;
    if (!id || props.elements.some((e) => e.id === id) || props.live.mode) return null;
    const el = props.slideEls.find((e) => e.id === id);
    if (!el) return null;
    return isLine(el) ? { line: true, a: pt(lineEnds(el).s), b: pt(lineEnds(el).e) } : { points: poly(el) };
});
const marqueeOutlines = computed(() => (props.live.marqueeIds || []).map((id) => props.slideEls.find((e) => e.id === id)).filter(Boolean).map((e) => ({ id: e.id, points: poly(e) })));

// ---- guides ------------------------------------------------------------------------------------
const guideLines = computed(() => (props.live.guides || []).map((gd, i) => {
    if (gd.kind === 'spacing') {
        const horizontal = gd.orient === 'h';
        const a = horizontal ? pt({ x: gd.from, y: gd.at }) : pt({ x: gd.at, y: gd.from });
        const b = horizontal ? pt({ x: gd.to, y: gd.at }) : pt({ x: gd.at, y: gd.to });
        return { i, spacing: true, horizontal, x1: a.x, y1: a.y, x2: b.x, y2: b.y };
    }
    const vertical = gd.orient === 'v';
    const to = Number.isFinite(gd.to) ? gd.to : (vertical ? 99999 : 99999);
    const a = vertical ? pt({ x: gd.pos, y: gd.from }) : pt({ x: gd.from, y: gd.pos });
    const b = vertical ? pt({ x: gd.pos, y: to }) : pt({ x: to, y: gd.pos });
    if (gd.kind === 'user') {
        return vertical ? { i, x1: a.x, y1: 0, x2: a.x, y2: props.size.h, user: true } : { i, x1: 0, y1: a.y, x2: props.size.w, y2: a.y, user: true };
    }
    return { i, x1: a.x, y1: a.y, x2: b.x, y2: b.y };
}));
const labelChips = computed(() => (props.live.labels || []).map((l, i) => ({ i, ...pt(l), text: l.text })));

const userGuideLines = computed(() => props.userGuides.map((gd, i) => (gd.orient === 'v'
    ? { i, x1: props.origin.x + gd.pos * props.zoom, y1: 0, x2: props.origin.x + gd.pos * props.zoom, y2: props.size.h }
    : { i, x1: 0, y1: props.origin.y + gd.pos * props.zoom, x2: props.size.w, y2: props.origin.y + gd.pos * props.zoom })));

// ---- marquee, draw preview -----------------------------------------------------------------------
const marquee = computed(() => props.live.marquee);
const drawing = computed(() => {
    const el = props.live.draw;
    if (!el) return null;
    if (isLine(el)) {
        const c = centerOf(el);
        const y = el.y + el.h / 2;
        const s = rotatePoint({ x: el.x, y }, c, el.rot || 0);
        const e = rotatePoint({ x: el.x + el.w, y }, c, el.rot || 0);
        return { line: true, a: pt(s), b: pt(e) };
    }
    const round = el.kind === 'ellipse' || el.kind === 'round';
    return { points: poly(el), round };
});

// ---- other people ----------------------------------------------------------------------------------
const others = computed(() => props.presence.map((p) => {
    const outlines = (p.selection || []).map((id) => props.slideEls.find((e) => e.id === id)).filter(Boolean).map((e) => ({ id: e.id, points: poly(e), tl: pt(corners(e)[0]) }));
    return {
        key: p.client, name: p.name || 'Guest', color: p.color || '#5B6269', outlines, cursor: p.cursor ? pt(p.cursor) : null,
    };
}));

const readout = computed(() => (props.live.readout && props.live.readoutAt ? { text: props.live.readout, ...props.live.readoutAt } : null));
const rotateGlyph = 'M0 -3.2a3.2 3.2 0 1 1 -3.2 3.2';
const hs = computed(() => (props.coarse ? 7.5 : 5)); // half the handle size in pixels
</script>

<template>
    <div class="es-layer" aria-hidden="true">
        <svg class="es-svg" :width="size.w" :height="size.h" :viewBox="`0 0 ${size.w} ${size.h}`">
            <!-- ruler guides placed by the user -->
            <g v-if="userGuideLines.length" class="es-uguides">
                <line v-for="l in userGuideLines" :key="l.i" :x1="l.x1" :y1="l.y1" :x2="l.x2" :y2="l.y2" />
            </g>

            <!-- hover and marquee preview outlines -->
            <template v-if="hoverPoly">
                <line v-if="hoverPoly.line" class="es-halo" :x1="hoverPoly.a.x" :y1="hoverPoly.a.y" :x2="hoverPoly.b.x" :y2="hoverPoly.b.y" />
                <line v-if="hoverPoly.line" class="es-hover" :x1="hoverPoly.a.x" :y1="hoverPoly.a.y" :x2="hoverPoly.b.x" :y2="hoverPoly.b.y" />
                <template v-else>
                    <polygon class="es-halo" :points="hoverPoly.points" />
                    <polygon class="es-hover" :points="hoverPoly.points" />
                </template>
            </template>
            <template v-for="m in marqueeOutlines" :key="m.id">
                <polygon class="es-halo" :points="m.points" />
                <polygon class="es-hover es-hover-on" :points="m.points" />
            </template>

            <!-- members of a multi selection -->
            <template v-for="m in memberOutlines" :key="m.id">
                <polygon class="es-halo" :points="m.points" />
                <polygon class="es-member" :points="m.points" />
            </template>

            <!-- the selection box -->
            <template v-if="layout">
                <polygon class="es-halo" :points="boxPoints" />
                <polygon class="es-box" :class="{ 'es-box-locked': allLocked }" :points="boxPoints" />
                <template v-if="handlesOn">
                    <line class="es-halo" :x1="topMid.x" :y1="topMid.y" :x2="layout.rotate.x" :y2="layout.rotate.y" />
                    <line class="es-stem" :x1="topMid.x" :y1="topMid.y" :x2="layout.rotate.x" :y2="layout.rotate.y" />
                    <circle class="es-knob" :class="{ 'es-on': hover.handle === 'rotate' }" :cx="layout.rotate.x" :cy="layout.rotate.y" :r="coarse ? 9.5 : 6.5" />
                    <path class="es-knob-glyph" :transform="`translate(${layout.rotate.x} ${layout.rotate.y}) scale(${coarse ? 1.4 : 1})`" :d="rotateGlyph" />
                    <g v-for="[h, p] in handleList" :key="h">
                        <rect class="es-hnd-halo" :x="p.x - hs - 1.5" :y="p.y - hs - 1.5" :width="2 * hs + 3" :height="2 * hs + 3" rx="3.5" />
                        <rect class="es-hnd" :class="{ 'es-on': hover.handle === h }" :x="p.x - hs" :y="p.y - hs" :width="2 * hs" :height="2 * hs" rx="2.5" />
                    </g>
                </template>
            </template>

            <!-- a selected line: the line itself and two end handles -->
            <template v-if="lineSel">
                <line class="es-halo" :x1="lineSel.s.x" :y1="lineSel.s.y" :x2="lineSel.e.x" :y2="lineSel.e.y" />
                <line class="es-box" :x1="lineSel.s.x" :y1="lineSel.s.y" :x2="lineSel.e.x" :y2="lineSel.e.y" />
                <template v-if="handlesOn">
                    <circle v-for="(p, k) in { s: lineSel.s, e: lineSel.e }" :key="k" class="es-hnd es-hnd-end" :class="{ 'es-on': hover.handle === 'line-' + k }" :cx="p.x" :cy="p.y" :r="coarse ? 9 : 6" />
                </template>
            </template>

            <!-- smart guides, equal spacing and distance labels in amber -->
            <g class="es-guides">
                <template v-for="l in guideLines" :key="l.i">
                    <line :class="l.user ? 'es-guide-user' : 'es-guide'" :x1="l.x1" :y1="l.y1" :x2="l.x2" :y2="l.y2" />
                    <template v-if="l.spacing">
                        <line v-if="l.horizontal" class="es-guide" :x1="l.x1" :y1="l.y1 - 4" :x2="l.x1" :y2="l.y1 + 4" />
                        <line v-if="l.horizontal" class="es-guide" :x1="l.x2" :y1="l.y2 - 4" :x2="l.x2" :y2="l.y2 + 4" />
                        <line v-if="!l.horizontal" class="es-guide" :x1="l.x1 - 4" :y1="l.y1" :x2="l.x1 + 4" :y2="l.y1" />
                        <line v-if="!l.horizontal" class="es-guide" :x1="l.x2 - 4" :y1="l.y2" :x2="l.x2 + 4" :y2="l.y2" />
                    </template>
                </template>
            </g>

            <!-- the marquee -->
            <rect v-if="marquee" class="es-marquee" :x="marquee.x" :y="marquee.y" :width="marquee.w" :height="marquee.h" />

            <!-- what a drawing tool is about to make -->
            <template v-if="drawing">
                <line v-if="drawing.line" class="es-draw" :x1="drawing.a.x" :y1="drawing.a.y" :x2="drawing.b.x" :y2="drawing.b.y" />
                <polygon v-else class="es-draw" :points="drawing.points" />
            </template>

            <!-- other people -->
            <g v-for="o in others" :key="o.key" :style="{ '--who': o.color }">
                <polygon v-for="s in o.outlines" :key="s.id" class="es-who" :points="s.points" />
                <g v-if="o.cursor" :transform="`translate(${o.cursor.x} ${o.cursor.y})`" class="es-who-cursor">
                    <path d="M0 0L0 15.5L4.4 11.7L7.6 18.6L10.4 17.4L7.2 10.7L13 10.4Z" />
                </g>
            </g>
        </svg>

        <div v-for="c in labelChips" :key="c.i" class="es-chip es-chip-guide" :style="{ transform: `translate(${c.x}px, ${c.y}px) translate(-50%, -50%)` }">{{ c.text }}</div>
        <template v-for="o in others" :key="'n' + o.key">
            <div v-for="s in o.outlines.slice(0, 1)" :key="s.id" class="es-who-name" :style="{ transform: `translate(${s.tl.x}px, ${s.tl.y}px) translateY(-100%)`, '--who': o.color }">{{ o.name }}</div>
            <div v-if="o.cursor" class="es-who-name es-who-name-cursor" :style="{ transform: `translate(${o.cursor.x + 12}px, ${o.cursor.y + 18}px)`, '--who': o.color }">{{ o.name }}</div>
        </template>
        <div v-if="readout" class="es-readout" :style="{ transform: `translate(${readout.x}px, ${readout.y}px)` }">{{ readout.text }}</div>
    </div>
</template>
