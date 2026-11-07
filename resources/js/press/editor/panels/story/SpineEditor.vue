<script setup>
// The argument spine: one claim per beat, each beat owning the slides that argue it. Edits (rename, drag to reorder,
// merge, split) are sent to the server, which answers with the operations; they are PREVIEWED in words and applied only
// on confirm, as one undo step.
import { computed, ref, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import { InlineNotice, ProgressBead, ReorderList, SelectMenu, EmptyState } from '../../../ui/controls';
import { describeError } from '../intel/api.js';
import { chunkOps, normalizeOps } from '../intel/opsText.js';
import { beatName, beatRows, evidenceWords, mergeTargets, previewEdit, reorderRequest, slideRange, splitOptions } from './spineModel.js';

const props = defineProps({ intel: { type: Object, required: true }, refreshKey: { type: Number, default: 0 } });
const emit = defineEmits(['applied']);
const editor = useEditorContext();
const { store, state } = editor;

const beats = ref(null); // server beats
const status = ref('loading');
const error = ref(null);
const busy = ref(false);
const pending = ref(null); // {edit, response, lines, rows}
const editing = ref(null); // {id, mode}
const draft = ref({ claim: '', into: null, at: null });
const doc = computed(() => state.value.doc);
const rows = computed(() => beatRows(beats.value, doc.value));
const canEdit = computed(() => !editor.readOnly && !!editor.can.update);
const shownRows = computed(() => (pending.value ? pending.value.rows : rows.value));
let applying = false;

async function load() {
    try {
        const r = await props.intel.spine();
        beats.value = r.beats || [];
        error.value = null;
        status.value = 'ready';
    } catch (e) {
        error.value = describeError(e);
        status.value = beats.value ? 'ready' : 'error';
    }
}
watch(() => props.refreshKey, load, { immediate: true });
watch(() => state.value.doc, () => {
    if (pending.value && !applying) {
        pending.value = null;
        editor.toast('The deck changed, so the spine preview was cleared. Make the edit again.', { kind: 'info' });
    }
});

function saved() { return ['saved', 'readonly'].includes(editor.saveInfo.value.key); }

async function propose(edit) {
    if (!canEdit.value) return;
    busy.value = true;
    error.value = null;
    try {
        await editor.settle();
        if (!saved()) {
            error.value = { kind: 'validation', message: 'Your latest changes are still saving. Try again in a moment, so the preview matches your deck.' };
            return;
        }
        const response = await props.intel.spineEdit(edit);
        const base = rows.value;
        const lines = previewEdit(edit, response, base, doc.value);
        const byId = new Map(base.map((r) => [r.id, r]));
        const draftRows = (response.beats || []).map((b) => {
            const old = byId.get(b.id);
            return {
                id: b.id, claim: b.claim || old?.claim || '', hollow: old?.hollow ?? false, evidence: old?.evidence || {}, sectionId: b.section_id ?? null,
                slides: (b.slides || []).map((id) => ({ id: String(id), no: doc.value.slides.findIndex((s) => s.id === String(id)) + 1 || null })),
            };
        });
        pending.value = { edit, response, lines, rows: draftRows };
        editing.value = null;
        editor.announce(`Preview ready. ${lines[0]}`);
    } catch (e) {
        error.value = describeError(e);
    } finally {
        busy.value = false;
    }
}

async function confirm() {
    const p = pending.value;
    if (!p) return;
    const ops = normalizeOps(p.response.ops);
    applying = true;
    let ok = true;
    try {
        store.transaction('Edit the argument spine', () => {
            for (const batch of chunkOps(ops)) {
                const r = store.dispatch(batch, { label: 'Edit the argument spine' });
                if (!r) ok = false;
            }
        });
    } finally {
        applying = false;
    }
    pending.value = null;
    if (!ok) editor.toast('Some of the changes no longer applied because the deck changed. Check the spine.', { kind: 'warn' });
    else editor.toast('Spine edit applied', { kind: 'ok', action: { label: 'Undo', run: () => store.undo() } });
    editor.announce('Spine edit applied');
    emit('applied');
    await editor.settle();
    load();
}
function cancel() { pending.value = null; editor.announce('Preview cancelled'); }

function onReorder({ from, to }) {
    const req = reorderRequest(rows.value, from, to);
    if (req) propose(req);
}
function open(id, mode) {
    const row = rows.value.find((r) => r.id === id);
    editing.value = editing.value?.id === id && editing.value.mode === mode ? null : { id, mode };
    draft.value = { claim: mode === 'rename' ? row.claim : '', into: null, at: null };
}
function submit() {
    const e = editing.value;
    if (!e) return;
    if (e.mode === 'rename') propose({ type: 'rename', beat: e.id, claim: draft.value.claim.trim() });
    if (e.mode === 'merge' && draft.value.into) propose({ type: 'merge', beat: e.id, into: draft.value.into });
    if (e.mode === 'split' && draft.value.at) propose({ type: 'split', beat: e.id, at: draft.value.at, ...(draft.value.claim.trim() ? { claim: draft.value.claim.trim() } : {}) });
}
const canSubmit = computed(() => {
    const e = editing.value;
    if (!e) return false;
    if (e.mode === 'rename') return draft.value.claim.trim().length > 0;
    if (e.mode === 'merge') return !!draft.value.into;
    return !!draft.value.at;
});
function jump(id) { store.setSlide(String(id)); }
</script>

<template>
    <div class="iq-spine">
        <ProgressBead v-if="status === 'loading'" :value="null" label="Reading the argument" />
        <InlineNotice v-else-if="status === 'error'" kind="error" title="The spine could not be read">
            {{ error?.message }} <button type="button" class="pr-btn pr-btn--sm iq-retry" @click="load">Try again</button>
        </InlineNotice>
        <template v-else>
            <InlineNotice v-if="error" kind="error">{{ error.message }}</InlineNotice>
            <EmptyState v-if="!rows.length" title="No claims yet." body="Add slides with titles and the spine fills in." compact />

            <div v-if="pending" class="iq-prop is-preview" role="region" aria-label="Preview of the spine edit">
                <div class="iq-prop-h"><h4 class="iq-prop-t">Preview. Nothing has changed yet.</h4></div>
                <ul class="iq-pm"><li v-for="(l, i) in pending.lines" :key="i">{{ l }}</li></ul>
                <div class="iq-prop-acts">
                    <button type="button" class="pr-btn pr-btn--primary pr-btn--sm" data-autofocus @click="confirm">Apply to the deck</button>
                    <button type="button" class="pr-btn pr-btn--sm" @click="cancel">Cancel</button>
                </div>
            </div>

            <ReorderList v-if="rows.length && !pending && canEdit" :items="rows" item-key="id" label="Argument spine" :handle-label="(r) => `Move the claim ${beatName(r)}`" @reorder="onReorder">
                <template #default="{ item: row }">
                    <div class="iq-sp-row" :data-beat="row.id">
                        <div class="iq-sp-claim">
                            <span class="pr-bead" :data-state="row.hollow ? 'hollow' : 'solid'" aria-hidden="true"></span>
                            <p class="iq-sp-text" :class="{ 'is-empty': !row.claim }">{{ row.claim || 'No claim yet' }}</p>
                        </div>
                        <p class="iq-beat-q"><span class="pr-state">{{ row.hollow ? 'hollow' : 'solid' }}</span> {{ evidenceWords(row) }}</p>
                        <div class="iq-chips"><button v-for="s in row.slides" :key="s.id" type="button" class="iq-slide-chip" :aria-label="`Go to slide ${s.no}`" @click="jump(s.id)">{{ s.no }}</button></div>
                        <div class="iq-sp-acts">
                            <button type="button" class="pr-btn pr-btn--ghost pr-btn--sm" :aria-expanded="editing?.id === row.id && editing.mode === 'rename'" :disabled="busy" @click="open(row.id, 'rename')">Rename</button>
                            <button type="button" class="pr-btn pr-btn--ghost pr-btn--sm" :aria-expanded="editing?.id === row.id && editing.mode === 'merge'" :disabled="busy || rows.length < 2" @click="open(row.id, 'merge')">Merge</button>
                            <button type="button" class="pr-btn pr-btn--ghost pr-btn--sm" :aria-expanded="editing?.id === row.id && editing.mode === 'split'" :disabled="busy || row.slides.length < 2" @click="open(row.id, 'split')">Split</button>
                        </div>
                        <form v-if="editing?.id === row.id" class="iq-form" @submit.prevent="submit">
                            <template v-if="editing.mode === 'rename'">
                                <label class="c-label" :for="`claim-${row.id}`">Claim, as one sentence</label>
                                <input :id="`claim-${row.id}`" v-model="draft.claim" class="iq-input" type="text" maxlength="120" data-autofocus autocomplete="off">
                            </template>
                            <template v-if="editing.mode === 'merge'">
                                <SelectMenu v-model="draft.into" label="Merge into" :options="mergeTargets(rows, row.id)" placeholder="Choose a beat" />
                                <p class="pr-small pr-muted">The slides of this beat join the end of the one you choose.</p>
                            </template>
                            <template v-if="editing.mode === 'split'">
                                <SelectMenu v-model="draft.at" label="Start a new beat at" :options="splitOptions(row, doc)" placeholder="Choose a slide" />
                                <label class="c-label" :for="`splitclaim-${row.id}`">Claim for the new beat (optional)</label>
                                <input :id="`splitclaim-${row.id}`" v-model="draft.claim" class="iq-input" type="text" maxlength="120" autocomplete="off">
                            </template>
                            <div class="iq-prop-acts">
                                <button type="submit" class="pr-btn pr-btn--primary pr-btn--sm" :disabled="!canSubmit || busy">Preview</button>
                                <button type="button" class="pr-btn pr-btn--sm" @click="editing = null">Cancel</button>
                            </div>
                        </form>
                    </div>
                </template>
            </ReorderList>
            <ol v-else-if="rows.length" class="iq-sp-plain" aria-label="Argument spine">
                <li v-for="row in shownRows" :key="row.id" class="iq-sp-plain-row">
                        <div class="iq-sp-claim">
                            <span class="pr-bead" :data-state="row.hollow ? 'hollow' : 'solid'" aria-hidden="true"></span>
                            <p class="iq-sp-text" :class="{ 'is-empty': !row.claim }">{{ row.claim || 'No claim yet' }}</p>
                        </div>
                        <p class="iq-beat-q"><span class="pr-state">{{ row.hollow ? 'hollow' : 'solid' }}</span> {{ evidenceWords(row) }}, {{ slideRange(row) }}</p>
                </li>
            </ol>
            <p v-if="!canEdit && rows.length" class="pr-small pr-muted">You can read the spine. Editing it needs edit access.</p>
        </template>
    </div>
</template>
