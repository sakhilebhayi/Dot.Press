<script setup>
// Everything for editing the numbers of one chart: the grid (or the key figure list), the toolbar, the series picker for
// point charts, the country picker for maps, and the imported-from chip. Used inline in the panel and in the large dialog.
import { computed, nextTick, ref, watch } from 'vue';
import { MAP_CODES, countryName } from '../../../charts/map.js';
import IconButton from '../../../ui/controls/IconButton.vue';
import Field from '../../../ui/controls/Field.vue';
import SelectMenu from '../../../ui/controls/SelectMenu.vue';
import Ico from '../../ui/Ico.vue';
import { useEditorContext } from '../../context.js';
import DataGrid from './DataGrid.vue';
import KpiEditor from './KpiEditor.vue';
import {
    addRow, addSeries, canSwap, dataSummary, gridView, moveKpi, pasteGrid, removeColumn, removeRow, removeSeries, renameSeries, setCell, setHeader, setSeriesKind,
    swapOrientation, toggleTotal,
} from './grid.js';
import { importedLabel } from './importMap.js';
import { typeNotes } from './convert.js';

const props = defineProps({
    t: { type: Object, required: true }, // useChartTarget
    dense: { type: Boolean, default: true },
    epoch: { type: Number, default: 0 }, // bumped when the data was replaced from outside (an import): drafts are dropped
});
const emit = defineEmits(['notice']);
const editor = useEditorContext();
const t = props.t;

const grid = ref(null);
const errors = ref({});
const seriesIndex = ref(0);
const activeRow = ref(null);

const chart = computed(() => t.chart.value);
const mode = computed(() => view.value.mode);
const ctx = computed(() => ({ series: seriesIndex.value }));
const view = computed(() => gridView(chart.value, ctx.value));
const notes = computed(() => typeNotes(chart.value));
const imported = computed(() => importedLabel(chart.value?.src));
const swap = computed(() => canSwap(chart.value));
const countries = computed(() => [...MAP_CODES].map((c) => ({ value: c, label: countryName(c), hint: c })).sort((a, b) => a.label.localeCompare(b.label)));
const seriesOptions = computed(() => (view.value.series || []).map((s, i) => ({ value: i, label: s.name || `Series ${i + 1}` })));
const rowWord = computed(() => ({ cat: 'row', points: 'point', kpi: 'figure', map: 'country' })[mode.value]);
const atRowLimit = computed(() => view.value.rowCount >= view.value.rowLimit);
const multiSeries = computed(() => !['waterfall', 'pie', 'doughnut', 'funnel'].includes(chart.value?.type) || (chart.value?.data?.series?.length || 0) > 1);
const atColLimit = computed(() => mode.value === 'cat' && !view.value.canAddColumn);

watch(() => props.epoch, () => { errors.value = {}; });
watch(() => t.el.value?.id, () => { errors.value = {}; seriesIndex.value = 0; activeRow.value = null; });
watch(() => chart.value?.type, () => { errors.value = {}; seriesIndex.value = 0; });
watch(() => view.value.series?.length, (n) => { if (n !== undefined && seriesIndex.value >= n) seriesIndex.value = Math.max(0, n - 1); });

function put(next, key, label) {
    return t.commit(next, { key, label });
}
function announce(text) { editor.announce?.(text); }

function onCell(r, c, text) {
    const res = setCell(chart.value, ctx.value, r, c, text);
    const k = `${r}:${c}`;
    const next = { ...errors.value };
    if (res.error) next[k] = { text, message: res.error }; else delete next[k];
    errors.value = next;
    if (res.changed) put(res.chart, `cell:${r}:${c}:${seriesIndex.value}`, 'Edit cell');
    if (res.error) announce(res.error);
}
function onHeader(c, text) { put(setHeader(chart.value, ctx.value, c, text), `header:${c}`, 'Rename series'); }
function onPaste(r, c, text) {
    const res = pasteGrid(chart.value, ctx.value, r, c, text);
    const next = {};
    for (const e of res.errors) next[`${e.r}:${e.c}`] = { text: e.text, message: e.message };
    errors.value = next;
    if (res.cells) put(res.chart, `paste:${Date.now()}`, 'Paste cells');
    const parts = [`Pasted ${res.cells} ${res.cells === 1 ? 'cell' : 'cells'}`];
    if (res.errors.length) parts.push(`${res.errors.length} could not be read`);
    if (res.clipped.rows) parts.push(`${res.clipped.rows} rows did not fit`);
    if (res.clipped.cols) parts.push(`${res.clipped.cols} columns did not fit`);
    const kind = res.errors.length || res.clipped.rows || res.clipped.cols ? 'warn' : 'ok';
    emit('notice', { kind, text: `${parts.join('. ')}.`, undo: res.cells > 0 });
    announce(parts.join('. '));
}
async function add(extra = {}) {
    const res = addRow(chart.value, ctx.value, activeRow.value, extra);
    if (!res.ok) { editor.toast?.(res.reason, { kind: 'warn' }); return; }
    errors.value = {};
    put(res.chart, `rows:${Date.now()}`, `Add ${rowWord.value}`);
    activeRow.value = res.index;
    announce(`Added ${rowWord.value} ${res.index + 1}`);
    await nextTick();
    if (mode.value === 'map') grid.value?.focusCell(res.index, 2); else grid.value?.focusCell(res.index, 0);
}
function onRemoveRow(r) {
    const res = removeRow(chart.value, ctx.value, r);
    if (!res.ok) { editor.toast?.(res.reason, { kind: 'warn' }); return; }
    errors.value = {};
    put(res.chart, `rows:${Date.now()}`, `Delete ${rowWord.value}`);
    announce(`Deleted ${rowWord.value} ${r + 1}`);
}
async function addCol() {
    const res = addSeries(chart.value);
    if (!res.ok) { editor.toast?.(res.reason, { kind: 'warn' }); return; }
    errors.value = {};
    put(res.chart, `cols:${Date.now()}`, 'Add series');
    if (mode.value === 'points') seriesIndex.value = res.index;
    announce(`Added series ${res.index + 1}`);
}
function onRemoveCol(c) {
    const res = removeColumn(chart.value, c);
    if (!res.ok) { editor.toast?.(res.reason, { kind: 'warn' }); return; }
    errors.value = {};
    put(res.chart, `cols:${Date.now()}`, 'Delete series');
    announce('Deleted a series');
}
function onRemoveSeries() {
    const res = removeSeries(chart.value, seriesIndex.value);
    if (!res.ok) { editor.toast?.(res.reason, { kind: 'warn' }); return; }
    errors.value = {};
    put(res.chart, `cols:${Date.now()}`, 'Delete series');
}
function onSwap() {
    const res = swapOrientation(chart.value);
    if (!res.ok) { editor.toast?.(res.reason, { kind: 'warn' }); return; }
    errors.value = {};
    put(res.chart, `swap:${Date.now()}`, 'Swap rows and columns');
    emit('notice', { kind: res.dropped.length ? 'warn' : 'ok', text: 'Rows and columns swapped.', dropped: res.dropped, undo: true });
    announce('Rows and columns swapped');
}
function onMove(from, to) { put(moveKpi(chart.value, from, to), `kpi-move:${Date.now()}`, 'Move figure'); }
function onTotal(r) { put(toggleTotal(chart.value, r), `total:${r}`, 'Mark total'); }
function onKind(i, kind) { put(setSeriesKind(chart.value, i, kind), `kind:${i}`, 'Bar or line'); }
function onRenameSeries(e) {
    put(renameSeries(chart.value, seriesIndex.value, e.target.value), `header:${seriesIndex.value}`, 'Rename series');
}
async function copyTsv() {
    const v = view.value;
    const head = v.columns.map((c) => c.label);
    const text = [head, ...v.rows].map((row) => row.join('\t')).join('\n');
    try {
        await navigator.clipboard.writeText(text);
        editor.toast?.(`Copied ${v.rowCount} ${v.rowCount === 1 ? 'row' : 'rows'} as tab separated text`, { kind: 'ok' });
    } catch {
        editor.toast?.('The browser did not allow copying. Select cells and copy them instead.', { kind: 'warn' });
    }
}
const swapTitle = computed(() => (swap.value.ok ? 'Categories become series and series become categories' : swap.value.reason));
</script>

<template>
    <div class="cpd">
        <div v-if="imported" class="cpd-src">
            <span class="pr-chip" :title="imported"><Ico name="document" size="sm" />{{ imported }}</span>
            <p class="c-hint">This is a snapshot of the file, not a live link. Edit the numbers here or import the file again.</p>
        </div>
        <p v-for="n in notes" :key="n" class="c-hint cpd-note">{{ n }}</p>

        <div v-if="mode === 'points'" class="cpd-series">
            <SelectMenu :model-value="seriesIndex" :options="seriesOptions" label="Series" @update:model-value="(v) => { seriesIndex = v; errors = {}; }" />
            <IconButton icon="plus" label="Add series" :disabled="!t.canEdit.value || atColLimit" @click="addCol" />
            <IconButton icon="trash" label="Delete this series" danger :disabled="!t.canEdit.value || seriesOptions.length < 2" @click="onRemoveSeries" />
            <Field v-if="seriesOptions.length" label="Series name" class="cpd-sname" v-slot="{ id }">
                <input :id="id" class="pr-field" :value="view.series[seriesIndex]?.name" :disabled="!t.canEdit.value" maxlength="80" autocomplete="off" @change="onRenameSeries" />
            </Field>
        </div>

        <KpiEditor v-if="mode === 'kpi'" :view="view" :can-edit="t.canEdit.value" @cell="onCell" @remove-row="onRemoveRow" @move="onMove" />
        <DataGrid
            v-else-if="view.rowCount || mode !== 'map'" ref="grid" :view="view" :errors="errors" :can-edit="t.canEdit.value" :dense="dense" :totals="chart.type === 'waterfall' ? view.totals : null" :kinds="chart.type === 'combo'"
            :label="`Data of the ${chart.type} chart`" @cell="onCell" @header="onHeader" @paste="onPaste" @remove-row="onRemoveRow" @remove-column="onRemoveCol" @kind="onKind" @total="onTotal" @active="(r) => (activeRow = r)"
        />
        <p v-else class="c-hint">No countries yet. Pick one below, or paste a country and a value per line.</p>

        <div class="cpd-bar">
            <template v-if="mode === 'map'">
                <SelectMenu
                    :model-value="null" :options="countries.filter((c) => !(chart.data.regions || []).some((g) => g.code === c.value))" searchable label="Add a country" hide-label placeholder="Add a country"
                    :disabled="!t.canEdit.value || atRowLimit" @update:model-value="(v) => add({ code: v })"
                />
            </template>
            <template v-else>
                <button type="button" class="pr-btn pr-btn--sm" :disabled="!t.canEdit.value || atRowLimit" :title="atRowLimit ? `A chart holds ${view.rowLimit} rows at most` : undefined" @click="add()"><Ico name="plus" size="sm" />Add {{ rowWord }}</button>
                <button v-if="mode === 'cat' && multiSeries" type="button" class="pr-btn pr-btn--sm" :disabled="!t.canEdit.value || atColLimit" :title="atColLimit ? 'A chart holds 40 series at most' : undefined" @click="addCol"><Ico name="plus" size="sm" />Add series</button>
                <button v-if="mode === 'cat'" type="button" class="pr-btn pr-btn--sm" :disabled="!t.canEdit.value || !swap.ok" :title="swapTitle" @click="onSwap"><Ico name="refresh" size="sm" />Swap rows and columns</button>
            </template>
            <button v-if="mode !== 'kpi'" type="button" class="pr-btn pr-btn--sm pr-btn--ghost" :title="'Copy the table as tab separated text'" @click="copyTsv"><Ico name="duplicate" size="sm" />Copy</button>
        </div>
        <p v-if="mode === 'kpi'" class="cpd-bar2"><button type="button" class="pr-btn pr-btn--sm" :disabled="!t.canEdit.value || atRowLimit" @click="add()"><Ico name="plus" size="sm" />Add figure</button></p>
        <p class="cpd-count pr-state">{{ dataSummary(chart, ctx) }}<template v-if="mode === 'cat'"> (up to {{ view.rowLimit }} rows and {{ view.colLimit }} series)</template></p>
        <p v-if="mode === 'cat' || mode === 'points'" class="c-hint">Paste cells from a spreadsheet into the grid. To read headings from pasted text or a file, use Import.</p>
    </div>
</template>

<style>
.cpd { display: grid; gap: 10px; min-width: 0; }
.cpd-src { display: grid; gap: 4px; justify-items: start; }
.cpd-src .pr-chip { max-width: 100%; overflow: hidden; text-overflow: ellipsis; }
.cpd-note { margin: 0; padding-left: 10px; border-left: 2px solid var(--line-strong); }
.cpd-series { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; gap: 6px; align-items: end; }
.cpd-sname { grid-column: 1 / -1; }
.cpd-bar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.cpd-bar .c-field { flex: 1 1 100%; }
.cpd-bar2 { margin: 0; }
.cpd-count { margin: 0; color: var(--muted); }
</style>
