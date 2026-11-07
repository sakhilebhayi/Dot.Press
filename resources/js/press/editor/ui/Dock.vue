<script setup>
// The dock under the stage: insert tools on the left, history, zoom and view toggles on the right.
import { computed } from 'vue';
import { useEditorContext } from '../context.js';
import DropMenu from './DropMenu.vue';
import Ico from './Ico.vue';

const editor = useEditorContext();
const { ui, store, state } = editor;
const locked = editor.readOnly;

const SHAPES = [
    ['rect', 'Rectangle'], ['round', 'Rounded rectangle'], ['ellipse', 'Ellipse'], ['triangle', 'Triangle'], ['diamond', 'Diamond'],
    ['pentagon', 'Pentagon'], ['hexagon', 'Hexagon'], ['star', 'Star'], ['chevron', 'Chevron'], ['arrow-r', 'Arrow right'],
    ['arrow-l', 'Arrow left'], ['callout', 'Callout'], ['cross', 'Cross'], ['heart', 'Heart'],
];
const shapeItems = SHAPES.map(([kind, label]) => ({ label, icon: kind, run: () => setTool(`shape:${kind}`) }));
const lineItems = [
    { label: 'Line', icon: 'line', run: () => setTool('line') },
    { label: 'Arrow', icon: 'arrow', run: () => setTool('arrow') },
];

const INSERT = [
    { tool: 'image', label: 'Picture', icon: 'image' },
    { tool: 'chart', label: 'Chart', icon: 'chart' },
    { tool: 'table', label: 'Table', icon: 'table' },
    { tool: 'icon', label: 'Icon', icon: 'icon' },
    { tool: 'video', label: 'Video', icon: 'video' },
    { tool: 'audio', label: 'Audio', icon: 'audio' },
];

function setTool(t) {
    if (locked) return;
    ui.tool = ui.tool === t ? 'select' : t;
}
function insert(kind) {
    if (locked) return;
    if (editor.hasPanel('insert')) { editor.openPanel('insert', { kind }); return; }
    setTool(kind);
}
const isShape = computed(() => ui.tool.startsWith('shape:'));
const isLine = computed(() => ui.tool === 'line' || ui.tool === 'arrow');
const shapeIcon = computed(() => (isShape.value ? ui.tool.slice(6) : 'shape'));
const lineIcon = computed(() => (ui.tool === 'arrow' ? 'arrow' : 'line'));

const insertItems = INSERT.map((t) => ({ label: t.label, icon: t.icon, run: () => insert(t.tool) }));
const pct = computed(() => `${Math.round(state.value.zoom * 100)}%`);
const fit = computed(() => state.value.zoomMode === 'fit');
const zoomItems = computed(() => [
    { label: 'Fit to window', checked: fit.value, shortcut: editor.mac ? '⌘0' : 'Ctrl+0', run: () => store.zoomFit() },
    ...[0.5, 0.75, 1, 1.5, 2].map((z) => ({ label: `${Math.round(z * 100)}%`, checked: !fit.value && Math.abs(state.value.zoom - z) < 0.005, shortcut: z === 1 ? (editor.mac ? '⌘1' : 'Ctrl+1') : undefined, run: () => store.setZoom(z) })),
]);
const viewItems = computed(() => toggles.value.map((t) => ({ label: t.label, icon: t.icon, checked: t.on, run: () => { ui[t.key] = !ui[t.key]; } })));
const toggles = computed(() => [
    { key: 'grid', icon: 'grid', label: 'Grid', on: ui.grid },
    { key: 'guides', icon: 'guides', label: 'Guides', on: ui.guides },
    { key: 'rulers', icon: 'rulers', label: 'Rulers', on: ui.rulers },
    { key: 'margins', icon: 'margins', label: 'Margins', on: ui.margins },
    { key: 'snap', icon: 'snap', label: 'Snap', on: ui.snap },
]);
</script>

<template>
    <div class="dk" role="toolbar" aria-label="Insert and view tools">
        <button type="button" class="dk-b dk-insp" :aria-pressed="ui.inspector.open" aria-controls="ed-insp" aria-label="Inspector" data-tip="Inspector" data-tip-up @click="editor.toggleInspector()"><Ico name="inspector" /></button>
        <div class="dk-group" role="group" aria-label="Insert">
            <button type="button" class="dk-b" :aria-pressed="ui.tool === 'select'" aria-label="Select" data-tip="Select" data-tip-up @click="ui.tool = 'select'"><Ico name="select" /></button>
            <button type="button" class="dk-b dk-hand" :aria-pressed="ui.tool === 'hand'" aria-label="Hand, move around the slide" data-tip="Hand" data-tip-up @click="ui.tool = ui.tool === 'hand' ? 'select' : 'hand'"><Ico name="hand" /></button>
            <span class="dk-sep" aria-hidden="true"></span>
            <button type="button" class="dk-b" :disabled="locked" :aria-pressed="ui.tool === 'text'" aria-label="Text" data-tip="Text" data-tip-up @click="setTool('text')"><Ico name="text" /></button>
            <DropMenu :items="shapeItems" up label="Shapes">
                <template #trigger="{ toggle, open }">
                    <button type="button" class="dk-b dk-b--menu" :disabled="locked" :aria-pressed="isShape" aria-haspopup="menu" :aria-expanded="open" aria-label="Shapes" data-tip="Shapes" data-tip-up @click="toggle"><Ico :name="shapeIcon" /><Ico name="chevronDown" size="sm" class="dk-caret" /></button>
                </template>
            </DropMenu>
            <DropMenu :items="lineItems" up label="Lines">
                <template #trigger="{ toggle, open }">
                    <button type="button" class="dk-b dk-b--menu" :disabled="locked" :aria-pressed="isLine" aria-haspopup="menu" :aria-expanded="open" aria-label="Line or arrow" data-tip="Line" data-tip-up @click="toggle"><Ico :name="lineIcon" /><Ico name="chevronDown" size="sm" class="dk-caret" /></button>
                </template>
            </DropMenu>
            <button v-for="t in INSERT" :key="t.tool" type="button" class="dk-b dk-ins" :disabled="locked" :aria-pressed="ui.tool === t.tool" :aria-label="t.label" :data-tip="t.label" data-tip-up @click="insert(t.tool)"><Ico :name="t.icon" /></button>
            <DropMenu :items="insertItems" up label="Insert" class="dk-insmenu">
                <template #trigger="{ toggle, open }">
                    <button type="button" class="dk-b dk-b--menu" :disabled="locked" :aria-pressed="INSERT.some((t) => t.tool === ui.tool)" aria-haspopup="menu" :aria-expanded="open" aria-label="Insert a picture, chart, table, icon, video or audio" @click="toggle"><Ico name="plus" /><Ico name="chevronDown" size="sm" class="dk-caret" /></button>
                </template>
            </DropMenu>
        </div>

        <div class="dk-group dk-group--end">
            <div class="dk-group" role="group" aria-label="History">
                <button type="button" class="dk-b" :disabled="locked || !state.canUndo" aria-label="Undo" :data-tip="state.undoLabel ? `Undo ${state.undoLabel}` : 'Undo'" data-tip-up @click="store.undo()"><Ico name="undo" /></button>
                <button type="button" class="dk-b" :disabled="locked || !state.canRedo" aria-label="Redo" :data-tip="state.redoLabel ? `Redo ${state.redoLabel}` : 'Redo'" data-tip-up @click="store.redo()"><Ico name="redo" /></button>
            </div>
            <span class="dk-sep" aria-hidden="true"></span>
            <div class="dk-group" role="group" aria-label="Zoom">
                <button type="button" class="dk-b" aria-label="Zoom out" data-tip="Zoom out" data-tip-up @click="store.zoomOut()"><Ico name="minus" /></button>
                <DropMenu :items="zoomItems" up align="end" label="Zoom" :min-width="180">
                    <template #trigger="{ toggle, open }">
                        <button type="button" class="dk-zoom pr-state" aria-haspopup="menu" :aria-expanded="open" :aria-label="`Zoom ${pct}${fit ? ', fitted' : ''}`" @click="toggle"><span>{{ pct }}</span><span v-if="fit" class="dk-fit">fit</span></button>
                    </template>
                </DropMenu>
                <button type="button" class="dk-b" aria-label="Zoom in" data-tip="Zoom in" data-tip-up @click="store.zoomIn()"><Ico name="plus" /></button>
            </div>
            <span class="dk-sep" aria-hidden="true"></span>
            <div class="dk-group dk-aids" role="group" aria-label="View aids">
                <button v-for="t in toggles" :key="t.key" type="button" class="dk-b" :aria-pressed="t.on" :aria-label="t.label" :data-tip="t.label" data-tip-up @click="ui[t.key] = !ui[t.key]"><Ico :name="t.icon" /></button>
            </div>
            <DropMenu :items="viewItems" up align="end" label="View aids" class="dk-viewmenu">
                <template #trigger="{ toggle, open }">
                    <button type="button" class="dk-b dk-b--menu" aria-haspopup="menu" :aria-expanded="open" aria-label="View aids" @click="toggle"><Ico name="grid" /><Ico name="chevronDown" size="sm" class="dk-caret" /></button>
                </template>
            </DropMenu>
            <span class="dk-sep" aria-hidden="true"></span>
            <button type="button" class="dk-b" :aria-pressed="ui.help" aria-label="Keyboard shortcuts" data-tip="Shortcuts ?" data-tip-up @click="ui.help = !ui.help"><Ico name="help" /></button>
        </div>
    </div>
</template>
