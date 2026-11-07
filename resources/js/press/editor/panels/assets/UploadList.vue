<script setup>
// An upload queue: rows for files that are sending, waiting or failed (with a progress rail, cancel and retry), and one summary line for
// the files that finished, so a hundred uploads do not push the library off the screen. Errors are shown plainly under the name.
import { computed } from 'vue';
import { ProgressBead } from '../../../ui/controls/index.js';
import Ico from '../../ui/Ico.vue';
import { formatBytes } from './assetsModel.js';

const props = defineProps({ items: { type: Array, required: true }, rows: { type: Number, default: 5 } });
const emit = defineEmits(['cancel', 'retry', 'remove', 'clear']);
const open = computed(() => props.items.filter((i) => i.state !== 'done'));
const shown = computed(() => {
    // failed and sending files first, so a problem is never hidden behind a long waiting list
    const rank = { failed: 0, uploading: 1, cancelled: 2, queued: 3 };
    return [...open.value].sort((a, b) => rank[a.state] - rank[b.state]).slice(0, props.rows);
});
const hiddenCount = computed(() => open.value.length - shown.value.length);
const done = computed(() => props.items.filter((i) => i.state === 'done'));
const already = computed(() => done.value.filter((i) => i.deduplicated).length);
const summary = computed(() => {
    const n = done.value.length;
    if (!n) return '';
    const added = n - already.value;
    const parts = [];
    if (added) parts.push(`${added} added to your library`);
    if (already.value) parts.push(`${already.value} already ${already.value === 1 ? 'was' : 'were'} there, so the existing ${already.value === 1 ? 'copy was' : 'copies were'} used`);
    return `${parts.join('. ')}.`;
});
const word = (i) => ({ queued: 'waiting', uploading: i.progress > 0 ? `${Math.round(i.progress * 100)}%` : 'sending', failed: 'failed', cancelled: 'stopped' })[i.state];
const finished = computed(() => props.items.some((i) => i.state !== 'queued' && i.state !== 'uploading'));
</script>

<template>
    <div v-if="items.length" class="al-ups">
        <ul class="ip-rows" aria-label="Uploads">
            <li v-for="i in shown" :key="i.id" class="ip-up" :data-state="i.state">
                <div class="ip-up-top">
                    <span class="ip-up-n" :title="i.name">{{ i.name }}</span>
                    <span class="pr-state ip-up-w">{{ formatBytes(i.size) }}</span>
                    <button v-if="i.state === 'queued' || i.state === 'uploading'" type="button" class="pr-btn pr-btn--ghost pr-btn--icon pr-btn--sm" :aria-label="`Cancel ${i.name}`" @click="emit('cancel', i.id)"><Ico name="close" size="sm" /></button>
                    <template v-else>
                        <button type="button" class="pr-btn pr-btn--sm" @click="emit('retry', i.id)">Try again</button>
                        <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon pr-btn--sm" :aria-label="`Dismiss ${i.name}`" @click="emit('remove', i.id)"><Ico name="close" size="sm" /></button>
                    </template>
                </div>
                <ProgressBead v-if="i.state === 'queued' || i.state === 'uploading'" :value="i.state === 'queued' ? 0 : (i.progress > 0 ? i.progress : null)" :label="`Uploading ${i.name}`" :word="word(i)" />
                <p v-else-if="i.state === 'failed'" class="c-error" role="alert"><span class="pr-bead pr-bead--bad pr-bead--sm" aria-hidden="true"></span>{{ i.error }}</p>
                <p v-else class="c-hint">Stopped.</p>
            </li>
        </ul>
        <p v-if="hiddenCount > 0" class="c-hint">and {{ hiddenCount }} more waiting</p>
        <p v-if="summary" class="c-hint" role="status">{{ summary }}</p>
        <button v-if="finished" type="button" class="pr-btn pr-btn--ghost pr-btn--sm" style="justify-self: start" @click="emit('clear')">Clear finished</button>
    </div>
</template>

<style>
.al-ups { display: grid; gap: 8px; }
</style>
