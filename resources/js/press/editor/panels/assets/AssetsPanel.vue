<script setup>
// The asset library (deck scope): GET /api/assets with search, kind, sort and endless paging; upload by button, drop or paste target;
// click or drag a file onto the slide; replace the selected picture; rename and alt text; usage; delete; and the team's brand logos.
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import {
    Segmented, SelectMenu, EmptyState, InlineNotice, ProgressBead,
} from '../../../ui/controls/index.js';
import { lsGet, lsSet } from '../../../ui/controls/persist.js';
import Ico from '../../ui/Ico.vue';
import { replacePatch } from '../../stage/lib/drop.js';
import { useAssetLibrary } from './useAssetLibrary.js';
import { useAssetUploads } from './useAssetUploads.js';
import { useInsert } from '../insert/useInsert.js';
import {
    SORTS, KINDS, assetPayload, createRefetchGate, kindOf, replaceableKind,
} from './assetsModel.js';
import AssetCard from './AssetCard.vue';
import AssetDetails from './AssetDetails.vue';
import DeleteDialog from './DeleteDialog.vue';
import UsagePopover from './UsagePopover.vue';
import UploadList from './UploadList.vue';
import BrandLogos from './BrandLogos.vue';
import '../insert/insert.css';
import './assets.css';

const editor = useEditorContext();
const { ui, store } = editor;
const ins = useInsert(editor);
const lib = useAssetLibrary(editor);
const up = useAssetUploads(editor, { onDone: (item) => { if (item.asset) lib.prepend(item.asset); } });

const view = ref(lsGet('press:assets:view', 'grid') === 'list' ? 'list' : 'grid');
watch(view, (v) => lsSet('press:assets:view', v));
const search = ref('');
const picker = ref(null);
const brand = ref(null);
const sentinel = ref(null);
const dropping = ref(false);
const broken = reactive({});
const gate = createRefetchGate({ minGap: 20000 });

const canAdd = computed(() => ins.canEdit);
const canEdit = computed(() => !editor.readOnly);
const canForce = computed(() => !!editor.can?.manage);
const replaceKind = computed(() => replaceableKind(editor.selected.value));

// ---- search and filters ----------------------------------------------------------------------
let timer = null;
watch(search, (v) => { clearTimeout(timer); timer = setTimeout(() => { lib.filters.q = v; lib.reload(); }, 250); });
watch(() => [lib.filters.kind, lib.filters.sort], () => lib.reload());
const kindOptions = KINDS.map((k) => ({ value: k.value, label: k.label }));
const viewOptions = [{ value: 'grid', icon: 'grid', title: 'Grid view' }, { value: 'list', icon: 'menu', title: 'List view' }];
function clearFilters() { search.value = ''; lib.filters.q = ''; lib.filters.kind = 'all'; }

// ---- endless scroll ----------------------------------------------------------------------------
let io = null;
const seen = ref(false);
function maybeMore() { if (seen.value && lib.hasMore.value && !lib.loadingMore.value && !lib.moreError.value) lib.loadMore(); }
watch([() => lib.items.value.length, () => lib.hasMore.value, () => lib.loadingMore.value], maybeMore, { flush: 'post' });
watch(sentinel, (el) => {
    io?.disconnect();
    if (!el) { seen.value = false; return; }
    if (typeof IntersectionObserver === 'undefined') { seen.value = true; maybeMore(); return; }
    io = new IntersectionObserver((es) => { seen.value = es.some((e) => e.isIntersecting); maybeMore(); }, { rootMargin: '400px 0px' });
    io.observe(el);
}, { flush: 'post' });

// ---- expired URLs ------------------------------------------------------------------------------
async function onImageError(asset) {
    if (asset) broken[asset.id] = true;
    if (!gate.attempt()) return;
    await lib.refetchLoaded();
    brand.value?.reload?.();
    for (const k of Object.keys(broken)) delete broken[k];
}

// ---- adding to the slide -----------------------------------------------------------------------
const add = (asset) => ins.insertAsset(asset);
function replaceSelected(asset) {
    const sel = editor.selected.value;
    const k = replaceableKind(sel);
    if (!k) { editor.toast('Select one picture or video on the slide first.', { kind: 'info' }); return; }
    if (kindOf(asset) !== k) { editor.toast(k === 'image' ? 'A picture can only be replaced by a picture.' : 'A video can only be replaced by a video.', { kind: 'warn' }); return; }
    ins.registerAsset(asset);
    const el = sel[0];
    store.dispatch([{ op: 'el.set', slide: store.slide().id, id: el.id, p: replacePatch(el, { asset: asset.id }) }], { label: k === 'image' ? 'Replace picture' : 'Replace video' });
    editor.announce?.(`Replaced the ${k === 'image' ? 'picture' : 'video'} with ${asset.name}`);
}
function dragStart(e, asset) {
    ins.dragStart(e, { kind: 'asset', id: asset.id, asset: assetPayload(asset) }, asset.name);
}

// ---- uploads -----------------------------------------------------------------------------------
function addFiles(list) {
    if (!canEdit.value) { editor.toast('You can view this library but not add files to it.', { kind: 'warn' }); return; }
    const files = [...list];
    if (files.length) up.add(files);
}
const onPick = (e) => { addFiles(e.target.files || []); e.target.value = ''; };
const hasFiles = (e) => [...(e.dataTransfer?.types || [])].includes('Files');
let depth = 0;
function onEnter(e) { if (hasFiles(e)) { depth += 1; dropping.value = canEdit.value; } }
function onOver(e) { if (hasFiles(e)) { e.preventDefault(); if (e.dataTransfer) e.dataTransfer.dropEffect = canEdit.value ? 'copy' : 'none'; } }
function onLeave() { depth = Math.max(0, depth - 1); if (!depth) dropping.value = false; }
function onDrop(e) { if (!hasFiles(e)) return; e.preventDefault(); depth = 0; dropping.value = false; addFiles(e.dataTransfer.files); }

// ---- dialogs -----------------------------------------------------------------------------------
const detailsFor = ref(null);
const detailsOpen = ref(false);
const deleteFor = ref(null);
const deleteOpen = ref(false);
const usageFor = ref(null);
const usageAnchor = ref(null);
const usageOpen = ref(false);
const liveAsset = computed(() => (detailsFor.value ? lib.items.value.find((a) => a.id === detailsFor.value.id) || detailsFor.value : null));
function openDetails(asset) { detailsFor.value = asset; detailsOpen.value = true; }
function openUsage(asset, anchor) { usageFor.value = asset; usageAnchor.value = anchor; usageOpen.value = true; }
function askDelete(asset) { detailsOpen.value = false; deleteFor.value = asset; deleteOpen.value = true; }
function afterDelete(asset) { editor.toast(`Deleted ${asset.name}`, { kind: 'ok' }); editor.announce?.(`Deleted ${asset.name}`); }

onMounted(() => lib.reload());
onBeforeUnmount(() => { clearTimeout(timer); io?.disconnect(); });
const countWord = computed(() => `${lib.items.value.length}${lib.hasMore.value ? '+' : ''} ${lib.items.value.length === 1 && !lib.hasMore.value ? 'file' : 'files'}`);
</script>

<template>
    <div class="al" @dragenter="onEnter" @dragover="onOver" @dragleave="onLeave" @drop="onDrop">
        <div class="al-head">
            <input v-model="search" type="search" class="pr-field" placeholder="Search by file name" aria-label="Search files by name" autocomplete="off" spellcheck="false" maxlength="100" />
            <Segmented :model-value="lib.filters.kind" :options="kindOptions" label="Show" hide-label @update:model-value="(v) => (lib.filters.kind = v)" />
            <div class="al-bar">
                <SelectMenu :model-value="lib.filters.sort" :options="SORTS" label="Sort" hide-label @update:model-value="(v) => (lib.filters.sort = v)" />
                <Segmented v-model="view" :options="viewOptions" label="Layout" hide-label :block="false" />
            </div>
            <template v-if="canEdit">
                <input ref="picker" type="file" class="pr-sr" tabindex="-1" aria-hidden="true" accept="image/*,video/*,audio/*" multiple @change="onPick" />
                <button type="button" class="pr-btn pr-btn--primary" @click="picker.click()"><Ico name="upload" size="sm" />Upload files</button>
            </template>
        </div>

        <div class="al-body">
            <UploadList :items="up.items.value" @cancel="up.cancel" @retry="up.retry" @remove="up.remove" @clear="up.clearFinished" />
            <BrandLogos ref="brand" :can-add="canAdd" @dragstart="dragStart" @dragend="ins.dragEnd" @imgerror="onImageError(null)" />

            <section aria-labelledby="al-lib-h" style="display: grid; gap: 8px">
                <h3 id="al-lib-h" class="ip-h">Library <span v-if="lib.loaded.value && !lib.error.value" class="pr-state" aria-live="polite">{{ countWord }}</span></h3>

                <InlineNotice v-if="lib.error.value" kind="error">{{ lib.error.value }} <button type="button" class="pr-btn pr-btn--sm" @click="lib.reload()">Try again</button></InlineNotice>

                <ul v-else-if="lib.loading.value && !lib.items.value.length" class="al-grid" aria-label="Loading files" aria-busy="true">
                    <li v-for="n in 6" :key="n" class="al-skel"></li>
                </ul>

                <EmptyState v-else-if="lib.loaded.value && !lib.items.value.length && lib.filtered.value" compact title="No file matches." body="Try another name, or show every kind of file.">
                    <button type="button" class="pr-btn pr-btn--sm" @click="clearFilters">Clear search and filters</button>
                </EmptyState>
                <EmptyState v-else-if="lib.loaded.value && !lib.items.value.length" compact title="Nothing in the library yet." body="Upload pictures, video or audio once and use them in any deck.">
                    <button v-if="canEdit" type="button" class="pr-btn pr-btn--primary pr-btn--sm" @click="picker.click()">Upload files</button>
                </EmptyState>

                <ul v-else class="al-grid" :class="{ 'is-list': view === 'list' }" aria-label="Files">
                    <AssetCard
                        v-for="a in lib.items.value" :key="a.id" :asset="a" :view="view" :can-add="canAdd" :replace-kind="replaceKind" :broken="!!broken[a.id]"
                        @add="add" @replace="replaceSelected" @details="openDetails" @usage="openUsage" @imgerror="onImageError" @dragstart="dragStart" @dragend="ins.dragEnd"
                    />
                </ul>

                <div v-if="lib.hasMore.value || lib.loadingMore.value || lib.moreError.value" ref="sentinel" class="al-more">
                    <ProgressBead v-if="lib.loadingMore.value" label="Loading more files" />
                    <template v-else-if="lib.moreError.value">
                        <p class="c-error" role="alert"><span class="pr-bead pr-bead--bad pr-bead--sm" aria-hidden="true"></span>{{ lib.moreError.value }}</p>
                        <button type="button" class="pr-btn pr-btn--sm" @click="lib.loadMore()">Try again</button>
                    </template>
                    <button v-else type="button" class="pr-btn pr-btn--ghost pr-btn--sm" @click="lib.loadMore()">Load more</button>
                </div>
            </section>
        </div>

        <div v-if="dropping" class="al-drop" aria-hidden="true"><p>Drop to add to your library</p></div>

        <AssetDetails
            v-model:open="detailsOpen" :asset="liveAsset" :can-edit="canEdit" :can-add="canAdd" :replace-kind="replaceKind"
            :save="lib.save" :load-usage="lib.usage" :set-poster="lib.setPoster"
            @add="add" @replace="replaceSelected" @delete="askDelete"
        />
        <DeleteDialog v-model:open="deleteOpen" :asset="deleteFor" :can-force="canForce" :remove="lib.remove" @deleted="afterDelete" />
        <UsagePopover v-model:open="usageOpen" :anchor="usageAnchor" :asset="usageFor" :load="lib.usage" />
    </div>
</template>
