<script setup>
// A spreadsheet-like grid for chart data. It shows `view` (grid.js gridView) and reports every edit upward as an event; the
// parent applies it to the chart, so the grid holds no data of its own, only focus and the text of cells that were refused.
//   Keys: Arrows move (Left and Right only when the caret is at the edge of the text), Tab and Shift+Tab walk the cells and
//   wrap to the next row, Enter commits and moves down (Shift+Enter up), Escape puts the old text back.
//   Paste: text with tabs or line breaks fills cells from the focused one (TSV or CSV from a spreadsheet); plain text pastes
//   into the cell. Long data is windowed: only the rows near the viewport are in the page.
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, useId, watch } from 'vue';
import { MAP_CODES, countryName } from '../../../charts/map.js';
import IconButton from '../../../ui/controls/IconButton.vue';
import Segmented from '../../../ui/controls/Segmented.vue';
import Ico from '../../ui/Ico.vue';

const props = defineProps({
    view: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) }, // 'r:c' -> {text, message}
    canEdit: { type: Boolean, default: true },
    dense: { type: Boolean, default: true },
    totals: { type: Array, default: null }, // waterfall: boolean per row, shows the Total column
    label: { type: String, default: 'Chart data' },
    kinds: { type: Boolean, default: false }, // combo: a bar or line choice under every series name
});
const emit = defineEmits(['cell', 'header', 'paste', 'remove-row', 'remove-column', 'kind', 'total', 'active']);

const uid = useId();
const scroller = ref(null);
const active = ref({ r: 0, c: 0 });
const scrollTop = ref(0);
const viewportH = ref(300);
const coarse = ref(false);
const rowH = computed(() => (coarse.value ? 44 : 32));
const WINDOW_AFTER = 60;

const rowCount = computed(() => props.view.rowCount);
const cols = computed(() => props.view.columns);
const windowed = computed(() => rowCount.value > WINDOW_AFTER);
const first = computed(() => (windowed.value ? Math.max(0, Math.floor(scrollTop.value / rowH.value) - 8) : 0));
const last = computed(() => (windowed.value ? Math.min(rowCount.value, Math.ceil((scrollTop.value + viewportH.value) / rowH.value) + 8) : rowCount.value));
const visibleRows = computed(() => { const out = []; for (let r = first.value; r < last.value; r += 1) out.push(r); return out; });
const colCount = computed(() => cols.value.length + 1 + (props.totals ? 1 : 0) + 1);
const navCols = computed(() => cols.value.map((c, i) => (c.kind === 'readonly' ? -1 : i)).filter((i) => i >= 0));
const errorList = computed(() => Object.entries(props.errors).map(([k, v]) => ({ key: k, r: Number(k.split(':')[0]), c: Number(k.split(':')[1]), ...v })).sort((a, b) => a.r - b.r || a.c - b.c));
const errId = computed(() => `cpg-err-${uid}`);
const countryList = computed(() => (props.view.mode === 'map' ? [...MAP_CODES].map((c) => countryName(c)).sort() : []));

const NUM_W = 26;
const ACT_W = 28;
const TOT_W = 48;
function widthOf(col, c) {
    if (col.kind === 'readonly') return 110;
    if (col.kind === 'country') return 56;
    if (props.view.mode === 'cat') return c === 0 ? 84 : props.kinds ? 100 : 74;
    if (col.kind === 'text') return 90;
    return 66;
}
const widths = computed(() => cols.value.map(widthOf));
const tableW = computed(() => NUM_W + (props.totals ? TOT_W : 0) + widths.value.reduce((a, b) => a + b, 0) + ACT_W);

const key = (r, c) => `${r}:${c}`;
// What the person has typed but not committed yet is kept in `drafts`: Vue writes the stored value back into an input whenever the
// grid redraws (moving the focus redraws it), which would wipe the typing before the change event fires.
const drafts = reactive({});
const shown = (r, c) => drafts[key(r, c)] ?? props.errors[key(r, c)]?.text ?? props.view.rows[r]?.[c] ?? '';
const hasError = (r, c) => !!props.errors[key(r, c)];
const isNumeric = (c) => cols.value[c]?.kind === 'number' || cols.value[c]?.kind === 'number-or-text';
const tabCell = computed(() => {
    const a = active.value;
    const r = a.r >= first.value && a.r < last.value ? a.r : first.value;
    return { r, c: navCols.value.includes(a.c) ? a.c : (navCols.value[0] ?? 0) };
});
const tabbable = (r, c) => tabCell.value.r === r && tabCell.value.c === c;
const rowLabel = (r) => `Row ${r + 1}`;
const cellLabel = (r, c) => {
    const col = cols.value[c];
    const name = props.view.mode === 'cat' ? (c === 0 ? 'Category' : col.label) : col.label;
    const head = props.view.rows[r]?.[0];
    return props.view.mode === 'cat' && c > 0 && head ? `${name}, ${head}` : `${name}, row ${r + 1}`;
};

function onScroll() {
    if (!windowed.value) return;
    scrollTop.value = scroller.value.scrollTop;
}
let ro = null;
onMounted(() => {
    coarse.value = typeof matchMedia === 'function' && matchMedia('(pointer: coarse)').matches;
    viewportH.value = scroller.value?.clientHeight || 300;
    if (typeof ResizeObserver === 'function') {
        ro = new ResizeObserver(() => { viewportH.value = scroller.value?.clientHeight || 300; });
        ro.observe(scroller.value);
    }
});
onBeforeUnmount(() => ro?.disconnect());
watch(rowCount, (n) => { if (active.value.r >= n) active.value = { ...active.value, r: Math.max(0, n - 1) }; });

async function focusCell(r, c) {
    const rr = Math.max(0, Math.min(rowCount.value - 1, r));
    const cc = Math.max(0, Math.min(cols.value.length - 1, c));
    active.value = { r: rr, c: cc };
    emit('active', rr);
    const el = scroller.value;
    if (el && windowed.value) {
        const top = rr * rowH.value;
        if (top < el.scrollTop) el.scrollTop = top;
        else if (top + rowH.value > el.scrollTop + el.clientHeight - rowH.value) el.scrollTop = top + rowH.value - el.clientHeight + rowH.value * 1.2;
        scrollTop.value = el.scrollTop;
    }
    await nextTick();
    const input = scroller.value?.querySelector(`[data-cell="${rr}:${cc}"]`);
    input?.focus();
    input?.select?.();
}
function colStep(c, dir) {
    const list = navCols.value;
    const i = list.indexOf(c);
    return i < 0 ? c : list[i + dir];
}

function onKey(e, r, c) {
    const t = e.target;
    const caretStart = t.selectionStart === 0 && t.selectionEnd === 0;
    const caretEnd = t.selectionStart === t.value.length && t.selectionEnd === t.value.length;
    const allSelected = t.selectionStart === 0 && t.selectionEnd === t.value.length;
    if (e.key === 'ArrowDown') { e.preventDefault(); focusCell(r + 1, c); return; }
    if (e.key === 'ArrowUp') { e.preventDefault(); focusCell(r - 1, c); return; }
    if (e.key === 'ArrowLeft' && (caretStart || allSelected) && colStep(c, -1) !== undefined) { e.preventDefault(); focusCell(r, colStep(c, -1)); return; }
    if (e.key === 'ArrowRight' && (caretEnd || allSelected) && colStep(c, 1) !== undefined) { e.preventDefault(); focusCell(r, colStep(c, 1)); return; }
    if (e.key === 'Enter') { e.preventDefault(); focusCell(e.shiftKey ? r - 1 : r + 1, c); return; }
    if (e.key === 'Escape') { e.preventDefault(); delete drafts[key(r, c)]; t.value = shown(r, c); return; }
    if (e.key === 'Tab') {
        const list = navCols.value;
        const i = list.indexOf(c);
        if (!e.shiftKey) {
            if (i < list.length - 1) { e.preventDefault(); focusCell(r, list[i + 1]); }
            else if (r < rowCount.value - 1) { e.preventDefault(); focusCell(r + 1, list[0]); }
        } else if (i > 0) { e.preventDefault(); focusCell(r, list[i - 1]); }
        else if (r > 0) { e.preventDefault(); focusCell(r - 1, list[list.length - 1]); }
    }
}
function onInput(e, r, c) {
    drafts[key(r, c)] = e.target.value;
}
function onBlur(r, c) {
    delete drafts[key(r, c)];
}
function onChange(e, r, c) {
    delete drafts[key(r, c)];
    if (!props.canEdit) return;
    const text = e.target.value;
    if (text === (props.view.rows[r]?.[c] ?? '') && !hasError(r, c)) return;
    emit('cell', r, c, text);
}
function onPaste(e, r, c) {
    if (!props.canEdit) return;
    const text = e.clipboardData?.getData('text') ?? '';
    if (!/[\t\n\r]/.test(text.replace(/[\r\n]+$/, ''))) return; // a single value pastes into the cell
    e.preventDefault();
    emit('paste', r, c, text);
}
function onFocus(r, c) {
    active.value = { r, c };
    emit('active', r);
}
defineExpose({ focusCell, active });
</script>

<template>
    <div class="cpg" :class="{ 'is-dense': dense, 'is-coarse': coarse }">
        <div ref="scroller" class="cpg-scroll pr-scroll" tabindex="-1" @scroll.passive="onScroll">
             <table class="cpg-t" role="grid" :style="{ width: tableW + 'px' }" :aria-label="label" :aria-rowcount="rowCount + 1" :aria-colcount="colCount" :aria-describedby="errorList.length ? errId : undefined">
                <colgroup>
                    <col :style="{ width: NUM_W + 'px' }" />
                    <col v-if="totals" :style="{ width: TOT_W + 'px' }" />
                    <col v-for="(w, c) in widths" :key="c" :style="{ width: w + 'px' }" />
                    <col :style="{ width: ACT_W + 'px' }" />
                </colgroup>
                <thead>
                    <tr>
                        <th class="cpg-n" scope="col"><span class="pr-sr">Row</span></th>
                        <th v-if="totals" class="cpg-tot" scope="col"><span class="cpg-hl">Total</span></th>
                        <th v-for="(col, c) in cols" :key="col.key" scope="col" class="cpg-h" :class="{ 'is-cat': c === 0 && view.mode === 'cat' && !totals, 'is-ro': col.kind === 'readonly' }">
                            <div class="cpg-hbox">
                                <template v-if="view.mode === 'cat' && c > 0">
                                    <input
                                        class="cpg-hin" :value="col.label" :disabled="!canEdit" :aria-label="`Series ${c} name`" spellcheck="false" maxlength="80"
                                        @change="emit('header', c, $event.target.value)"
                                        @keydown.enter.prevent="$event.target.blur()"
                                    />
                                    <IconButton v-if="view.canRemoveColumn" class="cpg-hx" icon="close" :label="`Delete series ${col.label}`" size="sm" danger :disabled="!canEdit" @click="emit('remove-column', c)" />
                                </template>
                                <span v-else class="cpg-hl">{{ col.label }}</span>
                            </div>
                            <Segmented
                                v-if="kinds && view.mode === 'cat' && c > 0" class="cpg-kind" :model-value="col.seriesKind || 'bar'" :disabled="!canEdit" :label="`How ${col.label} is drawn`" hide-label
                                :options="[{ value: 'bar', label: 'Bar' }, { value: 'line', label: 'Line' }]" @update:model-value="(v) => emit('kind', col.series, v)"
                            />
                        </th>
                        <th class="cpg-x" scope="col"><span class="pr-sr">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="windowed && first > 0" class="cpg-sp" aria-hidden="true" :style="{ height: first * rowH + 'px' }"><td :colspan="colCount"></td></tr>
                    <tr v-for="r in visibleRows" :key="r" :aria-rowindex="r + 2" class="cpg-r" :style="{ height: rowH + 'px' }">
                        <th class="cpg-n" scope="row"><span class="pr-state">{{ r + 1 }}</span></th>
                        <td v-if="totals" class="cpg-tot">
                            <input type="checkbox" class="cpg-chk" :checked="totals[r]" :disabled="!canEdit" :aria-label="`${rowLabel(r)} is a total`" @change="emit('total', r)" />
                        </td>
                        <td v-for="(col, c) in cols" :key="col.key" class="cpg-c" :class="{ 'is-err': hasError(r, c), 'is-num': isNumeric(c), 'is-ro': col.kind === 'readonly', 'is-first': c === 0 && view.mode === 'cat' && !totals }" role="gridcell">
                            <span v-if="col.kind === 'readonly'" class="cpg-ro">{{ view.rows[r]?.[c] }}</span>
                            <template v-else>
                                <input
                                    class="cpg-in" :data-cell="`${r}:${c}`" :value="shown(r, c)" :tabindex="tabbable(r, c) ? 0 : -1" :disabled="!canEdit" :list="col.kind === 'country' ? `cpg-cl-${uid}` : undefined"
                                    :inputmode="isNumeric(c) ? 'decimal' : undefined" :aria-label="cellLabel(r, c)" :aria-invalid="hasError(r, c) ? 'true' : undefined" :placeholder="isNumeric(c) ? '–' : ''"
                                    autocomplete="off" spellcheck="false" :title="hasError(r, c) ? errors[`${r}:${c}`].message : undefined"
                                     @focus="onFocus(r, c)" @input="onInput($event, r, c)" @blur="onBlur(r, c)" @keydown="onKey($event, r, c)" @change="onChange($event, r, c)" @paste="onPaste($event, r, c)"
                                />
                                <Ico v-if="hasError(r, c)" name="warning" size="sm" class="cpg-wi" />
                            </template>
                        </td>
                        <td class="cpg-x"><IconButton icon="trash" :label="`Delete ${rowLabel(r).toLowerCase()}`" size="sm" danger :disabled="!canEdit" @click="emit('remove-row', r)" /></td>
                    </tr>
                    <tr v-if="windowed && last < rowCount" class="cpg-sp" aria-hidden="true" :style="{ height: (rowCount - last) * rowH + 'px' }"><td :colspan="colCount"></td></tr>
                </tbody>
            </table>
            <datalist v-if="countryList.length" :id="`cpg-cl-${uid}`"><option v-for="n in countryList" :key="n" :value="n" /></datalist>
        </div>
        <div v-if="errorList.length" :id="errId" class="cpg-err" role="status">
            <Ico name="warning" size="sm" />
            <div>
                <p class="cpg-err-t">{{ errorList.length === 1 ? '1 cell needs attention' : `${errorList.length} cells need attention` }}</p>
                <ul>
                    <li v-for="e in errorList.slice(0, 3)" :key="e.key">{{ cellLabel(e.r, e.c) }}: {{ e.message }}</li>
                    <li v-if="errorList.length > 3">and {{ errorList.length - 3 }} more</li>
                </ul>
            </div>
        </div>
    </div>
</template>

<style>
.cpg { --cp-row: 32px; min-width: 0; }
.cpg.is-coarse { --cp-row: 44px; }
.cpg-scroll { overflow: auto; max-height: 280px; border: 1px solid var(--line-strong); border-radius: var(--r-2); background: var(--sheet); overscroll-behavior: contain; }
.cpg:not(.is-dense) .cpg-scroll { max-height: min(56vh, 520px); }
.cpg-t { border-collapse: separate; border-spacing: 0; table-layout: fixed; min-width: 100%; font-size: var(--t-sm); }
.cpg-t th, .cpg-t td { padding: 0; border-bottom: 1px solid var(--line); border-right: 1px solid var(--line); background: var(--sheet); }
.cpg-t thead th { position: sticky; top: 0; z-index: 3; background: var(--raised); border-bottom: 1px solid var(--line-strong); vertical-align: top; }
.cpg-n { position: sticky; left: 0; z-index: 2; text-align: center; background: var(--raised) !important; color: var(--muted); overflow: hidden; }
.cpg-t thead .cpg-n { z-index: 4; }
.cpg-r { height: var(--cp-row); }
.cpg-h.is-cat { position: sticky; left: 26px; z-index: 4; }
.cpg-c.is-first { position: sticky; left: 26px; z-index: 1; }
.cpg-hbox { position: relative; display: flex; align-items: center; min-height: var(--cp-row); }
.cpg-hx { position: absolute; right: 1px; top: 50%; margin-top: -14px; opacity: 0; background: var(--raised); }
.cpg-h:hover .cpg-hx, .cpg-h:focus-within .cpg-hx { opacity: 1; }
.cpg-hl { display: block; min-width: 0; padding: 0 8px; font-weight: 600; color: var(--ink); line-height: var(--cp-row); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cpg-hin { flex: 1; min-width: 0; width: 100%; text-overflow: ellipsis; height: calc(var(--cp-row) - 2px); padding: 0 8px; border: 0; border-radius: 0; background: transparent; color: var(--ink); font: inherit; font-weight: 600; }
.cpg-hin:hover { background: var(--sheet); }
.cpg-kind { padding: 0 4px 4px; }
.cpg-kind .c-seg { width: 100%; }
.cpg-kind .c-seg-b { height: 24px; font-size: var(--t-xs); padding: 0 6px; }
.cpg-c { position: relative; }
.cpg-in { display: block; width: 100%; min-width: 0; text-overflow: ellipsis; height: calc(var(--cp-row) - 1px); padding: 0 8px; border: 0; border-radius: 0; background: transparent; color: var(--ink); font: inherit; font-variant-numeric: tabular-nums; }
.cpg-c.is-num .cpg-in { text-align: right; font-family: var(--f-mono); font-size: var(--t-xs); letter-spacing: 0.01em; }
.cpg-in::placeholder { color: var(--faint); opacity: 1; }
.cpg-in:hover { background: var(--raised); }
.cpg-in:focus, .cpg-hin:focus { outline: 2px solid var(--focus); outline-offset: -2px; background: var(--sheet); }
.cpg-in:disabled, .cpg-hin:disabled { color: var(--ink-2); cursor: default; }
.cpg-c.is-err { background: color-mix(in srgb, var(--bad) 9%, var(--sheet)); box-shadow: inset 0 0 0 1.5px var(--bad); }
.cpg-c.is-err .cpg-in { padding-right: 26px; }
.cpg-wi { position: absolute; right: 6px; top: 50%; margin-top: -8px; color: var(--bad); pointer-events: none; }
.cpg-ro { display: block; overflow: hidden; text-overflow: ellipsis; padding: 0 8px; line-height: var(--cp-row); color: var(--muted); white-space: nowrap; }
.cpg-tot { text-align: center; }
.cpg-chk { width: 18px; height: 18px; margin: 0; accent-color: var(--ink); vertical-align: middle; }
.cpg-x { text-align: center; overflow: hidden; }
.cpg-x .c-ibtn { opacity: 0.55; }
.cpg-r:hover .cpg-x .c-ibtn, .cpg-r:focus-within .cpg-x .c-ibtn, .cpg-x .c-ibtn:focus-visible { opacity: 1; }
.cpg-sp td { border: 0; padding: 0; }
.cpg-err { display: flex; gap: 8px; align-items: flex-start; margin-top: 8px; padding: 8px 10px; border: 1px solid var(--bad); border-radius: var(--r-2); background: color-mix(in srgb, var(--bad) 7%, var(--sheet)); color: var(--ink); font-size: var(--t-sm); line-height: 1.4; }
.cpg-err .pr-ico { flex: none; margin-top: 1px; color: var(--bad); }
.cpg-err p { margin: 0; font-weight: 600; }
.cpg-err ul { margin: 2px 0 0; padding-left: 16px; color: var(--ink-2); }
@media (pointer: coarse) { .cpg-x .c-ibtn, .cpg-hx { opacity: 1; } }
@media (prefers-reduced-motion: reduce) { .cpg * { transition: none !important; } }
</style>
