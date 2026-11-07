<script setup>
// A slide thumbnail that draws only while it is near the viewport (300 slide decks stay light). It is taken down again
// some seconds after it leaves the neighbourhood: a sheets view that kept every thumbnail it had ever drawn held
// 200,000 DOM nodes and 165 MB of script heap after one scroll through 300 slides, and the page went from 60 to 30
// frames a second on a slow processor. The box keeps its size (aspect-ratio), so nothing jumps.
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { SlideView } from '../../render/index.js';

// One observer for every thumbnail on the page: 300 observers cost more to scroll past than the thumbnails they guard.
const handlers = new WeakMap();
let shared = null;
function observe(el, handler) {
    shared ??= new IntersectionObserver((entries) => {
        for (const e of entries) handlers.get(e.target)?.(e.isIntersecting);
    }, { rootMargin: '400px 0px' });
    handlers.set(el, handler);
    shared.observe(el);
}
function unobserve(el) {
    shared?.unobserve(el);
    handlers.delete(el);
}

const props = defineProps({
    slide: { type: Object, required: true },
    deck: { type: Object, required: true },
    assets: { type: Object, default: () => ({}) },
    eager: { type: Boolean, default: false },
});
const el = ref(null);
const shown = ref(props.eager);
let hideTimer = null;
// Long enough that a scroll through the whole list never pays for taking thumbnails down while it runs; the memory is
// given back once the person has stopped looking at that part of the list, and then in an idle moment.
const HIDE_AFTER_MS = 20_000;
const idle = (fn) => (typeof requestIdleCallback === 'function' ? requestIdleCallback(fn, { timeout: 2000 }) : fn());
onMounted(() => {
    if (typeof IntersectionObserver === 'undefined') { shown.value = true; return; }
    observe(el.value, (visible) => {
        if (visible) {
            window.clearTimeout(hideTimer);
            hideTimer = null;
            shown.value = true;
        } else if (!props.eager && shown.value && hideTimer === null) {
            hideTimer = window.setTimeout(() => { hideTimer = null; idle(() => { shown.value = false; }); }, HIDE_AFTER_MS);
        }
    });
});
onBeforeUnmount(() => { if (el.value) unobserve(el.value); window.clearTimeout(hideTimer); });
</script>

<template>
    <div ref="el" class="lt" :style="{ aspectRatio: `${deck.w} / ${deck.h}` }">
        <SlideView v-if="shown" :slide="slide" :deck="deck" :assets="assets" mode="thumb" />
    </div>
</template>

<style>
.lt { position: relative; width: 100%; background: var(--sheet); overflow: hidden; }
</style>
