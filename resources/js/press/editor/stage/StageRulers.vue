<script setup>
// Rulers along the top and left edge. Pressing on a ruler and dragging out pulls a guide onto the slide (guides snap moving
// elements, see useGestures). Guides live in ui.userGuides and are kept per deck in localStorage.
import { computed } from 'vue';
import { rulerTicks, addGuide } from './lib/guides.js';

const props = defineProps({
    vp: { type: Object, required: true },
    ui: { type: Object, required: true },
    deck: { type: Object, required: true },
});

const z = computed(() => props.vp.zoom.value);
const o = computed(() => props.vp.origin.value);
const size = computed(() => props.vp.size.value);
const xTicks = computed(() => {
    const min = (20 - o.value.x) / z.value;
    const max = (size.value.w - o.value.x) / z.value;
    return rulerTicks(Math.max(0, min), Math.min(props.deck.w, max), z.value).map((t) => ({ ...t, at: o.value.x + t.pos * z.value - 20 }));
});
const yTicks = computed(() => {
    const min = (20 - o.value.y) / z.value;
    const max = (size.value.h - o.value.y) / z.value;
    return rulerTicks(Math.max(0, min), Math.min(props.deck.h, max), z.value).map((t) => ({ ...t, at: o.value.y + t.pos * z.value - 20 }));
});

function drag(orient, e) {
    e.preventDefault();
    e.stopPropagation();
    const root = e.currentTarget.closest('.es-viewport');
    const rect = root.getBoundingClientRect();
    const guides = [...(props.ui.userGuides || [])];
    const at = (ev) => props.vp.toSlide({ x: ev.clientX - rect.left, y: ev.clientY - rect.top });
    const pos0 = orient === 'v' ? at(e).x : at(e).y;
    const next = addGuide(guides, orient, pos0, props.deck);
    props.ui.userGuides = next;
    const index = next.length - 1;
    const move = (ev) => {
        const p = orient === 'v' ? at(ev).x : at(ev).y;
        const cur = [...(props.ui.userGuides || [])];
        if (cur[index]) { cur[index] = { ...cur[index], pos: Math.round(p * 100) / 100 }; props.ui.userGuides = cur; }
    };
    const up = (ev) => {
        window.removeEventListener('pointermove', move);
        window.removeEventListener('pointerup', up);
        const p = orient === 'v' ? at(ev).x : at(ev).y;
        const max = orient === 'v' ? props.deck.w : props.deck.h;
        if (p <= 0 || p >= max) props.ui.userGuides = (props.ui.userGuides || []).filter((_, i) => i !== index);
    };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', up);
}
</script>

<template>
    <div data-es-ui>
        <div class="es-ruler-corner"></div>
        <div class="es-ruler es-ruler-x" @pointerdown="drag('v', $event)">
            <svg aria-hidden="true">
                <g v-for="t in xTicks" :key="t.pos">
                    <line :x1="t.at" :x2="t.at" :y1="t.major ? 6 : 14" y2="20" />
                    <text v-if="t.label" :x="t.at + 3" y="11">{{ t.label }}</text>
                </g>
            </svg>
        </div>
        <div class="es-ruler es-ruler-y" @pointerdown="drag('h', $event)">
            <svg aria-hidden="true">
                <g v-for="t in yTicks" :key="t.pos">
                    <line :y1="t.at" :y2="t.at" :x1="t.major ? 6 : 14" x2="20" />
                    <text v-if="t.label" :x="3" :y="t.at + 11" style="writing-mode: horizontal-tb">{{ t.label }}</text>
                </g>
            </svg>
        </div>
    </div>
</template>
