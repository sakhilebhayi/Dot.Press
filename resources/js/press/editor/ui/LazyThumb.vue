<script setup>
// A slide thumbnail that draws only once it is near the viewport (300 slide decks stay light).
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
onMounted(() => {
    if (shown.value || typeof IntersectionObserver === 'undefined') { shown.value = true; return; }
    io = new IntersectionObserver((entries) => {
        if (entries.some((e) => e.isIntersecting)) { shown.value = true; io.disconnect(); io = null; }
    }, { rootMargin: '300px 0px' });
    io.observe(el.value);
});
onBeforeUnmount(() => io?.disconnect());
</script>

<template>
    <div ref="el" class="lt" :style="{ aspectRatio: `${deck.w} / ${deck.h}` }">
        <SlideView v-if="shown" :slide="slide" :deck="deck" :assets="assets" mode="thumb" />
    </div>
</template>

<style>
.lt { position: relative; width: 100%; background: var(--sheet); overflow: hidden; }
</style>
