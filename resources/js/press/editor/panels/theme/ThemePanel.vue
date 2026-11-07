<script setup>
// Deck theme panel: the gallery (12 built in themes, the team's custom themes, brand kits), the report before a theme is
// applied, a temporary preview, the custom theme editor, brand kits and the consistency check. Applying goes through the
// core store (applyTheme and the deck.set op), so it is one undo step, autosaved and shared with collaborators.
import {
    computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch,
} from 'vue';
import { useEditorContext } from '../../context.js';
import {
    InlineNotice, PanelSection, ProgressBead, Segmented, Tabs,
} from '../../../ui/controls';
import ThemeGallery from './ThemeGallery.vue';
import ThemeApplyBar from './ThemeApplyBar.vue';
import ThemePreviewDialog from './ThemePreviewDialog.vue';
import ThemeEditor from './ThemeEditor.vue';
import BrandKitList from './BrandKitList.vue';
import BrandKitEditor from './BrandKitEditor.vue';
import ConsistencyReview from './ConsistencyReview.vue';
import { useThemeLibrary } from './useThemeLibrary.js';
import {
    apiErrorText, cleanTheme, draftDirty, draftFromTheme, draftPayload, isCurrent, isModified, themeKind,
} from './themeModel.js';
import { themeChanges, themeReport } from './themeReport.js';
import { findHardColours, mappingOps } from './consistency.js';
import {
    draftFromKit, emptyKit, kitBody, kitThemeCard,
} from './brandKitModel.js';
import { deepEqual } from '../../core/util.js';
import './design.css';

const props = defineProps({ panelProps: { type: Object, default: null } });
const editor = useEditorContext();
const { store } = editor;
const lib = useThemeLibrary(editor);

const tab = ref(props.panelProps?.tab === 'brand' ? 'brand' : 'themes');
const view = ref('gallery'); // gallery | editor | kit
const source = ref('sample');
const candidate = shallowRef(null);
const previewOpen = ref(false);
const justChanged = ref(null); // {name, count}
const consistencyBox = ref(null);

const canApply = computed(() => !editor.readOnly && !!editor.can.update);
const deckTheme = computed(() => editor.deck.value.theme);
const doc = computed(() => editor.state.value.doc);

// ---- gallery data -------------------------------------------------------------------------------
const groups = computed(() => {
    const g = lib.groups.value;
    const out = [{ id: 'builtin', title: 'Built in', items: g.builtin }];
    if (g.custom.length) out.push({ id: 'custom', title: 'Your team', items: g.custom });
    if (g.brand.length) out.push({ id: 'brand', title: 'Brand kits', items: g.brand });
    return out.filter((x) => x.items.length);
});
const tabs = computed(() => [
    { id: 'themes', label: 'Themes', count: lib.status.value === 'ready' ? lib.groups.value.builtin.length + lib.groups.value.custom.length : null },
    { id: 'brand', label: 'Brand kits', count: lib.status.value === 'ready' ? lib.kits.value.length : null },
]);
const defaultKitCard = computed(() => (lib.defaultKit.value?.theme ? kitThemeCard(lib.defaultKit.value) : null));

// ---- report before applying ---------------------------------------------------------------------
const report = computed(() => (candidate.value ? themeReport(doc.value, candidate.value) : null));
const changes = computed(() => (candidate.value ? themeChanges(deckTheme.value, candidate.value) : null));
const sameTheme = computed(() => !!candidate.value && isCurrent(deckTheme.value, candidate.value) && !isModified(deckTheme.value, candidate.value));
const candidateWarnings = computed(() => candidate.value?.warnings || []);
const editLabel = computed(() => (candidate.value && themeKind(candidate.value) === 'custom' ? 'Edit' : themeKind(candidate.value || {}) === 'brand' ? '' : 'Duplicate'));

function pick(theme) { candidate.value = theme; }
function cancel() { candidate.value = null; }

function apply() {
    const theme = candidate.value;
    if (!theme || !canApply.value) return;
    const clean = cleanTheme(theme);
    store.setTheme(clean);
    const name = theme.kitName || theme.name;
    candidate.value = null;
    previewOpen.value = false;
    recompute();
    const count = consistency.value.proposals.length;
    justChanged.value = { name, count };
    editor.toast(`Theme changed to ${name}`, { kind: 'ok', action: { label: 'Undo', run: () => { store.undo(); justChanged.value = null; } } });
    editor.announce(`Theme changed to ${name}.${count ? ` ${count} hard coded ${count === 1 ? 'colour does' : 'colours do'} not follow it.` : ''}`);
}

// ---- custom theme editor ------------------------------------------------------------------------
const edit = ref(null); // {draft, base, saved, isNew, error}: base is what the draft is compared with
const editDirty = computed(() => !!edit.value && draftDirty(edit.value.draft, edit.value.base));
const startEdit = (draft, saved, isNew) => { edit.value = { draft, base: draft, saved, isNew, error: '' }; view.value = 'editor'; };

function newFromDeck() {
    startEdit(draftFromTheme(deckTheme.value, `${deckTheme.value.name || 'Theme'} copy`.slice(0, 80)), null, true);
}
function editCandidate() {
    const t = candidate.value;
    if (!t) return;
    if (themeKind(t) === 'custom') startEdit(draftFromTheme(t), t, false);
    else startEdit(draftFromTheme(t, `${t.name} copy`.slice(0, 80)), null, true);
    candidate.value = null;
}
function backFromEditor() {
    if (editDirty.value && !window.confirm('Leave without saving your changes to this theme?')) return;
    edit.value = null;
    view.value = 'gallery';
}
async function saveTheme(andApply) {
    const e = edit.value;
    e.error = '';
    try {
        const body = draftPayload(e.draft);
        const t = e.isNew ? await lib.createTheme(body) : await lib.updateTheme(e.saved.db_id, body);
        startEdit(draftFromTheme(t), t, false);
        editor.announce(`Theme ${t.name} saved`);
        if (andApply) {
            candidate.value = t;
            apply();
        } else {
            editor.toast(`Saved the theme ${t.name}`, { kind: 'ok' });
        }
    } catch (err) {
        e.error = apiErrorText(err, 'themes');
    }
}
async function deleteTheme() {
    const e = edit.value;
    try {
        await lib.deleteTheme(e.saved.db_id);
        editor.toast(`Deleted the theme ${e.saved.name}`, { kind: 'info' });
        editor.announce('Theme deleted');
        edit.value = null;
        view.value = 'gallery';
    } catch (err) {
        e.error = apiErrorText(err, 'themes');
    }
}

// ---- brand kits ---------------------------------------------------------------------------------
const kitEdit = ref(null); // {draft, saved: draft, isNew, error}
const kitDirty = computed(() => !!kitEdit.value && (kitEdit.value.isNew || !deepEqual(kitBody(kitEdit.value.draft), kitBody(kitEdit.value.saved))));
function newKit() { const d = emptyKit(); kitEdit.value = { draft: d, saved: d, isNew: true, error: '' }; view.value = 'kit'; }
function editKit(k) { const d = draftFromKit(k); kitEdit.value = { draft: d, saved: d, isNew: false, error: '' }; view.value = 'kit'; }
function backFromKit() {
    if (kitDirty.value && !window.confirm('Leave without saving your changes to this brand kit?')) return;
    kitEdit.value = null;
    view.value = 'gallery';
}
async function saveKit() {
    const e = kitEdit.value;
    e.error = '';
    try {
        const body = kitBody(e.draft);
        const k = e.isNew ? await lib.createKit(body) : await lib.updateKit(e.draft.id, body);
        const d = draftFromKit(k);
        kitEdit.value = { draft: d, saved: d, isNew: false, error: '' };
        editor.toast(`Saved the brand kit ${k.name}`, { kind: 'ok' });
        editor.announce(`Brand kit ${k.name} saved`);
    } catch (err) {
        e.error = apiErrorText(err, 'brand kits');
    }
}
async function deleteKit() {
    const e = kitEdit.value;
    try {
        await lib.deleteKit(e.draft.id);
        editor.toast(`Deleted the brand kit ${e.draft.name}`, { kind: 'info' });
        kitEdit.value = null;
        view.value = 'gallery';
    } catch (err) {
        e.error = apiErrorText(err, 'brand kits');
    }
}
function applyKit(k) {
    candidate.value = kitThemeCard(k);
}

// ---- consistency --------------------------------------------------------------------------------
const consistency = shallowRef({ proposals: [], alphaKept: 0 });
let timer = null;
function recompute() { clearTimeout(timer); consistency.value = findHardColours(editor.state.value.doc, editor.deck.value.theme); }
watch([doc, deckTheme], () => { clearTimeout(timer); timer = setTimeout(recompute, 400); });
onMounted(recompute);
onBeforeUnmount(() => clearTimeout(timer));

function mapColours(chosen) {
  const ops = mappingOps(doc.value, chosen);
  if (!ops.length) return;
  store.dispatch(ops, { label: 'Map colours to theme' });
  recompute();
  justChanged.value = null;
  editor.toast(`Mapped ${chosen.length} ${chosen.length === 1 ? 'colour' : 'colours'} to theme colours`, { kind: 'ok', action: { label: 'Undo', run: () => store.undo() } });
  editor.announce(`Mapped ${chosen.length} colours to theme colours`);
}
async function reviewNow() {
    tab.value = 'themes';
    view.value = 'gallery';
    await nextTick();
    consistencyBox.value?.scrollIntoView?.({ block: 'start' });
}

watch(tab, () => { candidate.value = null; });
</script>

<template>
    <div class="dz dz-theme">
        <!-- Custom theme editor -->
        <ThemeEditor
            v-if="view === 'editor' && edit" v-model:draft="edit.draft" :is-new="edit.isNew" :dirty="editDirty" :busy="lib.busy.value" :error="edit.error"
            :read-only="!canApply" :in-use="!edit.isNew && isCurrent(deckTheme, edit.saved)" @back="backFromEditor" @save="saveTheme(false)" @save-apply="saveTheme(true)" @delete="deleteTheme"
        />
        <BrandKitEditor
            v-else-if="view === 'kit' && kitEdit" v-model:draft="kitEdit.draft" :limits="lib.kitLimits.value" :is-new="kitEdit.isNew" :dirty="kitDirty" :busy="lib.busy.value"
            :error="kitEdit.error" :read-only="!canApply" @back="backFromKit" @save="saveKit" @delete="deleteKit"
        />

        <template v-else>
            <div class="dz-pad">
                <Tabs v-model="tab" :items="tabs" label="Theme sections" />
            </div>

            <div v-if="lib.status.value === 'loading'" class="dz-pad" role="status"><ProgressBead :value="null" label="Loading themes" /></div>
            <div v-else-if="lib.status.value === 'error'" class="dz-pad">
                <InlineNotice kind="error" :title="lib.errorStatus.value === 403 ? 'Themes are not available to you' : 'Themes could not be loaded'">
                    {{ lib.error.value }} <button v-if="lib.errorStatus.value !== 403" type="button" class="pr-btn pr-btn--sm" @click="lib.load()">Try again</button>
                </InlineNotice>
            </div>

            <template v-else-if="tab === 'themes'">
                <div class="dz-pad c-stack">
                    <InlineNotice v-if="justChanged" kind="ok" :title="`Theme changed to ${justChanged.name}`">
                        <template v-if="justChanged.count">{{ justChanged.count }} hard coded {{ justChanged.count === 1 ? 'colour does' : 'colours do' }} not follow it.
                            <button type="button" class="pr-btn pr-btn--sm" @click="reviewNow">Review</button></template>
                        <template v-else>Every colour follows the new theme.</template>
                    </InlineNotice>
                    <Segmented v-model="source" label="Cards show" :options="[{ value: 'sample', label: 'Sample slide' }, { value: 'slide', label: 'This slide' }]" />
                    <InlineNotice v-if="defaultKitCard && !isCurrent(deckTheme, defaultKitCard)" kind="info" title="Team default brand kit">
                        {{ lib.defaultKit.value.name }} is your team default.
                        <button type="button" class="pr-btn pr-btn--sm" @click="pick(defaultKitCard)">Choose it</button>
                    </InlineNotice>
                    <InlineNotice v-if="lib.kitError.value" kind="warn">{{ lib.kitError.value }}</InlineNotice>
                </div>

                <ThemeGallery :groups="groups" :deck-theme="deckTheme" :candidate-id="candidate?.id || null" :source="source" @pick="pick" />

                <div class="dz-pad dz-actions">
                    <button type="button" class="pr-btn pr-btn--sm" :disabled="!canApply" @click="newFromDeck">New theme from this deck</button>
                </div>

                <div ref="consistencyBox">
                    <PanelSection id="theme.consistency" title="Consistency after theme change" :state="consistency.proposals.length ? String(consistency.proposals.length) : 'ok'" :collapsible="false">
                        <ConsistencyReview :proposals="consistency.proposals" :alpha-kept="consistency.alphaKept" :disabled="!canApply" :fresh="!!justChanged" @map="mapColours" />
                    </PanelSection>
                </div>
            </template>

            <template v-else>
                <BrandKitList
                    :kits="lib.kits.value" :limit="lib.kitLimits.value.kits" :can-write="canApply" :can-apply="canApply" :active-id="deckTheme.id" :candidate-id="candidate?.id || null"
                    :error="lib.kitError.value" @new="newKit" @edit="editKit" @apply="applyKit" @retry="lib.load()"
                />
            </template>

            <ThemeApplyBar
                v-if="candidate && report && changes && lib.status.value === 'ready'" :theme="candidate" :report="report" :changes="changes" :same="sameTheme"
                :disabled="!canApply" :warnings="candidateWarnings" :edit-label="editLabel" @apply="apply" @preview="previewOpen = true" @cancel="cancel" @edit="editCandidate"
            />
            <ThemePreviewDialog v-model:open="previewOpen" :theme="candidate" :can-apply="canApply && !sameTheme" @apply="apply" />
        </template>

    </div>
</template>
