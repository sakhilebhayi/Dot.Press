<script setup>
import { computed } from 'vue';
import { DEFAULT_ICON_SW, getIcon } from '../icons.js';
import { colorCss } from './theme.js';

const props = defineProps({ el: { type: Object, required: true } });
const icon = computed(() => getIcon(props.el.name));
const sw = computed(() => (Number.isFinite(+props.el.sw) && +props.el.sw > 0 ? Math.min(6, +props.el.sw) : DEFAULT_ICON_SW));
const label = computed(() => (typeof props.el.alt === 'string' ? props.el.alt : ''));
</script>

<template>
    <svg v-if="icon" class="pic" viewBox="0 0 24 24" fill="none" stroke-linecap="round" stroke-linejoin="round" preserveAspectRatio="xMidYMid meet" :style="{ stroke: colorCss(el.color ?? '@ink', 'var(--p-ink)'), strokeWidth: sw }" :role="label ? 'img' : undefined" :aria-label="label || undefined" :aria-hidden="label ? undefined : 'true'">
        <path v-for="(d, i) in icon.d" :key="i" :d="d" />
    </svg>
</template>
