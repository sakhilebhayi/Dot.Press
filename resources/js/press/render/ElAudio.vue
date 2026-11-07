<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRenderCtx } from './context.js';
import { resolveSrc } from './media.js';
import { reducedMotion } from './env.js';

const props = defineProps({ el: { type: Object, required: true } });
const ctx = useRenderCtx();
const audio = ref(null);
const src = computed(() => resolveSrc(props.el.src, ctx.assets.value));
const present = computed(() => ctx.mode.value === 'present');
const edit = computed(() => ctx.mode.value === 'edit');
const withControls = computed(() => !!props.el.controls && (present.value || ctx.mode.value === 'view'));
const live = computed(() => !!src.value && (present.value || ctx.mode.value === 'view'));

watch(
    () => [ctx.active.value, audio.value],
    ([active, a]) => {
        if (!a) return;
        if (active && present.value && props.el.autoplay && !reducedMotion.value) a.play?.().catch?.(() => {});
        else if (!active) a.pause();
    },
    { immediate: true },
);
onBeforeUnmount(() => audio.value?.pause());
</script>

<template>
    <div class="pa" :class="{ 'pa-edit': edit }">
        <audio v-if="live" ref="audio" :src="src.url" :loop="!!el.loop" :controls="withControls" preload="metadata" class="pa-el" :aria-label="el.alt || 'Audio'"></audio>
        <svg v-if="edit || ctx.mode.value === 'thumb'" class="pa-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" role="img" :aria-label="el.alt || 'Audio'"><path d="M4 9.5v5h3.5L12 18.5v-13L7.5 9.5z" /><path d="M15.5 9a4 4 0 0 1 0 6" /><path d="M18 6.5a7.5 7.5 0 0 1 0 11" /></svg>
    </div>
</template>
