<script setup>
// A temporary preview: the deck's own slides drawn in the chosen theme next to the current look. Nothing is committed.
import { computed, ref, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import { Dialog, IconButton, Segmented } from '../../../ui/controls';
import { SlideView } from '../../../render/index.js';
import { cleanTheme } from './themeModel.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    theme: { type: Object, default: null },
    canApply: { type: Boolean, default: true },
});
const emit = defineEmits(['update:open', 'apply']);
const editor = useEditorContext();
const which = ref('new');
const index = ref(0);

watch(() => props.open, (on) => {
    if (!on) return;
    which.value = 'new';
    const slides = editor.state.value.doc.slides;
    index.value = Math.max(0, slides.findIndex((s) => s.id === editor.state.value.slideId));
});

const slides = computed(() => editor.state.value.doc.slides);
const slide = computed(() => slides.value[Math.min(index.value, slides.value.length - 1)] || null);
const shownTheme = computed(() => (which.value === 'new' && props.theme ? cleanTheme(props.theme) : editor.deck.value.theme));
const deck = computed(() => ({ w: editor.deck.value.w, h: editor.deck.value.h, theme: shownTheme.value }));
const step = (d) => { index.value = (index.value + d + slides.value.length) % slides.value.length; };
</script>

<template>
    <Dialog :open="open" :title="`Preview: ${theme?.kitName || theme?.name || ''}`" size="lg" @update:open="emit('update:open', $event)">
        <template #default>
            <div class="c-stack">
                <Segmented
                    v-model="which" label="Show" :options="[{ value: 'new', label: 'With the new theme' }, { value: 'old', label: 'As it is now' }]"
                />
                <div class="dz-pv" role="img" :aria-label="`Slide ${index + 1} of ${slides.length} drawn with ${which === 'new' ? 'the new theme' : 'the current theme'}`">
                    <SlideView v-if="slide" :slide="slide" :deck="deck" :assets="editor.assets" mode="view" />
                </div>
                <div class="dz-pv-nav">
                    <IconButton icon="chevronLeft" label="Previous slide" :disabled="slides.length < 2" @click="step(-1)" />
                    <span class="pr-state" aria-live="polite">Slide {{ index + 1 }} of {{ slides.length }}</span>
                    <IconButton icon="chevronRight" label="Next slide" :disabled="slides.length < 2" @click="step(1)" />
                </div>
                <p class="c-hint">This is a preview only. The deck changes when you choose Apply theme.</p>
            </div>
        </template>
        <template #footer="{ close }">
            <button type="button" class="pr-btn" @click="close">Close preview</button>
            <button type="button" class="pr-btn pr-btn--primary" :disabled="!canApply" @click="emit('apply'); close()">Apply theme</button>
        </template>
    </Dialog>
</template>
