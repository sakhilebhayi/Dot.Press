<script setup>
// Cell editing for a table: double click a table to enter it. Click (or drag, or Shift+arrows) selects cells, typing or Enter
// edits the cell in a small text area that sits exactly on it, Tab and the arrow keys move, Delete clears. The table
// bar (merge, insert and delete) works on the selection through the stage. Cell positions are read from the table the
// slide renderer drew, so the overlay always lines up with what is on the slide (rows grow with their text).
import {
    computed, nextTick, onBeforeUnmount, onMounted, ref, watch,
} from 'vue';
import { useEditorContext } from '../context.js';
import { centerOf, rotatePoint } from '../core/geometry.js';
import {
    anchors, anchorAt, expandRange, setCell, nextCell,
} from './lib/table.js';

const props = defineProps({
    el: { type: Object, required: true },
    getNode: { type: Function, required: true },
    vp: { type: Object, required: true },
});
const emit = defineEmits(['range', 'exit']);

const { store } = useEditorContext();
const frame = ref(null);
const rects = ref([]); // [{r, c, rs, cs, x, y, w, h, font}] in slide units relative to the table
const cursor = ref({ r: 0, c: 0 });
const anchor = ref({ r: 0, c: 0 });
const editing = ref(null); // {r, c, value}
const area = ref(null);

const range = computed(() => expandRange(props.el, { r1: anchor.value.r, c1: anchor.value.c, r2: cursor.value.r, c2: cursor.value.c }));
watch(range, (r) => emit('range', r), { immediate: true });

function measure() {
    const node = props.getNode(props.el.id);
    const table = node?.querySelector('table');
    if (!table) { rects.value = []; return; }
    const cells = [...table.querySelectorAll('td, th')];
    const list = anchors(props.el);
    rects.value = list.map((a, i) => {
        const td = cells[i];
        if (!td) return null;
        const cs = getComputedStyle(td);
        return {
            ...a, x: td.offsetLeft, y: td.offsetTop, w: td.offsetWidth, h: td.offsetHeight,
            font: { size: cs.fontSize, family: cs.fontFamily, weight: cs.fontWeight, style: cs.fontStyle, align: cs.textAlign, color: cs.color, pad: cs.padding, lh: cs.lineHeight },
        };
    }).filter(Boolean);
}

let obs = null;
onMounted(async () => {
    await nextTick();
    measure();
    const t = props.getNode(props.el.id)?.querySelector('table');
    if (t && typeof ResizeObserver !== 'undefined') { obs = new ResizeObserver(measure); obs.observe(t); }
    frame.value?.focus({ preventScroll: true });
});
onBeforeUnmount(() => obs && obs.disconnect());
watch(() => props.el, () => nextTick(measure));

const frameStyle = computed(() => {
    const e = props.el;
    return { width: `${e.w}px`, height: `${e.h}px`, transform: `translate(${e.x}px, ${e.y}px)${e.rot ? ` rotate(${e.rot}deg)` : ''}` };
});

const cellState = (r) => {
  const x = range.value;
  const inRange = r.r <= x.r2 && r.r + r.rs - 1 >= x.r1 && r.c <= x.c2 && r.c + r.cs - 1 >= x.c1;
  return { sel: inRange, cur: r.r === anchorOf(cursor.value).r && r.c === anchorOf(cursor.value).c };
};
function anchorOf(p) {
  return anchorAt(props.el, p.r, p.c) || { r: p.r, c: p.c };
}
const rows = computed(() => (props.el.rows || []).length);
const cols = computed(() => Math.max(1, ...(props.el.rows || []).map((r) => r.length)));
const clampPos = (r, c) => ({ r: Math.min(rows.value - 1, Math.max(0, r)), c: Math.min(cols.value - 1, Math.max(0, c)) });

// ---- pointer: click and drag to select -------------------------------------------------------------
function localPoint(e) {
    const rect = frame.value.closest('.es-viewport').getBoundingClientRect();
    const p = props.vp.toSlide({ x: e.clientX - rect.left, y: e.clientY - rect.top });
    const q = rotatePoint(p, centerOf(props.el), -(props.el.rot || 0));
    return { x: q.x - props.el.x, y: q.y - props.el.y };
}
function cellAtPoint(pt) {
    return rects.value.find((r) => pt.x >= r.x && pt.x <= r.x + r.w && pt.y >= r.y && pt.y <= r.y + r.h) || null;
}
let dragging = false;
function onDown(e) {
    if (e.button !== 0) return;
    e.stopPropagation();
    if (editing.value) commitEdit();
    const hit = cellAtPoint(localPoint(e));
    if (!hit) return;
    cursor.value = { r: hit.r, c: hit.c };
    if (!e.shiftKey) anchor.value = { r: hit.r, c: hit.c };
    dragging = true;
    frame.value.setPointerCapture(e.pointerId);
    frame.value.focus({ preventScroll: true });
}
function onMove(e) {
    if (!dragging) return;
    const hit = cellAtPoint(localPoint(e));
    if (hit) cursor.value = { r: hit.r, c: hit.c };
}
function onUp(e) {
    dragging = false;
    try { frame.value.releasePointerCapture(e.pointerId); } catch { /* released */ }
}
function onDbl(e) {
    const hit = cellAtPoint(localPoint(e));
    if (hit) beginEdit(hit.r, hit.c);
}

// ---- editing ----------------------------------------------------------------------------------------------
const editRect = computed(() => {
    const ed = editing.value;
    if (!ed) return null;
    return rects.value.find((r) => r.r === ed.r && r.c === ed.c) || null;
});
function beginEdit(r, c, initial = null) {
    const a = anchorAt(props.el, r, c) || { r, c };
    const cell = props.el.rows[a.r]?.[a.c];
    editing.value = { r: a.r, c: a.c, value: initial != null ? initial : (cell?.t ?? ''), original: cell?.t ?? '' };
    nextTick(() => { area.value?.focus({ preventScroll: true }); if (initial == null) area.value?.select?.(); });
}
function commitEdit() {
    const ed = editing.value;
    if (!ed) return;
    editing.value = null;
    if (ed.value !== ed.original) {
        const patch = setCell(props.el, ed.r, ed.c, { t: ed.value });
        if (patch) store.patch([{ id: props.el.id, p: patch }], { undoKey: `cell:${props.el.id}:${ed.r}:${ed.c}:${Date.now()}`, label: 'Edit cell' });
    }
    nextTick(() => frame.value?.focus({ preventScroll: true }));
}
function onAreaKey(e) {
    e.stopPropagation();
    if (e.key === 'Escape') { e.preventDefault(); commitEdit(); return; }
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        const ed = editing.value;
        commitEdit();
        move(1, 0, false, ed);
        return;
    }
    if (e.key === 'Tab') {
        e.preventDefault();
        const ed = editing.value;
        commitEdit();
        const n = nextCell(props.el, ed.r, ed.c, e.shiftKey ? -1 : 1);
        if (n) { cursor.value = { r: n.r, c: n.c }; anchor.value = { r: n.r, c: n.c }; nextTick(() => beginEdit(n.r, n.c)); }
    }
}

function move(dr, dc, extend, from = null) {
    const base = from ? { r: from.r, c: from.c } : cursor.value;
    const a = anchorAt(props.el, base.r, base.c) || base;
    const r = dr > 0 ? a.r + (a.rs || 1) - 1 + dr : a.r + dr;
    const c = dc > 0 ? a.c + (a.cs || 1) - 1 + dc : a.c + dc;
    const p = clampPos(r, c);
    cursor.value = p;
    if (!extend) anchor.value = p;
}
function onKey(e) {
    if (editing.value) return;
    const mod = e.metaKey || e.ctrlKey;
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); emit('exit'); return; }
    if (mod && !e.altKey) return; // let undo, copy and friends reach the stage
    if (e.key === 'ArrowUp') { e.preventDefault(); move(-1, 0, e.shiftKey); } else if (e.key === 'ArrowDown') { e.preventDefault(); move(1, 0, e.shiftKey); } else if (e.key === 'ArrowLeft') { e.preventDefault(); move(0, -1, e.shiftKey); } else if (e.key === 'ArrowRight') { e.preventDefault(); move(0, 1, e.shiftKey); } else if (e.key === 'Tab') {
        e.preventDefault();
        const n = nextCell(props.el, cursor.value.r, cursor.value.c, e.shiftKey ? -1 : 1);
        if (n) { cursor.value = { r: n.r, c: n.c }; anchor.value = { r: n.r, c: n.c }; }
    } else if (e.key === 'Enter' || e.key === 'F2') { e.preventDefault(); beginEdit(cursor.value.r, cursor.value.c); } else if (e.key === 'Delete' || e.key === 'Backspace') {
        e.preventDefault();
        clearRange();
    } else if (e.key.length === 1 && !e.altKey) {
        e.preventDefault();
        beginEdit(cursor.value.r, cursor.value.c, e.key);
    }
    e.stopPropagation();
}
function clearRange() {
    const x = range.value;
    let rowsNext = props.el.rows.map((r) => r.map((c) => ({ ...c })));
    let changed = false;
    for (const a of anchors(props.el)) {
        if (a.r < x.r1 || a.r > x.r2 || a.c < x.c1 || a.c > x.c2) continue;
        if (rowsNext[a.r][a.c].t) { rowsNext[a.r][a.c] = { ...rowsNext[a.r][a.c], t: '' }; changed = true; }
    }
    if (changed) store.patch([{ id: props.el.id, p: { rows: rowsNext } }], { label: 'Clear cells' });
    rowsNext = null;
}

defineExpose({ range, beginEdit, commitEdit, focus: () => frame.value?.focus({ preventScroll: true }), cursor, anchor });
</script>

<template>
    <div
        ref="frame"
        class="es-tbl-frame"
        data-es-ui
        tabindex="0"
        role="grid"
        :aria-label="`Table, ${rows} rows, ${cols} columns. Arrow keys move between cells, Enter edits, Escape leaves the table.`"
        :style="frameStyle"
        @pointerdown="onDown"
        @pointermove="onMove"
        @pointerup="onUp"
        @pointercancel="onUp"
        @dblclick.stop="onDbl"
        @keydown="onKey"
    >
        <div
            v-for="r in rects"
            :key="r.r + ':' + r.c"
            class="es-tcell"
            :class="{ 'es-tcell-sel': cellState(r).sel, 'es-tcell-cur': cellState(r).cur }"
            role="gridcell"
            :style="{ left: r.x + 'px', top: r.y + 'px', width: r.w + 'px', height: r.h + 'px' }"
        ></div>
        <textarea
            v-if="editing && editRect"
            ref="area"
            v-model="editing.value"
            class="es-tcell-edit"
            aria-label="Cell text"
            spellcheck="true"
            :style="{
                left: editRect.x + 'px', top: editRect.y + 'px', width: editRect.w + 'px', minHeight: editRect.h + 'px',
                fontSize: editRect.font.size, fontFamily: editRect.font.family, fontWeight: editRect.font.weight, fontStyle: editRect.font.style,
                textAlign: editRect.font.align, padding: editRect.font.pad, lineHeight: editRect.font.lh,
            }"
            @keydown="onAreaKey"
            @blur="commitEdit"
        ></textarea>
    </div>
</template>

<style>
.es-tbl-frame { position: absolute; left: 0; top: 0; transform-origin: 50% 50%; pointer-events: auto; outline: none; cursor: cell; touch-action: none; }
.es-tcell { position: absolute; box-sizing: border-box; pointer-events: none; }
.es-tcell-sel { background: rgb(242 161 0 / 0.16); box-shadow: inset 0 0 0 calc(1.5px / var(--z, 1)) var(--es-ink); }
.es-tcell-cur { background: rgb(242 161 0 / 0.26); }
.es-tbl-frame:focus-visible { outline: calc(3px / var(--z, 1)) solid var(--focus); outline-offset: calc(2px / var(--z, 1)); }
.es-tcell-edit {
    position: absolute;
    box-sizing: border-box;
    margin: 0;
    border: calc(2px / var(--z, 1)) solid var(--es-ink);
    border-radius: 0;
    background: #fff;
    color: #14171a;
    resize: none;
    overflow: hidden;
    white-space: pre-wrap;
    outline: none;
    z-index: 3;
}
</style>
