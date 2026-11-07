<script setup>
// The status chip in the top bar: a bead and the status word. It opens the approval panel. Starts the shared status and
// comments stores, so pins and the chip are right before any panel was opened.
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { useEditorContext } from '../../context.js';
import { useDeckStatus } from './useDeckStatus.js';
import { useComments } from '../comments/useComments.js';
import { statusMeta } from './statusModel.js';

const editor = useEditorContext();
const ds = useDeckStatus(editor);
const comments = useComments(editor);
const meta = computed(() => statusMeta(ds.status.value));
onMounted(() => { ds.start(); comments.start(); });
onBeforeUnmount(() => { ds.stop(); comments.stop(); });
</script>

<template>
    <button
        v-if="editor.hasPanel('approval')" type="button" class="pr-btn tb-act apc" :aria-label="`Approval: ${meta.label}. Open approval`" :title="`Approval: ${meta.label}`" @click="editor.openPanel('approval')"
    >
        <span class="pr-bead pr-bead--sm" :data-state="meta.bead" :data-evidence="meta.evidence" aria-hidden="true"></span>
        <span class="tb-lbl apc-l">{{ meta.label }}</span>
    </button>
</template>

<style scoped>
.apc { gap: 8px; }
.apc-l { white-space: nowrap; }
</style>
