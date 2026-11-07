<script setup>
// One theme as a real mini slide: SlideView draws a sample slide (or the current slide) in the theme.
import { computed } from 'vue';
import { useEditorContext } from '../../context.js';
import { SlideView } from '../../../render/index.js';
import { cleanTheme } from './themeModel.js';
import { sampleSlide } from './sampleDoc.js';

const props = defineProps({
    theme: { type: Object, required: true },
    source: { type: String, default: 'sample' }, // sample | slide
    candidate: { type: Boolean, default: false }, // chosen, not yet applied
    current: { type: Boolean, default: false }, // the deck uses it
    modified: { type: Boolean, default: false },
    tabbable: { type: Boolean, default: false },
});
const emit = defineEmits(['pick']);
const editor = useEditorContext();

const clean = computed(() => cleanTheme(props.theme));
const view = computed(() => {
    if (props.source === 'slide' && editor.slide.value) {
        const d = editor.deck.value;
        return { deck: { w: d.w, h: d.h, theme: clean.value }, slide: editor.slide.value };
    }
    return sampleSlide(clean.value);
});
const name = computed(() => props.theme.kitName ? `${props.theme.kitName}` : props.theme.name);
</script>

<template>
    <button
        type="button" role="radio" class="dz-card" :class="{ 'is-candidate': candidate, 'is-current': current }" :aria-checked="candidate" :tabindex="tabbable ? 0 : -1"
        :data-theme-id="theme.id" :aria-label="`${name}${current ? ', in use' : ''}${theme.isDefault ? ', team default' : ''}`" @click="emit('pick')"
    >
        <span class="dz-card-slide" aria-hidden="true"><SlideView :slide="view.slide" :deck="view.deck" :assets="editor.assets" mode="thumb" /></span>
        <span class="dz-card-meta">
            <span v-if="current" class="pr-bead pr-bead--sm" data-state="current" aria-hidden="true"></span>
            <span class="dz-card-name">{{ name }}</span>
            <span v-if="current" class="pr-state">{{ modified ? 'in use, edited' : 'in use' }}</span>
            <span v-else-if="theme.isDefault" class="pr-state">default</span>
        </span>
    </button>
</template>
