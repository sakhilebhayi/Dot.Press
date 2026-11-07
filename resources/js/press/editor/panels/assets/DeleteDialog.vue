<script setup>
// Delete a file. A file still used answers 409 with the decks that use it; people who may delete anyway (can.manage) get a second,
// explicit button. The API refuses anyone else and says why, and that sentence is shown as written.
import { computed, ref, watch } from 'vue';
import { Dialog, InlineNotice } from '../../../ui/controls/index.js';

const props = defineProps({ open: { type: Boolean, default: false }, asset: { type: Object, default: null }, canForce: { type: Boolean, default: false }, remove: { type: Function, required: true } });
const emit = defineEmits(['update:open', 'deleted']);
const busy = ref(false);
const answer = ref(null);
watch(() => props.open, (o) => { if (o) { answer.value = null; busy.value = false; } });
const inUse = computed(() => answer.value?.type === 'in_use');

async function go(force) {
    busy.value = true;
    const r = await props.remove(props.asset, { force });
    busy.value = false;
    if (r.ok || r.type === 'missing') { emit('deleted', props.asset); emit('update:open', false); return; }
    answer.value = r;
}
</script>

<template>
    <Dialog :open="open" :title="asset ? `Delete ${asset.name}?` : 'Delete file'" size="sm" @update:open="emit('update:open', $event)">
        <div class="al-dlg">
            <p v-if="!answer" class="c-hint">This removes the file from your library. It cannot be undone.</p>
            <InlineNotice v-else-if="inUse" kind="warn" title="This file is still used.">
                <ul class="al-lines">
                    <li v-for="d in answer.decks" :key="d.id">{{ d.title }}<span class="pr-state"> ({{ d.slide_count }} {{ d.slide_count === 1 ? 'slide' : 'slides' }})</span></li>
                    <li v-for="k in answer.brandKits" :key="`k${k.id}`">Brand kit: {{ k.name }}</li>
                </ul>
                <p v-if="canForce" style="margin: 8px 0 0">If you delete it anyway, those places show a missing picture until someone replaces it.</p>
                <p v-else style="margin: 8px 0 0">Remove it from those decks first. Only a team admin can delete a file that is still used.</p>
            </InlineNotice>
            <InlineNotice v-else kind="error">{{ answer.message }}</InlineNotice>
        </div>
        <template #footer="{ close }">
            <button type="button" class="pr-btn" @click="close">{{ inUse && !canForce ? 'Close' : 'Cancel' }}</button>
            <button v-if="!answer" type="button" class="pr-btn pr-btn--danger al-danger" data-autofocus :disabled="busy" @click="go(false)">Delete file</button>
            <button v-else-if="inUse && canForce" type="button" class="pr-btn pr-btn--danger al-danger" :disabled="busy" @click="go(true)">Delete anyway</button>
        </template>
    </Dialog>
</template>
