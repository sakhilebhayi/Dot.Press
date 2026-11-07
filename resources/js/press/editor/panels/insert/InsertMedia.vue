<script setup>
// Media: pictures, video and audio. Files are uploaded to the asset library (progress per file) and then placed on the slide; a link
// to YouTube, Vimeo or a video file is added as a video without uploading anything.
import { computed, nextTick, onMounted, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import { InlineNotice } from '../../../ui/controls/index.js';
import Ico from '../../ui/Ico.vue';
import { parseVideoUrl } from './insertModel.js';
import { useInsert } from './useInsert.js';
import { useAssetUploads } from '../assets/useAssetUploads.js';
import UploadList from '../assets/UploadList.vue';

const props = defineProps({ focusKind: { type: String, default: null } });
const editor = useEditorContext();
const ins = useInsert(editor);
const picker = ref(null);
const dropBtn = ref(null);
const urlIn = ref(null);
const over = ref(false);
const url = ref('');
const urlError = ref('');
const notes = ref([]);

const up = useAssetUploads(editor, {
    onDone(item) {
        if (!item.asset) return;
        ins.insertAsset(item.asset);
        if (item.deduplicated) notes.value = [...notes.value.slice(-2), `${item.name} was already in your library, so the existing copy was used.`];
    },
});

const parsed = computed(() => (url.value.trim() ? parseVideoUrl(url.value) : null));
const hint = computed(() => {
    const p = parsed.value;
    if (!p?.ok) return '';
    if (p.provider === 'file') return 'A video file. It plays from that address.';
    return `${p.label} video${p.start ? `, starts at ${Math.floor(p.start / 60)}:${String(p.start % 60).padStart(2, '0')}` : ''}. It loads only when someone presses play.`;
});

function addFiles(list) {
    if (!ins.canEdit) { editor.toast('You can view this deck but not change it.', { kind: 'warn' }); return; }
    const files = [...list];
    if (files.length) up.add(files);
}
function onPick(e) { addFiles(e.target.files || []); e.target.value = ''; }
function onDrop(e) { over.value = false; e.preventDefault(); addFiles(e.dataTransfer?.files || []); }
function onOver(e) { if ([...(e.dataTransfer?.types || [])].includes('Files')) { e.preventDefault(); over.value = true; if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'; } }

function addUrl() {
  const p = parseVideoUrl(url.value);
  if (!p.ok) { urlError.value = p.error; urlIn.value?.focus(); return; }
  urlError.value = '';
  if (ins.insertVideo(p)) url.value = '';
}
onMounted(async () => {
    await nextTick();
    if (props.focusKind === 'video') urlIn.value?.focus();
});
const canLibrary = computed(() => editor.hasPanel?.('assets'));
</script>

<template>
    <section class="ip-sec" aria-labelledby="ip-media-h" style="display: grid; gap: 14px">
        <h3 id="ip-media-h" class="ip-h">Pictures, video and audio</h3>
        <input ref="picker" type="file" class="pr-sr" tabindex="-1" aria-hidden="true" accept="image/*,video/*,audio/*" multiple @change="onPick" />
        <button
            ref="dropBtn" type="button" class="ip-drop" :class="{ 'is-over': over }" :disabled="!ins.canEdit" @click="picker.click()" @dragover="onOver" @dragleave="over = false" @drop="onDrop"
        >
            <Ico name="upload" />
            <span class="ip-drop-t">{{ over ? 'Drop to upload' : 'Drop files here or choose them' }}</span>
            <span class="ip-drop-s">Pictures, video and audio. Each file goes into your library and onto the slide.</span>
        </button>
        <UploadList :items="up.items.value" @cancel="up.cancel" @retry="up.retry" @remove="up.remove" @clear="up.clearFinished" />
        <InlineNotice v-for="(n, i) in notes" :key="i" kind="info">{{ n }}</InlineNotice>

        <div class="ip-or pr-state" aria-hidden="true">or add a link</div>
        <form class="ip-url" novalidate @submit.prevent="addUrl">
            <label class="c-label" for="ip-video-url">Video link</label>
            <div class="ip-url-row">
                <input id="ip-video-url" ref="urlIn" v-model="url" type="url" class="pr-field" placeholder="Paste a video link" autocomplete="off" spellcheck="false" :disabled="!ins.canEdit" :aria-invalid="!!urlError" :aria-describedby="urlError ? 'ip-url-err' : 'ip-url-hint'" @input="urlError = ''" />
                <button type="submit" class="pr-btn pr-btn--primary" :disabled="!ins.canEdit || !url.trim()">Add video</button>
            </div>
            <p v-if="urlError" id="ip-url-err" class="c-error" role="alert"><span class="pr-bead pr-bead--bad pr-bead--sm" aria-hidden="true"></span>{{ urlError }}</p>
            <p v-else id="ip-url-hint" class="c-hint">{{ hint || 'Only https links. YouTube and Vimeo videos load when someone presses play.' }}</p>
        </form>
        <button v-if="canLibrary" type="button" class="pr-btn" @click="editor.openPanel('assets')"><Ico name="folder" size="sm" />Browse the library</button>
    </section>
</template>
