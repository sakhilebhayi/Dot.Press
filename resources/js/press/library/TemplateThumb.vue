<script setup>
// The first slide of a built-in template, fetched once when the tile nears the viewport (GET /api/templates/{key}).
import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import axios from 'axios';
import { SlideView } from '../render/index.js';

const props = defineProps({ templateId: { type: String, required: true } });
const cache = (globalThis.__pressTplPreview ||= new Map());
const host = ref(null);
const preview = shallowRef(null);
let io = null;

async function load() {
    if (cache.has(props.templateId)) { preview.value = cache.get(props.templateId); return; }
    try {
        const { data } = await axios.get(`/api/templates/${encodeURIComponent(props.templateId)}`, { withCredentials: true, headers: { Accept: 'application/json' } });
        const t = data?.template;
        if (!t?.preview?.slide) return;
        const v = { deck: { w: t.preview.w || 1920, h: t.preview.h || 1080, theme: t.theme_data || undefined }, slide: { id: 'p', ...t.preview.slide } };
        cache.set(props.templateId, v);
        preview.value = v;
    } catch { /* the tile still works without a picture */ }
}
onMounted(() => {
    if (typeof IntersectionObserver === 'undefined') { load(); return; }
    io = new IntersectionObserver((e) => { if (e.some((x) => x.isIntersecting)) { io.disconnect(); io = null; load(); } }, { rootMargin: '200px 0px' });
    io.observe(host.value);
});
onBeforeUnmount(() => io?.disconnect());
</script>

<template>
    <span ref="host" class="ns-tpl-thumb" :style="{ aspectRatio: preview ? `${preview.deck.w} / ${preview.deck.h}` : '16 / 9' }" aria-hidden="true">
        <SlideView v-if="preview" :slide="preview.slide" :deck="preview.deck" mode="thumb" />
    </span>
</template>
