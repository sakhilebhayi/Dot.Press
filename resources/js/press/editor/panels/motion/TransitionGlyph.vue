<script setup>
// A 28 px schematic of a transition: the slide that leaves is hollow, the one that arrives is solid. Directional
// transitions are drawn for "enters from the right" and turned for the other sides.
import { computed } from 'vue';

const props = defineProps({ type: { type: String, required: true }, dir: { type: String, default: 'r' } });
const turn = computed(() => ({ r: 0, d: 90, l: 180, u: 270 })[props.dir] ?? 0);
const directional = computed(() => ['push', 'wipe', 'reveal'].includes(props.type));
</script>

<template>
    <svg class="tg" viewBox="0 0 28 28" width="28" height="28" aria-hidden="true" focusable="false">
        <g :transform="directional ? `rotate(${turn} 14 14)` : undefined" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round">
            <template v-if="type === 'none'">
                <rect x="2.5" y="8" width="9" height="12" rx="1" />
                <rect x="16.5" y="8" width="9" height="12" rx="1" fill="currentColor" />
            </template>
            <template v-else-if="type === 'fade'">
                <rect x="3" y="5" width="15" height="15" rx="1" />
                <rect x="10" y="9" width="15" height="15" rx="1" fill="currentColor" fill-opacity="0.35" />
            </template>
            <template v-else-if="type === 'push'">
                <rect x="2.5" y="7" width="11" height="14" rx="1" />
                <rect x="14.5" y="7" width="11" height="14" rx="1" fill="currentColor" />
                <path d="M9 14H5M7 12l-2 2 2 2" stroke-width="1.25" />
            </template>
            <template v-else-if="type === 'wipe'">
                <rect x="3" y="6" width="22" height="16" rx="1" />
                <path d="M15 6h9a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-9z" fill="currentColor" />
            </template>
            <template v-else-if="type === 'reveal'">
                <rect x="3" y="6" width="22" height="16" rx="1" fill="currentColor" fill-opacity="0.35" />
                <rect x="3" y="6" width="11" height="16" rx="1" fill="var(--sheet)" />
                <path d="M9 14H5M7 12l-2 2 2 2" stroke-width="1.25" />
            </template>
            <template v-else-if="type === 'zoom'">
                <rect x="3" y="4" width="22" height="20" rx="1" />
                <rect x="9" y="9" width="10" height="10" rx="1" fill="currentColor" />
            </template>
            <template v-else>
                <circle cx="8.5" cy="9" r="4.5" />
                <rect x="14" y="14" width="11" height="10" rx="1.5" fill="currentColor" />
                <path d="M11.5 12.5l3.5 3" stroke-dasharray="1.5 2" />
            </template>
        </g>
    </svg>
</template>
