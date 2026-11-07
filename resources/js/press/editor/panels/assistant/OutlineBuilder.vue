<script setup>
// "Generate slides from text": a brief and some source text become an outline you edit, and only then become slides.
import { computed, onMounted, reactive, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import { IconButton, InlineNotice, NumberField, ProgressBead, ReorderList, SelectMenu } from '../../../ui/controls';
import { newId } from '../../core/doc.js';
import { createIntel, describeError, frameworkOptions } from '../intel/api.js';
import { chunkOps } from '../intel/opsText.js';
import ModeChip from '../intel/ModeChip.vue';
import { bulletText, formError, hasBullets, moveRow, outlineRequest, outlineRows, outlineStats, removeRow, renameRow, replaceBeat, rowWords, setBullets, withHeaders } from './outlineModel.js';
import { composeInsert } from './compose.js';
import { degradedLine } from './assistantModel.js';

const props = defineProps({ intel: { type: Object, required: true } });
const editor = useEditorContext();
const { store, state } = editor;

const form = reactive({ brief: '', source: '', framework: 'auto', audience: '', length: 10 });
const frameworks = ref([]);
const status = ref('idle'); // idle | working | ready
const error = ref(null);
const showError = ref(false);
const resp = ref(null);
const rows = ref([]);
const editingKey = ref(null);
const regenerating = ref(null);
const lastRequest = ref(null);
const doc = computed(() => state.value.doc);
const canEdit = computed(() => !editor.readOnly && !!editor.can.update);
const aiOn = computed(() => editor.aiMode === 'ai');
const options = computed(() => frameworkOptions(frameworks.value, { auto: 'Detect from my text' }));
const headers = computed(() => new Map(withHeaders(rows.value).map((h) => [h.row.key, h.header])));
const stats = computed(() => outlineStats(rows.value));
const currentNo = computed(() => doc.value.slides.findIndex((s) => s.id === state.value.slideId) + 1);

onMounted(() => { props.intel.frameworks().then((l) => { frameworks.value = l; }).catch(() => {}); });

async function make() {
    showError.value = true;
    if (formError(form)) return;
    status.value = 'working';
    error.value = null;
    const req = outlineRequest(form);
    try {
        const r = await props.intel.outline(req);
        resp.value = r;
        rows.value = outlineRows(r);
        lastRequest.value = req;
        status.value = 'ready';
        editor.announce(`Outline ready with ${rows.value.length} slides`);
    } catch (e) {
        error.value = describeError(e);
        status.value = resp.value ? 'ready' : 'idle';
    }
}

async function regenerate(row) {
    regenerating.value = row.beatId;
    try {
        const r = await props.intel.outline({ ...lastRequest.value, framework: resp.value.framework.id });
        rows.value = replaceBeat(rows.value, row.beatId, r);
        editor.announce(`New version of ${row.beatLabel}`);
    } catch (e) {
        error.value = describeError(e);
    } finally {
        regenerating.value = null;
    }
}

function discard() { resp.value = null; rows.value = []; status.value = 'idle'; editingKey.value = null; }

async function insert() {
    if (!canEdit.value || !rows.value.length) return;
    let layouts = [];
    try { layouts = await props.intel.layouts(doc.value.deck.w, doc.value.deck.h); } catch { layouts = []; }
    const taken = doc.value.slides.map((s) => s.id);
    const ops = composeInsert(rows.value.map((r) => r.model), {
        deck: doc.value.deck, layouts, afterSlide: state.value.slideId,
        makeTid: () => { const t = newId(taken, 's'); taken.push(t); return t; },
    });
    const first = ops[0]?.tid;
    store.transaction('Insert generated slides', () => { for (const b of chunkOps(ops, 40)) store.dispatch(b, { label: 'Insert generated slides' }); });
    if (first && doc.value.slides.some((s) => s.id === first || true)) store.setSlide(first);
    const n = ops.length;
    editor.toast(`${n} ${n === 1 ? 'slide' : 'slides'} added after slide ${currentNo.value || 1}`, { kind: 'ok', action: { label: 'Undo', run: () => store.undo() } });
    editor.announce(`${n} slides added`);
    discard();
}
</script>

<template>
    <div class="iq-fields">
        <div class="iq-field">
            <label class="c-label" for="ob-brief">What is the deck for?</label>
            <input id="ob-brief" v-model="form.brief" class="iq-input" type="text" maxlength="500" placeholder="Investor pitch for SolarGrid" autocomplete="off">
        </div>
        <div class="iq-field">
            <label class="c-label" for="ob-source">Your notes or source text</label>
            <textarea id="ob-source" v-model="form.source" class="iq-textarea" rows="6" placeholder="Paste the text the slides should be built from. The outline only uses what is in it."></textarea>
        </div>
        <SelectMenu v-model="form.framework" label="Structure" :options="options" />
        <div class="c-grid2">
            <NumberField v-model="form.length" label="Slides" :min="5" :max="40" :step="1" />
            <div class="iq-field"><label class="c-label" for="ob-aud">Audience</label><input id="ob-aud" v-model="form.audience" class="iq-input" type="text" maxlength="200" autocomplete="off"></div>
        </div>
        <p v-if="showError && formError(form)" class="c-error" role="alert"><span class="pr-bead pr-bead--bad pr-bead--sm" aria-hidden="true"></span>{{ formError(form) }}</p>
        <p class="pr-small pr-muted">{{ aiOn ? 'A language model will write the outline from your text. It counts against your daily AI quota.' : 'Local rules build the outline from your own sentences and figures. They place and trim your words, and cannot write new ones.' }}</p>
        <div class="iq-prop-acts"><button type="button" class="pr-btn pr-btn--primary pr-btn--sm" :disabled="status === 'working'" @click="make">Make an outline</button></div>
        <ProgressBead v-if="status === 'working'" :value="null" label="Building the outline" />
        <InlineNotice v-if="error" kind="error" title="No outline yet">{{ error.message }}</InlineNotice>
    </div>

    <section v-if="resp" class="iq-ol" aria-label="Outline">
        <div class="iq-prop-h">
            <h4 class="iq-prop-t">Outline: {{ stats.slides }} {{ stats.slides === 1 ? 'slide' : 'slides' }}</h4>
            <ModeChip :mode="resp.mode" :degraded="resp.degraded" />
        </div>
        <InlineNotice v-if="resp.degraded" kind="warn" title="A language model was not used">{{ degradedLine(resp) }}</InlineNotice>
        <p class="iq-prop-s"><strong>{{ resp.framework.name }}.</strong> {{ resp.framework.reason }}</p>
        <p v-if="resp.notes_on_mode" class="pr-small pr-muted">{{ resp.notes_on_mode }}</p>
        <InlineNotice v-if="resp.unsupported_numbers?.length" kind="warn" title="Check these figures">These figures are not in your source: {{ resp.unsupported_numbers.join(', ') }}.</InlineNotice>
        <ul v-if="resp.warnings?.length" class="iq-warn-list"><li v-for="w in resp.warnings" :key="w">{{ w }}</li></ul>

        <ReorderList v-model:items="rows" item-key="key" label="Outline slides" :handle-label="(r) => `Move the slide ${r.model.title}`">
            <template #default="{ item: row }">
                <div class="iq-sp-row">
                    <p v-if="headers.get(row.key)" class="iq-ol-beat">{{ headers.get(row.key) }}</p>
                    <label class="pr-sr" :for="`ol-${row.key}`">Slide title</label>
                    <input :id="`ol-${row.key}`" class="iq-input" type="text" maxlength="200" :value="row.model.title" @input="rows = renameRow(rows, row.key, $event.target.value)">
                    <p class="iq-beat-q"><span class="pr-state">{{ row.model.kind }}</span> {{ rowWords(row) || 'no body text' }}<template v-if="row.model.needs?.length"> <span class="pr-state">needs your evidence</span></template><template v-if="row.model.unsupported_numbers?.length"> <span class="pr-state">check {{ row.model.unsupported_numbers.join(', ') }}</span></template></p>
                    <div v-if="editingKey === row.key" class="iq-form">
                        <label class="c-label" :for="`olb-${row.key}`">Points, one per line</label>
                        <textarea :id="`olb-${row.key}`" class="iq-textarea" rows="5" :value="bulletText(row)" @input="rows = setBullets(rows, row.key, $event.target.value)"></textarea>
                    </div>
                </div>
            </template>
            <template #actions="{ item: row }">
                <IconButton v-if="hasBullets(row)" icon="pen" :label="editingKey === row.key ? 'Done editing the points' : `Edit the points of ${row.model.title}`" size="sm" :pressed="editingKey === row.key" @click="editingKey = editingKey === row.key ? null : row.key" />
                <IconButton v-if="aiOn" icon="restore" :label="`Write another version of ${row.beatLabel}`" size="sm" :disabled="regenerating === row.beatId" @click="regenerate(row)" />
                <IconButton icon="trash" :label="`Delete the slide ${row.model.title}`" size="sm" danger @click="rows = removeRow(rows, row.key)" />
            </template>
        </ReorderList>
        <p v-if="!rows.length" class="pr-small pr-muted">You deleted every slide. Make the outline again.</p>

        <div class="iq-prop-acts">
            <button type="button" class="pr-btn pr-btn--primary pr-btn--sm" :disabled="!canEdit || !rows.length" :title="canEdit ? '' : 'You can read this deck, not change it'" @click="insert">Insert {{ rows.length }} {{ rows.length === 1 ? 'slide' : 'slides' }} after slide {{ currentNo }}</button>
            <button type="button" class="pr-btn pr-btn--sm" @click="discard">Discard</button>
        </div>
        <p class="pr-small pr-muted">Slides use your deck's theme and layouts. {{ resp.mode === 'ai' ? 'They are marked "AI draft" until you mark them reviewed.' : 'Slides that need evidence get a visible prompt where it goes.' }}</p>
    </section>
</template>
