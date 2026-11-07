<script setup>
// Hosts one registered panel by id. A panel that throws while drawing is replaced by a short notice so the editor stays usable.
import { computed, onErrorCaptured, ref, watch } from 'vue';
import { getPanel } from './registry.js';

const props = defineProps({
    id: { type: String, default: null },
    panelProps: { type: Object, default: null },
});
const failed = ref(null);
const def = computed(() => (props.id ? getPanel(props.id) : null));
watch(() => props.id, () => { failed.value = null; });
onErrorCaptured((err) => {
    failed.value = err;
    console.error(`Panel "${props.id}" failed`, err);
    return false;
});
</script>

<template>
    <div class="ph" :data-panel="id" role="tabpanel" :aria-label="def?.label || undefined">
        <component :is="def.component" v-if="def && !failed" :key="id" :panel-props="panelProps" />
        <div v-else-if="failed" class="ph-fail">
            <p class="pr-small">This panel could not be shown.</p>
            <button type="button" class="pr-btn pr-btn--sm" @click="failed = null">Try again</button>
        </div>
        <slot v-else />
    </div>
</template>

<style>
.ph { min-height: 0; flex: 1; overflow-y: auto; overflow-x: hidden; scrollbar-width: thin; scrollbar-color: var(--line-strong) transparent; }
.ph-fail { padding: 24px 16px; display: grid; gap: 12px; justify-items: start; color: var(--ink-2); }
</style>
