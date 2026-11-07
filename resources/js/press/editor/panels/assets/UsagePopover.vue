<script setup>
// "Used in N decks": the decks and brand kits that use a file (GET /api/assets/{id}/usage).
import { ref, watch } from 'vue';
import { Popover, ProgressBead, InlineNotice } from '../../../ui/controls/index.js';
import { describeApiError } from './assetsModel.js';

const props = defineProps({ open: { type: Boolean, default: false }, anchor: { type: Object, default: null }, asset: { type: Object, default: null }, load: { type: Function, required: true } });
const emit = defineEmits(['update:open']);
const data = ref(null);
const error = ref('');
const busy = ref(false);
async function fetchUsage() {
    if (!props.asset) return;
    busy.value = true; error.value = ''; data.value = null;
    try { data.value = await props.load(props.asset); } catch (e) { error.value = describeApiError(e, 'where this file is used'); } finally { busy.value = false; }
}
watch(() => [props.open, props.asset?.id], ([o]) => { if (o) fetchUsage(); }, { immediate: true });
const hidden = 'A deck you cannot open';
</script>

<template>
    <Popover :open="open" :anchor="anchor" label="Where this file is used" placement="bottom-start" :width="280" @update:open="emit('update:open', $event)">
        <div class="al-pop">
            <ProgressBead v-if="busy" label="Looking up where this file is used" />
            <InlineNotice v-else-if="error" kind="error">{{ error }} <button type="button" class="pr-btn pr-btn--sm" @click="fetchUsage">Try again</button></InlineNotice>
            <template v-else-if="data">
                <h4>Decks <span class="pr-state">{{ data.deck_count }}</span></h4>
                <ul v-if="data.decks.length">
                    <li v-for="d in data.decks" :key="d.id">
                        <span v-if="d.title === hidden">{{ d.title }}</span>
                        <a v-else :href="`/decks/${d.id}`" target="_blank" rel="noopener" class="pr-link">{{ d.title || 'Untitled deck' }}</a>
                        <span class="pr-state">{{ d.slide_count }} {{ d.slide_count === 1 ? 'slide' : 'slides' }}{{ d.in_theme ? ', theme' : '' }}</span>
                    </li>
                </ul>
                <p v-else class="c-hint">No deck uses this file.</p>
                <template v-if="data.brand_kits.length">
                    <h4>Brand kits <span class="pr-state">{{ data.brand_kit_count }}</span></h4>
                    <ul><li v-for="k in data.brand_kits" :key="k.id"><span>{{ k.name }}</span></li></ul>
                </template>
            </template>
        </div>
    </Popover>
</template>
