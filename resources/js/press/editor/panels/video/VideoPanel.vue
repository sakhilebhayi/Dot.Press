<script setup>
// Inspector panel for video: where it comes from (the library, a YouTube or Vimeo address, or a link to a file), the poster
// (library picture or a frame of an uploaded video), and how it plays. Embeds are never loaded in the editor: the slide shows
// a facade and the iframe only exists when presenting with embeds allowed or after a click, which is how the renderer works.
import { computed, ref, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import {
    PanelSection, NumberField, Segmented, Toggle, Field, InlineNotice, ProgressBead, usePanelChange, MIXED,
} from '../../../ui/controls/index.js';
import Ico from '../../ui/Ico.vue';
import { replacePatch } from '../../stage/lib/drop.js';
import { embedInfo, resolveSrc } from '../../../render/media.js';
import AssetPicker from '../image/AssetPicker.vue';
import { apiProblem, registerAsset } from '../image/lib/library.js';
import { classifyVideoUrl, captureProblem, formatStart } from './lib/embed.js';
import '../image/media.css';

const editor = useEditorContext();
const pc = usePanelChange(editor);
const els = pc.elements;
const single = computed(() => (els.value.length === 1 ? els.value[0] : null));
const hasStage = computed(() => !!editor.stage);

const src = computed(() => (single.value ? resolveSrc(single.value.src, editor.assets) : null));
const embed = computed(() => (single.value?.embed ? embedInfo(single.value.embed, single.value.start) : null));
// The library gives a video a thumbnail only when it has a poster; otherwise the entry's thumb is the video file itself.
const realThumb = computed(() => {
    const a = single.value?.src?.asset != null ? editor.assets[single.value.src.asset] : null;
    return a && a.thumb && a.thumb !== a.url ? a.thumb : null;
});
const posterSrc = computed(() => (single.value?.poster ? resolveSrc(single.value.poster, editor.assets) : null));
const sourceKind = computed(() => (embed.value ? 'embed' : single.value?.src?.asset != null ? 'asset' : single.value?.src?.url ? 'file' : 'none'));

// ---- source ------------------------------------------------------------------------------------------------
const mode = ref('library');
watch(() => single.value?.id, () => { mode.value = sourceKind.value === 'embed' || sourceKind.value === 'file' ? 'link' : 'library'; }, { immediate: true });
const modeOptions = [{ value: 'library', label: 'Library' }, { value: 'link', label: 'Web link' }];
const picking = ref(false);
const pickingPoster = ref(false);
const linkText = ref('');
const linkError = ref('');
function onPick(a) {
    if (pc.each((el) => replacePatch(el, { asset: a.id }), { label: 'Replace video' })) editor.announce(`Using ${a.name}`);
}
function useLink() {
    const r = classifyVideoUrl(linkText.value);
    if (r.kind === 'error') { linkError.value = r.message; return; }
    linkError.value = '';
    const ok = pc.each((el) => (r.kind === 'embed'
        ? { embed: r.embed, src: null, ...(r.start > 0 ? { start: r.start } : {}) }
        : { src: { url: r.url }, embed: null }), { label: 'Set video address' });
    if (ok) {
        linkText.value = '';
        editor.announce(r.kind === 'embed' ? `Using a ${r.embed.provider === 'youtube' ? 'YouTube' : 'Vimeo'} video` : 'Using a video file link');
    }
}
function upload() { if (single.value) editor.stage?.replacePicture?.(single.value.id); }

// ---- poster ------------------------------------------------------------------------------------------------
const vid = ref(null);
const ready = ref(false);
const capture = ref({ busy: false, error: '' });
watch(() => src.value?.url, () => { ready.value = false; capture.value = { busy: false, error: '' }; });
const captureWhy = computed(() => captureProblem({
    hasAssetSource: sourceKind.value === 'asset' && !!src.value,
    ready: ready.value,
    videoWidth: vid.value?.videoWidth || 0,
}));
function onPosterPick(a) {
    pc.each(() => ({ poster: { asset: a.id } }), { label: 'Set poster' });
    editor.announce('Poster set');
}
async function useFrame() {
    const v = vid.value;
    const e = single.value;
    if (!v || !e || captureWhy.value) return;
    capture.value = { busy: true, error: '' };
    try {
        const scale = Math.min(1, 1920 / v.videoWidth);
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(v.videoWidth * scale));
        canvas.height = Math.max(1, Math.round(v.videoHeight * scale));
        canvas.getContext('2d').drawImage(v, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.88));
        if (!blob) throw new Error('This browser could not read the frame.');
        const form = new FormData();
        form.append('file', blob, 'poster.jpg');
        const res = await editor.api.post(`/api/assets/${e.src.asset}/poster`, form);
        registerAsset(editor, { ...res.poster, kind: 'poster', name: 'Poster' });
        pc.each(() => ({ poster: { asset: res.poster.id } }), { label: 'Set poster' });
        capture.value = { busy: false, error: '' };
        editor.announce(`Poster set to the frame at ${formatStart(v.currentTime)}`);
    } catch (err) {
        const message = err?.name === 'SecurityError' ? 'This browser would not let the frame be read.' : apiProblem(err).message;
        capture.value = { busy: false, error: message };
    }
}

// ---- playback ----------------------------------------------------------------------------------------------
const tog = (key, label) => ({ key, label });
const toggles = [tog('autoplay', 'Autoplay'), tog('loop', 'Loop'), tog('muted', 'Muted'), tog('controls', 'Show controls')];
const defaults = { autoplay: false, loop: false, muted: false, controls: true };
const val = (k) => pc.value(k, { fallback: defaults[k] });
const blockedAutoplay = computed(() => val('autoplay') === true && val('muted') !== true);
const start = computed(() => pc.value('start', { fallback: 0 }));
</script>

<template>
    <div class="pm-panel" data-panel-root="video">
        <PanelSection id="video.source" :title="single ? 'Video' : `${els.length} videos`">
            <template v-if="single">
                <Segmented v-model="mode" label="Source" :options="modeOptions" :disabled="!pc.canEdit.value" />

                <template v-if="mode === 'library'">
                    <div v-if="sourceKind === 'asset'" class="pm-src">
                        <div class="pm-src-img"><img v-if="posterSrc || realThumb" :src="posterSrc?.thumb || realThumb" alt="" /><Ico v-else name="video" /></div>
                        <div class="pm-src-t">
                            <span class="pm-src-n" :title="src?.name">{{ src?.name || 'Video' }}</span>
                            <span v-if="src?.w" class="pm-src-s pr-state">{{ src.w }} x {{ src.h }} px</span>
                        </div>
                    </div>
                    <p v-else class="c-hint">{{ sourceKind === 'none' ? 'No video yet. Choose one from the library or upload a file.' : 'This video uses a web link. Choose a file to switch to the library.' }}</p>
                    <div class="pm-actions">
                        <button type="button" class="pr-btn" :disabled="!pc.canEdit.value" @click="picking = true"><Ico name="folder" size="sm" />Choose from library</button>
                        <button v-if="hasStage" type="button" class="pr-btn" :disabled="!pc.canEdit.value" @click="upload"><Ico name="upload" size="sm" />Upload new</button>
                    </div>
                </template>

                <template v-else>
                    <div v-if="embed" class="pm-src">
                        <div class="pm-src-img"><Ico name="video" /></div>
                        <div class="pm-src-t">
                            <span class="pm-src-n">{{ embed.label }} video</span>
                            <a class="pm-src-s pr-state" :href="embed.page" target="_blank" rel="noopener noreferrer">{{ embed.id }}</a>
                        </div>
                    </div>
                    <div v-else-if="sourceKind === 'file'" class="pm-src">
                        <div class="pm-src-img"><Ico name="video" /></div>
                        <div class="pm-src-t"><span class="pm-src-n">Video file link</span><span class="pm-src-s pr-state" :title="single.src.url">{{ single.src.url }}</span></div>
                    </div>
                    <Field v-slot="{ id, describedby }" label="YouTube, Vimeo or file address" :error="linkError" hint="Paste the address from the browser bar. Only https links are used.">
                        <div class="pm-pick-bar">
                            <input :id="id" v-model="linkText" class="pr-field" type="url" inputmode="url" autocomplete="off" placeholder="https://youtu.be/..." :aria-describedby="describedby" :disabled="!pc.canEdit.value" @keydown.enter.prevent="useLink" @input="linkError = ''" />
                            <button type="button" class="pr-btn pr-btn--primary" :disabled="!pc.canEdit.value || !linkText.trim()" @click="useLink">Use</button>
                        </div>
                    </Field>
                </template>

                <div v-if="src && sourceKind !== 'embed'" class="pm-preview">
                    <span class="c-label">Preview</span>
                    <video ref="vid" class="pm-prev" :src="src.url" :poster="posterSrc?.url || undefined" controls muted playsinline preload="metadata" :aria-label="`Preview of ${src.name || 'the video'}`" @loadeddata="ready = true"></video>
                </div>
                <p v-else-if="embed" class="c-hint">The slide shows a play button. The video loads from {{ embed.host }} only when someone presses it.</p>
            </template>
            <p v-else class="c-hint">Pick one video to change where it comes from. The playback options below apply to all of them.</p>
        </PanelSection>

        <PanelSection id="video.poster" title="Poster" :state="single ? (posterSrc ? 'set' : 'none') : ''">
            <template v-if="single">
                <div class="pm-poster">
                    <div class="pm-src-img"><img v-if="posterSrc" :src="posterSrc.thumb" alt="" /><Ico v-else name="image" /></div>
                    <p class="c-hint" style="margin: 0">The picture shown before the video plays and on the slide thumbnails.</p>
                </div>
                <div class="pm-actions">
                    <button type="button" class="pr-btn" :disabled="!pc.canEdit.value" @click="pickingPoster = true"><Ico name="image" size="sm" />Choose a picture</button>
                    <button type="button" class="pr-btn" :disabled="!pc.canEdit.value || !!captureWhy || capture.busy" :title="captureWhy || ''" @click="useFrame"><Ico name="video" size="sm" />Use this frame</button>
                    <button v-if="posterSrc" type="button" class="pr-btn pr-btn--ghost" :disabled="!pc.canEdit.value" @click="pc.reset('poster', { label: 'Remove poster' })">Remove</button>
                </div>
                <p v-if="sourceKind === 'asset' && captureWhy" class="c-hint">{{ captureWhy }}</p>
                <p v-else-if="sourceKind === 'asset'" class="c-hint">Pause the preview on the frame you want, then press Use this frame.</p>
                <p v-else-if="sourceKind !== 'none'" class="c-hint">A frame can only be taken from a video in the library.</p>
                <ProgressBead v-if="capture.busy" label="Saving the frame" />
                <InlineNotice v-if="capture.error" kind="error" title="No poster saved">{{ capture.error }}</InlineNotice>
            </template>
            <p v-else class="c-hint">Pick one video to set its poster.</p>
        </PanelSection>

        <PanelSection id="video.play" title="Playback">
            <div class="c-stack">
                <Toggle v-for="t in toggles" :key="t.key" :label="t.label" :model-value="val(t.key)" :disabled="!pc.canEdit.value" @update:model-value="(v) => pc.set(t.key, v, { label: t.label })" />
                <InlineNotice v-if="blockedAutoplay" kind="info" title="Browsers block autoplay with sound">
                    A video that starts by itself must be muted, or the browser refuses and the audience sees a Play button. Mute it so it can start on its own.
                    <button v-if="pc.canEdit.value" type="button" class="pr-btn pr-btn--sm pm-note-btn" @click="pc.set('muted', true, { label: 'Mute video' })">Mute this video</button>
                </InlineNotice>
                <p v-if="embed && val('autoplay') === true" class="c-hint">Embedded videos start by themselves only when presenting with embeds allowed.</p>
                <NumberField label="Start at" unit="s" :min="0" :max="86400" :step="1" :model-value="start" :disabled="!pc.canEdit.value" :hint="typeof start === 'number' && start > 0 ? `Starts at ${formatStart(start)}.` : 'Seconds into the video.'" @update:model-value="(v) => pc.set('start', v || null, { label: 'Start time' })" />
            </div>
        </PanelSection>

        <PanelSection id="video.alt" title="Description">
            <Field v-slot="{ id }" label="Alt text" hint="What the video is about, for people who cannot watch it.">
                <textarea :id="id" class="pr-field" rows="2" maxlength="500" :value="pc.value('alt') === MIXED ? '' : pc.value('alt') || ''" :placeholder="pc.value('alt') === MIXED ? 'Mixed' : 'A two minute tour of the new dashboard'" :disabled="!pc.canEdit.value" @input="(e) => pc.set('alt', e.target.value === '' ? null : e.target.value, { label: 'Alt text' })"></textarea>
            </Field>
        </PanelSection>

        <AssetPicker v-model:open="picking" kind="video" title="Choose a video" :current-id="single?.src?.asset ?? null" can-upload @pick="onPick" />
        <AssetPicker v-model:open="pickingPoster" kind="image" title="Choose a poster" :current-id="single?.poster?.asset ?? null" @pick="onPosterPick" />
    </div>
</template>

