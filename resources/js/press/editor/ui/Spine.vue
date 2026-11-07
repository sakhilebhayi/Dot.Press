<script setup>
// The Spine: the whole argument as a rail of beads. See DESIGN.md.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useEditorContext } from '../context.js';
import { uniqueId } from '../core/util.js';
import Bead from './Bead.vue';
import Ico from './Ico.vue';
import LazyThumb from './LazyThumb.vue';
import LayoutChooser from './LayoutChooser.vue';
import SpineRow from './SpineRow.vue';
import SpineSection from './SpineSection.vue';
import SheetCell from './SheetCell.vue';
import { slideEvidence } from './evidence.js';
import { addSectionOps, buildRows, dropTarget, moveOps, rangeSelect, slidesOfSection, stepTarget } from './spineModel.js';
import { useSpineDrag } from './useSpineDrag.js';

const editor = useEditorContext();
const { store, state, ui } = editor;
const canEdit = !editor.readOnly;

const listEl = ref(null);
const scroller = ref(null);
const collapsed = ref(new Set());
const renamingSlide = ref(null);
const renamingSection = ref(null);
const chooser = ref(null); // {anchor, after}
const preview = ref(null); // hover preview {slide, top, left}
const flash = ref(null);
let anchorId = state.value.slideId;

const slides = computed(() => state.value.doc.slides);
const deck = editor.deck;
const view = computed(() => ui.spine.view);
const rows = computed(() => buildRows(slides.value, deck.value.sections || [], collapsed.value));
const slideRows = computed(() => rows.value.filter((r) => r.kind === 'slide'));
const sectionTitleOf = (id) => (deck.value.sections || []).find((s) => s.id === id)?.title || '';
const selectedSet = computed(() => new Set(editor.spineSelection.value));
const currentId = computed(() => state.value.slideId);
const summary = computed(() => {
    const n = slides.value.length;
    const solid = slides.value.reduce((k, s) => k + (slideEvidence(s).state === 'solid' ? 1 : 0), 0);
    return { n, solid };
});

// ---- selection ------------------------------------------------------------------------------------
watch(currentId, (id) => {
    if (!selectedSet.value.has(id)) editor.spineSelection.value = [id];
    anchorId = anchorId && slides.value.some((s) => s.id === anchorId) ? anchorId : id;
    nextTick(() => {
        const el = listEl.value?.querySelector(`[data-id="${CSS.escape(id)}"]`);
        el?.scrollIntoView({ block: 'nearest' });
    });
}, { immediate: true });
watch(slides, (list) => {
    const live = new Set(list.map((s) => s.id));
    const kept = editor.spineSelection.value.filter((id) => live.has(id));
    if (kept.length !== editor.spineSelection.value.length) editor.spineSelection.value = kept.length ? kept : [currentId.value];
});

function pick(id, e = {}) {
    if (e.shiftKey) {
        editor.spineSelection.value = rangeSelect(slides.value, anchorId, id);
    } else if (e.metaKey || e.ctrlKey) {
        const set = new Set(editor.spineSelection.value);
        if (set.has(id) && set.size > 1) set.delete(id); else set.add(id);
        editor.spineSelection.value = slides.value.filter((s) => set.has(s.id)).map((s) => s.id);
        anchorId = id;
    } else {
        editor.spineSelection.value = [id];
        anchorId = id;
    }
    if (id !== currentId.value) store.setSlide(id);
}
const idsFor = (id) => (selectedSet.value.has(id) ? slides.value.filter((s) => selectedSet.value.has(s.id)).map((s) => s.id) : [id]);

function focusRow(id) {
    nextTick(() => listEl.value?.querySelector(`[data-id="${CSS.escape(id)}"]`)?.focus({ preventScroll: false }));
}
function onActivate(id, e) {
    pick(id, e);
}

// ---- FLIP: rows spring to their new place ------------------------------------------------------
function reducedMotion() { return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches; }
function measureRows() {
    const m = new Map();
    for (const el of listEl.value.querySelectorAll('[data-flip]')) {
        const r = el.getBoundingClientRect();
        m.set(el.dataset.flip, { left: r.left, top: r.top });
    }
    return m;
}
async function flip(fn, settleIds = []) {
    const first = reducedMotion() || !listEl.value ? null : measureRows();
    const out = fn();
    if (!first) return out;
    await nextTick();
    const spring = getComputedStyle(document.documentElement).getPropertyValue('--spring').trim() || 'cubic-bezier(0.2, 0.9, 0.25, 1)';
    const scr = scroller.value.getBoundingClientRect();
    for (const el of listEl.value.querySelectorAll('[data-flip]')) {
        const a = first.get(el.dataset.flip);
        if (!a) continue;
        const b = el.getBoundingClientRect();
        const dx = a.left - b.left;
        const dy = a.top - b.top;
        if (Math.abs(dx) < 1 && Math.abs(dy) < 1) continue;
        if (b.bottom < scr.top - 400 && a.top < scr.top - 400) continue;
        el.animate([{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'translate(0, 0)' }], { duration: 420, easing: spring });
    }
    for (const id of settleIds) {
        const el = listEl.value.querySelector(`[data-id="${CSS.escape(id)}"]`);
        if (el) { el.classList.remove('sp-settle'); void el.offsetWidth; el.classList.add('sp-settle'); setTimeout(() => el.classList.remove('sp-settle'), 600); }
    }
    return out;
}

// ---- reorder -----------------------------------------------------------------------------------------
function applyMove(ids, target, label = 'Move slides') {
    const ops = moveOps(slides.value, ids, target);
    if (!ops.length) return false;
    flip(() => store.dispatch(ops, { label }), ids);
    const first = ids[0];
    const pos = store.getState().doc.slides.findIndex((s) => s.id === first) + 1;
    editor.announce(ids.length === 1 ? `Moved slide to position ${pos}` : `Moved ${ids.length} slides to start at position ${pos}`);
    return true;
}
function onDrop(ids, gap) {
    if (!canEdit) return;
    const target = dropTarget(rows.value, gap, ids, slides.value);
    applyMove(ids, target);
}
const drag = useSpineDrag({
    listEl, scroller,
    getRows: () => rows.value,
    getView: () => view.value,
    canDrag: () => canEdit,
    idsFor,
    labelFor: (id, ids) => {
        const s = slides.value.find((x) => x.id === id);
        const t = slideEvidence(s).title || 'Untitled slide';
        return ids.length > 1 ? `${t}` : t;
    },
    onDrop,
});
const dragIds = computed(() => new Set(drag.state.active ? drag.state.ids : []));
function startDrag(e, id) {
    if (renamingSlide.value) return;
    drag.begin(e, id);
}

// ---- adding -------------------------------------------------------------------------------------------
function openChooser(el, afterId, place = 'side') {
    const r = el.getBoundingClientRect();
    chooser.value = { anchor: { left: r.left, top: r.top, right: r.right, bottom: r.bottom }, after: afterId, place, opener: el };
}
function closeChooser() {
    const opener = chooser.value?.opener;
    chooser.value = null;
    if (opener && document.contains(opener) && opener.tabIndex >= 0) opener.focus();
}
function pickLayout(item) {
    const after = chooser.value.after;
    chooser.value = null;
    if (!canEdit) return;
    const id = flip(() => {
        const tid = store.addSlide({ layout: item.id === 'blank' ? 'blank' : item.id, after });
        if (item.def) store.applyLayout(item.def);
        return tid;
    });
    Promise.resolve(id).then((tid) => { editor.spineSelection.value = [tid]; anchorId = tid; editor.announce('New slide added'); focusRow(tid); });
}

function duplicate(ids) {
    if (!canEdit) return;
    const taken = new Set(slides.value.map((s) => s.id));
    const ops = [];
    const made = [];
    let after = ids[ids.length - 1];
    for (const id of ids) {
        const tid = uniqueId(taken, 's');
        taken.add(tid);
        ops.push({ op: 'slide.dup', id, tid, after });
        made.push(tid);
        after = tid;
    }
    flip(() => store.dispatch(ops, { label: ids.length === 1 ? 'Duplicate slide' : 'Duplicate slides' }));
    const live = made.filter((id) => store.getState().doc.slides.some((s) => s.id === id));
    if (live.length) { editor.spineSelection.value = live; store.setSlide(live[0]); anchorId = live[0]; }
    editor.announce(ids.length === 1 ? 'Slide duplicated' : `${ids.length} slides duplicated`);
}
function toggleHidden(ids) {
    if (!canEdit) return;
    const hide = !ids.every((id) => slides.value.find((s) => s.id === id)?.hidden);
    store.dispatch(ids.map((id) => ({ op: 'slide.set', id, hidden: hide })), { label: hide ? 'Hide slides' : 'Show slides' });
    editor.announce(hide ? 'Hidden from the presentation' : 'Shown in the presentation');
}
function moveToSection(ids, sectionId) {
    const all = slides.value;
    const moving = new Set(ids);
    let after;
    if (sectionId === null) after = null;
    else {
        const members = slidesOfSection(all, sectionId).filter((id) => !moving.has(id));
        after = members.length ? members[members.length - 1] : [...all].reverse().find((s) => !moving.has(s.id))?.id ?? null;
    }
    applyMove(ids, { after, sectionId }, 'Move to section');
}

// ---- sections -----------------------------------------------------------------------------------------
function addSection(slideId) {
    if (!canEdit) return;
    const taken = new Set((deck.value.sections || []).map((s) => s.id));
    const id = uniqueId(taken, 'sec');
    const ops = addSectionOps(slides.value, slideId, id, 'New section');
    if (!ops.length) return;
    store.dispatch(ops, { label: 'Add section' });
    renamingSection.value = id;
}
function commitSectionName(id, title) {
    renamingSection.value = null;
    const cur = (deck.value.sections || []).find((s) => s.id === id);
    if (!canEdit || !cur || !title || title === cur.title) return;
    store.dispatch([{ op: 'section.set', id, title }], { label: 'Rename section' });
}
function removeSection(id) {
    if (!canEdit) return;
    store.dispatch([{ op: 'section.remove', id }], { label: 'Remove section' });
    editor.toast('Section removed. Its slides stay.', { action: { label: 'Undo', run: () => store.undo() } });
}
function toggleSection(id) {
    const next = new Set(collapsed.value);
    if (next.has(id)) next.delete(id); else next.add(id);
    collapsed.value = next;
}

// ---- renaming -----------------------------------------------------------------------------------------
function commitTitle(id, title) {
    renamingSlide.value = null;
    focusRow(id);
    const s = slides.value.find((x) => x.id === id);
    if (!canEdit || !s || title === (s.title || '')) return;
    store.setSlideProps(id, { title }, { undoKey: `title:${id}` });
    editor.announce('Claim updated');
}
const offRename = editor.on('spine:rename', () => { if (canEdit) renamingSlide.value = currentId.value; });
onBeforeUnmount(offRename);

// ---- context menu -------------------------------------------------------------------------------------
async function pasteAfter() {
    try {
        const text = await navigator.clipboard.readText();
        if (!store.pasteSlides({ text })) editor.toast('There are no copied slides to paste', { kind: 'warn' });
    } catch {
        editor.toast('Use Ctrl+V or Cmd+V to paste slides', { kind: 'info' });
    }
}
function menuItems(id) {
    const ids = idsFor(id);
    const many = ids.length > 1;
    const sections = deck.value.sections || [];
    const items = [
        { label: 'New slide after', icon: 'plus', run: () => { const el = listEl.value.querySelector(`[data-id="${CSS.escape(id)}"]`); openChooser(el, id); }, disabled: !canEdit },
        { label: many ? `Duplicate ${ids.length} slides` : 'Duplicate', icon: 'duplicate', shortcut: editor.mac ? '⌘D' : 'Ctrl+D', run: () => duplicate(ids), disabled: !canEdit },
        { label: slides.value.find((s) => s.id === id)?.hidden && !many ? 'Show slide' : many ? 'Hide or show' : 'Hide slide', icon: 'eyeOff', run: () => toggleHidden(ids), disabled: !canEdit },
        { label: many ? 'Rename' : 'Rename claim', icon: 'pen', shortcut: 'F2', run: () => { store.setSlide(id); renamingSlide.value = id; }, disabled: !canEdit || many },
        { separator: true },
        { label: 'Copy', shortcut: editor.mac ? '⌘C' : 'Ctrl+C', run: () => { const r = store.copySlides(ids); navigator.clipboard?.writeText(r.text).catch(() => {}); } },
        { label: 'Paste after', shortcut: editor.mac ? '⌘V' : 'Ctrl+V', run: () => { store.setSlide(id); pasteAfter(); }, disabled: !canEdit },
        { separator: true },
        { heading: 'Move to section' },
        ...sections.map((s) => ({ label: s.title || 'Untitled section', run: () => moveToSection(ids, s.id), disabled: !canEdit })),
        { label: 'No section', run: () => moveToSection(ids, null), disabled: !canEdit },
        { label: 'Start a section here', icon: 'section', run: () => addSection(id), disabled: !canEdit || many },
        { separator: true },
        { label: many ? `Delete ${ids.length} slides` : 'Delete', icon: 'trash', shortcut: 'Del', danger: true, run: () => editor.removeSlides(ids), disabled: !canEdit },
    ];
    return items;
}
function sectionMenu(row) {
    return [
        { label: 'Rename section', icon: 'pen', run: () => { renamingSection.value = row.id; }, disabled: !canEdit || !row.id },
        { label: row.collapsed ? 'Expand' : 'Collapse', run: () => toggleSection(row.id) },
        { separator: true },
        { label: 'Remove section', icon: 'trash', danger: true, run: () => removeSection(row.id), disabled: !canEdit || !row.id },
    ];
}
function openMenu(e, items) {
    const t = e.currentTarget?.getBoundingClientRect?.();
    const fromButton = e.type === 'click' && t;
    ui.contextMenu = { x: fromButton ? t.right : e.clientX, y: fromButton ? t.bottom : e.clientY, items };
}
function onRowMenu(e, id) {
    if (!selectedSet.value.has(id)) pick(id);
    openMenu(e, menuItems(id));
}

// ---- keyboard -----------------------------------------------------------------------------------------
function focusedId() { return document.activeElement?.closest?.('[data-row="slide"]')?.dataset.id || currentId.value; }
function onKey(e) {
    if (e.target.closest('input')) return;
    const id = focusedId();
    const list = slides.value;
    const i = list.findIndex((s) => s.id === id);
    const mod = e.metaKey || e.ctrlKey;
    const vertical = view.value === 'spine';
    const step = { ArrowDown: vertical ? 1 : 2, ArrowUp: vertical ? -1 : -2, ArrowRight: 1, ArrowLeft: -1 }[e.key];
    if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
        e.preventDefault(); e.stopPropagation();
        if (!canEdit) return;
        const ids = idsFor(id);
        const target = stepTarget(list, ids, e.key === 'ArrowUp' ? -1 : 1);
        if (target) { applyMove(ids, target, 'Move slides'); focusRow(id); }
        return;
    }
    if (step !== undefined && !e.altKey && !mod && (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' || view.value === 'sheets')) {
        e.preventDefault(); e.stopPropagation();
        const n = Math.min(list.length - 1, Math.max(0, i + step));
        const target = list[n];
        if (!target) return;
        if (e.shiftKey) editor.spineSelection.value = rangeSelect(list, anchorId, target.id); else { editor.spineSelection.value = [target.id]; anchorId = target.id; }
        store.setSlide(target.id);
        focusRow(target.id);
        return;
    }
    if (e.key === 'Home' || e.key === 'End') {
        e.preventDefault(); e.stopPropagation();
        const target = e.key === 'Home' ? list[0] : list[list.length - 1];
        pick(target.id, { shiftKey: e.shiftKey });
        focusRow(target.id);
        return;
    }
    if (e.key === ' ') { e.preventDefault(); e.stopPropagation(); pick(id, { ctrlKey: true }); return; }
    if (e.key === 'Escape' && editor.spineSelection.value.length > 1) { e.preventDefault(); e.stopPropagation(); editor.spineSelection.value = [currentId.value]; return; }
    if (mod && e.key.toLowerCase() === 'a') { e.preventDefault(); e.stopPropagation(); editor.spineSelection.value = list.map((s) => s.id); return; }
    if (e.key === 'F2') { e.preventDefault(); e.stopPropagation(); if (canEdit) renamingSlide.value = id; return; }
    if ((e.key === 'Delete' || e.key === 'Backspace') && !mod) {
        e.preventDefault(); e.stopPropagation();
        editor.removeSlides(idsFor(id));
        nextTick(() => focusRow(store.getState().slideId));
        return;
    }
    if (mod && !e.shiftKey && e.key.toLowerCase() === 'd') { e.preventDefault(); e.stopPropagation(); duplicate(idsFor(id)); return; }
    if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) {
        e.preventDefault();
        const el = listEl.value.querySelector(`[data-id="${CSS.escape(id)}"]`);
        const r = el.getBoundingClientRect();
        ui.contextMenu = { x: r.left + 60, y: r.top + 20, items: menuItems(id) };
    }
}

// ---- hover preview ----------------------------------------------------------------------------------
let previewTimer = null;
function onHover(e, id, on) {
    clearTimeout(previewTimer);
    if (!on || drag.state.active || id === currentId.value || !window.matchMedia('(hover: hover)').matches || ui.spine.view !== 'spine') { preview.value = null; return; }
    const el = e.currentTarget;
    previewTimer = setTimeout(() => {
        const r = el.getBoundingClientRect();
        const slide = slides.value.find((s) => s.id === id);
        if (!slide || drag.state.active) return;
        const h = 300 * (deck.value.h / deck.value.w) + 54;
        preview.value = { slide, top: Math.max(8, Math.min(window.innerHeight - h - 8, r.top - 4)), left: r.right + 10 };
    }, 420);
}
watch(() => drag.state.active, (a) => { if (a) preview.value = null; });
onBeforeUnmount(() => clearTimeout(previewTimer));

// the list is the keyboard owner; the first row in view takes the tab stop when nothing is current
const tabStopId = computed(() => (slides.value.some((s) => s.id === currentId.value) ? currentId.value : slides.value[0]?.id));
const indicatorStyle = computed(() => {
    const ind = drag.state.indicator;
    if (!ind) return null;
    return { transform: `translate(${ind.x}px, ${ind.y}px)`, width: `${ind.w}px`, height: `${ind.h}px` };
});
const ghostStyle = computed(() => ({ transform: `translate(${drag.state.x + 14}px, ${drag.state.y + 8}px)` }));

function addAtEnd(e) {
    const last = slides.value[slides.value.length - 1];
    openChooser(e.currentTarget, last.id, 'up');
}
onMounted(() => {
    // keep the current row visible when the list first draws
    nextTick(() => listEl.value?.querySelector('[aria-current="true"]')?.scrollIntoView({ block: 'center' }));
});
</script>

<template>
    <aside class="sp" :class="{ 'sp--sheets': view === 'sheets', 'sp--ro': !canEdit }" aria-label="Slides">
        <header class="sp-head">
            <div class="sp-head-text">
                <h2 class="sp-h">Slides</h2>
                <p class="sp-sum pr-state" :aria-label="`${summary.n} slides, ${summary.solid} with evidence`">{{ summary.n }} · {{ summary.solid }} solid</p>
            </div>
            <div class="sp-views" role="group" aria-label="Spine view">
                <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon pr-btn--sm" :aria-pressed="view === 'spine'" data-tip="Claims" @click="ui.spine.view = 'spine'"><Ico name="spine" /><span class="pr-sr">Claims view</span></button>
                <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon pr-btn--sm" :aria-pressed="view === 'sheets'" data-tip="Sheets" @click="ui.spine.view = 'sheets'"><Ico name="sheets" /><span class="pr-sr">Sheets view</span></button>
            </div>
            <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon pr-btn--sm sp-close" aria-label="Close the slide list" @click="ui.spine.open = false"><Ico name="close" /></button>
        </header>

        <div ref="scroller" class="sp-scroll pr-scroll">
            <ul
                ref="listEl" class="sp-list" :class="{ 'is-dragging': drag.state.active }" role="listbox" aria-label="Slides, one claim each" aria-multiselectable="true" data-keyscope="spine"
                @keydown="onKey"
            >
                <template v-for="(row, ri) in rows" :key="row.key">
                    <SpineSection
                        v-if="row.kind === 'section'" :row="row" :read-only="!canEdit" :renaming="renamingSection === row.id && row.id !== null"
                        @toggle="toggleSection(row.id)" @menu="openMenu($event, sectionMenu(row))" @rename-start="renamingSection = row.id" @rename="commitSectionName" @rename-cancel="renamingSection = null"
                    />
                    <SheetCell
                        v-else-if="view === 'sheets'" :slide="row.slide" :number="row.number" :current="row.slide.id === currentId" :selected="selectedSet.has(row.slide.id)"
                        :focusable="row.slide.id === tabStopId" :issues="editor.reviewIssues[row.slide.id] || 0" :dragging="dragIds.has(row.slide.id)" :deck="deck" :assets="editor.assets"
                        @activate="onActivate(row.slide.id, $event)" @menu="onRowMenu($event, row.slide.id)" @drag="startDrag($event, row.slide.id)"
                    />
                    <SpineRow
                        v-else :slide="row.slide" :number="row.number" :section-title="sectionTitleOf(row.sectionId)" :current="row.slide.id === currentId" :selected="selectedSet.has(row.slide.id)"
                        :focusable="row.slide.id === tabStopId" :issues="editor.reviewIssues[row.slide.id] || 0" :pos="slideRows.length === 1 ? 'only' : row === slideRows[0] ? 'first' : row === slideRows[slideRows.length - 1] ? 'last' : ''"
                        :renaming="renamingSlide === row.slide.id" :dragging="dragIds.has(row.slide.id)" :deck="deck" :assets="editor.assets" :read-only="!canEdit"
                        @activate="onActivate(row.slide.id, $event)" @menu="onRowMenu($event, row.slide.id)" @insert="openChooser($event.currentTarget, row.slide.id)"
                        @rename-start="renamingSlide = row.slide.id" @rename="commitTitle" @rename-cancel="renamingSlide = null; focusRow(row.slide.id)"
                        @hover="(e, on) => onHover(e, row.slide.id, on)" @drag="startDrag($event, row.slide.id)"
                    />
                </template>
                <li v-if="indicatorStyle" class="sp-drop" :class="{ 'is-vertical': drag.state.indicator.vertical }" :style="indicatorStyle" aria-hidden="true"><span class="sp-drop-bead"></span></li>
            </ul>
            <p v-if="!slides.length" class="sp-empty pr-title">Nothing on this line yet.</p>
        </div>

        <footer v-if="canEdit" class="sp-foot">
            <button type="button" class="pr-btn pr-btn--primary sp-add" aria-haspopup="dialog" :aria-expanded="!!chooser" @click="addAtEnd"><Ico name="plus" size="sm" :stroke="2.2" />New slide</button>
            <button type="button" class="pr-btn" data-tip="Start a section at this slide" data-tip-up @click="addSection(currentId)"><Ico name="section" size="sm" />Section</button>
        </footer>
        <p v-else class="sp-foot sp-foot--ro pr-small pr-muted">View only</p>

        <Teleport to="body">
            <div v-if="drag.state.active" class="pr sp-ghost" :style="ghostStyle" aria-hidden="true">
                <Bead state="current" :evidence="'hollow'" />
                <span class="sp-ghost-text">{{ drag.state.label }}</span>
                <span v-if="drag.state.ids.length > 1" class="sp-ghost-n pr-state">{{ drag.state.ids.length }}</span>
            </div>
            <div v-if="preview" class="pr sp-preview" :style="{ top: preview.top + 'px', left: preview.left + 'px' }" aria-hidden="true">
                <LazyThumb :slide="preview.slide" :deck="deck" :assets="editor.assets" eager />
                <p class="sp-preview-t">{{ slideEvidence(preview.slide).title || 'Untitled slide' }}</p>
            </div>
        </Teleport>
        <LayoutChooser v-if="chooser" :anchor="chooser.anchor" :place="chooser.place" @pick="pickLayout" @close="closeChooser" />
    </aside>
</template>
