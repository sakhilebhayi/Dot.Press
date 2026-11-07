<script setup>
// Mark and name together. The name is set in the display face; "Dot" and "Press" sit on one line with the dot as a full stop.
import Mark from './Mark.vue';

defineProps({
    size: { type: String, default: 'md', validator: (v) => ['sm', 'md', 'lg'].includes(v) },
    name: { type: Boolean, default: true },
});
</script>

<template>
    <span class="pr-wordmark" :data-size="size">
        <Mark :width="size === 'lg' ? 48 : size === 'sm' ? 24 : 32" />
        <span v-if="name" class="pr-wordmark-n">Dot.Press</span>
    </span>
</template>

<style>
.pr-wordmark { display: inline-flex; align-items: center; gap: 10px; color: var(--ink); }
.pr-wordmark-n { font-family: var(--f-display); font-weight: 600; letter-spacing: -0.02em; font-size: var(--t-lg); line-height: 1; font-variation-settings: 'opsz' 28; }
.pr-wordmark[data-size='lg'] { gap: 14px; }
.pr-wordmark[data-size='lg'] .pr-wordmark-n { font-size: var(--t-xl); font-variation-settings: 'opsz' 48; }
.pr-wordmark[data-size='sm'] { gap: 8px; }
.pr-wordmark[data-size='sm'] .pr-wordmark-n { font-size: var(--t-md); }
</style>
