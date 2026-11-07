<script setup>
// A slide thumbnail that draws only while it is near the viewport (300 slide decks stay light). It is taken down again
// a couple of seconds after it leaves the neighbourhood: a sheets view that kept every thumbnail it had ever drawn held
// 200,000 DOM nodes and 165 MB of script heap after one scroll through 300 slides, and the page went from 60 to 30
// frames a second on a slow processor. The box keeps its size (aspect-ratio), so nothing jumps.
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { SlideView } from '../../render/index.js';

const props = defineProps({
    slide: { type: Object, required: true },
    deck: { type: Object, required: true },
    assets: { type: Object, default: () => ({}) },
    eager: { type: Boolean, default: false },
});
const el = ref(null);
const shown = ref(props.eager);
let io = null;
let hideTimer = null;
const HIDE_AFTER_MS = 2500;
onMounted(() => {
    if (typeof IntersectionObserver === 'undefined') { shown.value = true; return; }
    io = new IntersectionObserver((entries) => {
        for (const e of entries) {
            if (e.isIntersecting) {
                window.clearTimeout(hideTimer);
                hideTimer = null;
                shown.value = true;
            } else if (!props.eager && shown.value && hideTimer === null) {
                hideTimer = window.setTimeout(() => { hideTimer = null; shown.value = false; }, HIDE_AFTER_MS);
            }
        }
    }, { rootMargin: '400px 0px' });
    io.observe(el.value);
});
onBeforeUnmount(() => { io?.disconnect(); window.clearTimeout(hideTimer); });
</script>

<template>
    <div ref="el" class="lt" :style="{ aspectRatio: `${deck.w} / ${deck.h}` }">
        <SlideView v-if="shown" :slide="slide" :deck="deck" :assets="assets" mode="thumb" />
    </div>
</template>

<style>
.lt { position: relative; width: 100%; background: var(--sheet); overflow: hidden; }
</style>
