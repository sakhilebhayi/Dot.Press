<script setup>
// One glyph, from the editor set (glyphs.js) or the shared icon set (press/icons.js).
import { computed } from 'vue';
import { GLYPHS } from './glyphs.js';
import { getIcon } from '../../icons.js';

const props = defineProps({ name: { type: String, required: true }, size: { type: String, default: 'md' }, stroke: { type: Number, default: 1.75 } });
const paths = computed(() => GLYPHS[props.name] || getIcon(props.name)?.d || []);
</script>

<template>
    <svg class="pr-ico" :class="{ 'pr-ico--sm': size === 'sm', 'pr-ico--lg': size === 'lg' }" viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="stroke" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <template v-for="(d, i) in paths" :key="i">
            <path v-if="d.startsWith('F:')" :d="d.slice(2)" fill="currentColor" stroke="none" />
            <path v-else :d="d" />
        </template>
    </svg>
</template>
