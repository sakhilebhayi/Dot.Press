<script setup>
// Shape: kind, fill, stroke, corners, line ends, shadow, opacity and flip for shapes and lines. Theme swatches on top give a one
// click look that follows the theme.
import { computed } from 'vue';
import { useEditorContext } from '../../context.js';
import {
    ColorField, IconButton, NumberField, PanelSection, Segmented, Slider, Toggle, usePanelChange,
} from '../../../ui/controls/index.js';
import { resolveColor } from '../../core/theme.js';
import '../arrange/glyphs.js';
import '../arrange/panels.css';
import FillControl from './FillControl.vue';
import {
    DASHES, ENDS, ENDS_START, KIND_ROWS, defaultShadow, defaultStrokeWidth, endsPatch, isLineKind, kindSwapPatch, quickStylePatch, quickStyles,
} from './shapeModel.js';

const editor = useEditorContext();
const { store } = editor;
const sub = Object.create(editor);
sub.selected = computed(() => editor.selected.value.filter((e) => e.type === 'shape'));
const pc = usePanelChange(sub);
const can = pc.canEdit;
const els = pc.elements;
const deck = computed(() => editor.deck.value);
const theme = computed(() => deck.value?.theme || {});
const skipped = computed(() => editor.selected.value.length - els.value.length);
const hasBox = computed(() => els.value.some((e) => !isLineKind(e.kind)));
const hasLine = computed(() => els.value.some((e) => isLineKind(e.kind)));
const boxOnly = computed(() => els.value.filter((e) => !isLineKind(e.kind)));
const lineOnly = computed(() => els.value.filter((e) => isLineKind(e.kind)));
const boxPc = usePanelChange(Object.assign(Object.create(editor), { selected: boxOnly }));
const linePc = usePanelChange(Object.assign(Object.create(editor), { selected: lineOnly }));

// ---- kind ------------------------------------------------------------------------------------------------------------
const kind = computed(() => pc.value('kind'));
const kindRows = computed(() => KIND_ROWS.map((r) => ({ ...r, show: true })));
function swap(k) {
    if (!can.value) return;
    const patches = els.value.map((e) => ({ id: e.id, p: kindSwapPatch(e, k, deck.value) })).filter((p) => p.p);
    if (patches.length) store.patch(patches, { undoKey: null, label: 'Change shape' });
}
const kindLabel = computed(() => {
    for (const r of KIND_ROWS) for (const [v, t] of r.kinds) if (v === kind.value) return t;
    return kind.value === 'mixed' ? 'mixed' : '';
});

// ---- quick styles ----------------------------------------------------------------------------------------------------
const allStyles = quickStyles();
const styles = computed(() => (hasBox.value ? allStyles : allStyles.filter((s) => s.id !== 'outline')));
const styleCss = (s) => {
    const fill = s.fill ? resolveColor(s.fill, theme.value) : null;
    const stroke = s.stroke ? resolveColor(s.stroke, theme.value) : null;
    return { background: fill || 'transparent', boxShadow: stroke ? `inset 0 0 0 2px ${stroke}` : 'none' };
};
function applyQuick(s) {
    if (!can.value) return;
    const patches = els.value.map((e) => ({ id: e.id, p: quickStylePatch(e, s, deck.value) }));
    store.patch(patches, { undoKey: null, label: `${s.label} style` });
    editor.announce?.(`${s.label} style applied`);
}
const quickOn = (s) => {
    const list = hasBox.value ? boxOnly.value : lineOnly.value;
    return list.length > 0 && list.every((e) => {
        const p = quickStylePatch(e, s, deck.value);
        return Object.entries(p).every(([k, v]) => (e[k] ?? null) === (v ?? null) || (k === 'sw' && v > 0 && e.sw > 0));
    });
};

// ---- stroke ----------------------------------------------------------------------------------------------------------
const strokeColour = computed(() => pc.value('stroke'));
const strokeWidth = computed(() => pc.value('sw', { fallback: 0 }));
function setStroke(c) {
    if (c == null || c === '') {
        // a line has no other colour: removing its stroke would make it vanish, so it keeps the stroke and the colour goes to the default
        pc.each((e) => (isLineKind(e.kind) ? { stroke: '@ink' } : { stroke: null, sw: 0 }), { label: 'Remove stroke' });
        return;
    }
    pc.each((e) => ({ stroke: c, ...(!(e.sw > 0) ? { sw: isLineKind(e.kind) ? defaultStrokeWidth(deck.value) * 2 : defaultStrokeWidth(deck.value) } : {}) }), { label: 'Stroke colour', undoKey: `panel:stroke:${els.value.map((e) => e.id)}` });
}
const swMax = 500;

// ---- corners ---------------------------------------------------------------------------------------------------------
const rects = computed(() => els.value.filter((e) => e.kind === 'rect'));
const rectPc = usePanelChange(Object.assign(Object.create(editor), { selected: rects }));
const radiusMax = computed(() => Math.max(1, Math.round(Math.min(...rects.value.map((e) => Math.min(e.w, e.h) / 2)) || 1)));

// ---- ends ------------------------------------------------------------------------------------------------------------
function endNow(which) {
    const vals = lineOnly.value.map((e) => endsPatch(e, which, undefined)[which]);
    return vals.length && vals.every((v) => v === vals[0]) ? vals[0] : (vals.length ? 'mixed' : null);
}
function setEnd(which, value) {
    linePc.each((e) => ({ ends: endsPatch(e, which, value) }), { label: 'Line end', undoKey: null });
}

// ---- shadow ----------------------------------------------------------------------------------------------------------
const shadowOn = computed(() => {
    const on = els.value.map((e) => !!e.shadow);
    return on.length && on.every((v) => v === on[0]) ? on[0] : 'mixed';
});
function toggleShadow(v) {
    if (v) pc.each((e) => (e.shadow ? {} : { shadow: defaultShadow(deck.value) }), { label: 'Add shadow' });
    else pc.reset('shadow', { label: 'Remove shadow' });
}
const sh = (k, fb) => pc.value(`shadow.${k}`, { fallback: fb });
const setSh = (k, v) => pc.set(`shadow.${k}`, v, { label: 'Shadow' });

// ---- opacity and flip ------------------------------------------------------------------------------------------------
const opacity = computed(() => { const v = pc.value('op', { fallback: 1 }); return v === 'mixed' ? v : Math.round(v * 100); });
const flippable = computed(() => els.value.length > 0);
</script>

<template>
    <div class="pp sh" data-panel-body="shape">
        <div v-if="skipped > 0" class="pp-lead"><p class="pp-note">Changes apply to the {{ els.length }} shape{{ els.length === 1 ? '' : 's' }} in your selection.</p></div>

        <PanelSection id="shape.style" title="Style">
            <div class="pp-swatches" role="group" aria-label="Quick styles from the theme">
                <button v-for="s in styles" :key="s.id" type="button" class="pp-swatch" :aria-pressed="quickOn(s)" :disabled="!can" @click="applyQuick(s)">
                    <span class="pp-swatch-chip" aria-hidden="true"><i class="pp-swatch-fill" :style="styleCss(s)"></i></span>
                    <span class="pp-swatch-n">{{ s.label }}</span>
                </button>
            </div>
        </PanelSection>

        <PanelSection id="shape.kind" title="Shape" :state="kindLabel">
            <div class="pp-group">
                <Segmented v-for="r in kindRows" :key="r.id" :model-value="r.options.some((o) => o.value === kind) ? kind : null" :label="r.label" hide-label :options="r.options" :disabled="!can" @update:model-value="swap" />
            </div>
        </PanelSection>

        <PanelSection v-if="hasBox" id="shape.fill" title="Fill">
            <FillControl :model-value="boxPc.value('fill')" label="Fill" hide-label :disabled="!can" @update:model-value="(v) => boxPc.set('fill', v, { label: 'Fill' })" />
        </PanelSection>

        <PanelSection id="shape.stroke" title="Stroke">
            <ColorField :model-value="strokeColour === undefined ? null : strokeColour" :label="hasLine && !hasBox ? 'Line colour' : 'Stroke colour'" :allow-none="!hasLine || hasBox" :disabled="!can" @update:model-value="setStroke" />
            <div class="c-grid2">
                <NumberField :label="hasLine && !hasBox ? 'Thickness' : 'Width'" unit="px" :model-value="strokeWidth" :min="hasLine && !hasBox ? 1 : 0" :max="swMax" :step="1" :disabled="!can" @update:model-value="(v) => pc.set('sw', v, { label: 'Stroke width' })" />
            </div>
            <Segmented :model-value="pc.value('dash', { fallback: 'solid' })" label="Line style" :options="DASHES" :disabled="!can" @update:model-value="(v) => pc.set('dash', v, { label: 'Dash' })" />
        </PanelSection>

        <PanelSection v-if="rects.length" id="shape.corners" title="Corners">
            <NumberField label="Radius" unit="px" :model-value="rectPc.value('radius', { fallback: 0 })" :min="0" :max="radiusMax" :step="1" :disabled="!can" @update:model-value="(v) => rectPc.set('radius', v, { label: 'Corner radius' })" />
            <Slider :model-value="rectPc.value('radius', { fallback: 0 })" label="Radius slider" hide-label :min="0" :max="radiusMax" :step="1" :disabled="!can" @update:model-value="(v) => rectPc.set('radius', v, { label: 'Corner radius' })" />
        </PanelSection>

        <PanelSection v-if="hasLine" id="shape.ends" title="Line ends">
            <Segmented :model-value="endNow('s')" label="Start" :options="ENDS_START" :disabled="!can" @update:model-value="(v) => setEnd('s', v)" />
            <Segmented :model-value="endNow('e')" label="End" :options="ENDS" :disabled="!can" @update:model-value="(v) => setEnd('e', v)" />
        </PanelSection>

        <PanelSection id="shape.shadow" title="Shadow" :state="shadowOn === true ? 'on' : ''">
            <Toggle :model-value="shadowOn" label="Drop shadow" :disabled="!can" @update:model-value="toggleShadow" />
            <template v-if="shadowOn === true">
                <div class="c-grid3">
                    <NumberField label="X" :model-value="sh('x', 0)" :min="-1000" :max="1000" :disabled="!can" @update:model-value="(v) => setSh('x', v)" />
                    <NumberField label="Y" :model-value="sh('y', 0)" :min="-1000" :max="1000" :disabled="!can" @update:model-value="(v) => setSh('y', v)" />
                    <NumberField label="Blur" :model-value="sh('blur', 0)" :min="0" :max="1000" :disabled="!can" @update:model-value="(v) => setSh('blur', v)" />
                </div>
                <ColorField :model-value="sh('c', '#00000040')" label="Shadow colour" :disabled="!can" @update:model-value="(v) => setSh('c', v)" />
            </template>
        </PanelSection>

        <PanelSection id="shape.look" title="Opacity and flip">
            <Slider :model-value="opacity" label="Opacity" :min="0" :max="100" :step="1" unit="%" :disabled="!can" @update:model-value="(v) => pc.set('op', Math.round(v) / 100, { label: 'Opacity' })" />
            <div class="pp-group" role="group" aria-label="Flip">
                <div class="pp-tools">
                    <IconButton icon="p-flipH" label="Flip horizontally" :disabled="!can || !flippable" @click="store.flip('h')" />
                    <IconButton icon="p-flipV" label="Flip vertically" :disabled="!can || !flippable" @click="store.flip('v')" />
                </div>
            </div>
        </PanelSection>
    </div>
</template>
