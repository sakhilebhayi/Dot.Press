<script setup>
// One file in the library: click to add it to the slide, drag it onto the stage, see where it is used, open its details.
import { computed } from 'vue';
import Ico from '../../ui/Ico.vue';
import { IconButton } from '../../../ui/controls/index.js';
import { dimensions, formatBytes, kindOf, usageWords } from './assetsModel.js';

const props = defineProps({
    asset: { type: Object, required: true },
    view: { type: String, default: 'grid' },
    canAdd: { type: Boolean, default: true },
    replaceKind: { type: String, default: null }, // 'image' | 'video' when one such element is selected
    broken: { type: Boolean, default: false },
});
const emit = defineEmits(['add', 'replace', 'details', 'usage', 'imgerror', 'dragstart', 'dragend']);
const kind = computed(() => kindOf(props.asset));
const use = computed(() => usageWords(props.asset.used_in));
const glyph = computed(() => ({ image: 'image', video: 'video', audio: 'audio' })[kind.value]);
const thumb = computed(() => (props.asset.thumb && !props.broken ? props.asset.thumb : null));
const sub = computed(() => [kind.value, formatBytes(props.asset.size), dimensions(props.asset)].filter(Boolean).join(', '));
const canReplace = computed(() => props.canAdd && props.replaceKind && props.replaceKind === kind.value);
</script>

<template>
    <li class="al-card" :data-id="asset.id">
        <button
            type="button" class="al-main" :draggable="canAdd" :aria-label="`${canAdd ? 'Add to slide' : 'Preview'}: ${asset.name}`"
            @click="canAdd ? emit('add', asset) : emit('details', asset)" @dragstart="emit('dragstart', $event, asset)" @dragend="emit('dragend')"
        >
            <span class="al-thumb">
                <img v-if="thumb" :src="thumb" :alt="asset.alt || ''" loading="lazy" decoding="async" draggable="false" @error="emit('imgerror', asset)" />
                <Ico v-else :name="glyph" />
                <span class="al-kind pr-state">{{ kind }}</span>
            </span>
            <span class="al-text">
                <span class="al-name" :title="asset.name">{{ asset.name }}</span>
                <span class="al-sub pr-state">{{ sub }}</span>
            </span>
        </button>
        <div class="al-meta">
            <button type="button" class="al-use pr-state" :aria-label="`${use.word}. ${use.used ? 'Show where' : ''}`" :disabled="!use.used" @click="emit('usage', asset, $event.currentTarget)">
                <span class="pr-bead pr-bead--sm" :data-state="use.used ? 'solid' : 'hollow'" aria-hidden="true"></span><span>{{ use.short }}</span>
            </button>
            <IconButton v-if="canReplace" icon="refresh" :label="`Replace the selected ${replaceKind === 'video' ? 'video' : 'picture'} with ${asset.name}`" size="sm" @click="emit('replace', asset)" />
            <IconButton icon="info" :label="`Details for ${asset.name}`" size="sm" @click="emit('details', asset)" />
        </div>
    </li>
</template>
