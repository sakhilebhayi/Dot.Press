<script setup>
// Import numbers into the chart from pasted text or a file (CSV, TSV, TXT, XLSX through POST /api/data/parse). The text or file
// is read, laid out as the chart would use it and shown as a preview with the role of every column; nothing changes until
// "Replace data" is pressed, and that is one undo step. The import is a snapshot: the numbers are copied, there is no live link.
import { computed, onBeforeUnmount, ref } from 'vue';
import { CHART_TYPES } from '../../../charts/index.js';
import InlineNotice from '../../../ui/controls/InlineNotice.vue';
import ProgressBead from '../../../ui/controls/ProgressBead.vue';
import Segmented from '../../../ui/controls/Segmented.vue';
import SelectMenu from '../../../ui/controls/SelectMenu.vue';
import Tabs from '../../../ui/controls/Tabs.vue';
import Toggle from '../../../ui/controls/Toggle.vue';
import Ico from '../../ui/Ico.vue';
import { useEditorContext } from '../../context.js';
import { applyImport, cellsFromApi, cellsFromText, fileSrc, mapImport } from './importMap.js';

const props = defineProps({ t: { type: Object, required: true } });
const emit = defineEmits(['notice', 'applied']);
const editor = useEditorContext();
const t = props.t;

const MAX_MB = 5;
const tab = ref('paste');
const text = ref('');
const cells = ref(null); // rows of number | string | null once something was read
const origin = ref(null); // { kind: 'paste' | 'file', name, meta }
const busy = ref(false);
const failure = ref(null); // { message, retry }
const file = ref(null);
const picker = ref(null);
const opts = ref({ orientation: 'columns', firstRowHeader: 'auto', firstColHeader: 'auto' });
let timer = null;

const typeLabel = computed(() => CHART_TYPES.find((x) => x.id === t.chart.value?.type)?.label || 'chart');
const mapping = computed(() => (cells.value ? mapImport(cells.value, { type: t.chart.value.type, ...opts.value }) : null));
const rowsRead = computed(() => cells.value?.length || 0);
const colsRead = computed(() => Math.max(0, ...(cells.value || []).map((r) => r.length)));
const sheetOptions = computed(() => (origin.value?.meta?.sheets || []).map((s) => ({ value: s.name, label: s.hidden ? `${s.name} (hidden)` : s.name })));
const ROLE = { category: 'Categories', series: 'Series', skipped: 'Not used' };

const tabs = [{ id: 'paste', label: 'Paste' }, { id: 'file', label: 'File' }];
const ORIENT = [{ value: 'columns', label: 'Series in columns' }, { value: 'rows', label: 'Series in rows' }];

function reset() {
    cells.value = null;
    origin.value = null;
    failure.value = null;
    file.value = null;
    opts.value = { orientation: 'columns', firstRowHeader: 'auto', firstColHeader: 'auto' };
    if (picker.value) picker.value.value = '';
}
function onText(e) {
    text.value = e.target.value;
    clearTimeout(timer);
    timer = setTimeout(() => {
        if (!text.value.trim()) { cells.value = null; origin.value = null; return; }
        cells.value = cellsFromText(text.value);
        origin.value = { kind: 'paste', name: 'pasted text', meta: null };
    }, 250);
}
onBeforeUnmount(() => clearTimeout(timer));

function describe(e) {
    if (e?.status === 403) return 'You are not allowed to import data into this deck.';
    if (e?.status === 413) return `The file is larger than ${MAX_MB} MB.`;
    if (e?.status === 429) return `Too many imports in a row. Try again in ${e.retryAfter || 60} seconds.`;
    if (e?.status === 422) return e.message || 'The file could not be read.';
    if (e?.network) return 'The network is not reachable.';
    return e?.message || 'The file could not be read.';
}
async function upload(f, sheet = null) {
    busy.value = true;
    failure.value = null;
    try {
        const form = new FormData();
        form.append('file', f);
        if (sheet) form.append('sheet', sheet);
        const res = await editor.api.post('/api/data/parse', form, { timeout: 60000 });
        cells.value = cellsFromApi(res.rows);
        origin.value = { kind: 'file', name: f.name, meta: { sheets: res.sheets || [], sheet: res.sheet || null, truncated: res.truncated || {}, format: res.format } };
        editor.announce?.(`Read ${res.row_count} rows from ${f.name}`);
    } catch (e) {
        failure.value = { message: describe(e), retry: e?.status === 422 || e?.status === 403 || e?.status === 413 ? null : () => upload(f, sheet) };
        cells.value = null;
        origin.value = null;
    } finally {
        busy.value = false;
    }
}
function onFile(e) {
    const f = e.target.files?.[0];
    if (!f) return;
    file.value = f;
    if (f.size > MAX_MB * 1048576) {
        failure.value = { message: `${f.name} is larger than ${MAX_MB} MB.`, retry: null };
        return;
    }
    if (!/\.(csv|tsv|txt|xlsx)$/i.test(f.name)) {
        failure.value = { message: 'Choose a CSV, TSV, TXT or XLSX file.', retry: null };
        return;
    }
    upload(f);
}
function onSheet(name) {
    if (file.value) upload(file.value, name);
}
function apply() {
    const m = mapping.value;
    if (!m?.ok || !t.canEdit.value) return;
    const src = origin.value?.kind === 'file' ? fileSrc(origin.value.name) : null;
    const next = applyImport(t.chart.value, m.data, src);
    t.commit(next, { key: `import:${Date.now()}`, label: 'Import data' });
    const what = m.stats.series ? `${m.stats.rows} ${m.stats.rows === 1 ? 'row' : 'rows'} and ${m.stats.series} series` : `${m.stats.rows} rows`;
    emit('notice', { kind: m.warnings.length ? 'warn' : 'ok', text: `Replaced the data with ${what}${src ? ` from ${src.name}` : ''}.`, undo: true, lines: m.warnings });
    emit('applied');
    reset();
    text.value = '';
}
const setHeader = (k, v) => { opts.value = { ...opts.value, [k]: v }; };
</script>

<template>
    <div class="cpi">
        <Tabs v-model="tab" :items="tabs" label="Import from" variant="pill" />
        <div v-if="tab === 'paste'" class="cpi-pane" role="tabpanel" aria-labelledby="tab-paste">
            <label class="c-label" for="cpi-text">Paste cells from a spreadsheet or CSV</label>
            <textarea
                id="cpi-text" class="pr-field cpi-text" :value="text" rows="5" spellcheck="false" :disabled="!t.canEdit.value" aria-describedby="cpi-text-h"
                placeholder="Month&#9;Sales&#9;Costs&#10;Jan&#9;120&#9;80" @input="onText"
            ></textarea>
            <p id="cpi-text-h" class="c-hint">Tabs, commas or semicolons separate the columns. The first row is read as headings when it is text above numbers.</p>
        </div>
        <div v-else class="cpi-pane" role="tabpanel" aria-labelledby="tab-file">
            <input ref="picker" type="file" class="pr-sr" tabindex="-1" aria-hidden="true" accept=".csv,.tsv,.txt,.xlsx,text/csv,text/tab-separated-values,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" @change="onFile" />
            <button type="button" class="pr-btn" :disabled="busy || !t.canEdit.value" @click="picker?.click()"><Ico name="upload" size="sm" />Choose a file</button>
            <p class="c-hint">CSV, TSV, TXT or XLSX, up to {{ MAX_MB }} MB. The file is read and not stored; the numbers are copied into the chart.</p>
            <ProgressBead v-if="busy" :value="null" label="Reading the file" />
            <SelectMenu v-if="sheetOptions.length > 1" :model-value="origin?.meta?.sheet" :options="sheetOptions" label="Sheet" @update:model-value="onSheet" />
        </div>

        <InlineNotice v-if="failure" kind="error" :title="'Could not import'">
            {{ failure.message }}
            <button v-if="failure.retry" type="button" class="pr-btn pr-btn--sm cpi-retry" @click="failure.retry()">Try again</button>
        </InlineNotice>

        <template v-if="mapping">
            <p class="cpi-read pr-state">Read {{ rowsRead }} {{ rowsRead === 1 ? 'row' : 'rows' }}, {{ colsRead }} {{ colsRead === 1 ? 'column' : 'columns' }}<template v-if="origin?.meta?.truncated?.rows || origin?.meta?.truncated?.cols"> (the file was cut to fit the limits)</template></p>
            <Segmented v-model="opts.orientation" :options="ORIENT" label="Layout" />
            <Toggle :model-value="mapping.guess.row" label="First row holds the headings" :hint="opts.firstRowHeader === 'auto' ? 'Detected from the data' : 'Set by you'" @update:model-value="(v) => setHeader('firstRowHeader', v)" />
            <Toggle :model-value="mapping.guess.col" label="First column holds the categories" :hint="opts.firstColHeader === 'auto' ? 'Detected from the data' : 'Set by you'" @update:model-value="(v) => setHeader('firstColHeader', v)" />

            <div v-if="mapping.preview" class="cpi-pv pr-scroll" tabindex="0" role="region" aria-label="Preview of the first rows">
                <table class="cpi-t">
                    <thead>
                        <tr><th v-for="(h, j) in mapping.preview.header" :key="j" scope="col">{{ h }}</th></tr>
                        <tr class="cpi-roles">
                            <td v-for="(r, j) in mapping.preview.roles" :key="j" :class="`is-${r.role}`"><span class="pr-state">{{ ROLE[r.role] }}</span><span class="cpi-ty">{{ r.type }}</span></td>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(row, i) in mapping.preview.rows" :key="i"><td v-for="(cell, j) in row" :key="j">{{ cell }}</td></tr>
                    </tbody>
                </table>
            </div>
            <p v-if="mapping.preview?.more || mapping.preview?.moreCols" class="c-hint">
                <template v-if="mapping.preview.more">and {{ mapping.preview.more }} more {{ mapping.preview.more === 1 ? 'row' : 'rows' }}</template>
                <template v-if="mapping.preview.more && mapping.preview.moreCols">, </template>
                <template v-if="mapping.preview.moreCols">{{ mapping.preview.moreCols }} more {{ mapping.preview.moreCols === 1 ? 'column' : 'columns' }}</template>
            </p>

            <InlineNotice v-if="!mapping.ok" kind="warn" title="Nothing to import yet">{{ mapping.why }}</InlineNotice>
            <template v-else>
                <ul v-if="mapping.warnings.length" class="cpi-warn">
                    <li v-for="w in mapping.warnings" :key="w"><Ico name="warning" size="sm" />{{ w }}</li>
                </ul>
                <p class="c-hint">
                    <template v-if="mapping.stats.series">{{ mapping.stats.rows }} {{ mapping.stats.rows === 1 ? 'row' : 'rows' }} and {{ mapping.stats.series }} series</template>
                    <template v-else>{{ mapping.stats.rows }} {{ mapping.stats.rows === 1 ? 'row' : 'rows' }}</template>
                    will replace the data in this {{ typeLabel.toLowerCase() }} chart. Formatting stays. You can undo it.
                </p>
            </template>
            <div class="cpi-act">
                <button type="button" class="pr-btn pr-btn--primary" :disabled="!mapping.ok || !t.canEdit.value" @click="apply">Replace data</button>
                <button type="button" class="pr-btn pr-btn--ghost" @click="reset(); text = ''">Clear</button>
            </div>
        </template>
    </div>
</template>

<style>
.cpi { display: grid; gap: 10px; min-width: 0; }
.cpi-pane { display: grid; gap: 8px; justify-items: start; }
.cpi-pane > * { max-width: 100%; }
.cpi-text { width: 100%; min-height: 96px; font-family: var(--f-mono); font-size: var(--t-xs); line-height: 1.5; white-space: pre; overflow: auto; }
.cpi-read { margin: 0; color: var(--muted); }
.cpi-retry { margin-top: 6px; }
.cpi-pv { overflow: auto; max-height: 220px; border: 1px solid var(--line-strong); border-radius: var(--r-2); background: var(--sheet); }
.cpi-t { border-collapse: collapse; font-size: var(--t-xs); width: max-content; min-width: 100%; }
.cpi-t th, .cpi-t td { padding: 4px 8px; border-bottom: 1px solid var(--line); border-right: 1px solid var(--line); text-align: left; white-space: nowrap; max-width: 140px; overflow: hidden; text-overflow: ellipsis; font-weight: 500; color: var(--ink); }
.cpi-t thead th { background: var(--raised); font-weight: 600; position: sticky; top: 0; }
.cpi-roles td { background: var(--raised); color: var(--muted); }
.cpi-roles td.is-skipped { background: repeating-linear-gradient(135deg, var(--raised) 0 5px, var(--sheet) 5px 10px); }
.cpi-roles .pr-state { display: block; color: var(--ink-2); }
.cpi-ty { display: block; font-family: var(--f-mono); font-weight: 500; }
.cpi-warn { margin: 0; padding: 0; list-style: none; display: grid; gap: 6px; font-size: var(--t-sm); color: var(--ink-2); }
.cpi-warn li { display: flex; gap: 8px; align-items: flex-start; line-height: 1.4; }
.cpi-warn .pr-ico { flex: none; margin-top: 1px; color: var(--warn); }
.cpi-act { display: flex; gap: 8px; flex-wrap: wrap; }
</style>
