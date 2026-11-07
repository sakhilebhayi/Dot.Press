<script setup>
// The asset library in a dialog: search, a grid of what the project holds for one kind (image, video or audio), load more, and
// an optional upload. Picking emits `pick` with the asset payload (already registered in the editor's asset map).
// States: loading, empty (with and without a search), error with a retry, 403 (read only), 429 (wait), offline.
import {
    computed, nextTick, onBeforeUnmount, ref, watch,
} from 'vue';
import { useEditorContext } from '../../context.js';
import {
    Dialog, EmptyState, InlineNotice, ProgressBead,
} from '../../../ui/controls/index.js';
import Ico from '../../ui/Ico.vue';
import {
    listParams, apiProblem, registerAsset, uploadProblem, humanSize,
} from './lib/library.js';
import './media.css';

const props = defineProps({
    open: { type: Boolean, default: false },
    kind: { type: String, required: true }, // image | video | audio
    title: { type: String, default: '' },
    currentId: { type: [Number, String], default: null },
    canUpload: { type: Boolean, default: false },
});
const emit = defineEmits(['update:open', 'pick']);
const editor = useEditorContext();

const noun = computed(() => ({ image: 'picture', video: 'video', audio: 'audio file' })[props.kind] || 'file');
const heading = computed(() => props.title || `Choose a ${noun.value}`);

const q = ref('');
const items = ref([]);
const cursor = ref(null);
const more = ref(false);
const loading = ref(false);
const problem = ref(null);
const loaded = ref(false);
const upload = ref({ busy: false, progress: null, error: '' });
const fileInput = ref(null);
let seq = 0;
let timer = 0;

async function load(reset) {
    const mine = ++seq;
    loading.value = true;
    problem.value = null;
    if (reset) { cursor.value = null; }
    try {
        const res = await editor.api.get('/api/assets', listParams({
            kind: props.kind, q: q.value, cursor: reset ? null : cursor.value, projectId: editor.deck.value?.project_id,
        }));
        if (mine !== seq) return;
        const rows = Array.isArray(res?.data) ? res.data : [];
        items.value = reset ? rows : [...items.value, ...rows];
        cursor.value = res?.next_cursor || null;
        more.value = !!res?.has_more;
        loaded.value = true;
    } catch (e) {
        if (mine !== seq) return;
        problem.value = apiProblem(e);
    } finally {
        if (mine === seq) loading.value = false;
    }
}
function close() { emit('update:open', false); }
watch(() => props.open, (on) => {
    if (!on) return;
    q.value = '';
    items.value = [];
    loaded.value = false;
    upload.value = { busy: false, progress: null, error: '' };
    load(true);
}, { immediate: true });
watch(q, () => {
    if (!props.open) return;
    clearTimeout(timer);
    timer = setTimeout(() => load(true), 250);
});
onBeforeUnmount(() => clearTimeout(timer));

function pick(asset) {
    registerAsset(editor, asset);
    emit('pick', asset);
    close();
}

async function onFile(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    const bad = uploadProblem(file, props.kind);
    if (bad) { upload.value = { busy: false, progress: null, error: bad }; return; }
    upload.value = { busy: true, progress: 0, error: '' };
    const form = new FormData();
    form.append('file', file);
    const pid = editor.deck.value?.project_id;
    if (pid != null) form.append('project_id', String(pid));
    try {
        const res = await editor.api.post('/api/assets', form, {
            onUploadProgress: (ev) => { if (ev.total) upload.value = { ...upload.value, progress: Math.min(0.97, ev.loaded / ev.total) }; },
        });
        upload.value = { busy: false, progress: null, error: '' };
        pick(res.asset);
    } catch (err) {
        upload.value = { busy: false, progress: null, error: apiProblem(err).message };
    }
}
const accept = computed(() => `${props.kind}/*`);
const search = ref(null);
watch(() => props.open, async (on) => { if (on) { await nextTick(); search.value?.focus(); } }, { flush: 'post' });
const sizeWord = (a) => [a.width && a.height ? `${a.width} x ${a.height}` : '', humanSize(a.size)].filter(Boolean).join(', ');
</script>

<template>
    <Dialog :open="open" :title="heading" size="md" @update:open="(v) => emit('update:open', v)">
        <div class="pm-pick">
            <div class="pm-pick-bar">
                <label class="pr-sr" for="pm-pick-q">Search the library</label>
                <input id="pm-pick-q" ref="search" v-model="q" class="pr-field" type="search" :placeholder="`Search ${noun}s by name`" autocomplete="off" data-autofocus />
                <template v-if="canUpload">
                    <button type="button" class="pr-btn" :disabled="upload.busy || editor.readOnly" @click="fileInput.click()"><Ico name="upload" size="sm" />Upload</button>
                    <input ref="fileInput" type="file" class="pr-sr" tabindex="-1" :accept="accept" @change="onFile" />
                </template>
            </div>
            <ProgressBead v-if="upload.busy" :value="upload.progress" label="Uploading" />
            <InlineNotice v-if="upload.error" kind="error">{{ upload.error }}</InlineNotice>

            <InlineNotice v-if="problem" :kind="problem.kind === 'limited' ? 'warn' : 'error'" :title="problem.kind === 'forbidden' ? 'Not allowed' : problem.kind === 'limited' ? 'Slow down' : 'Could not load the library'">
                {{ problem.message }}
                <button v-if="problem.kind !== 'forbidden'" type="button" class="pr-btn pr-btn--sm pm-retry" @click="load(true)">Try again</button>
            </InlineNotice>
            <ProgressBead v-else-if="loading && !items.length" label="Loading the library" />
            <EmptyState v-else-if="loaded && !items.length" compact :title="q ? 'Nothing matches.' : `No ${noun}s yet.`" :body="q ? 'Try a shorter search.' : canUpload ? 'Upload one to start the library.' : 'Add files from the Assets panel.'" />

            <ul v-if="items.length" class="pm-grid" role="list" :aria-busy="loading">
                <li v-for="a in items" :key="a.id">
                    <button type="button" class="pm-tile" :class="{ 'is-current': String(a.id) === String(currentId) }" :aria-pressed="String(a.id) === String(currentId)" @click="pick(a)">
                        <span class="pm-tile-img">
                            <img v-if="a.thumb || (kind === 'image' && a.url)" :src="a.thumb || a.url" alt="" loading="lazy" decoding="async" />
                            <Ico v-else :name="kind === 'audio' ? 'audio' : kind === 'video' ? 'video' : 'image'" size="lg" />
                        </span>
                        <span class="pm-tile-n">{{ a.name }}</span>
                        <span class="pm-tile-s pr-state">{{ sizeWord(a) }}</span>
                    </button>
                </li>
            </ul>
            <div v-if="more && !problem" class="pm-more"><button type="button" class="pr-btn" :disabled="loading" @click="load(false)">{{ loading ? 'Loading' : 'Show more' }}</button></div>
        </div>
        <template #footer="{ close: done }"><button type="button" class="pr-btn" @click="done()">Cancel</button></template>
    </Dialog>
</template>
