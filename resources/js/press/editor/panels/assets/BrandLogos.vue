<script setup>
// The team's brand kits (GET /api/brand-kits): each logo is a one click insert. Hidden when the team has no logos; a failure says so.
import { onMounted, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import { PanelSection, InlineNotice, ProgressBead } from '../../../ui/controls/index.js';
import { describeApiError } from './assetsModel.js';
import { useInsert } from '../insert/useInsert.js';

const props = defineProps({ canAdd: { type: Boolean, default: true } });
const emit = defineEmits(['dragstart', 'dragend', 'imgerror']);
const editor = useEditorContext();
const ins = useInsert(editor);
const kits = ref([]);
const state = ref('loading');
const error = ref('');

async function load() {
    state.value = 'loading';
    try {
        const res = await editor.api.get('/api/brand-kits');
        kits.value = (res.data || []).filter((k) => (k.logo_assets || []).length);
        state.value = 'ready';
    } catch (e) {
        error.value = describeApiError(e, 'your brand kits');
        state.value = 'error';
    }
}
const asImage = (logo, kit) => ({ ...logo, kind: 'image', alt: `${kit.name} logo` });
function add(logo, kit) { ins.insertAsset(asImage(logo, kit), 'image'); }
onMounted(load);
defineExpose({ reload: load });
</script>

<template>
    <PanelSection v-if="state !== 'ready' || kits.length" id="assets.brand" title="Brand logos" :state="state === 'ready' ? String(kits.reduce((n, k) => n + k.logo_assets.length, 0)) : ''">
        <ProgressBead v-if="state === 'loading'" label="Loading brand logos" />
        <InlineNotice v-else-if="state === 'error'" kind="error">{{ error }} <button type="button" class="pr-btn pr-btn--sm" @click="load">Try again</button></InlineNotice>
        <template v-else>
            <div v-for="kit in kits" :key="kit.id">
                <p class="al-kit pr-state">{{ kit.name }}{{ kit.is_default ? ', default' : '' }}</p>
                <ul class="al-logos">
                    <li v-for="logo in kit.logo_assets" :key="logo.id">
                        <button
                            type="button" class="al-logo" :draggable="props.canAdd" :disabled="!props.canAdd" :aria-label="`Add the ${kit.name} logo ${logo.name}`"
                            @click="add(logo, kit)" @dragstart="emit('dragstart', $event, asImage(logo, kit))" @dragend="emit('dragend')"
                        ><img :src="logo.thumb || logo.url" alt="" loading="lazy" decoding="async" draggable="false" @error="emit('imgerror')" /></button>
                    </li>
                </ul>
            </div>
        </template>
    </PanelSection>
</template>
