<script setup>
// Inspector panel for audio: the file from the library (or a new upload), and how it plays. In the editor an audio element is
// a speaker mark; the player shows a control bar only when "Show controls" is on.
import { computed, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import {
    PanelSection, Toggle, Field, InlineNotice, usePanelChange, MIXED,
} from '../../../ui/controls/index.js';
import Ico from '../../ui/Ico.vue';
import { resolveSrc } from '../../../render/media.js';
import AssetPicker from '../image/AssetPicker.vue';
import '../image/media.css';

const editor = useEditorContext();
const pc = usePanelChange(editor);
const els = pc.elements;
const single = computed(() => (els.value.length === 1 ? els.value[0] : null));
const src = computed(() => (single.value ? resolveSrc(single.value.src, editor.assets) : null));
const picking = ref(false);
function onPick(a) {
    if (pc.each(() => ({ src: { asset: a.id } }), { label: 'Replace audio' })) editor.announce(`Using ${a.name}`);
}
const defaults = { autoplay: false, loop: false, controls: true };
const val = (k) => pc.value(k, { fallback: defaults[k] });
const toggles = [{ key: 'autoplay', label: 'Autoplay' }, { key: 'loop', label: 'Loop' }, { key: 'controls', label: 'Show controls' }];
</script>

<template>
    <div class="pm-panel" data-panel-root="audio">
        <PanelSection id="audio.source" :title="single ? 'Audio' : `${els.length} audio files`">
            <template v-if="single">
                <div v-if="src" class="pm-src">
                    <div class="pm-src-img"><Ico name="audio" size="lg" /></div>
                    <div class="pm-src-t"><span class="pm-src-n" :title="src.name">{{ src.name || 'Audio' }}</span></div>
                </div>
                <p v-else class="c-hint">No audio file yet. Choose one from the library or upload a file.</p>
                <div class="pm-actions">
                    <button type="button" class="pr-btn" :disabled="!pc.canEdit.value" @click="picking = true"><Ico name="folder" size="sm" />Choose or upload</button>
                </div>
                <div v-if="src" class="pm-preview">
                    <span class="c-label">Preview</span>
                    <audio class="pm-prev-a" :src="src.url" controls preload="metadata" :aria-label="`Preview of ${src.name || 'the audio'}`"></audio>
                </div>
            </template>
            <p v-else class="c-hint">Pick one audio file to change its source. The options below apply to all of them.</p>
        </PanelSection>

        <PanelSection id="audio.play" title="Playback">
            <div class="c-stack">
                <Toggle v-for="t in toggles" :key="t.key" :label="t.label" :model-value="val(t.key)" :disabled="!pc.canEdit.value" @update:model-value="(v) => pc.set(t.key, v, { label: t.label })" />
                <InlineNotice v-if="val('autoplay') === true" kind="info" title="Autoplay needs a click first">
                    Sound starts by itself when the slide appears in the player. Browsers can still refuse until the viewer has clicked or pressed a key on the page, which pressing Present counts as.
                </InlineNotice>
                <p v-if="val('controls') === false" class="c-hint">With controls off, nothing on the slide shows the audio. Turn on autoplay or the audience cannot start it.</p>
            </div>
        </PanelSection>

        <PanelSection id="audio.alt" title="Description">
            <Field v-slot="{ id }" label="Alt text" hint="What the audio is, for people who cannot hear it.">
                <textarea :id="id" class="pr-field" rows="2" maxlength="500" :value="pc.value('alt') === MIXED ? '' : pc.value('alt') || ''" :placeholder="pc.value('alt') === MIXED ? 'Mixed' : 'The founder reading the opening paragraph'" :disabled="!pc.canEdit.value" @input="(e) => pc.set('alt', e.target.value === '' ? null : e.target.value, { label: 'Alt text' })"></textarea>
            </Field>
        </PanelSection>

        <AssetPicker v-model:open="picking" kind="audio" title="Choose an audio file" :current-id="single?.src?.asset ?? null" can-upload @pick="onPick" />
    </div>
</template>

