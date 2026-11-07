<script setup>
// A rows by columns picker (up to 10 by 8). Hover or arrow keys preview the size, click or Enter inserts. The number fields below give the
// same choice without a pointer.
import { computed, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import { NumberField } from '../../../ui/controls/index.js';
import { GRID_MAX, clampGrid, gridWords } from './insertModel.js';
import { useInsert } from './useInsert.js';

const editor = useEditorContext();
const ins = useInsert(editor);
const rows = ref(3);
const cols = ref(3);
const hover = ref(null);
const grid = ref(null);
const shown = computed(() => hover.value || { rows: rows.value, cols: cols.value });
const cells = Array.from({ length: GRID_MAX.rows * GRID_MAX.cols }, (_, i) => ({ r: Math.floor(i / GRID_MAX.cols) + 1, c: (i % GRID_MAX.cols) + 1 }));
const focusCell = ref({ r: 1, c: 1 });

function choose(r, c) {
    const g = clampGrid(r, c);
    rows.value = g.rows;
    cols.value = g.cols;
    ins.insertTable(g.rows, g.cols);
}
function key(e) {
    const dr = { ArrowDown: 1, ArrowUp: -1 }[e.key] || 0;
    const dc = { ArrowRight: 1, ArrowLeft: -1 }[e.key] || 0;
    if (!dr && !dc) return;
    e.preventDefault();
    const g = clampGrid(focusCell.value.r + dr, focusCell.value.c + dc);
    focusCell.value = { r: g.rows, c: g.cols };
    hover.value = { rows: g.rows, cols: g.cols };
    grid.value?.querySelector(`[data-r="${g.rows}"][data-c="${g.cols}"]`)?.focus();
}
const setRows = (v) => { rows.value = clampGrid(v, cols.value).rows; };
const setCols = (v) => { cols.value = clampGrid(rows.value, v).cols; };
const drag = computed(() => ({ kind: 'insert', what: 'table', rows: rows.value, cols: cols.value }));
</script>

<template>
    <section class="ip-sec" aria-labelledby="ip-tables-h" style="display: grid; gap: 12px">
        <h3 id="ip-tables-h" class="ip-h">Table <span class="pr-state" aria-live="polite">{{ gridWords(shown.rows, shown.cols) }}</span></h3>
        <div
            ref="grid" class="ip-tp" role="group" aria-label="Table size, up to 10 rows by 8 columns" @mouseleave="hover = null" @keydown="key"
            @focusout="(e) => { if (!grid.contains(e.relatedTarget)) hover = null; }"
        >
            <button
                v-for="cell in cells" :key="`${cell.r}-${cell.c}`" type="button" class="ip-tp-c" :data-r="cell.r" :data-c="cell.c"
                :class="{ 'is-in': cell.r <= shown.rows && cell.c <= shown.cols, 'is-chosen': cell.r <= rows && cell.c <= cols }"
                :tabindex="cell.r === focusCell.r && cell.c === focusCell.c ? 0 : -1"
                :aria-label="`${gridWords(cell.r, cell.c)}`"
                :disabled="!ins.canEdit"
                @mouseenter="hover = { rows: cell.r, cols: cell.c }" @focus="focusCell = { r: cell.r, c: cell.c }; hover = { rows: cell.r, cols: cell.c }" @click="choose(cell.r, cell.c)"
            ></button>
        </div>
        <div class="c-grid2">
            <NumberField :model-value="rows" label="Rows" :min="1" :max="GRID_MAX.rows" :disabled="!ins.canEdit" @update:model-value="setRows" />
            <NumberField :model-value="cols" label="Columns" :min="1" :max="GRID_MAX.cols" :disabled="!ins.canEdit" @update:model-value="setCols" />
        </div>
        <button
            type="button" class="pr-btn pr-btn--primary" draggable="true" :disabled="!ins.canEdit" @click="choose(rows, cols)"
            @dragstart="ins.dragStart($event, drag, 'Table')" @dragend="ins.dragEnd"
        >Insert table, {{ gridWords(rows, cols) }}</button>
        <p class="ip-note">The first row is a header. Double click a cell on the slide to type in it.</p>
    </section>
</template>
