<script setup>
// One real slide of the sample deck, drawn by the real renderer. The box holds its aspect ratio from the first paint, so
// nothing moves when the renderer's chunk arrives, and the renderer is only fetched once the sheet is near the screen.
import { defineAsyncComponent, onBeforeUnmount, onMounted, ref } from 'vue';

const SlideView = defineAsyncComponent(() => import('../render/SlideView.vue'));

const props = defineProps({
    slide: { type: Object, required: true },
    deck: { type: Object, required: true },
    label: { type: String, default: '' },
    eager: { type: Boolean, default: false },
});

const root = ref(null);
const near = ref(props.eager);
let io = null;

onMounted(() => {
    if (near.value || !('IntersectionObserver' in window)) { near.value = true; return; }
    io = new IntersectionObserver((entries) => {
        if (entries.some((e) => e.isIntersecting)) { near.value = true; io.disconnect(); }
    }, { rootMargin: '700px 0px' });
    io.observe(root.value);
});
onBeforeUnmount(() => io?.disconnect());
</script>

<template>
    <div ref="root" class="lp-sheet" role="group" :aria-label="label || `Sample slide: ${slide.title}`" :style="{ aspectRatio: `${deck.w} / ${deck.h}` }">
        <div :key="slide.id" class="lp-sheet-in">
            <SlideView v-if="near" :slide="slide" :deck="deck" mode="view" :allow-embeds="false" eager />
        </div>
    </div>
</template>
