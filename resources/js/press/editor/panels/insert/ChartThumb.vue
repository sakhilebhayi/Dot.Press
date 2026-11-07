<script setup>
// A live chart thumbnail: chartToSvg(sampleChart(type)) drawn once the row is near the viewport, cached per type and theme.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { chartToSvg, sampleChart } from '../../../charts/index.js';

const props = defineProps({ type: { type: String, required: true }, theme: { type: Object, default: null }, label: { type: String, default: '' } });
const host = ref(null);
const near = ref(false);
let io = null;
const cache = (globalThis.__pressChartThumbs ||= new Map());
const themeKey = computed(() => JSON.stringify([props.theme?.c, props.theme?.t?.body]));
const svg = computed(() => {
    if (!near.value) return '';
    const key = `${props.type}|${themeKey.value}`;
    if (cache.has(key)) return cache.get(key);
    let out = '';
    try {
        out = chartToSvg(sampleChart(props.type), { w: 320, h: 200, theme: props.theme || undefined, reduced: true, animate: false, idPrefix: `ict-${props.type}`, surface: props.theme?.c?.bg });
    } catch {
        out = '';
    }
    if (cache.size > 80) cache.clear();
    cache.set(key, out);
    return out;
});
onMounted(() => {
    if (typeof IntersectionObserver === 'undefined') { near.value = true; return; }
    io = new IntersectionObserver((es) => { if (es.some((e) => e.isIntersecting)) { near.value = true; io.disconnect(); io = null; } }, { rootMargin: '240px 0px' });
    io.observe(host.value);
});
onBeforeUnmount(() => io?.disconnect());
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html -->
    <span ref="host" class="ip-chart-t" :class="{ 'is-wait': !svg }" :style="theme?.c?.bg ? { background: theme.c.bg } : null" role="img" :aria-label="`${label} chart preview`" v-html="svg"></span>
</template>
