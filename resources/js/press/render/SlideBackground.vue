<script setup>
import { computed } from 'vue';
import { useRenderCtx } from './context.js';
import { resolveSrc } from './media.js';
import { resolveBackground } from './theme.js';

const ctx = useRenderCtx();
const props = defineProps({ slide: { type: Object, required: true } });
const bg = computed(() => resolveBackground(props.slide.bg, ctx.theme.value));
const img = computed(() => {
    if (bg.value.kind !== 'image') return null;
    const r = resolveSrc(bg.value.img, ctx.assets.value);
    return r ? (ctx.mode.value === 'thumb' ? r.thumb : r.url) : null;
});
const fillStyle = computed(() => {
    const css = bg.value.kind === 'image' ? bg.value.base.css : bg.value.fill.css;
    return { background: css === 'none' ? 'var(--p-bg)' : css };
});
</script>

<template>
    <div class="ps-bg" :style="fillStyle" aria-hidden="true">
        <img v-if="img" class="ps-bg-img" :src="img" alt="" :style="{ objectFit: bg.fit }" decoding="async" />
        <div v-if="img && bg.dim > 0" class="ps-bg-dim" :style="{ opacity: bg.dim }"></div>
    </div>
</template>
