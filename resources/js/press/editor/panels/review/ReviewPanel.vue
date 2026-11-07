<script setup>
// The live consistency inspector. Fixed local rules read the SAVED deck on the server; this panel asks when it opens and
// again 2.5 seconds after the deck stops changing and has finished saving. Nothing is ever applied without a click.
import { computed, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import { Dialog, EmptyState, InlineNotice, PanelSection, ProgressBead, Segmented, SelectMenu, Toggle } from '../../../ui/controls';
import { lsGet, lsSet } from '../../../ui/controls/persist.js';
import { createIntel, describeError } from '../intel/api.js';
import { chunkOps, isSafeFix, normalizeOps } from '../intel/opsText.js';
import { createSettler } from '../intel/settler.js';
import ModeChip from '../intel/ModeChip.vue';
import '../intel/intel.css';
import IssueItem from './IssueItem.vue';
import { buildView, CATEGORIES, categoryLabel, fixAllPlan, summaryLine, truncationNotes } from './reviewModel.js';
import { ignoreIssue, ignoreKind, loadIgnores, restoreAll, restoreIssue, restoreKind, saveIgnores } from './ignoreStore.js';

const editor = useEditorContext();
const { store, state } = editor;
const intel = createIntel(editor.api, editor.deckId);
const storage = { get: (k) => lsGet(k, null), set: (k, v) => lsSet(k, v) };

const result = shallowRef(null);
// The deck as it was when the check answered: previews and slide numbers read this, so a drag or a keystroke does not
// re-draw hundreds of findings.
const snap = shallowRef(state.value.doc);
const status = ref('loading'); // loading | ready | error
const error = ref(null);
const checkedAt = ref(null);
const sync = ref({ stale: false, waiting: false, running: false });
const ignores = ref(loadIgnores(storage, editor.deckId));
const applied = ref(new Set());
const filters = reactive({ severity: 'all', category: 'all', thisSlide: false, showIgnored: false });
const limits = reactive({});
const fixAll = ref({ open: false, group: null, plan: null });
let token = 0;
let alive = true;

const slideCount = computed(() => snap.value.slides.length);
const canEdit = computed(() => !editor.readOnly && !!editor.can.update);
const view = computed(() => buildView(result.value?.issues || [], {
    ignores: ignores.value,
    severity: filters.severity,
    category: filters.category,
    slide: filters.thisSlide ? state.value.slideId : null,
    showIgnored: filters.showIgnored,
    applied: applied.value,
}));
const summary = computed(() => summaryLine(view.value, slideCount.value));
const notes = computed(() => truncationNotes(result.value?.summary));
const settled = () => ['saved', 'readonly'].includes(editor.saveInfo.value.key);

async function run() {
    const mine = ++token;
    try {
        const r = await intel.analyze();
        if (!alive || mine !== token) return;
        result.value = r;
        snap.value = state.value.doc;
        applied.value = new Set();
        error.value = null;
        checkedAt.value = new Date();
        status.value = 'ready';
    } catch (e) {
        if (!alive || mine !== token) return;
        error.value = describeError(e);
        status.value = result.value ? 'ready' : 'error';
    }
}
const settler = createSettler({ run, delay: 2500, isSettled: settled, onState: (s) => { sync.value = s; } });

onMounted(() => {
    if (settled()) settler.now(); else settler.touch();
});
watch(() => state.value.doc, () => settler.touch());
watch(() => editor.saveInfo.value.key, () => settler.settledChanged());
onBeforeUnmount(() => {
    alive = false;
    settler.stop();
    for (const k of Object.keys(editor.reviewIssues)) delete editor.reviewIssues[k];
});

// The Spine draws a square bead on every slide with an open finding.
watch(() => view.value.bySlide, (map) => {
    for (const k of Object.keys(editor.reviewIssues)) if (!(k in map)) delete editor.reviewIssues[k];
    for (const [k, n] of Object.entries(map)) if (editor.reviewIssues[k] !== n) editor.reviewIssues[k] = n;
}, { immediate: true });

const statusLine = computed(() => {
    if (sync.value.running) return 'Checking your saved deck.';
    if (sync.value.waiting) return 'Waiting for your changes to save, then checking again.';
    if (sync.value.stale) return 'You changed the deck. Checking again in a moment.';
    if (checkedAt.value) return `Checked at ${checkedAt.value.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}.`;
    return '';
});

function recheck() {
    editor.announce('Checking the deck');
    settler.now();
}

// ---- jumping, ignoring ----------------------------------------------------------------------------------------
function jump(slideId, elId) {
    if (slideId === null || slideId === undefined) return;
    store.setSlide(String(slideId));
    if (elId) store.select([elId]);
}
function persist(next) {
    ignores.value = next;
    if (!saveIgnores(storage, editor.deckId, next)) editor.toast('Your choice will be forgotten when you close this page, because this browser blocks local storage.', { kind: 'warn' });
}
const onIgnore = (i) => { persist(ignoreIssue(ignores.value, i)); editor.announce('Ignored'); };
const onIgnoreKind = (i) => { persist(ignoreKind(ignores.value, i)); editor.announce(`Ignoring every ${i.kind.replace(/-/g, ' ')} finding`); };
const onRestore = (i) => persist(restoreKind(restoreIssue(ignores.value, i), i.kind));
const ignoredKinds = computed(() => ignores.value.kinds);
const ignoreWords = computed(() => {
    const n = ignores.value.ids.length;
    const k = ignoredKinds.value.length;
    const parts = [n ? `${n} ${n === 1 ? 'finding' : 'findings'}` : '', k ? `every ${k === 1 ? 'finding of 1 kind' : `finding of ${k} kinds`}` : ''].filter(Boolean);
    return `You are ignoring ${parts.join(' and ')}.`;
});

// ---- applying -----------------------------------------------------------------------------------------------
function applyOne(issue, fix) {
    if (!canEdit.value) return;
    const ops = normalizeOps(fix.ops);
    const r = store.dispatch(ops, { undoKey: `review:fix:${issue.id}`, label: fix.label });
    if (!r) {
        editor.toast('That fix no longer applies. The slide has changed since the check.', { kind: 'warn' });
        settler.touch();
        return;
    }
    applied.value = new Set([...applied.value, issue.id]);
    const left = r.skipped?.length || 0;
    editor.toast(left ? `${fix.label}. ${left} of the changes no longer applied.` : `${fix.label}`, { kind: left ? 'warn' : 'ok', action: { label: 'Undo', run: () => store.undo() } });
    editor.announce(`${fix.label}. Applied.`);
}

function openFixAll(group) {
    const plan = fixAllPlan(group.issues.filter((i) => !ignoredKindOrId(i)), snap.value);
    fixAll.value = { open: true, group, plan };
}
const ignoredKindOrId = (i) => ignores.value.kinds.includes(i.kind) || ignores.value.ids.includes(i.id);
function confirmFixAll() {
    const { group, plan } = fixAll.value;
    if (!plan?.ops.length) return;
    const label = `Apply ${plan.entries.length} ${plan.entries.length === 1 ? 'fix' : 'fixes'}: ${group.label.toLowerCase()}`;
    let count = 0;
    store.transaction(label, () => {
        for (const batch of chunkOps(plan.ops)) {
            const r = store.dispatch(batch, { label });
            count += r ? r.applied.length : 0;
        }
    });
    applied.value = new Set([...applied.value, ...plan.entries.map((e) => e.issue.id)]);
    fixAll.value = { open: false, group: null, plan: null };
    editor.toast(`${plan.entries.length} ${plan.entries.length === 1 ? 'fix' : 'fixes'} applied`, { kind: 'ok', action: { label: 'Undo', run: () => store.undo() } });
    editor.announce(`${plan.entries.length} fixes applied`);
}
const safeCounts = computed(() => Object.fromEntries(view.value.groups.map((g) => [g.id, g.issues.filter((i) => i.fix && isSafeFix(i.fix) && !ignoredKindOrId(i)).length])));

const shownIn = (g) => g.issues.slice(0, limits[g.id] || 15);
const more = (g) => { limits[g.id] = (limits[g.id] || 15) + 25; };

const severityOptions = computed(() => [
    { value: 'all', label: 'All' },
    { value: 'issue', label: `Issues ${view.value.totals.issue}` },
    { value: 'warn', label: `Warnings ${view.value.totals.warn}` },
    { value: 'info', label: `Notes ${view.value.totals.info}` },
]);
const categoryOptions = computed(() => [
    { value: 'all', label: `All categories (${view.value.open})` },
    ...CATEGORIES.filter((c) => view.value.categoryCounts[c.id]).map((c) => ({ value: c.id, label: `${c.label} (${view.value.categoryCounts[c.id]})` })),
    ...Object.keys(view.value.categoryCounts).filter((id) => !CATEGORIES.some((c) => c.id === id)).map((id) => ({ value: id, label: `${categoryLabel(id)} (${view.value.categoryCounts[id]})` })),
]);
</script>

<template>
    <div class="iq iq-review">
        <header class="iq-head">
            <div class="iq-head-row">
                <h2 class="iq-title">Review</h2>
                <ModeChip mode="local" />
                <button type="button" class="pr-btn pr-btn--sm iq-recheck" :disabled="sync.running" @click="recheck">Check again</button>
            </div>
            <p class="iq-lead">Fixed rules read your saved deck. No language model is involved, and nothing here changes until you press Apply.</p>
            <p v-if="status !== 'loading' && result" class="iq-sum" aria-live="polite">{{ summary }}</p>
            <p class="iq-status pr-small pr-muted" role="status">
                <ProgressBead v-if="sync.running || status === 'loading'" :value="null" label="Checking" />
                <span v-else>{{ statusLine }}</span>
            </p>
        </header>

        <div v-if="status === 'loading'" class="iq-pad"><ProgressBead :value="null" label="Reading your deck" /></div>

        <div v-else-if="status === 'error'" class="iq-pad">
            <InlineNotice kind="error" :title="error?.kind === 'throttled' ? 'Slow down a moment' : 'The review could not run'">
                {{ error?.message }}
                <button type="button" class="pr-btn pr-btn--sm iq-retry" @click="recheck">Try again</button>
            </InlineNotice>
        </div>

        <template v-else>
            <div v-if="error" class="iq-pad"><InlineNotice kind="warn" title="Showing the last check">{{ error.message }}<button type="button" class="pr-btn pr-btn--sm iq-retry" @click="recheck">Try again</button></InlineNotice></div>
            <div v-for="n in notes" :key="n" class="iq-pad"><InlineNotice kind="info">{{ n }}</InlineNotice></div>

            <div v-if="result?.issues.length" class="iq-filters">
                <Segmented v-model="filters.severity" label="Severity" hide-label :options="severityOptions" />
                <SelectMenu v-model="filters.category" label="Category" hide-label :options="categoryOptions" />
                <Toggle v-model="filters.thisSlide" label="This slide only" />
                <Toggle v-if="view.ignored || filters.showIgnored" v-model="filters.showIgnored" :label="`Show ignored (${view.ignored})`" />
            </div>

            <EmptyState v-if="!result?.issues.length" title="No findings." body="Nothing in the rules applies to this deck. Claims, placeholders, fit, density and accessibility were all checked." compact />
            <EmptyState v-else-if="!view.open && !filters.showIgnored" title="Nothing open." body="Everything the rules found has been fixed or ignored." compact>
                <button v-if="view.ignored" type="button" class="pr-btn pr-btn--sm" @click="filters.showIgnored = true">Show the {{ view.ignored }} ignored</button>
            </EmptyState>
            <EmptyState v-else-if="!view.shown" title="Nothing matches these filters." compact>
                <button type="button" class="pr-btn pr-btn--sm" @click="Object.assign(filters, { severity: 'all', category: 'all', thisSlide: false })">Clear the filters</button>
            </EmptyState>

            <PanelSection v-for="g in view.groups" :id="`review.${g.id}`" :key="g.id" :title="g.label" :state="g.total">
                <template v-if="canEdit && safeCounts[g.id] > 1" #actions>
                    <button type="button" class="pr-btn pr-btn--ghost pr-btn--sm" @click="openFixAll(g)">Fix all safe ({{ safeCounts[g.id] }})</button>
                </template>
                <ul class="iq-list">
                    <IssueItem
                        v-for="i in shownIn(g)" :key="i.id" :issue="i" :doc="snap" :can-edit="canEdit" :ignored="ignoredKindOrId(i)"
                        @jump="jump" @apply="applyOne" @ignore="onIgnore" @ignore-kind="onIgnoreKind" @restore="onRestore"
                    />
                </ul>
                <button v-if="g.issues.length > shownIn(g).length" type="button" class="pr-btn pr-btn--sm iq-more" @click="more(g)">Show {{ Math.min(25, g.issues.length - shownIn(g).length) }} more of {{ g.issues.length - shownIn(g).length }}</button>
            </PanelSection>

            <footer v-if="ignoredKinds.length || ignores.ids.length" class="iq-foot">
                <p class="pr-small pr-muted">{{ ignoreWords }} This is remembered in this browser, for this deck only.</p>
                <button type="button" class="pr-btn pr-btn--ghost pr-btn--sm" @click="persist(restoreAll())">Stop ignoring everything</button>
            </footer>
        </template>

        <Dialog v-model:open="fixAll.open" :title="`Apply safe fixes: ${fixAll.group?.label || ''}`" size="md">
            <template v-if="fixAll.plan">
                <p class="iq-dlg-lead">These {{ fixAll.plan.entries.length }} fixes only change how things look or are placed. Text, pictures and data are not touched. One Undo reverses all of them.</p>
                <ul class="iq-plan">
                    <li v-for="e in fixAll.plan.entries" :key="e.issue.id"><span class="iq-plan-m">{{ e.issue.message }}</span><span v-for="(l, i) in e.lines" :key="i" class="iq-plan-l">{{ l }}</span></li>
                </ul>
                <template v-if="fixAll.plan.left.length">
                    <p class="iq-dlg-lead">Left for you to decide one at a time ({{ fixAll.plan.left.length }}):</p>
                    <ul class="iq-plan is-left"><li v-for="l in fixAll.plan.left" :key="l.issue.id"><span class="iq-plan-m">{{ l.issue.message }}</span><span class="iq-plan-l">{{ l.reason }}</span></li></ul>
                </template>
            </template>
            <template #footer="{ close }">
                <button type="button" class="pr-btn" @click="close">Cancel</button>
                <button type="button" class="pr-btn pr-btn--primary" data-autofocus :disabled="!fixAll.plan?.ops.length" @click="confirmFixAll">Apply {{ fixAll.plan?.entries.length || 0 }} fixes</button>
            </template>
        </Dialog>
    </div>
</template>
