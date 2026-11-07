<script setup>
// The library: the presentation you were last in, then a wall of sheets. Everything on it comes from GET /api/decks.
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import PressLayout from '@/Layouts/PressLayout.vue';
import DeckTile from '@/press/library/DeckTile.vue';
import ContinueSheet from '@/press/library/ContinueSheet.vue';
import NewDeckSheet from '@/press/library/NewDeckSheet.vue';
import ConfirmDialog from '@/press/library/ConfirmDialog.vue';
import ToastView from '@/press/editor/ui/ToastView.vue';
import Ico from '@/press/editor/ui/Ico.vue';
import { createApi } from '@/press/editor/ui/transport.js';
import { createToasts } from '@/press/editor/ui/toasts.js';
import { FILTERS, SORTS, emptyCopy, gridMove } from '@/press/library/libraryModel.js';

const props = defineProps({
    canCreate: { type: Boolean, default: false },
    team: { type: Object, default: null },
    ai: { type: Object, default: () => ({ mode: 'local' }) },
    themes: { type: Array, default: () => [] },
});

const api = createApi(axios.create({ withCredentials: true, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } }));
const toasts = createToasts({ setTimeout: (f, ms) => window.setTimeout(f, ms), clearTimeout: (h) => window.clearTimeout(h) });
const toast = shallowRef(null);
const offToasts = toasts.subscribe((t) => { toast.value = t; });
onBeforeUnmount(offToasts);
const live = ref('');
const say = (m) => { live.value = ''; nextTick(() => { live.value = m; }); };

// ---- state, kept in the address so a reload comes back to the same wall -------------------------------
const params = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);
const filter = ref(FILTERS.some((f) => f.id === params.get('filter')) ? params.get('filter') : null);
const sort = ref(SORTS.some((s) => s.id === params.get('sort')) ? params.get('sort') : 'updated');
const q = ref(params.get('q') || '');
const items = ref([]);
const next = ref(null);
const loading = ref(true);
const loadError = ref('');
const continueRow = ref(null);
const newOpen = ref(false);
const renamingId = ref(null);
const confirm = ref(null); // {row, busy}
const focusIndex = ref(0);
const grid = ref(null);
const searchEl = ref(null);
const tiles = reactive({});
let ticket = 0;
let searchTimer = null;

function syncUrl() {
    try {
        const u = new URL(window.location.href);
        for (const [k, v] of [['filter', filter.value], ['sort', sort.value === 'updated' ? null : sort.value], ['q', q.value.trim() || null]]) {
            if (v) u.searchParams.set(k, v); else u.searchParams.delete(k);
        }
        window.history.replaceState(window.history.state, '', u);
    } catch { /* the address is a convenience */ }
}

async function load({ more = false } = {}) {
    const mine = ++ticket;
    loading.value = true;
    loadError.value = '';
    try {
        const res = await api.get('/api/decks', { filter: filter.value || undefined, sort: sort.value, q: q.value.trim() || undefined, per_page: 24, cursor: more ? next.value : undefined });
        if (mine !== ticket) return;
        items.value = more ? [...items.value, ...res.data.filter((r) => !items.value.some((x) => x.id === r.id))] : res.data;
        next.value = res.next_cursor || null;
        if (!more) focusIndex.value = 0;
    } catch (e) {
        if (mine !== ticket) return;
        loadError.value = e.message || 'Could not load your presentations.';
    } finally {
        if (mine === ticket) loading.value = false;
    }
}
async function loadContinue() {
    try {
        const res = await api.get('/api/decks', { filter: 'recent', per_page: 1 });
        continueRow.value = res.data[0] || null;
    } catch { continueRow.value = null; }
}
function setFilter(id) {
    if (filter.value === id) return;
    filter.value = id;
    syncUrl();
    load();
}
watch(sort, () => { syncUrl(); load(); });
watch(q, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { syncUrl(); load(); }, 250);
});
onBeforeUnmount(() => clearTimeout(searchTimer));

const showContinue = computed(() => continueRow.value && filter.value !== 'archived' && !q.value.trim());
const archived = computed(() => filter.value === 'archived');
const empty = computed(() => !loading.value && !loadError.value && !items.value.length);

// ---- actions ---------------------------------------------------------------------------------------
const drop = (id) => { items.value = items.value.filter((r) => r.id !== id); if (focusIndex.value >= items.value.length) focusIndex.value = Math.max(0, items.value.length - 1); };

async function toggleFavourite(row) {
    const was = row.favourite;
    row.favourite = !was;
    try {
        const res = await api.post(`/api/decks/${row.id}/favourite`, { favourite: !was });
        row.favourite = !!res.favourite;
        say(row.favourite ? `${row.title} added to favourites` : `${row.title} removed from favourites`);
        if (filter.value === 'favourites' && !row.favourite) drop(row.id);
    } catch (e) { row.favourite = was; toasts.push(`Could not change the favourite: ${e.message}`, { kind: 'error' }); }
}
async function rename(row, title) {
    renamingId.value = null;
    await nextTick();
    document.querySelector(`.dt[data-id="${row.id}"] [data-tile]`)?.focus();
    if (!title || title === row.title) return;
    const old = row.title;
    row.title = title;
    try { await api.patch(`/api/decks/${row.id}`, { title }); say('Renamed'); if (continueRow.value?.id === row.id) continueRow.value.title = title; } catch (e) { row.title = old; toasts.push(`Could not rename: ${e.message}`, { kind: 'error' }); }
}
async function duplicate(row) {
    try {
        const copy = await api.post(`/api/decks/${row.id}/duplicate`);
        toasts.push(`Duplicated "${row.title}"`, { kind: 'ok', action: { label: 'Open', run: () => router.visit(route('press.editor', { deck: copy.id })) } });
        await load();
    } catch (e) { toasts.push(`Could not duplicate: ${e.message}`, { kind: 'error' }); }
}
async function archive(row) {
    try {
        await api.delete(`/api/decks/${row.id}`);
        drop(row.id);
        if (continueRow.value?.id === row.id) loadContinue();
        say(`${row.title} archived`);
        toasts.push(`Archived "${row.title}"`, { action: { label: 'Undo', run: () => restore(row, true) } });
    } catch (e) { toasts.push(`Could not archive: ${e.message}`, { kind: 'error' }); }
}
async function restore(row, fromUndo = false) {
    try {
        await api.post(`/api/decks/${row.id}/unarchive`);
        if (!fromUndo) { drop(row.id); toasts.push(`Restored "${row.title}"`, { kind: 'ok' }); } else await load();
        loadContinue();
    } catch (e) { toasts.push(`Could not restore: ${e.message}`, { kind: 'error' }); }
}
async function deleteForever() {
    const row = confirm.value.row;
    confirm.value.busy = true;
    try {
        await api.delete(`/api/decks/${row.id}`, { params: { permanent: 1 } });
        drop(row.id);
        confirm.value = null;
        say(`${row.title} deleted`);
        toasts.push(`Deleted "${row.title}" for good`, { kind: 'info' });
    } catch (e) { confirm.value = null; toasts.push(`Could not delete: ${e.message}`, { kind: 'error' }); }
}

// ---- keyboard ---------------------------------------------------------------------------------------
function tileEls() { return [...(grid.value?.querySelectorAll('[data-tile]') || [])]; }
function onGridKey(e) {
    if (e.target.closest('input')) return;
    const els = tileEls();
    const i = els.indexOf(document.activeElement);
    if (i < 0) return;
    if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(e.key)) {
        e.preventDefault();
        const n = gridMove(els.map((el) => el.getBoundingClientRect()), i, e.key);
        focusIndex.value = n;
        els[n]?.focus();
        return;
    }
    const row = items.value[i];
    if (!row) return;
    if (e.key === 'F2' && !archived.value) { e.preventDefault(); renamingId.value = row.id; }
    else if ((e.key === 'Delete' || e.key === 'Backspace') && !archived.value) { e.preventDefault(); archive(row); }
    else if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) { e.preventDefault(); tiles[row.id]?.openMenu(); }
    else if (e.key === 'f' || e.key === 'F') { if (!archived.value && !e.metaKey && !e.ctrlKey) { e.preventDefault(); toggleFavourite(row); } }
}
function onWindowKey(e) {
    if (e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey && !e.target.closest?.('input, textarea, select, [contenteditable], [role="dialog"]')) { e.preventDefault(); searchEl.value?.focus(); }
    if ((e.key === 'n' || e.key === 'N') && !e.metaKey && !e.ctrlKey && !e.altKey && props.canCreate && !e.target.closest?.('input, textarea, select, [contenteditable], [role="dialog"], [role="menu"]')) { e.preventDefault(); newOpen.value = true; }
}

// ---- more, as you reach the end ------------------------------------------------------------------------
const sentinel = ref(null);
let io = null;
onMounted(() => {
    load();
    loadContinue();
    window.addEventListener('keydown', onWindowKey);
    if (typeof IntersectionObserver !== 'undefined') {
        io = new IntersectionObserver((entries) => { if (entries.some((x) => x.isIntersecting) && next.value && !loading.value) load({ more: true }); }, { rootMargin: '400px 0px' });
        if (sentinel.value) io.observe(sentinel.value);
    }
});
onBeforeUnmount(() => { window.removeEventListener('keydown', onWindowKey); io?.disconnect(); });
const showMore = () => load({ more: true });
</script>

<template>
    <PressLayout title="Presentations">
        <template #search>
            <label class="ls">
                <Ico name="search" size="sm" />
                <span class="pr-sr">Search presentations</span>
                <input ref="searchEl" v-model="q" type="search" class="ls-in" placeholder="Search presentations" autocomplete="off" />
                <kbd class="pr-kbd ls-key" aria-hidden="true">/</kbd>
            </label>
        </template>
        <template #actions>
            <button v-if="canCreate" type="button" class="pr-btn pr-btn--primary lh-new" aria-haspopup="dialog" @click="newOpen = true"><Ico name="plus" size="sm" :stroke="2.2" /><span>New</span></button>
        </template>

        <div class="lp">
            <ContinueSheet v-if="showContinue" :row="continueRow" />

            <section class="lw" aria-labelledby="lw-h">
                <div class="lw-bar">
                    <h2 id="lw-h" class="pr-sr">Presentations</h2>
                    <div class="lw-filters" role="group" aria-label="Filter presentations">
                        <button v-for="f in FILTERS" :key="String(f.id)" type="button" class="lw-f" :class="{ 'is-on': filter === f.id }" :aria-pressed="filter === f.id" @click="setFilter(f.id)">{{ f.label }}</button>
                    </div>
                    <label class="lw-sort"><span class="pr-small pr-muted">Sort</span>
                        <select v-model="sort" class="pr-field lw-select" aria-label="Sort presentations"><option v-for="s in SORTS" :key="s.id" :value="s.id">{{ s.label }}</option></select>
                    </label>
                </div>

                <ul v-if="items.length" ref="grid" class="lw-grid" aria-label="Presentations" :aria-busy="loading" @keydown="onGridKey">
                    <DeckTile
                        v-for="(row, i) in items" :key="row.id" :ref="(el) => { if (el) tiles[row.id] = el; else delete tiles[row.id]; }" :row="row" :archived="archived" :focusable="i === focusIndex" :renaming="renamingId === row.id"
                        @favourite="toggleFavourite(row)" @rename-start="renamingId = row.id" @rename="rename(row, $event)" @rename-cancel="renamingId = null" @duplicate="duplicate(row)"
                        @archive="archive(row)" @restore="restore(row)" @delete="confirm = { row, busy: false }"
                    />
                </ul>
                <ul v-else-if="loading" class="lw-grid lw-grid--wait" aria-busy="true" aria-label="Loading presentations"><li v-for="n in 6" :key="n" class="dt-wait" aria-hidden="true"><div class="dt-wait-sheet"></div><div class="dt-wait-line"></div></li></ul>

                <div v-if="empty" class="lw-empty">
                    <svg class="lw-empty-rail" viewBox="0 0 24 160" aria-hidden="true"><line x1="12" y1="0" x2="12" y2="160" stroke="var(--line-strong)" stroke-width="1.5" /></svg>
                    <div>
                        <p class="lw-empty-t pr-display">Nothing on this line yet.</p>
                        <p class="lw-empty-s">{{ emptyCopy(filter, q.trim()) }}</p>
                        <button v-if="canCreate && !archived && !q.trim()" type="button" class="pr-btn pr-btn--primary" @click="newOpen = true"><Ico name="plus" size="sm" :stroke="2.2" />New presentation</button>
                    </div>
                </div>
                <div v-if="loadError" class="lw-error" role="alert">
                    <p>{{ loadError }}</p>
                    <button type="button" class="pr-btn pr-btn--sm" @click="load()">Try again</button>
                </div>

                <div ref="sentinel" class="lw-end">
                    <button v-if="next && !loading" type="button" class="pr-btn" @click="showMore">Show more</button>
                    <p v-else-if="next && loading" class="pr-small pr-muted">Loading more</p>
                </div>
            </section>
        </div>

        <NewDeckSheet v-if="newOpen" :themes="themes" :ai="ai" @close="newOpen = false" />
        <ConfirmDialog
            v-if="confirm" title="Delete for good?" :body="`&quot;${confirm.row.title}&quot; and all its slides will be deleted. This cannot be undone.`" confirm="Delete permanently" :busy="confirm.busy"
            @confirm="deleteForever" @cancel="confirm = null"
        />
        <ToastView :toast="toast" @dismiss="toasts.dismiss($event)" @run="(t) => { t.action.run(); toasts.dismiss(t.id); }" />
        <div class="pr-sr" role="status" aria-live="polite">{{ live }}</div>
    </PressLayout>
</template>
