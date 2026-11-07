<script setup>
// Pick one image from the team's asset library (GET /api/assets), or upload a new one (POST /api/assets). Shared by the
// background panel and the brand kit logo list. Emits `pick` with the asset payload; the caller registers it in
// editor.assets so SlideView can draw it.
import { computed, ref, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import { Dialog, EmptyState, InlineNotice } from '../../../ui/controls';
import { apiErrorText } from '../theme/themeModel.js';
import { toAssetEntry } from '../../stage/lib/drop.js';
import Ico from '../../ui/Ico.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Choose a picture' },
    canUpload: { type: Boolean, default: true },
});
const emit = defineEmits(['update:open', 'pick']);
const editor = useEditorContext();

const items = ref([]);
const status = ref('idle'); // idle | loading | ready | error
const error = ref('');
const cursor = ref(null);
const more = ref(false);
const q = ref('');
const uploading = ref(false);
const fileInput = ref(null);
let timer = null;

const empty = computed(() => status.value === 'ready' && !items.value.length);

async function load(reset = true) {
    if (reset) { items.value = []; cursor.value = null; }
    status.value = 'loading';
    error.value = '';
    try {
        const res = await editor.api.get('/api/assets', { kind: 'image', limit: 24, q: q.value || undefined, cursor: reset ? undefined : cursor.value || undefined });
        items.value = reset ? res.data || [] : [...items.value, ...(res.data || [])];
        cursor.value = res.next_cursor || null;
        more.value = !!res.has_more;
        status.value = 'ready';
    } catch (e) {
        status.value = 'error';
        error.value = apiErrorText(e, 'the asset library');
    }
}

watch(() => props.open, (on) => { if (on) { q.value = ''; load(true); } });
watch(q, () => { if (!props.open) return; clearTimeout(timer); timer = setTimeout(() => load(true), 300); });

function choose(asset, close) {
    editor.assets[asset.id] = toAssetEntry(asset, typeof location !== 'undefined' ? location.origin : null);
    emit('pick', asset);
    close();
}

async function upload(ev) {
    const file = ev.target.files?.[0];
    ev.target.value = '';
    if (!file) return;
    uploading.value = true;
    error.value = '';
    try {
        const form = new FormData();
        form.append('file', file);
        const pid = editor.deck.value?.project_id;
        if (pid != null) form.append('project_id', String(pid));
        const res = await editor.api.post('/api/assets', form);
        items.value = [res.asset, ...items.value.filter((a) => a.id !== res.asset.id)];
        status.value = 'ready';
        editor.announce(`${res.asset.name} uploaded`);
    } catch (e) {
        error.value = apiErrorText(e, 'the asset library');
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" :title="title" size="lg" @update:open="emit('update:open', $event)">
        <template #default="{ close }">
            <div class="dz-ap">
                <div class="dz-ap-bar">
                    <input v-model="q" type="search" class="pr-field" placeholder="Search by name" aria-label="Search pictures" data-autofocus />
                    <template v-if="canUpload && !editor.readOnly">
                        <button type="button" class="pr-btn" :disabled="uploading" @click="fileInput.click()"><Ico name="upload" size="sm" />{{ uploading ? 'Uploading' : 'Upload' }}</button>
                        <input ref="fileInput" type="file" accept="image/*" class="pr-sr" tabindex="-1" aria-label="Upload a picture" @change="upload" />
                    </template>
                </div>
                <InlineNotice v-if="error" kind="error">
                    {{ error }} <button type="button" class="pr-btn pr-btn--sm" @click="load(true)">Try again</button>
                </InlineNotice>
                <p v-if="status === 'loading' && !items.length" class="pr-small pr-muted" role="status">Loading pictures</p>
                <EmptyState v-else-if="empty" compact :title="q ? 'No picture matches.' : 'No pictures yet.'" :body="q ? 'Try a different name.' : 'Upload one to use it here and on any slide.'" />
                <ul v-else class="dz-ap-grid" aria-label="Pictures">
                    <li v-for="a in items" :key="a.id">
                        <button type="button" class="dz-ap-item" :aria-label="`Use ${a.name}`" @click="choose(a, close)">
                            <img :src="a.thumb || a.url" :alt="a.alt || ''" loading="lazy" decoding="async" />
                            <span class="dz-ap-name">{{ a.name }}</span>
                        </button>
                    </li>
                </ul>
                <button v-if="more && status !== 'loading'" type="button" class="pr-btn" @click="load(false)">Show more</button>
            </div>
        </template>
    </Dialog>
</template>
