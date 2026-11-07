<script setup>
// Slide and deck background. A slide background goes through slide.set; the deck default is the `bg` of the deck theme
// (deck.set). "Use theme background" returns the slide to the deck default. "Apply to all slides" asks first.
import { computed, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import { Dialog, EmptyState, PanelSection } from '../../../ui/controls';
import BackgroundControls from './BackgroundControls.vue';
import { applyToAllOps, bgKind, themeWithBg } from './backgroundModel.js';
import '../theme/design.css';

const editor = useEditorContext();
const { store } = editor;
const confirm = ref(false);

const slide = computed(() => editor.slide.value);
const deck = computed(() => editor.deck.value);
const slideBg = computed(() => slide.value?.bg ?? null);
const readOnly = computed(() => editor.readOnly || !editor.can.update);
const kindWord = { theme: 'deck default', solid: 'colour', gradient: 'gradient', image: 'image' };
const total = computed(() => editor.state.value.doc.slides.length);
const allOps = computed(() => applyToAllOps(editor.state.value.doc, slideBg.value));

function setSlide(v) {
    if (readOnly.value || !slide.value) return;
    store.setSlideProps(slide.value.id, { bg: v }, { undoKey: `bg:${slide.value.id}`, label: 'Slide background' });
}
function resetSlide() {
    if (readOnly.value || !slide.value) return;
    store.setSlideProps(slide.value.id, { bg: null }, { label: 'Use theme background' });
    editor.announce('This slide now follows the theme background');
}
function setDeck(v) {
    if (readOnly.value) return;
    store.setDeckProps({ theme: themeWithBg(deck.value.theme, v) }, { undoKey: 'deck-bg', label: 'Deck background' });
}
function resetDeck() {
    if (readOnly.value) return;
    store.setDeckProps({ theme: themeWithBg(deck.value.theme, null) }, { label: 'Remove deck background' });
}
function applyAll(close) {
    const ops = allOps.value;
    if (!ops.length) { close(); return; }
    store.dispatch(ops, { label: 'Background on all slides' });
    close();
    const n = ops.length;
    editor.toast(`Background applied to ${n} ${n === 1 ? 'slide' : 'slides'}`, { kind: 'ok', action: { label: 'Undo', run: () => store.undo() } });
    editor.announce(`Background applied to ${n} slides`);
}
</script>

<template>
    <div class="dz">
        <EmptyState v-if="!slide" compact title="No slide selected." body="Pick a slide on the left to change its background." />
        <template v-else>
            <PanelSection id="background.slide" title="This slide" :state="kindWord[bgKind(slideBg)]" :collapsible="false">
                <p v-if="readOnly" class="c-hint">You can see the background but not change it.</p>
                <BackgroundControls :model-value="slideBg" theme-label="Theme" label="Slide background" :disabled="readOnly" @update:model-value="setSlide" />
                <div class="dz-actions">
                    <button type="button" class="pr-btn pr-btn--sm" :disabled="readOnly || slideBg == null" @click="resetSlide">Use theme background</button>
                    <button type="button" class="pr-btn pr-btn--sm" :disabled="readOnly || total < 2" @click="confirm = true">Apply to all slides</button>
                </div>
            </PanelSection>

            <PanelSection id="background.deck" title="Deck default" :state="deck.theme?.bg ? kindWord[bgKind(deck.theme.bg)] : 'theme colour'" :default-open="false">
                <p class="c-hint">Every slide that follows the theme uses this. It is saved in the deck theme.</p>
                <BackgroundControls
                    :model-value="deck.theme?.bg ?? null" theme-label="Theme" label="Deck default background" :theme="deck.theme" :disabled="readOnly"
                    @update:model-value="setDeck"
                />
                <div class="dz-actions">
                    <button type="button" class="pr-btn pr-btn--sm" :disabled="readOnly || !deck.theme?.bg" @click="resetDeck">Remove deck default</button>
                </div>
            </PanelSection>
        </template>

        <Dialog v-model:open="confirm" title="Apply to all slides?" size="sm">
            <p class="pr-small">
                This copies the background of this slide ({{ kindWord[bgKind(slideBg)] }}) to {{ allOps.length === 1 ? 'one other slide' : `${allOps.length} other slides` }}
                and replaces the background they have now. You can undo it in one step.
            </p>
            <template #footer="{ close }">
                <button type="button" class="pr-btn" @click="close">Cancel</button>
                <button type="button" class="pr-btn pr-btn--primary" :disabled="!allOps.length" data-autofocus @click="applyAll(close)">Apply to {{ allOps.length }} {{ allOps.length === 1 ? 'slide' : 'slides' }}</button>
            </template>
        </Dialog>
    </div>
</template>
