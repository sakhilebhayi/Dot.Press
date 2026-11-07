<script setup>
// Crop mode for a picture. The whole source image is shown dimmed behind the frame; the frame shows the bright window.
// Drag inside to move the picture in the frame, wheel or +/- to scale it, drag the frame handles to change what the frame
// shows (the picture stays where it is). Enter commits, Escape cancels. All the maths is in lib/crop.js.
import {
    computed, onMounted, ref, shallowRef, watch,
} from 'vue';
import { adjustFilter, maskClip } from '../../render/geometry.js';
import { resolveSrc } from '../../render/media.js';
import { normalizeTheme } from '../../render/theme.js';
import { rotatePoint, centerOf } from '../core/geometry.js';
import { cursorFor } from './lib/handles.js';
import {
    visibleWindow, imageRect, panWindow, zoomWindow, resizeWindow, commitCrop,
} from './lib/crop.js';

const props = defineProps({
    el: { type: Object, required: true },
    assets: { type: Object, default: () => ({}) },
    deck: { type: Object, required: true },
    zoom: { type: Number, required: true },
    origin: { type: Object, required: true },
    vp: { type: Object, required: true },
});
const emit = defineEmits(['done']);

const src = computed(() => resolveSrc(props.el.src, props.assets));
const nat = ref(src.value?.w && src.value?.h ? { w: src.value.w, h: src.value.h } : null);
const box0 = { x: props.el.x, y: props.el.y, w: props.el.w, h: props.el.h, rot: props.el.rot || 0 };
const box = shallowRef({ ...box0 });
const win = ref(visibleWindow({ crop: props.el.crop, nat: nat.value, box: box0, fit: props.el.fit === 'fill' ? 'fill' : 'cover' }));
let finished = false;

onMounted(() => {
    if (!nat.value && src.value?.url) {
        const img = new Image();
        img.onload = () => {
            nat.value = { w: img.naturalWidth, h: img.naturalHeight };
            win.value = visibleWindow({ crop: props.el.crop, nat: nat.value, box: box0, fit: props.el.fit === 'fill' ? 'fill' : 'cover' });
        };
        img.src = src.value.url;
    }
});
watch(src, () => {});

const natural = computed(() => nat.value || { w: box.value.w, h: box.value.h });
const rect = computed(() => imageRect(win.value, natural.value, box.value));
const frameStyle = computed(() => ({
    width: `${box.value.w}px`,
    height: `${box.value.h}px`,
    transform: `translate(${box.value.x}px, ${box.value.y}px)${box.value.rot ? ` rotate(${box.value.rot}deg)` : ''}`,
}));
const imgStyle = computed(() => ({
    left: `${rect.value.left}px`, top: `${rect.value.top}px`, width: `${rect.value.width}px`, height: `${rect.value.height}px`,
}));
const brightStyle = computed(() => ({ ...imgStyle.value, filter: adjustFilter(props.el.adj) || undefined }));
const flip = computed(() => {
    const x = props.el.flipH ? -1 : 1;
    const y = props.el.flipV ? -1 : 1;
    return x === 1 && y === 1 ? undefined : `scale(${x}, ${y})`;
});
const windowStyle = computed(() => {
    const clip = maskClip(props.el.mask, normalizeTheme(props.deck.theme).s.radius);
    return clip ? { clipPath: clip } : {};
});
const hitStyle = computed(() => ({
    left: `${rect.value.left}px`, top: `${rect.value.top}px`, width: `${rect.value.width}px`, height: `${rect.value.height}px`,
}));

const HANDLES = ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w'];
const POS = {
    nw: [0, 0], n: [0.5, 0], ne: [1, 0], e: [1, 0.5], se: [1, 1], s: [0.5, 1], sw: [0, 1], w: [0, 0.5],
};
const handleStyle = (h) => ({ left: `${POS[h][0] * box.value.w}px`, top: `${POS[h][1] * box.value.h}px`, cursor: cursorFor(h, box.value.rot) });

function slidePoint(e) {
    const r = props.vp.rootEl?.value?.getBoundingClientRect?.() || e.currentTarget.closest('.es-viewport').getBoundingClientRect();
    return props.vp.toSlide({ x: e.clientX - r.left, y: e.clientY - r.top });
}

let drag = null;
function onHitDown(e) {
    if (e.button !== 0) return;
    e.preventDefault();
    e.stopPropagation();
    e.currentTarget.setPointerCapture(e.pointerId);
    drag = { kind: 'pan', start: slidePoint(e), win: { ...win.value } };
}
function onHandleDown(e, h) {
    if (e.button !== 0) return;
    e.preventDefault();
    e.stopPropagation();
    e.currentTarget.setPointerCapture(e.pointerId);
    drag = { kind: 'resize', handle: h, box: { ...box.value }, win: { ...win.value } };
}
function onMove(e) {
    if (!drag) return;
    const p = slidePoint(e);
    if (drag.kind === 'pan') {
      const d = { x: p.x - drag.start.x, y: p.y - drag.start.y };
      const local = rotatePoint(d, { x: 0, y: 0 }, -(box.value.rot || 0));
      win.value = panWindow(drag.win, local.x, local.y, natural.value, box.value);
    } else {
      const r = resizeWindow(drag.box, drag.win, drag.handle, p, natural.value, { center: e.altKey });
      box.value = r.box;
      win.value = r.win;
    }
}
function onUp(e) {
    if (!drag) return;
    drag = null;
    try { e.currentTarget.releasePointerCapture(e.pointerId); } catch { /* released */ }
}

function anchorFor(clientX, clientY, root) {
    const r = root.getBoundingClientRect();
    const p = props.vp.toSlide({ x: clientX - r.left, y: clientY - r.top });
    const c = centerOf(box.value);
    const local = rotatePoint(p, c, -(box.value.rot || 0));
    return {
        u: Math.min(1, Math.max(0, (local.x - box.value.x) / box.value.w)),
        v: Math.min(1, Math.max(0, (local.y - box.value.y) / box.value.h)),
    };
}
function wheel(e) {
    const root = e.currentTarget || e.target.closest('.es-viewport');
    const a = anchorFor(e.clientX, e.clientY, root.closest?.('.es-viewport') || root);
    win.value = zoomWindow(win.value, Math.exp(-e.deltaY * 0.0018), a, natural.value, box.value);
    return true;
}
function zoomBy(f) { win.value = zoomWindow(win.value, f, { u: 0.5, v: 0.5 }, natural.value, box.value); }
function nudge(dx, dy) {
    const local = rotatePoint({ x: dx, y: dy }, { x: 0, y: 0 }, -(box.value.rot || 0));
    win.value = panWindow(win.value, local.x, local.y, natural.value, box.value);
}

function commit() {
    if (finished) return;
    finished = true;
    const b1 = { x: box.value.x, y: box.value.y, w: box.value.w, h: box.value.h };
    emit('done', { id: props.el.id, patch: commitCrop(win.value, { x: box0.x, y: box0.y, w: box0.w, h: box0.h }, b1, props.el) });
}
function cancel() {
    if (finished) return;
    finished = true;
    emit('done', { id: props.el.id, patch: null });
}
defineExpose({ commit, cancel, wheel, zoomBy, nudge });
</script>

<template>
    <div class="es-crop-frame" :style="frameStyle">
        <div class="es-crop-flip" :style="{ position: 'absolute', inset: 0, transform: flip }">
            <img v-if="src" class="es-crop-full" :src="src.url" alt="" draggable="false" :style="imgStyle" />
        </div>
        <div class="es-crop-window" :style="windowStyle">
            <div :style="{ position: 'absolute', inset: 0, transform: flip }">
                <img v-if="src" :src="src.url" alt="" draggable="false" :style="brightStyle" />
            </div>
        </div>
        <div class="es-crop-edge"></div>
        <div class="es-crop-hit" data-es-ui :style="hitStyle" @pointerdown="onHitDown" @pointermove="onMove" @pointerup="onUp" @pointercancel="onUp"></div>
        <span
            v-for="h in HANDLES"
            :key="h"
            class="es-crop-handle"
            data-es-ui
            :style="handleStyle(h)"
            @pointerdown="onHandleDown($event, h)"
            @pointermove="onMove"
            @pointerup="onUp"
            @pointercancel="onUp"
        ></span>
    </div>
</template>

<style>
.es-crop-handle {
    position: absolute;
    width: calc(12px / var(--z, 1));
    height: calc(12px / var(--z, 1));
    margin: calc(-6px / var(--z, 1)) 0 0 calc(-6px / var(--z, 1));
    background: #fff;
    border: calc(1.5px / var(--z, 1)) solid #14171a;
    border-radius: calc(3px / var(--z, 1));
    pointer-events: auto;
    touch-action: none;
    z-index: 2;
}
.es-crop-handle:hover { background: var(--amber); }
</style>
