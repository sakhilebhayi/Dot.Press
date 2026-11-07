<script setup>
// Arrange: where the selection sits and how it stacks. Position and size, align and distribute, group, lock and hide, flip, order,
// copy and paste style, and the layers of the whole slide. Works for any selection (one element or many).
import { computed, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import {
    IconButton, NumberField, PanelSection, Segmented, usePanelChange,
} from '../../../ui/controls/index.js';
import Ico from '../../ui/Ico.vue';
import './glyphs.js';
import './panels.css';
import LayersList from './LayersList.vue';
import {
    canAlign, canDistribute, canMatchSize, framePatches, groupState, isLine, selectionFrame, unitCount,
} from './arrangeModel.js';
import { describeStyle, pasteStylePatches, pickStyle } from './styleModel.js';
import { setStyleClip, styleClip } from './styleClipboard.js';

const editor = useEditorContext();
const { store } = editor;
const pc = usePanelChange(editor);
const els = pc.elements;
const can = pc.canEdit;
const deck = computed(() => editor.deck.value);

const lockRatio = ref(false);
const frame = computed(() => { void editor.state.value; return selectionFrame(els.value); });
const lines = computed(() => els.value.length > 0 && els.value.every(isLine));
const ids = computed(() => els.value.map((e) => e.id).join(','));
const unit = computed(() => (deck.value?.w || 1920) / 1920);
const n = computed(() => els.value.length);

const rot = computed(() => pc.value('rot', { fallback: 0 }));
function field(key) {
    const f = frame.value;
    return f ? f[key] : null;
}
function setFrame(key, value) {
    if (!can.value) return;
    const patches = framePatches(els.value, key, value, { lockRatio: lockRatio.value, unit: unit.value });
    if (patches.length) store.patch(patches, { undoKey: `panel:frame:${key}:${ids.value}`, label: key === 'rot' ? 'Rotate' : 'Move and resize' });
}

// ---- align and distribute ----------------------------------------------------------------------------------------
const alignChoice = ref(null);
const alignTo = computed(() => alignChoice.value || (unitCount(els.value) >= 2 ? 'selection' : 'slide'));
const distMode = ref('gaps');
const ALIGNS = [
    { id: 'left', icon: 'p-aL', label: 'Align left' },
    { id: 'center', icon: 'p-aCh', label: 'Align centres horizontally' },
    { id: 'right', icon: 'p-aR', label: 'Align right' },
    { id: 'top', icon: 'p-aT', label: 'Align top' },
    { id: 'middle', icon: 'p-aMv', label: 'Align middles vertically' },
    { id: 'bottom', icon: 'p-aB', label: 'Align bottom' },
];
const alignOk = computed(() => can.value && canAlign(els.value, alignTo.value));
const distOk = computed(() => can.value && canDistribute(els.value, alignTo.value));
const matchOk = computed(() => can.value && canMatchSize(els.value));
function align(mode) { if (store.align(mode, { to: alignTo.value })) editor.announce?.(`Aligned ${mode}`); }
function distribute(axis) { if (store.distribute(axis, { to: alignTo.value, mode: distMode.value })) editor.announce?.(axis === 'h' ? 'Distributed horizontally' : 'Distributed vertically'); }
const alignHint = computed(() => {
    if (alignTo.value === 'slide') return 'Edges meet the slide margin. Centres use the slide centre.';
    return unitCount(els.value) < 2 ? 'Select two or more to align them to each other.' : 'Aligns to the box around the selection.';
});
const distHint = computed(() => {
    if (distOk.value) return '';
    return alignTo.value === 'slide' ? 'Select two or more to spread them across the slide.' : 'Select three or more to spread them out.';
});

// ---- group, lock, hide, flip, order -----------------------------------------------------------------------------
const gstate = computed(() => groupState(els.value));
const allLocked = computed(() => n.value > 0 && els.value.every((e) => e.lock));
const allHidden = computed(() => n.value > 0 && els.value.every((e) => e.hide));
const flippable = computed(() => els.value.some((e) => !['text', 'table', 'chart', 'audio'].includes(e.type)));
const act = (fn) => { if (can.value) fn(); };

// ---- style clipboard ---------------------------------------------------------------------------------------------
const pasteLabel = computed(() => describeStyle(styleClip.value));
function copyStyle() {
    if (n.value !== 1) return;
    const s = pickStyle(els.value[0]);
    if (!s) { editor.toast?.('This element has no style to copy', { kind: 'warn' }); return; }
    setStyleClip(s);
    editor.toast?.(`Style copied: ${describeStyle(s).toLowerCase()}`, { kind: 'ok' });
    editor.announce?.('Style copied');
}
function pasteStyle() {
    if (!can.value || !styleClip.value) return;
    const patches = pasteStylePatches(styleClip.value, els.value);
    if (!patches.length) { editor.toast?.('Nothing to change: the look already matches'); return; }
    store.patch(patches, { undoKey: null, label: 'Paste style' });
    editor.announce?.(patches.length === 1 ? 'Style pasted' : `Style pasted on ${patches.length} elements`);
}
const DISTS = [{ value: 'gaps', label: 'Equal gaps' }, { value: 'centers', label: 'Equal centres' }];
const TO = [{ value: 'slide', label: 'Slide' }, { value: 'selection', label: 'Selection' }];
</script>

<template>
    <div class="pp ar" data-panel-body="arrange">
        <PanelSection id="arrange.frame" title="Position and size" :state="n > 1 ? `${n} selected` : ''">
            <div class="c-grid2">
                <NumberField label="X" unit="px" :model-value="field('x')" :disabled="!can" :step="1" @update:model-value="(v) => setFrame('x', v)" />
                <NumberField label="Y" unit="px" :model-value="field('y')" :disabled="!can" :step="1" @update:model-value="(v) => setFrame('y', v)" />
            </div>
            <div class="ar-size">
                <div class="c-grid2">
                    <NumberField label="W" unit="px" :model-value="field('w')" :min="0" :disabled="!can" @update:model-value="(v) => setFrame('w', v)" />
                    <NumberField label="H" unit="px" :model-value="lines ? 0 : field('h')" :min="0" :disabled="!can || lines" @update:model-value="(v) => setFrame('h', v)" />
                </div>
                <IconButton :icon="lockRatio ? 'p-lock' : 'p-unlock'" :label="lockRatio ? 'Ratio locked. Press to unlock' : 'Lock the width to height ratio'" :pressed="lockRatio" :disabled="!can || lines" @click="lockRatio = !lockRatio" />
            </div>
            <NumberField label="Rotate" unit="deg" :model-value="rot" :min="-360" :max="360" :step="1" :disabled="!can" @update:model-value="(v) => setFrame('rot', v)" />
            <p v-if="lines" class="pp-note">A line is as thick as its stroke. Change that in the Shape tab.</p>
        </PanelSection>

        <PanelSection id="arrange.align" title="Align and distribute">
            <Segmented :model-value="alignTo" label="Align to" :options="TO" :disabled="!can" @update:model-value="(v) => (alignChoice = v)" />
            <div class="pp-group" role="group" aria-label="Align">
                <div class="pp-tools">
                    <IconButton v-for="a in ALIGNS.slice(0, 3)" :key="a.id" :icon="a.icon" :label="a.label" :disabled="!alignOk" @click="align(a.id)" />
                    <span class="pp-sep" aria-hidden="true"></span>
                    <IconButton v-for="a in ALIGNS.slice(3)" :key="a.id" :icon="a.icon" :label="a.label" :disabled="!alignOk" @click="align(a.id)" />
                </div>
                <p class="pp-note">{{ alignHint }}</p>
            </div>
            <div class="pp-group" role="group" aria-label="Distribute">
                <span class="pp-cap">Distribute</span>
                <div class="pp-tools">
                    <IconButton icon="p-distH" label="Distribute horizontally" :disabled="!distOk" @click="distribute('h')" />
                    <IconButton icon="p-distV" label="Distribute vertically" :disabled="!distOk" @click="distribute('v')" />
                    <span class="pp-sep" aria-hidden="true"></span>
                    <IconButton icon="p-matchW" label="Match width" :disabled="!matchOk" @click="store.matchSize('w')" />
                    <IconButton icon="p-matchH" label="Match height" :disabled="!matchOk" @click="store.matchSize('h')" />
                </div>
                <Segmented v-model="distMode" label="Spacing" hide-label :options="DISTS" :disabled="!distOk" />
                <p v-if="distHint" class="pp-note">{{ distHint }}</p>
                <p v-else-if="matchOk" class="pp-note">Match size follows the element nearest the back.</p>
            </div>
        </PanelSection>

        <PanelSection id="arrange.order" title="Group and order">
            <div class="pp-btns">
                <button type="button" class="pr-btn pr-btn--sm" :disabled="!can || n < 2 || (gstate === 'grouped' && unitCount(els) < 2)" @click="act(() => store.group())"><span class="pp-btn-ico"><Ico name="p-group" size="sm" />Group</span></button>
                <button type="button" class="pr-btn pr-btn--sm" :disabled="!can || gstate === 'none'" @click="act(() => store.ungroup())"><span class="pp-btn-ico"><Ico name="p-ungroup" size="sm" />Ungroup</span></button>
            </div>
            <div class="pp-group" role="group" aria-label="Lock, hide and flip">
                <div class="pp-tools">
                    <IconButton :icon="allLocked ? 'p-lock' : 'p-unlock'" :label="allLocked ? 'Unlock' : 'Lock'" :pressed="allLocked" :disabled="!can" @click="act(() => store.toggleLock())" />
                    <IconButton :icon="allHidden ? 'eyeOff' : 'eye'" :label="allHidden ? 'Show' : 'Hide'" :pressed="allHidden" :disabled="!can" @click="act(() => store.toggleHide())" />
                    <span class="pp-sep" aria-hidden="true"></span>
                    <IconButton icon="p-flipH" label="Flip horizontally" :disabled="!can || !flippable" @click="act(() => store.flip('h'))" />
                    <IconButton icon="p-flipV" label="Flip vertically" :disabled="!can || !flippable" @click="act(() => store.flip('v'))" />
                </div>
                <p v-if="!flippable" class="pp-note">Text, tables and charts cannot be flipped.</p>
            </div>
            <div class="pp-group" role="group" aria-label="Order">
                <span class="pp-cap">Order</span>
                <div class="pp-tools">
                    <IconButton icon="p-front" label="Bring to front" :disabled="!can" @click="act(() => store.front())" />
                    <IconButton icon="p-forward" label="Bring forward" :disabled="!can" @click="act(() => store.forward())" />
                    <IconButton icon="p-backward" label="Send backward" :disabled="!can" @click="act(() => store.backward())" />
                    <IconButton icon="p-back" label="Send to back" :disabled="!can" @click="act(() => store.back())" />
                </div>
            </div>
        </PanelSection>

        <PanelSection id="arrange.style" title="Style" :state="styleClip ? 'held' : ''">
            <div class="pp-btns">
                <button type="button" class="pr-btn pr-btn--sm" :disabled="n !== 1" :title="n === 1 ? 'Remember this element\'s fill, stroke, font, shadow and opacity' : 'Select one element to copy its style'" @click="copyStyle"><span class="pp-btn-ico"><Ico name="p-copyStyle" size="sm" />Copy style</span></button>
                <button type="button" class="pr-btn pr-btn--sm" :disabled="!can || !styleClip" @click="pasteStyle"><span class="pp-btn-ico"><Ico name="p-pasteStyle" size="sm" />Paste style</span></button>
            </div>
            <p class="pp-note"><template v-if="styleClip">Holding <b>{{ pasteLabel }}</b> from a {{ styleClip.line ? 'line' : styleClip.from }}.</template><template v-else>Copy one element, then paste its look onto others. Content and position stay put.</template></p>
        </PanelSection>

        <LayersList />
    </div>
</template>

<style>
.ar-size { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: center; }
</style>
