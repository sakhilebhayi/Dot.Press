<script setup>
// Inspector panel for tables: structure, header and banding, lines, type, per-cell formatting for the selected cells, column
// widths, spreadsheet import, and a chart made from the table. Cell selection comes from the stage (ui.tableSel, set while a
// table is being edited by double click). Table level settings work on several tables at once; the rest needs exactly one.
import { computed, ref, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import {
    PanelSection, NumberField, Segmented, Toggle, ColorField, FontPicker, SelectMenu, Field, InlineNotice, ProgressBead, usePanelChange, MIXED,
} from '../../../ui/controls/index.js';
import Ico from '../../ui/Ico.vue';
import { gridSize, expandRange } from '../../stage/lib/table.js';
import { newChart } from '../../core/doc.js';
import { columnFractions } from '../../../render/table.js';
import { apiProblem } from '../image/lib/library.js';
import ColumnBar from './ColumnBar.vue';
import {
    targetCells, readCells, cellsPatch, structurePatch, countPatch, setWidth, evenColumns, fractionsText, MAX_ROWS, MAX_COLS,
} from './lib/cells.js';
import {
    textToGrid, fileRowsToGrid, importPatch, tableChart, MAX_IMPORT_ROWS, MAX_IMPORT_COLS,
} from './lib/import.js';
import '../image/media.css';

const editor = useEditorContext();
const pc = usePanelChange(editor);
const els = pc.elements;
const single = computed(() => (els.value.length === 1 ? els.value[0] : null));
const canEdit = pc.canEdit;

// ---- selection from the stage --------------------------------------------------------------------------------
const sel = computed(() => {
    const s = editor.ui.tableSel;
    return s && single.value && s.id === single.value.id ? { r1: s.r1, c1: s.c1, r2: s.r2, c2: s.c2 } : null;
});
const selWord = computed(() => {
    if (!sel.value || !single.value) return '';
    const x = expandRange(single.value, sel.value);
    const a = `row ${x.r1 + 1}, column ${x.c1 + 1}`;
    return x.r1 === x.r2 && x.c1 === x.c2 ? a : `${a} to row ${x.r2 + 1}, column ${x.c2 + 1}`;
});
const scope = ref('all');
watch(sel, (s, old) => { if (s && !old) scope.value = 'selection'; else if (!s && scope.value === 'selection') scope.value = 'all'; });
const scopeOptions = computed(() => [
    ...(sel.value ? [{ value: 'selection', label: 'Selected' }] : []),
    { value: 'header', label: 'Row 1' },
    { value: 'firstCol', label: 'Column 1' },
    { value: 'all', label: 'All' },
]);
const cells = computed(() => {
    void editor.state.value;
    return single.value ? targetCells(single.value, scope.value, sel.value) : [];
});
const cv = (key) => (single.value ? readCells(single.value, cells.value, key) : undefined);
function setCells(props, label) {
    const e = single.value;
    if (!e) return;
    const p = cellsPatch(e, cells.value, props);
    if (p) editor.store.patch([{ id: e.id, p }], { label, undoKey: `panel:cells:${Object.keys(props).join('+')}:${e.id}` });
}
const alignOptions = [{ value: 'l', label: 'Left' }, { value: 'c', label: 'Centre' }, { value: 'r', label: 'Right' }];
const cellsWord = computed(() => `${cells.value.length} ${cells.value.length === 1 ? 'cell' : 'cells'}`);

// ---- structure -----------------------------------------------------------------------------------------------
const size = computed(() => { void editor.state.value; return single.value ? gridSize(single.value) : { rows: 0, cols: 0 }; });
function patchTable(p, label, announce) {
    const e = single.value;
    if (!e || !p) return false;
    editor.store.patch([{ id: e.id, p }], { label });
    if (announce) editor.announce(announce);
    return true;
}
function structure(op, label, said) {
    const e = single.value;
    if (!e) return;
    const p = structurePatch(e, op, sel.value);
    if (!patchTable(p, label, said)) editor.toast(op.startsWith('del') ? 'A table needs at least one row and one column.' : 'The table is as large as it can get.', { kind: 'warn' });
}
const where = computed(() => (sel.value ? 'at the selected cell' : 'at the end'));
function setCount(which, n) {
    const e = single.value;
    if (!e || !Number.isFinite(+n)) return;
    patchTable(countPatch(e, which, n), which === 'rows' ? 'Set row count' : 'Set column count');
}

// ---- column widths ---------------------------------------------------------------------------------------------
const colw = computed(() => {
    void editor.state.value;
    return single.value ? columnFractions(single.value.colw, size.value.cols) : [];
});
function setWidths(w) {
    const e = single.value;
    if (e) editor.store.patch([{ id: e.id, p: { colw: w } }], { label: 'Column widths', undoKey: `panel:colw:${e.id}` });
}
const widthIndex = ref(0);
watch(() => size.value.cols, (n) => { if (widthIndex.value >= n) widthIndex.value = Math.max(0, n - 1); });
const widthOptions = computed(() => colw.value.map((_, i) => ({ value: i, label: `Column ${i + 1}` })));

// ---- import ----------------------------------------------------------------------------------------------------
const importMode = ref('paste');
const pasted = ref('');
const header = ref(true);
const file = ref(null);
const sheet = ref(null);
const parsing = ref(false);
const fileResult = ref(null); // {name, sheets, grid, clipped}
const importError = ref('');
const fileInput = ref(null);
const pasteGrid = computed(() => textToGrid(pasted.value));
const previewGrid = computed(() => (importMode.value === 'paste' ? pasteGrid.value.grid : fileResult.value?.grid || []));
const clipped = computed(() => (importMode.value === 'paste' ? pasteGrid.value.clipped : !!fileResult.value?.clipped));
const dims = computed(() => (previewGrid.value.length ? `${previewGrid.value.length} rows, ${previewGrid.value[0].length} columns` : ''));
async function parseFile(f, sheetName) {
    parsing.value = true;
    importError.value = '';
    try {
        const form = new FormData();
        form.append('file', f);
        if (sheetName) form.append('sheet', sheetName);
        const res = await editor.api.post('/api/data/parse', form);
        const g = fileRowsToGrid(res.rows);
        fileResult.value = { name: f.name, sheets: (res.sheets || []).filter((s) => !s.hidden), grid: g.grid, clipped: g.clipped || !!res.truncated?.rows || !!res.truncated?.cols };
        sheet.value = res.sheet || null;
        if (typeof res.header === 'boolean') header.value = res.header;
        if (!g.grid.length) importError.value = 'That file has no data in it.';
    } catch (err) {
        fileResult.value = null;
        importError.value = err?.status === 422 ? err.message : apiProblem(err).message;
    } finally {
        parsing.value = false;
    }
}
function onFile(e) {
    const f = e.target.files?.[0];
    e.target.value = '';
    if (!f) return;
    file.value = f;
    sheet.value = null;
    parseFile(f, null);
}
function chooseSheet(name) {
    if (!file.value || name === sheet.value) return;
    sheet.value = name;
    parseFile(file.value, name);
}
const sheetOptions = computed(() => (fileResult.value?.sheets || []).map((s) => ({ value: s.name, label: s.name })));
function replaceRows() {
    const e = single.value;
    const grid = previewGrid.value;
    const p = e ? importPatch(e, grid, { header: header.value }) : null;
    if (!p) return;
    patchTable(p, 'Import table data', `Table now has ${grid.length} rows and ${grid[0].length} columns`);
    editor.toast(`Imported ${grid.length} rows, ${grid[0].length} columns`, { kind: 'ok', action: { label: 'Undo', run: () => editor.store.undo() } });
    pasted.value = '';
    fileResult.value = null;
    file.value = null;
}

// ---- chart ---------------------------------------------------------------------------------------------------------
const chartPlan = computed(() => { void editor.state.value; return single.value ? tableChart(single.value) : { ok: false, message: '' }; });
function makeChart() {
    const e = single.value;
    const slide = editor.slide.value;
    if (!e || !slide || !chartPlan.value.ok) return;
    const deck = editor.deck.value;
    const base = newChart(deck, { chartType: chartPlan.value.type, data: chartPlan.value.data });
    const below = e.y + e.h + deck.h * 0.03;
    const room = deck.h - below - deck.h * 0.03;
    const pos = room >= deck.h * 0.3 ? { x: e.x, y: Math.round(below * 100) / 100, w: Math.min(e.w, base.w), h: Math.round(Math.min(base.h, room) * 100) / 100 } : {};
    const el = { ...base, ...pos };
    const r = editor.store.dispatch([{ op: 'el.add', slide: slide.id, el }], { label: 'Chart from table' });
    if (!r) return;
    editor.store.select([el.id]);
    editor.announce(`Added a ${chartPlan.value.type} chart from the table`);
    if (editor.hasPanel('chart')) editor.openPanel('chart');
    else editor.toast('Chart added next to the table.', { kind: 'ok' });
}
</script>

<template>
    <div class="pm-panel" data-panel-root="table">
        <PanelSection id="table.structure" :title="single ? 'Table' : `${els.length} tables`" :state="single ? `${size.rows} x ${size.cols}` : ''">
            <template v-if="single">
                <div class="c-grid2">
                    <NumberField label="Rows" :min="1" :max="MAX_ROWS" :model-value="size.rows" :disabled="!canEdit" @update:model-value="(v) => setCount('rows', v)" />
                    <NumberField label="Columns" :min="1" :max="MAX_COLS" :model-value="size.cols" :disabled="!canEdit" @update:model-value="(v) => setCount('cols', v)" />
                </div>
                <p class="c-hint" style="margin-top: -4px">{{ sel ? `Selected: ${selWord}.` : 'Double click the table, then click or drag cells to select them.' }} Buttons act {{ where }}.</p>
                <div class="pm-bar-row">
                    <button type="button" class="pr-btn pr-btn--sm" :disabled="!canEdit" @click="structure('rowAbove', 'Add row', 'Row added above')"><Ico name="plus" size="sm" />Row above</button>
                    <button type="button" class="pr-btn pr-btn--sm" :disabled="!canEdit" @click="structure('rowBelow', 'Add row', 'Row added below')"><Ico name="plus" size="sm" />Row below</button>
                    <button type="button" class="pr-btn pr-btn--sm" :disabled="!canEdit" @click="structure('colLeft', 'Add column', 'Column added on the left')"><Ico name="plus" size="sm" />Column left</button>
                    <button type="button" class="pr-btn pr-btn--sm" :disabled="!canEdit" @click="structure('colRight', 'Add column', 'Column added on the right')"><Ico name="plus" size="sm" />Column right</button>
                    <button type="button" class="pr-btn pr-btn--sm pr-btn--danger" :disabled="!canEdit || size.rows < 2" @click="structure('delRow', 'Delete row', 'Row deleted')"><Ico name="trash" size="sm" />Delete row</button>
                    <button type="button" class="pr-btn pr-btn--sm pr-btn--danger" :disabled="!canEdit || size.cols < 2" @click="structure('delCol', 'Delete column', 'Column deleted')"><Ico name="trash" size="sm" />Delete column</button>
                </div>
            </template>
            <p v-else class="c-hint">Pick one table to add or remove rows and columns, format cells, or import data.</p>
        </PanelSection>

        <PanelSection id="table.style" title="Style">
            <div class="c-stack">
                <Toggle label="Header row" hint="The first row is bold and has a heavier line below it." :model-value="pc.value('header', { fallback: false })" :disabled="!canEdit" @update:model-value="(v) => pc.set('header', v, { label: 'Header row' })" />
                <Toggle label="Banded rows" :model-value="pc.value('band', { fallback: false })" :disabled="!canEdit" @update:model-value="(v) => pc.set('band', v, { label: 'Banded rows' })" />
                <Segmented label="Lines" :model-value="pc.value('lines', { fallback: 'rows' })" :options="[{ value: 'none', label: 'None' }, { value: 'rows', label: 'Rows' }, { value: 'grid', label: 'Grid' }]" :disabled="!canEdit" @update:model-value="(v) => pc.set('lines', v, { label: 'Table lines' })" />
                <FontPicker label="Font" :model-value="pc.value('font', { fallback: '@body' })" :disabled="!canEdit" @update:model-value="(v) => pc.set('font', v, { label: 'Table font' })" />
                <NumberField label="Text size" :min="6" :max="200" :model-value="pc.value('size', { fallback: 28 })" :disabled="!canEdit" @update:model-value="(v) => pc.set('size', v, { label: 'Table text size' })" />
            </div>
        </PanelSection>

        <PanelSection v-if="single" id="table.cells" title="Cells" :state="cellsWord">
            <div class="c-stack">
                <Segmented label="Format" :model-value="scope" :options="scopeOptions" :hint="sel ? `Selected: ${selWord}.` : 'Choose a part of the table, or select cells on the slide first.'" :disabled="!canEdit" @update:model-value="(v) => (scope = v)" />
                <div class="pm-bar-row" role="group" aria-label="Text style">
                    <button type="button" class="pr-btn pr-btn--sm" :aria-pressed="cv('b') === true" :disabled="!canEdit" @click="setCells({ b: cv('b') !== true }, 'Bold')"><strong>Bold</strong></button>
                    <button type="button" class="pr-btn pr-btn--sm" :aria-pressed="cv('i') === true" :disabled="!canEdit" @click="setCells({ i: cv('i') !== true }, 'Italic')"><em>Italic</em></button>
                </div>
                <Segmented label="Align" :model-value="cv('al') === null || cv('al') === undefined ? '' : cv('al')" :options="alignOptions" :disabled="!canEdit" @update:model-value="(v) => setCells({ al: v }, 'Align cells')" />
                <ColorField label="Fill" allow-none :model-value="cv('fill') === undefined ? null : cv('fill')" :disabled="!canEdit" @update:model-value="(v) => setCells({ fill: v }, 'Cell fill')" />
                <ColorField label="Text colour" allow-none :model-value="cv('color') === undefined ? null : cv('color')" :disabled="!canEdit" @update:model-value="(v) => setCells({ color: v }, 'Cell text colour')" />
            </div>
        </PanelSection>

        <PanelSection v-if="single" id="table.cols" title="Column widths" :state="size.cols > 1 ? '' : 'one'">
            <template v-if="size.cols > 1">
                <ColumnBar :colw="colw" :disabled="!canEdit" @update:colw="setWidths" />
                <p class="c-hint pr-state" style="margin: 0">{{ fractionsText(colw) }}</p>
                <div class="c-grid2">
                    <SelectMenu v-model="widthIndex" label="Column" :options="widthOptions" :disabled="!canEdit" />
                    <NumberField label="Width" unit="%" :min="4" :max="90" :model-value="Math.round((colw[widthIndex] || 0) * 100)" :disabled="!canEdit" @update:model-value="(v) => setWidths(setWidth(colw, widthIndex, v / 100))" />
                </div>
                <button type="button" class="pr-btn pr-btn--sm" :disabled="!canEdit" @click="setWidths(evenColumns(size.cols))">Make columns equal</button>
            </template>
            <p v-else class="c-hint">A table with one column fills its width. Add a column to set widths.</p>
        </PanelSection>

        <PanelSection v-if="single" id="table.import" title="Import data" :default-open="false">
            <Segmented v-model="importMode" label="From" :options="[{ value: 'paste', label: 'Pasted text' }, { value: 'file', label: 'File' }]" :disabled="!canEdit" />
            <template v-if="importMode === 'paste'">
                <Field v-slot="{ id, describedby }" label="Paste from a spreadsheet" hint="Copy cells in Excel, Sheets or Numbers and paste here. Commas, tabs and semicolons all work.">
                    <textarea :id="id" v-model="pasted" class="pr-field pm-paste" :aria-describedby="describedby" placeholder="Region	Sales&#10;North	120&#10;South	98" spellcheck="false" :disabled="!canEdit"></textarea>
                </Field>
            </template>
            <template v-else>
                <div class="pm-actions">
                    <button type="button" class="pr-btn" :disabled="!canEdit || parsing" @click="fileInput.click()"><Ico name="upload" size="sm" />{{ fileResult ? 'Choose another file' : 'Choose a CSV, TSV or XLSX file' }}</button>
                    <input ref="fileInput" type="file" class="pr-sr" tabindex="-1" accept=".csv,.tsv,.txt,.xlsx" @change="onFile" />
                </div>
                <ProgressBead v-if="parsing" label="Reading the file" />
                <p v-if="fileResult" class="c-hint pr-state">{{ fileResult.name }}</p>
                <SelectMenu v-if="sheetOptions.length > 1" :model-value="sheet" label="Sheet" :options="sheetOptions" @update:model-value="chooseSheet" />
            </template>
            <InlineNotice v-if="importError" kind="error" title="Could not import">{{ importError }}</InlineNotice>
            <div v-if="previewGrid.length" class="c-stack">
                <div class="pm-mini-grid" :style="{ gridTemplateColumns: `repeat(${Math.min(previewGrid[0].length, 6)}, minmax(0, 1fr))` }" role="img" :aria-label="`Preview, ${dims}`">
                    <template v-for="(row, r) in previewGrid.slice(0, 4)" :key="r"><span v-for="(c, j) in row.slice(0, 6)" :key="j" :class="{ 'is-head': r === 0 && header }">{{ c }}</span></template>
                </div>
                <p class="c-hint pr-state" style="margin: 0">{{ dims }}</p>
                <InlineNotice v-if="clipped" kind="warn">Only the first {{ MAX_IMPORT_ROWS }} rows and {{ MAX_IMPORT_COLS }} columns fit in a table. The rest is left out.</InlineNotice>
                <Toggle v-model="header" label="First row is a header" />
                <button type="button" class="pr-btn pr-btn--primary" :disabled="!canEdit" @click="replaceRows">Replace the table's cells</button>
                <p class="c-hint" style="margin: 0">This replaces every cell, and the formatting of cells with it. Undo brings them back.</p>
            </div>
        </PanelSection>

        <PanelSection v-if="single" id="table.chart" title="Chart" :default-open="false">
            <p class="c-hint" style="margin: 0">Adds a chart under the table with its numbers. The table stays as it is.</p>
            <InlineNotice v-if="!chartPlan.ok && chartPlan.message" kind="info">{{ chartPlan.message }}</InlineNotice>
            <p v-else-if="chartPlan.ok" class="c-hint pr-state" style="margin: 0">{{ chartPlan.type }}: {{ chartPlan.why }}</p>
            <button type="button" class="pr-btn" :disabled="!canEdit || !chartPlan.ok" @click="makeChart"><Ico name="chart" size="sm" />Make a chart from this table</button>
        </PanelSection>
    </div>
</template>
