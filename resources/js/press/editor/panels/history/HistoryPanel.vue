<script setup>
// History: the versions of the deck (autosaved, saved by hand, approved, safety copies), a comparison between a version and
// the deck as it is now, restoring one, and the activity feed on a second tab.
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import { createDoc } from '../../core/doc.js';
import { Dialog, EmptyState, InlineNotice, ProgressBead, Tabs } from '../../../ui/controls';
import { timeAgo } from '../comments/commentsModel.js';
import { useComments } from '../comments/useComments.js';
import { useDeckStatus } from '../approval/useDeckStatus.js';
import { clockTime, cleanVersionName, compareDocs, describeRestore, describeSince, groupByDay, kindBead, kindWord, versionTitle } from './versionDiff.js';

const editor = useEditorContext();
const rootEl = ref(null);
/** The drawer keeps its scroll position between panels; a panel starts at its top. */
onMounted(() => rootEl.value?.closest('.ph')?.scrollTo({ top: 0 }));
const base = `/api/decks/${editor.deckId}`;
const canUpdate = computed(() => editor.can.update === true);
const deckStatus = useDeckStatus(editor);
const comments = useComments(editor);
const tab = ref('versions');

// ---- versions ----------------------------------------------------------------------------------------------
const versions = ref([]);
const nextBefore = ref(null);
const status = ref('loading'); // loading | ready | error
const error = ref('');
const loadingMore = ref(false);
const now = ref(Date.now());
let clock = null;

async function loadVersions() {
    status.value = versions.value.length ? 'ready' : 'loading';
    try {
        const res = await editor.api.get(`${base}/versions`, { limit: 40 });
        versions.value = res.versions || [];
        nextBefore.value = res.next_before ?? null;
        status.value = 'ready';
        error.value = '';
    } catch (e) {
        status.value = versions.value.length ? 'ready' : 'error';
        error.value = e?.status === 403 ? 'Your role cannot see the history of this deck.' : (e?.message || 'Could not load the versions.');
    }
}
async function loadOlder() {
    if (!nextBefore.value || loadingMore.value) return;
    loadingMore.value = true;
    try {
        const res = await editor.api.get(`${base}/versions`, { limit: 40, before: nextBefore.value });
        versions.value = [...versions.value, ...(res.versions || [])];
        nextBefore.value = res.next_before ?? null;
    } catch (e) { error.value = e?.message || 'Could not load older versions.'; } finally { loadingMore.value = false; }
}
const groups = computed(() => groupByDay(versions.value, now.value));

onMounted(() => {
    loadVersions();
    clock = window.setInterval(() => { now.value = Date.now(); }, 60000);
});
onBeforeUnmount(() => window.clearInterval(clock));

// ---- save a named version -----------------------------------------------------------------------------------
const name = ref('');
const saving = ref(false);
const saveError = ref('');
async function saveNamed() {
    saving.value = true;
    saveError.value = '';
    try {
        await editor.settle();
        const v = await editor.api.post(`${base}/versions`, { label: cleanVersionName(name.value) || undefined });
        versions.value = [v, ...versions.value.filter((x) => x.id !== v.id)];
        name.value = '';
        editor.toast('Version saved', { kind: 'ok' });
        editor.announce('Version saved');
        activityLoaded.value = false;
    } catch (e) {
        saveError.value = e?.status === 403 ? 'Your role cannot save versions.' : e?.status === 429 ? 'Too many saves in a row. Try again in a moment.' : (e?.message || 'Could not save the version.');
    } finally { saving.value = false; }
}

// ---- opening a version: comparison ----------------------------------------------------------------------------
const openId = ref(null);
const snapshots = new Map();
const compare = ref(null); // {id, diff}
const compareStatus = ref('idle'); // idle | loading | error
const compareError = ref('');

async function toggle(v) {
    if (openId.value === v.id) { openId.value = null; return; }
    openId.value = v.id;
    compare.value = null;
    compareError.value = '';
    compareStatus.value = 'loading';
    try {
        let snap = snapshots.get(v.id);
        if (!snap) {
            const full = await editor.api.get(`${base}/versions/${v.id}`);
            snap = createDoc(full.snapshot);
            snapshots.set(v.id, snap);
        }
        if (openId.value !== v.id) return;
        compare.value = { id: v.id, diff: compareDocs(snap, editor.state.value.doc) };
        compareStatus.value = 'idle';
    } catch (e) {
        if (openId.value !== v.id) return;
        compareStatus.value = 'error';
        compareError.value = e?.message || 'Could not load this version.';
    }
}
const since = computed(() => (compare.value ? describeSince(compare.value.diff) : []));
const slideChanges = computed(() => {
    const d = compare.value?.diff;
    if (!d) return [];
    const rows = [];
    for (const s of d.added) rows.push({ k: `a${s.id}`, word: 'added', text: s.title });
    for (const s of d.removed) rows.push({ k: `r${s.id}`, word: 'removed', text: s.title });
    for (const s of d.retitled) rows.push({ k: `t${s.id}`, word: 'retitled', text: `${s.from} to ${s.to}` });
    for (const s of d.moved) rows.push({ k: `m${s.id}`, word: 'moved', text: `${s.title}, from ${s.from + 1} to ${s.to + 1}` });
    for (const s of d.edited) rows.push({ k: `e${s.id}`, word: 'edited', text: `${s.title}: ${[s.added && `${s.added} added`, s.removed && `${s.removed} removed`, s.changed && `${s.changed} changed`].filter(Boolean).join(', ')}` });
    for (const s of d.notes) rows.push({ k: `n${s.id}`, word: 'notes', text: s.title });
    return rows;
});
const showAllChanges = ref(false);
const shownChanges = computed(() => (showAllChanges.value ? slideChanges.value : slideChanges.value.slice(0, 8)));

// ---- restore -------------------------------------------------------------------------------------------------
const confirming = ref(null); // version row
const restoring = ref(false);
const restoreError = ref('');
const confirmEffect = computed(() => (confirming.value && compare.value?.id === confirming.value.id ? describeRestore(compare.value.diff) : []));

function ask(v) { restoreError.value = ''; confirming.value = v; }
async function restore(v, { undo = false } = {}) {
    restoring.value = true;
    restoreError.value = '';
    try {
        await editor.settle();
        const res = await editor.api.post(`${base}/versions/${v.id}/restore`);
        // The server logged deck.reload; ask for the feed now instead of waiting for the next poll.
        await editor.sync.pollNow();
        if ((editor.sync.getBase()?.seq ?? 0) < res.seq) await editor.sync.reload();
        confirming.value = null;
        openId.value = null;
        snapshots.clear();
        const label = versionTitle(v);
        editor.announce(undo ? 'Restore undone' : `Restored ${label}`);
        editor.toast(undo ? 'Restore undone' : 'Restored. A safety copy is kept.', {
            kind: 'ok',
            ...(undo || !res.safety_version ? {} : { action: { label: 'Undo', run: () => restore({ id: res.safety_version, label: 'the safety copy', kind: 'restore' }, { undo: true }) } }),
        });
        // The restored deck may be approved again, and its comments may sit on other slides.
        deckStatus.refresh();
        comments.load();
        activityLoaded.value = false;
        await loadVersions();
    } catch (e) {
        restoreError.value = e?.status === 403 ? 'Your role cannot restore versions.' : (e?.message || 'Could not restore this version.');
    } finally { restoring.value = false; }
}

// ---- activity ------------------------------------------------------------------------------------------------
const activity = ref([]);
const actStatus = ref('idle');
const actError = ref('');
const actPage = ref(0);
const actLast = ref(1);
const activityLoaded = ref(false);
async function loadActivity(more = false) {
    if (actStatus.value === 'loading') return;
    const page = more ? actPage.value + 1 : 1;
    actStatus.value = 'loading';
    try {
        const res = await editor.api.get(`${base}/activity`, { per_page: 30, page });
        activity.value = more ? [...activity.value, ...res.data] : res.data;
        actPage.value = res.current_page;
        actLast.value = res.last_page;
        actStatus.value = 'ready';
        actError.value = '';
        activityLoaded.value = true;
    } catch (e) {
        actStatus.value = activity.value.length ? 'ready' : 'error';
        actError.value = e?.message || 'Could not load the activity.';
    }
}
async function pickTab(id) {
    tab.value = id;
    if (id === 'activity' && !activityLoaded.value) loadActivity();
    await nextTick();
}
const actGroups = computed(() => groupByDay(activity.value, now.value));
const actBead = (event) => ({ approved: 'solid', 'review.approved': 'solid', 'review.changes_requested': 'issue', 'version.restored': 'current' })[event] || 'hollow';

const tabs = computed(() => [{ id: 'versions', label: 'Versions' }, { id: 'activity', label: 'Activity' }]);
</script>

<template>
    <div ref="rootEl" class="hst">
        <div class="hst-tabs"><Tabs :model-value="tab" :items="tabs" label="History" @update:model-value="pickTab" /></div>

        <!-- versions -->
        <section v-if="tab === 'versions'" id="tabpanel-versions" role="tabpanel" aria-labelledby="tab-versions" class="hst-body">
            <form v-if="canUpdate" class="hst-save" @submit.prevent="saveNamed">
                <label class="c-label" for="hp-name">Save a named version</label>
                <div class="hst-save-r">
                    <input id="hp-name" v-model="name" class="pr-field" maxlength="120" placeholder="For example: sent to the board" autocomplete="off" />
                    <button type="submit" class="pr-btn pr-btn--primary" :disabled="saving">{{ saving ? 'Saving' : 'Save' }}</button>
                </div>
                <p v-if="saveError" class="c-error" role="alert"><span class="pr-bead pr-bead--bad pr-bead--sm" aria-hidden="true"></span>{{ saveError }}</p>
                <p v-else class="c-hint">The deck is also saved on its own about every 20 minutes. Named versions are kept until you delete the deck.</p>
            </form>
            <p v-else class="hst-ro pr-small">You can look at earlier versions. Only editors can save or restore them.</p>

            <div v-if="status === 'loading'" class="hst-state"><ProgressBead :value="null" label="Loading versions" /><p class="pr-small pr-muted">Loading versions</p></div>
            <div v-else-if="status === 'error'" class="hst-state">
                <InlineNotice kind="error" title="Versions did not load">{{ error }}</InlineNotice>
                <button type="button" class="pr-btn pr-btn--sm" @click="loadVersions">Try again</button>
            </div>
            <EmptyState v-else-if="!versions.length" title="No versions yet." body="The first one is saved after your next change, and again about every 20 minutes of editing." compact />
            <template v-else>
                <InlineNotice v-if="error" kind="warn" class="hst-note">{{ error }}</InlineNotice>
                <div v-for="g in groups" :key="g.key" class="hst-day">
                    <h3 class="hst-dayh pr-state">{{ g.label }}</h3>
                    <ul class="hst-list">
                        <li v-for="v in g.items" :key="v.id" class="hsv" :class="{ 'is-open': openId === v.id }">
                            <button type="button" class="hsv-row" :aria-expanded="openId === v.id" :aria-controls="`vr-${v.id}`" @click="toggle(v)">
                                <span class="pr-bead hsv-bead" :data-state="kindBead(v.kind).state" :data-evidence="kindBead(v.kind).evidence" aria-hidden="true"></span>
                                <span class="hsv-t">
                                    <span class="hsv-title">{{ versionTitle(v) }}</span>
                                    <span class="hsv-meta"><span class="pr-state">{{ kindWord(v.kind) }}</span> {{ v.author ? v.author.name : 'The system' }}, {{ v.slide_count }} {{ v.slide_count === 1 ? 'slide' : 'slides' }}</span>
                                </span>
                                <span class="pr-state hsv-time" :title="timeAgo(v.created_at, now)">{{ clockTime(v.created_at) }}</span>
                            </button>
                            <div v-if="openId === v.id" :id="`vr-${v.id}`" class="hsv-detail">
                                <div v-if="compareStatus === 'loading'" class="hst-state hst-state--tight"><ProgressBead :value="null" label="Comparing" /><p class="pr-small pr-muted">Comparing with the deck now</p></div>
                                <InlineNotice v-else-if="compareStatus === 'error'" kind="error">{{ compareError }}</InlineNotice>
                                <template v-else-if="compare && compare.id === v.id">
                                    <p class="hsv-h pr-state">since this version</p>
                                    <ul class="hsv-sum">
                                        <li v-for="line in since" :key="line">{{ line }}</li>
                                    </ul>
                                    <ul v-if="slideChanges.length" class="hsv-ch" aria-label="Changed slides">
                                        <li v-for="c in shownChanges" :key="c.k"><span class="pr-state hsv-w">{{ c.word }}</span><span class="hsv-ct">{{ c.text }}</span></li>
                                    </ul>
                                    <button v-if="slideChanges.length > 8 && !showAllChanges" type="button" class="hsv-link" @click="showAllChanges = true">Show all {{ slideChanges.length }}</button>
                                    <div v-if="canUpdate" class="hsv-act">
                                        <button type="button" class="pr-btn pr-btn--sm" :disabled="compare.diff.identical" @click="ask(v)">Restore this version</button>
                                    </div>
                                </template>
                            </div>
                        </li>
                    </ul>
                </div>
                <div v-if="nextBefore" class="hst-more"><button type="button" class="pr-btn pr-btn--sm" :disabled="loadingMore" @click="loadOlder">{{ loadingMore ? 'Loading' : 'Show older versions' }}</button></div>
            </template>
        </section>

        <!-- activity -->
        <section v-else id="tabpanel-activity" role="tabpanel" aria-labelledby="tab-activity" class="hst-body">
            <div v-if="actStatus === 'loading' && !activity.length" class="hst-state"><ProgressBead :value="null" label="Loading activity" /><p class="pr-small pr-muted">Loading activity</p></div>
            <div v-else-if="actStatus === 'error' && !activity.length" class="hst-state">
                <InlineNotice kind="error" title="Activity did not load">{{ actError }}</InlineNotice>
                <button type="button" class="pr-btn pr-btn--sm" @click="loadActivity()">Try again</button>
            </div>
            <EmptyState v-else-if="!activity.length" title="Nothing has happened yet." body="Renames, sharing, comments, reviews and versions are listed here." compact />
            <template v-else>
                <div class="hst-actbar"><button type="button" class="hsv-link" :disabled="actStatus === 'loading'" @click="loadActivity()">Refresh</button></div>
                <div v-for="g in actGroups" :key="g.key" class="hst-day">
                    <h3 class="hst-dayh pr-state">{{ g.label }}</h3>
                    <ul class="hsa-list">
                        <li v-for="a in g.items" :key="a.id" class="hsa-row">
                            <span class="pr-bead pr-bead--sm hsa-bead" :data-state="actBead(a.event)" :data-evidence="actBead(a.event) === 'solid' ? 'solid' : 'hollow'" aria-hidden="true"></span>
                            <span class="hsa-t">{{ a.summary }}</span>
                            <span class="pr-state hsa-time" :title="timeAgo(a.created_at, now)">{{ clockTime(a.created_at) }}</span>
                        </li>
                    </ul>
                </div>
                <div v-if="actPage < actLast" class="hst-more"><button type="button" class="pr-btn pr-btn--sm" :disabled="actStatus === 'loading'" @click="loadActivity(true)">Show older activity</button></div>
            </template>
        </section>

        <Dialog :open="!!confirming" title="Restore this version?" size="sm" @update:open="confirming = null">
            <template v-if="confirming">
                <p class="pr-small"><strong>{{ versionTitle(confirming) }}</strong>, {{ timeAgo(confirming.created_at, now) }}.</p>
                <ul v-if="confirmEffect.length" class="hst-eff"><li v-for="line in confirmEffect" :key="line">{{ line }}</li></ul>
                <p class="pr-small">The deck changes for everyone who has it open. A safety version of the deck as it is now is saved first, so you can go back to it from this list.</p>
                <InlineNotice v-if="restoreError" kind="error">{{ restoreError }}</InlineNotice>
            </template>
            <template #footer="{ close }">
                <button type="button" class="pr-btn pr-btn--sm pr-btn--ghost" @click="close">Cancel</button>
                <button type="button" class="pr-btn pr-btn--sm pr-btn--primary" data-autofocus :disabled="restoring" @click="restore(confirming)">{{ restoring ? 'Restoring' : 'Restore' }}</button>
            </template>
        </Dialog>
    </div>
</template>

<style scoped>
.hst { display: flex; flex-direction: column; min-height: 100%; }
.hst-tabs { position: sticky; top: 0; z-index: 3; background: var(--sheet); padding: 6px 12px 0; border-bottom: 1px solid var(--line); }
.hst-body { display: grid; align-content: start; gap: 4px; padding-bottom: 24px; }
.hst-save { display: grid; gap: 6px; padding: 14px 16px; border-bottom: 1px solid var(--line); }
.hst-save .c-label { margin: 0; }
.hst-save-r { display: flex; gap: 8px; }
.hst-save-r .pr-field { flex: 1; min-width: 0; height: 36px; font-size: var(--t-sm); }
.hst-ro { margin: 0; padding: 12px 16px; color: var(--ink-2); border-bottom: 1px solid var(--line); }
.hst-state { display: grid; gap: 12px; justify-items: start; padding: 20px 16px; }
.hst-state--tight { padding: 8px 0; }
.hst-note { margin: 12px 16px 0; }
.hst-day { padding-top: 12px; }
.hst-dayh { margin: 0; padding: 0 16px 6px; color: var(--muted); font-weight: 500; }
.hst-list, .hsa-list { list-style: none; margin: 0; padding: 0; }
.hsv { position: relative; }
.hsv-row { position: relative; display: grid; grid-template-columns: 16px minmax(0, 1fr) auto; gap: 10px; align-items: start; width: 100%; min-height: 52px; padding: 8px 16px 8px 14px; border: 0; border-left: 2px solid transparent; background: transparent; text-align: left; color: var(--ink); }
.hsv-row:hover { background: var(--raised); }
.hsv.is-open > .hsv-row { background: var(--raised); border-left-color: var(--ink); }
.hsv-row:focus-visible { outline: 2px solid var(--focus); outline-offset: -2px; }
.hsv-bead { margin-top: 3px; justify-self: center; }
.hsv-bead[data-state='hollow'] { background-color: var(--sheet); }
.hsv-t { display: grid; gap: 1px; min-width: 0; }
.hsv-title { font-size: var(--t-sm); font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hsv-meta { font-size: var(--t-xs); color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hsv-meta .pr-state { margin-right: 4px; color: var(--ink-2); }
.hsv-time { color: var(--muted); padding-top: 2px; }
.hsv-detail { display: grid; gap: 8px; padding: 4px 16px 14px 42px; background: var(--raised); border-left: 2px solid var(--ink); }
.hsv-h { margin: 0; color: var(--muted); }
.hsv-sum { margin: 0; padding: 0; list-style: none; display: grid; gap: 3px; }
.hsv-sum li { font-size: var(--t-sm); line-height: 1.4; }
.hsv-ch { margin: 0; padding: 0; list-style: none; display: grid; gap: 2px; border-top: 1px solid var(--line); padding-top: 8px; }
.hsv-ch li { display: grid; grid-template-columns: 60px minmax(0, 1fr); gap: 8px; font-size: var(--t-sm); line-height: 1.4; }
.hsv-w { color: var(--muted); }
.hsv-ct { overflow-wrap: anywhere; }
.hsv-link { justify-self: start; min-height: 28px; padding: 0; border: 0; background: none; color: var(--ink-2); font-size: var(--t-sm); font-weight: 500; text-decoration: underline; text-decoration-color: var(--line-strong); text-underline-offset: 3px; }
.hsv-link:hover:not(:disabled) { color: var(--ink); text-decoration-color: var(--ink); }
.hsv-act { padding-top: 4px; }
.hst-more { padding: 14px 16px 0; }
.hst-actbar { display: flex; justify-content: flex-end; padding: 8px 16px 0; }
.hsa-row { display: grid; grid-template-columns: 12px minmax(0, 1fr) auto; gap: 10px; align-items: start; padding: 8px 16px 8px 16px; }
.hsa-bead { margin-top: 5px; }
.hsa-bead[data-state='hollow'] { background-color: var(--sheet); }
.hsa-t { font-size: var(--t-sm); line-height: 1.4; overflow-wrap: anywhere; }
.hsa-time { color: var(--muted); padding-top: 2px; }
.hst-eff { margin: 0 0 12px; padding-left: 18px; font-size: var(--t-sm); line-height: 1.5; }
@media (pointer: coarse) { .hsv-row { min-height: 60px; } .hsv-link { min-height: 44px; } .hst-save-r .pr-field { height: 44px; } }
</style>
