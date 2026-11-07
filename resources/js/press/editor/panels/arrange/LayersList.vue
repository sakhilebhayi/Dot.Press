<script setup>
// The layers of the whole slide, front first. Drag a handle (or focus it and use the arrow keys) to change the stacking order; each
// row can be locked, hidden and renamed; a click selects (Shift or Ctrl adds). A group is one row that opens to show its members.
import { computed, nextTick, ref, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import {
    EmptyState, IconButton, ListRow, PanelSection, ReorderList,
} from '../../../ui/controls/index.js';
import Ico from '../../ui/Ico.vue';
import './glyphs.js';
import { layerUnits, renamePatch, reorderOps } from './layersModel.js';

const editor = useEditorContext();
const { store } = editor;
const can = computed(() => !editor.readOnly && editor.can?.update !== false);

const collapsed = ref(new Set());
const slide = computed(() => { void editor.state.value; return editor.slide.value; });
const selectedIds = computed(() => editor.state.value.selection.ids);
const units = computed(() => layerUnits(slide.value, { selected: selectedIds.value, collapsed: [...collapsed.value] }));
const total = computed(() => slide.value?.els.length || 0);

const glyph = (type, kind) => {
    if (type === 'shape') return kind || 'shape';
    return { text: 'text', image: 'image', chart: 'chart', table: 'table', icon: 'star', video: 'present', audio: 'present', group: 'p-group' }[type] || 'shape';
};

// ---- select ----------------------------------------------------------------------------------------------------------
let fromList = false;
function pick(unit, e) {
    fromList = true;
    const add = e.shiftKey || e.metaKey || e.ctrlKey;
    if (unit.kind === 'group') store.select(unit.ids, add ? 'toggle' : 'replace');
    else store.clickElement(unit.id, { shift: e.shiftKey, meta: e.metaKey || e.ctrlKey });
    store.getState();
}
function pickMember(row, e) {
    fromList = true;
    const add = e.shiftKey || e.metaKey || e.ctrlKey;
    store.select([row.id], add ? 'toggle' : 'replace', { members: true });
}

// ---- reorder ---------------------------------------------------------------------------------------------------------
function onReorder({ from, to }) {
    if (!can.value) return;
    const ops = reorderOps(slide.value, units.value, from, to);
    if (!ops.length) return;
    store.dispatch(ops, { label: 'Reorder layers' });
    editor.announce?.(`Moved to position ${to + 1} of ${units.value.length}`);
}

// ---- lock, hide, rename, collapse --------------------------------------------------------------------------------------
const toggle = (key, ids) => { if (!can.value) return; if (key === 'lock') store.toggleLock(ids); else store.toggleHide(ids); };
function openGroup(id) {
    const next = new Set(collapsed.value);
    if (next.has(id)) next.delete(id); else next.add(id);
    collapsed.value = next;
}
const renaming = ref(null); // {id, text}
const renameInput = ref(null);
async function startRename(id, label) {
    if (!can.value) return;
    const el = slide.value.els.find((x) => x.id === id);
    renaming.value = { id, text: el?.name || label };
    await nextTick();
    const input = [].concat(renameInput.value || [])[0];
    input?.focus();
    input?.select();
}
function finishRename(commit) {
    const r = renaming.value;
    renaming.value = null;
    if (!r || !commit) return;
    const el = slide.value?.els.find((x) => x.id === r.id);
    const p = el && renamePatch(el, r.text);
    if (p) store.patch([p], { undoKey: null, label: 'Rename layer' });
}
function onRowKey(e, unit) {
    if (e.key === 'F2' && unit.kind === 'el') { e.preventDefault(); startRename(unit.id, unit.label); }
}

// keep the selected row in view when the stage changed the selection
const list = ref(null);
watch(selectedIds, async () => {
    if (fromList) { fromList = false; return; }
    await nextTick();
    const row = list.value?.querySelector('.lay-main[aria-pressed="true"]');
    const box = list.value;
    if (!row || !box) return;
    const r = row.getBoundingClientRect();
    const b = box.getBoundingClientRect();
    if (r.top < b.top) box.scrollTop -= b.top - r.top + 4;
    else if (r.bottom > b.bottom) box.scrollTop += r.bottom - b.bottom + 4;
});
</script>

<template>
    <PanelSection id="arrange.layers" title="Layers" :state="total || ''">
        <EmptyState v-if="!units.length" compact title="No layers yet." body="Add something to the slide and it appears here." />
        <div v-else ref="list" class="lay" data-layers>
            <ReorderList v-if="can" :items="units" item-key="key" label="Layers, front first" :handle-label="(u) => `Reorder ${u.label}`" @reorder="onReorder">
                    <template #default="{ item: u }">
                        <div class="lay-cell" @keydown="onRowKey($event, u)">
                            <div class="lay-line">
                                <button v-if="u.kind === 'group'" type="button" class="lay-chev" :aria-expanded="!u.collapsed" :aria-label="u.collapsed ? `Open ${u.label}` : `Close ${u.label}`" @click="openGroup(u.id)"><Ico name="p-chev" size="sm" :class="{ 'is-open': !u.collapsed }" /></button>
                                <input v-if="renaming && renaming.id === u.id" ref="renameInput" v-model="renaming.text" class="pr-field lay-rename" type="text" maxlength="120" aria-label="Layer name" @keydown.enter.prevent="finishRename(true)" @keydown.esc.stop.prevent="finishRename(false)" @blur="finishRename(true)" />
                                <button v-else type="button" class="lay-main" :class="{ 'is-sel': u.selected, 'is-part': u.partial, 'is-off': u.hide }" :aria-pressed="u.selected" :title="u.role ? `${u.label} (role: ${u.role})` : u.label" @click="pick(u, $event)" @dblclick="u.kind === 'el' && startRename(u.id, u.label)">
                                    <Ico :name="glyph(u.type, u.shape)" size="sm" class="lay-ico" /><span class="lay-name">{{ u.label }}</span>
                                </button>
                            </div>
                            <ul v-if="u.kind === 'group' && !u.collapsed" class="lay-kids" :aria-label="`${u.label} members`">
                                <li v-for="m in u.members" :key="m.id" class="lay-kid">
                                    <input v-if="renaming && renaming.id === m.id" ref="renameInput" v-model="renaming.text" class="pr-field lay-rename" type="text" maxlength="120" aria-label="Layer name" @keydown.enter.prevent="finishRename(true)" @keydown.esc.stop.prevent="finishRename(false)" @blur="finishRename(true)" />
                                    <button v-else type="button" class="lay-main" :class="{ 'is-sel': m.selected, 'is-off': m.hide }" :aria-pressed="m.selected" @click="pickMember(m, $event)" @dblclick="startRename(m.id, m.label)" @keydown.f2.prevent="startRename(m.id, m.label)">
                                        <Ico :name="glyph(m.type, m.kind)" size="sm" class="lay-ico" /><span class="lay-name">{{ m.label }}</span>
                                    </button>
                                    <span class="lay-acts">
                                        <IconButton v-if="m.selected" size="sm" icon="p-rename" :label="`Rename ${m.label}`" @click="startRename(m.id, m.label)" />
                                        <IconButton size="sm" :icon="m.lock ? 'p-lock' : 'p-unlock'" :label="m.lock ? `Unlock ${m.label}` : `Lock ${m.label}`" :pressed="m.lock" @click="toggle('lock', [m.id])" />
                                        <IconButton size="sm" :icon="m.hide ? 'eyeOff' : 'eye'" :label="m.hide ? `Show ${m.label}` : `Hide ${m.label}`" :pressed="m.hide" @click="toggle('hide', [m.id])" />
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </template>
                    <template #actions="{ item: u }">
                        <IconButton v-if="u.kind === 'el' && u.selected" size="sm" icon="p-rename" :label="`Rename ${u.label}`" @click="startRename(u.id, u.label)" />
                        <IconButton size="sm" :icon="u.lock ? 'p-lock' : 'p-unlock'" :label="u.lock ? `Unlock ${u.label}` : `Lock ${u.label}`" :pressed="u.lock" @click="toggle('lock', u.ids)" />
                        <IconButton size="sm" :icon="u.hide ? 'eyeOff' : 'eye'" :label="u.hide ? `Show ${u.label}` : `Hide ${u.label}`" :pressed="u.hide" @click="toggle('hide', u.ids)" />
                    </template>
            </ReorderList>
            <ul v-else class="c-list" aria-label="Layers, front first">
                    <ListRow v-for="u in units" :key="u.key">
                        <div class="lay-cell">
                            <div class="lay-line">
                                <button v-if="u.kind === 'group'" type="button" class="lay-chev" :aria-expanded="!u.collapsed" :aria-label="u.collapsed ? `Open ${u.label}` : `Close ${u.label}`" @click="openGroup(u.id)"><Ico name="p-chev" size="sm" :class="{ 'is-open': !u.collapsed }" /></button>
                                <button type="button" class="lay-main" :class="{ 'is-sel': u.selected, 'is-part': u.partial, 'is-off': u.hide }" :aria-pressed="u.selected" @click="pick(u, $event)">
                                    <Ico :name="glyph(u.type, u.shape)" size="sm" class="lay-ico" /><span class="lay-name">{{ u.label }}</span>
                                </button>
                            </div>
                            <ul v-if="u.kind === 'group' && !u.collapsed" class="lay-kids">
                                <li v-for="m in u.members" :key="m.id" class="lay-kid">
                                    <button type="button" class="lay-main" :class="{ 'is-sel': m.selected }" :aria-pressed="m.selected" @click="pickMember(m, $event)"><Ico :name="glyph(m.type, m.kind)" size="sm" class="lay-ico" /><span class="lay-name">{{ m.label }}</span></button>
                                </li>
                            </ul>
                        </div>
                    </ListRow>
            </ul>
        </div>
        <p v-if="units.length" class="pp-note">Top of the list is the front. Drag a handle, or focus it and press the Up or Down arrow. Double click a name to rename it.</p>
    </PanelSection>
</template>

<style>
.lay { max-height: min(46vh, 440px); overflow-y: auto; margin: 0 -6px; padding: 2px 6px; scrollbar-width: thin; scrollbar-color: var(--line-strong) transparent; }
.lay .c-list { gap: 3px; }
.lay .c-row { align-items: flex-start; }
.lay .c-row-h { height: 34px; }
.lay .c-row-c { padding: 0; }
.lay .c-row-a { align-self: flex-start; padding-top: 3px; }
.lay .c-row:has(.lay-main.is-sel) { background: var(--sheet); border-color: var(--ink); }
.lay .c-row:has(.lay-main.is-part) { border-color: var(--line-strong); }
.lay-cell { display: grid; min-width: 0; }
.lay-line { display: flex; align-items: center; min-width: 0; }
.lay-main { flex: 1; min-width: 0; display: flex; align-items: center; gap: 8px; min-height: 34px; padding: 0 6px; border: 0; border-radius: var(--r-1); background: transparent; color: var(--ink); text-align: left; font-size: var(--t-sm); font-weight: 500; }
.lay-main:hover { background: color-mix(in srgb, var(--ink) 5%, transparent); }
.lay-main.is-off { color: var(--faint); }
.lay-main.is-off .lay-name { text-decoration: line-through; text-decoration-color: var(--line-strong); }
.lay-ico { flex: none; color: var(--ink-2); }
.lay-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lay-chev { flex: none; display: grid; place-items: center; width: 24px; height: 34px; padding: 0; border: 0; background: transparent; color: var(--muted); border-radius: var(--r-1); }
.lay-chev:hover { color: var(--ink); }
.lay-chev .pr-ico { transition: transform var(--d-1) var(--ease-snap); }
.lay-chev .pr-ico.is-open { transform: rotate(90deg); }
.lay-rename { flex: 1; min-width: 0; height: 30px; margin: 2px 4px; }
.lay-kids { list-style: none; margin: 0 0 2px 11px; padding: 0 0 0 8px; display: grid; gap: 1px; border-left: 1.5px solid var(--line-strong); }
.lay-kid { display: flex; align-items: center; min-width: 0; border-radius: var(--r-1); }
.lay-kid .lay-main { min-height: 30px; }
.lay-kid .lay-main.is-sel { background: color-mix(in srgb, var(--ink) 10%, transparent); font-weight: 600; }
.lay-acts { display: flex; flex: none; }
.lay-main.is-sel { font-weight: 600; }
@media (pointer: coarse) {
    .lay-main { min-height: 44px; }
    .lay-chev { width: 36px; height: 44px; }
    .lay-kid .lay-main { min-height: 44px; }
    .lay .c-row-a { padding-top: 0; }
}
@media (prefers-reduced-motion: reduce) { .lay-chev .pr-ico { transition: none; } }
</style>
