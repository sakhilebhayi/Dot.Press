<script setup>
// The library, as a ledger: a page bar (title, filters, sort), the arrival row (logo, where you were last, the one
// primary action), then the ledger of presentations beside the stage of the selected one, and a foot. The arrival
// comes from the `landing` prop; the rows come from GET /api/decks.
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import PressLayout from '@/Layouts/PressLayout.vue';
import DotSearch from '@/dot-ui/DotSearch.vue';
import LedgerRow from '@/press/library/LedgerRow.vue';
import LibraryStage from '@/press/library/LibraryStage.vue';
import LandingBlock from '@/press/library/LandingBlock.vue';
import NewDeckSheet from '@/press/library/NewDeckSheet.vue';
import ConfirmDialog from '@/press/library/ConfirmDialog.vue';
import ToastView from '@/press/editor/ui/ToastView.vue';
import { createApi } from '@/press/editor/ui/transport.js';
import { createToasts } from '@/press/editor/ui/toasts.js';
import { FILTERS, SORTS, emptyCopy, footCounts, pickSelection, rowMove } from '@/press/library/libraryModel.js';

const props = defineProps({
    canCreate: { type: Boolean, default: false },
    landing: { type: Object, default: () => ({ firstName: '', company: null, logo: { light: '/images/logo.png', dark: '/images/logo-light.png' }, infodot: { home: 'https://infodot.co.za/home', platforms: 'https://infodot.co.za/platforms' }, featured: null, primary: null }) },
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
const continueRow = ref(props.landing.featured || null);
watch(() => props.landing.featured, (row) => { continueRow.value = row || null; });
// The primary action is a real address (/dashboard?new=1): arriving on it opens the New sheet.
const newOpen = ref(props.canCreate && params.get('new') === '1');
const renamingId = ref(null);
const confirm = ref(null); // {row, busy}
const focusIndex = ref(0);
const table = ref(null);
const ledgerEl = ref(null);
const searchEl = ref(null);
const rowRefs = reactive({});
// The selected row: a click or an arrow key, or `?sel=` in the address. It only counts while it is one of the rows loaded.
const wantedSel = ref(params.get('sel') || null);
const phone = ref(false);
let ticket = 0;
let searchTimer = null;

function syncUrl() {
    try {
        const u = new URL(window.location.href);
        for (const [k, v] of [['filter', filter.value], ['sort', sort.value === 'updated' ? null : sort.value], ['q', q.value.trim() || null], ['new', newOpen.value ? '1' : null], ['sel', wantedSel.value && selected.value && String(selected.value.id) === String(wantedSel.value) ? wantedSel.value : null]]) {
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
// The presentation the landing shows is chosen by the server (LibraryLanding); ask it again when that one may have changed.
function loadContinue() {
    router.reload({ only: ['landing'] });
}
watch(newOpen, syncUrl);
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

// The landing steps aside while a search is typed, so the results sit under the search field.
const showLanding = computed(() => !q.value.trim());
const selectedId = computed(() => pickSelection(items.value, wantedSel.value, continueRow.value?.id ?? null));
const selected = computed(() => items.value.find((r) => r.id === selectedId.value) || null);
function select(row, index) {
    wantedSel.value = String(row.id);
    if (Number.isInteger(index)) focusIndex.value = index;
    syncUrl();
}
const counts = computed(() => footCounts(items.value, !!next.value));
const sortedBy = (id) => sort.value === id;
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
    document.querySelector(`.lr[data-id="${row.id}"] [data-row]`)?.focus();
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
function rowEls() { return [...(table.value?.querySelectorAll('[data-row]') || [])]; }
function onGridKey(e) {
    if (e.target.closest('input')) return;
    const els = rowEls();
    const i = els.indexOf(document.activeElement);
    if (i < 0) return;
    if (['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(e.key)) {
        e.preventDefault();
        const n = rowMove(els.length, i, e.key);
        focusIndex.value = n;
        els[n]?.focus(); // the ledger has scroll-padding for its sticky head, so the row never lands under it
        if (items.value[n]) select(items.value[n], n);
        return;
    }
    const row = items.value[i];
    if (!row) return;
    if (e.key === 'F2' && !archived.value) { e.preventDefault(); renamingId.value = row.id; }
    else if ((e.key === 'Delete' || e.key === 'Backspace') && !archived.value) { e.preventDefault(); archive(row); }
    else if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) { e.preventDefault(); rowRefs[row.id]?.openMenu(); }
    else if (e.key === 'f' || e.key === 'F') { if (!archived.value && !e.metaKey && !e.ctrlKey) { e.preventDefault(); toggleFavourite(row); } }
}
function onWindowKey(e) {
    if (e.key === '/' && !e.metaKey && !e.ctrlKey && !e.altKey && !e.target.closest?.('input, textarea, select, [contenteditable], [role="dialog"]')) { e.preventDefault(); searchEl.value?.focus(); }
    if ((e.key === 'n' || e.key === 'N') && !e.metaKey && !e.ctrlKey && !e.altKey && props.canCreate && !e.target.closest?.('input, textarea, select, [contenteditable], [role="dialog"], [role="menu"]')) { e.preventDefault(); newOpen.value = true; }
}

// ---- more, as you reach the end ------------------------------------------------------------------------
const sentinel = ref(null);
let io = null;
let mq = null;
// The ledger scrolls inside the page on a tablet and a desktop; on a phone the page region scrolls, so the observer's root changes.
function watchEnd() {
    io?.disconnect();
    if (typeof IntersectionObserver === 'undefined' || !sentinel.value) return;
    io = new IntersectionObserver((entries) => { if (entries.some((x) => x.isIntersecting) && next.value && !loading.value) load({ more: true }); }, { root: phone.value ? null : ledgerEl.value, rootMargin: '400px 0px' });
    io.observe(sentinel.value);
}
const onMq = () => { phone.value = !!mq?.matches; watchEnd(); };
onMounted(() => {
    load();
    window.addEventListener('keydown', onWindowKey);
    mq = window.matchMedia('(max-width: 639px)');
    phone.value = mq.matches;
    mq.addEventListener('change', onMq);
    watchEnd();
});
onBeforeUnmount(() => { window.removeEventListener('keydown', onWindowKey); mq?.removeEventListener('change', onMq); io?.disconnect(); });
const showMore = () => load({ more: true });
const infodot = computed(() => props.landing.infodot || { home: 'https://infodot.co.za/home', platforms: 'https://infodot.co.za/platforms' });
// Rows with no first load yet show the dashed placeholder lines; a sentence under the empty ledger needs a reason to say it.
const emptyText = computed(() => (filter.value !== null || q.value.trim() || props.landing.primary ? emptyCopy(filter.value, q.value.trim()) : ''));
</script>

<template>
    <PressLayout title="Presentations" own-heading shell>
        <template #search>
            <!-- the shared Dot search pill (dot-ui). The query, the 250ms debounce, the address and the API call below are unchanged. -->
            <DotSearch ref="searchEl" v-model="q" name="q" placeholder="Search presentations" label="Search presentations" hint="Ctrl K" />
        </template>

        <div class="lp">
            <div class="bar">
                <h1 class="bar-h">Presentations</h1>
                <div class="tabs" role="group" aria-label="Filter presentations">
                    <button v-for="f in FILTERS" :key="String(f.id)" type="button" class="tab" :aria-pressed="filter === f.id" @click="setFilter(f.id)">{{ f.label }}</button>
                </div>
                <label class="sort"><span class="sort-l">Sort</span>
                    <select v-model="sort" class="pr-field lw-select" aria-label="Sort presentations"><option v-for="s in SORTS" :key="s.id" :value="s.id">{{ s.label }}</option></select>
                </label>
            </div>

            <LandingBlock v-if="showLanding" :landing="landing" :row="continueRow" @new="newOpen = true" />

            <div class="desk">
                <div ref="ledgerEl" class="ledger">
                    <table v-if="items.length" ref="table" class="lg" aria-label="Presentations" :aria-busy="loading" @keydown="onGridKey">
                        <thead>
                            <tr>
                                <th scope="col"><button type="button" class="sorth" :aria-pressed="sortedBy('title')" @click="sort = 'title'">Title</button></th>
                                <th scope="col" class="lr-status">Status</th>
                                <th scope="col" class="lr-n lr-slides">Slides</th>
                                <th scope="col" class="lr-size">Size</th>
                                <th scope="col" class="lr-n lr-edited"><button type="button" class="sorth" :aria-pressed="sortedBy('updated')" @click="sort = 'updated'">{{ archived ? 'Archived' : 'Edited' }}</button></th>
                                <th scope="col" class="lr-act"><span class="pr-sr">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <LedgerRow
                                v-for="(row, i) in items" :key="row.id" :ref="(el) => { if (el) rowRefs[row.id] = el; else delete rowRefs[row.id]; }" :row="row" :archived="archived" :focusable="i === focusIndex"
                                :selected="row.id === selectedId" :renaming="renamingId === row.id" :order="i < 12 ? i : 0"
                                @select="select(row, i)" @favourite="toggleFavourite(row)" @rename-start="renamingId = row.id" @rename="rename(row, $event)" @rename-cancel="renamingId = null" @duplicate="duplicate(row)"
                                @archive="archive(row)" @restore="restore(row)" @delete="confirm = { row, busy: false }"
                            />
                        </tbody>
                    </table>
                    <ul v-else-if="loading" class="lg-wait" aria-busy="true" aria-label="Loading presentations"><li v-for="n in 6" :key="n" aria-hidden="true"><span class="lg-wait-th"></span><span class="lg-wait-ln"></span></li></ul>

                    <div v-if="empty" class="lw-empty">
                        <svg class="lw-empty-rail" viewBox="0 0 420 12" preserveAspectRatio="none" aria-hidden="true" focusable="false"><line x1="0" y1="6" x2="420" y2="6" stroke="var(--line-strong)" stroke-width="1.5" /></svg>
                        <h3 v-if="!showLanding || filter !== null" class="lw-empty-t">Nothing on this line yet.</h3>
                        <p v-if="emptyText" class="lw-empty-s">{{ emptyText }}</p>
                    </div>
                    <div v-if="loadError" class="lw-error" role="alert">
                        <p>{{ loadError }}</p>
                        <button type="button" class="pr-btn pr-btn--sm" @click="load()">Try again</button>
                    </div>

                    <div ref="sentinel" class="lw-end">
                        <button v-if="next && !loading" type="button" class="pr-btn" @click="showMore">Show more</button>
                        <p v-else-if="next && loading" class="pr-small pr-muted">Loading more</p>
                    </div>
                </div>

                <aside v-if="!phone" class="stage" aria-label="Selected presentation">
                    <Transition name="st" mode="out-in">
                        <LibraryStage
                            v-if="selected" :key="selected.id" :row="selected" :archived="archived" :can-create="canCreate"
                            @rename-start="renamingId = selected.id" @duplicate="duplicate(selected)" @archive="archive(selected)" @restore="restore(selected)" @delete="confirm = { row: selected, busy: false }"
                        />
                    </Transition>
                </aside>
            </div>
        </div>

        <template #foot>
            <footer class="foot">
                <p class="foot-n" role="status"><span>{{ counts.lead }}</span><span v-if="counts.detail" class="foot-d">: {{ counts.detail }}</span></p>
                <nav aria-label="Dot ecosystem"><a :href="infodot.home">InfoDot</a><a :href="infodot.platforms">Platforms</a></nav>
            </footer>
        </template>

        <NewDeckSheet v-if="newOpen" :themes="themes" :ai="ai" @close="newOpen = false" />
        <ConfirmDialog
            v-if="confirm" title="Delete for good?" :body="`&quot;${confirm.row.title}&quot; and all its slides will be deleted. This cannot be undone.`" confirm="Delete permanently" :busy="confirm.busy"
            @confirm="deleteForever" @cancel="confirm = null"
        />
        <ToastView :toast="toast" @dismiss="toasts.dismiss($event)" @run="(t) => { t.action.run(); toasts.dismiss(t.id); }" />
        <div class="pr-sr" role="status" aria-live="polite">{{ live }}</div>
    </PressLayout>
</template>
