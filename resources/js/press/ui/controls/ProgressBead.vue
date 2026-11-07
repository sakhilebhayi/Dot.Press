<script setup>
// Progress on a rail. value 0..1 for determinate, null for indeterminate (a bead travelling the rail). `label` is the accessible name.
import { computed } from 'vue';

const props = defineProps({ value: { type: Number, default: null }, label: { type: String, default: 'Working' }, word: { type: String, default: '' } });
const pct = computed(() => (props.value === null ? null : Math.round(Math.min(1, Math.max(0, props.value)) * 100)));
</script>

<template>
    <div class="c-prog" :class="{ 'is-indet': pct === null }" role="progressbar" :aria-label="label" :aria-valuemin="pct === null ? undefined : 0" :aria-valuemax="pct === null ? undefined : 100" :aria-valuenow="pct ?? undefined">
        <span class="c-prog-rail" aria-hidden="true"><span class="c-prog-fill" :style="pct === null ? { transform: 'scaleX(0)' } : { transform: `scaleX(${pct / 100})` }"></span><span class="c-prog-bead pr-bead" :data-state="pct === 100 ? 'solid' : 'current'" :style="pct === null ? null : { left: pct + '%' }"></span></span>
        <span class="c-prog-w pr-state">{{ word || (pct === null ? 'working' : `${pct}%`) }}</span>
    </div>
</template>
