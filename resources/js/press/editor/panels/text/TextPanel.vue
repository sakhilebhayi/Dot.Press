<script setup>
// Text: type, alignment, lists, spacing, colour, fit, effects, the box around the text, link and the theme's style presets.
// For texts and for shapes that hold text. While a text is being edited in place, the marks act on the selected words.
import {
    computed, nextTick, onBeforeUnmount, ref, watch,
} from 'vue';
import { useEditorContext } from '../../context.js';
import {
    ColorField, Dialog, Field, FontPicker, IconButton, InlineNotice, NumberField, PanelSection, SelectMenu, Segmented, Toggle, usePanelChange,
} from '../../../ui/controls/index.js';
import { resolveColor } from '../../core/theme.js';
import { resolveFont } from '../../../render/theme.js';
import { safeHref } from '../../../render/doc.js';
import Ico from '../../ui/Ico.vue';
import '../arrange/glyphs.js';
import '../arrange/panels.css';
import FillControl from '../shape/FillControl.vue';
import { useTextFormat } from './useTextFormat.js';
import {
    bulkRolePlan, docIndent, docListKind, docSetList, fittedSize, isTextual, presetDefs, presetOfSelection, presetPatches, styleOfElement, weightOptions,
} from './textModel.js';

const editor = useEditorContext();
const { store } = editor;

// Only the text-bearing part of the selection is edited here, even when a picture is selected too.
const sub = Object.create(editor);
const all = editor.selected;
sub.selected = computed(() => all.value.filter(isTextual));
const pc = usePanelChange(sub);
const els = pc.elements;
const can = pc.canEdit;
const fmt = useTextFormat(editor, els, pc);
const deck = computed(() => editor.deck.value);
const theme = computed(() => deck.value?.theme || {});
const n = computed(() => els.value.length);
const skipped = computed(() => all.value.length - els.value.length);
const hasTextBox = computed(() => els.value.some((e) => e.type === 'text'));
const live = fmt.live;
const mixed = (v) => v === 'mixed';

// ---- presets ---------------------------------------------------------------------------------------------------------
const presets = computed(() => presetDefs(deck.value));
const currentPreset = computed(() => presetOfSelection(els.value));
function previewStyle(p) {
    const f = resolveFont(p.patch.font, deck.value?.theme);
    return {
        fontFamily: f.stack, fontWeight: p.patch.weight, color: resolveColor(p.patch.color, theme.value) || 'var(--ink)', fontSize: `${Math.min(22, Math.max(14, 12 + p.patch.size / 14))}px`, background: resolveColor('@bg', theme.value) || '#ffffff',
    };
}
function applyPreset(p) {
    if (!can.value) return;
    const patches = presetPatches(els.value, p);
    if (!patches.length) return;
    store.patch(patches, { undoKey: null, label: `${p.label} style` });
    editor.announce?.(`${p.label} style applied`);
}
const single = computed(() => (els.value.length === 1 ? els.value[0] : null));
const roleStyle = computed(() => (single.value?.role ? { patch: styleOfElement(single.value) } : null));
const plan = computed(() => {
    void editor.state.value;
    if (!roleStyle.value) return null;
    return bulkRolePlan(editor.state.value.doc.slides, single.value.role, roleStyle.value);
});
const confirmOpen = ref(false);
function applyToRole() {
    const p = plan.value;
    if (!can.value || !p || !p.ops.length) return;
    store.dispatch(p.ops, { label: 'Apply style to role' });
    confirmOpen.value = false;
    const msg = p.changing === 1 ? 'Restyled 1 text' : `Restyled ${p.changing} texts on ${p.slides} ${p.slides === 1 ? 'slide' : 'slides'}`;
    editor.toast?.(msg, { kind: 'ok', action: { label: 'Undo', run: () => store.undo() } });
    editor.announce?.(msg);
}

// ---- typography ------------------------------------------------------------------------------------------------------
const font = computed(() => (live.value ? fmt.get('font') : pc.value('font', { fallback: '@body' })));
const size = computed(() => (live.value ? fmt.get('size') : pc.value('size', { fallback: 48 })));
const color = computed(() => (live.value ? fmt.get('color') : pc.value('color', { fallback: '@ink' })));
const weightNow = computed(() => pc.value('weight', { fallback: (single.value?.font === '@heading' ? (theme.value.t?.hw ?? 700) : 400) }));
const weightOpts = computed(() => weightOptions(mixed(font.value) ? '@body' : (single.value?.font || font.value), theme.value, weightNow.value));
const bold = computed(() => fmt.get('bold'));
const MARKS = [
    { id: 'bold', icon: 'p-bold', label: 'Bold' },
    { id: 'italic', icon: 'p-italic', label: 'Italic' },
    { id: 'underline', icon: 'p-underline', label: 'Underline' },
    { id: 'strike', icon: 'p-strike', label: 'Strikethrough' },
];
const markOn = (id) => fmt.get(id) === true;

const ALIGN = [
    { value: 'left', icon: 'p-alignL', title: 'Align left' }, { value: 'center', icon: 'p-alignC', title: 'Align centre' },
    { value: 'right', icon: 'p-alignR', title: 'Align right' }, { value: 'justify', icon: 'p-alignJ', title: 'Justify' },
];
const VALIGN = [
    { value: 'top', icon: 'p-vTop', title: 'Align to top' }, { value: 'middle', icon: 'p-vMid', title: 'Align to middle' }, { value: 'bottom', icon: 'p-vBot', title: 'Align to bottom' },
];
const align = computed(() => (live.value ? fmt.get('align') : pc.value('align', { fallback: 'left' })));
const valign = computed(() => pc.value('valign', { fallback: 'top' }));

// ---- lists -------------------------------------------------------------------------------------------------------------
const listKind = computed(() => {
    void editor.state.value;
    const kinds = [...new Set(els.value.map((e) => docListKind(e.doc)))];
    return kinds.length === 1 ? kinds[0] : 'mixed';
});
function session() { return editor.stage?.format?.session?.() || null; }
function setList(kind) {
    if (!can.value) return;
    const s = live.value ? session() : null;
    if (s) {
        const chain = s.editor.chain().focus();
        if (kind === 'bullet') chain.toggleBulletList().run(); else chain.toggleOrderedList().run();
        return;
    }
    const next = listKind.value === kind ? null : kind;
    pc.each((e) => (e.doc ? { doc: docSetList(e.doc, next) } : {}), { label: next ? 'List' : 'Remove list' });
}
function indent(dir) {
    if (!can.value) return;
    const s = live.value ? session() : null;
    if (s) {
        const chain = s.editor.chain().focus();
        if (dir > 0) chain.sinkListItem('listItem').run(); else chain.liftListItem('listItem').run();
        return;
    }
    pc.each((e) => (e.doc ? { doc: docIndent(e.doc, dir) } : {}), { label: dir > 0 ? 'Indent' : 'Outdent' });
}
const hasList = computed(() => listKind.value === 'bullet' || listKind.value === 'ordered' || listKind.value === 'mixed');

// ---- fit ---------------------------------------------------------------------------------------------------------------
const fit = computed(() => pc.value('fit', { fallback: 'none' }));
const factor = ref(1);
const FIT_KEYS = ['w', 'h', 'size', 'weight', 'font', 'lh', 'ls', 'ps', 'pad', 'doc', 'fit'];
const fitSig = computed(() => {
    void editor.state.value;
    const e = single.value;
    return e ? JSON.stringify(FIT_KEYS.map((k) => e[k])) : '';
});
let timers = [];
function readFactor() {
    const e = single.value;
    if (!e || e.fit !== 'shrink') { factor.value = 1; return; }
    const nodes = [...document.querySelectorAll(`[data-eid="${CSS.escape(e.id)}"] [style*="--p-fit"]`)];
    const node = nodes.sort((a, b) => b.offsetWidth - a.offsetWidth)[0];
    const f = node ? parseFloat(node.style.getPropertyValue('--p-fit')) : NaN;
    factor.value = Number.isFinite(f) ? f : 1;
}
watch(fitSig, () => {
    timers.forEach(clearTimeout);
    timers = [120, 420, 1000].map((ms) => setTimeout(readFactor, ms));
}, { immediate: true });
onBeforeUnmount(() => timers.forEach(clearTimeout));
const fitted = computed(() => {
    const e = single.value;
    if (!e || e.fit !== 'shrink') return null;
    return { size: fittedSize(e.size || 48, factor.value), pct: Math.round(factor.value * 100), shrunk: factor.value < 0.999 };
});

// ---- box ---------------------------------------------------------------------------------------------------------------
const boxEls = computed(() => els.value.filter((e) => e.type === 'text'));
const boxPc = usePanelChange(Object.assign(Object.create(editor), { selected: boxEls }));
const strokeNow = computed(() => boxPc.value('stroke'));
function setStroke(c) {
    if (c == null || c === '') { boxPc.setMany({ stroke: null, sw: 0 }, { label: 'Remove stroke' }); return; }
    const sw0 = boxPc.value('sw', { fallback: 0 });
    const dflt = Math.round((theme.value.s?.stroke ?? 2) * ((deck.value?.w || 1920) / 1920) * 100) / 100;
    boxPc.setMany({ stroke: c, ...(sw0 === 0 || mixed(sw0) ? { sw: dflt } : {}) }, { label: 'Stroke colour' });
}

// ---- link --------------------------------------------------------------------------------------------------------------
const linkText = ref('');
const linkError = ref('');
const linkNow = computed(() => (live.value ? '' : pc.value('link', { fallback: '' })));
watch(linkNow, (v) => { linkText.value = mixed(v) ? '' : (v || ''); linkError.value = ''; }, { immediate: true });
function commitLink() {
    const v = linkText.value.trim();
    if (v === (mixed(linkNow.value) ? '' : (linkNow.value || ''))) return;
    if (!v) { live.value ? fmt.set('link', null) : pc.set('link', null, { label: 'Remove link' }); linkError.value = ''; return; }
    if (!safeHref(v)) { linkError.value = 'Use an address that starts with https:// or #slide: followed by a slide id.'; return; }
    linkError.value = '';
    fmt.set('link', v);
}
async function clearLink() {
    linkText.value = '';
    linkError.value = '';
    if (live.value) fmt.set('link', null); else pc.set('link', null, { label: 'Remove link' });
    await nextTick();
}
const inlineLink = computed(() => live.value && fmt.get('link'));
</script>

<template>
    <div class="pp tx" data-panel-body="text" data-es-ui>
        <div v-if="live" class="pp-lead">
            <InlineNotice kind="info" title="Editing text">Bold, italic, underline, strike, colour, size, font and link now apply to the words you have selected.</InlineNotice>
        </div>
        <div v-else-if="skipped > 0" class="pp-lead"><p class="pp-note">Changes apply to the {{ n }} text {{ n === 1 ? 'element' : 'elements' }} in your selection.</p></div>

        <PanelSection id="text.styles" title="Styles" :state="currentPreset === 'mixed' ? 'mixed' : ''">
            <div class="pp-swatches" role="group" aria-label="Text styles from the theme">
                <button v-for="p in presets" :key="p.id" type="button" class="pp-swatch" :aria-pressed="currentPreset === p.id" :disabled="!can" @click="applyPreset(p)">
                    <span class="pp-swatch-chip tx-chip" :style="previewStyle(p)" aria-hidden="true">Aa</span>
                    <span class="pp-swatch-n">{{ p.label }}</span>
                    <span class="pr-state tx-px">{{ Math.round(p.patch.size) }} px</span>
                </button>
            </div>
            <div v-if="roleStyle && plan" class="pp-group">
                <button type="button" class="pr-btn pr-btn--sm" :disabled="!can || !plan.changing" @click="confirmOpen = true">
                    {{ plan.changing ? `Apply this style to ${plan.changing} more ${single.role} ${plan.changing === 1 ? 'text' : 'texts'}` : `Every ${single.role} text already matches` }}
                </button>
                <p class="pp-note">Uses this text's font, size, weight and colour. You confirm first and can undo it.</p>
            </div>
            <p v-else-if="single && !single.role" class="pp-note">Pick a style above to give this text a role. Then you can restyle every text with that role.</p>
            <p v-else-if="n > 1" class="pp-note">Select one text to restyle everything that shares its role.</p>
        </PanelSection>

        <PanelSection id="text.type" title="Type">
            <FontPicker :model-value="font" label="Font" :disabled="!can" @update:model-value="(v) => fmt.set('font', v)" />
            <div class="c-grid2">
                <NumberField label="Size" unit="px" :model-value="size" :min="1" :max="5000" :step="1" :disabled="!can" @update:model-value="(v) => fmt.set('size', v)" />
                <SelectMenu :model-value="weightNow" label="Weight" :options="weightOpts" :disabled="!can || live" @update:model-value="(v) => pc.set('weight', v, { label: 'Font weight' })" />
            </div>
            <div class="pp-group" role="group" aria-label="Text marks">
                <div class="pp-tools">
                    <IconButton v-for="m in MARKS" :key="m.id" :icon="m.icon" :label="m.label" :pressed="m.id === 'bold' ? bold === true : markOn(m.id)" :disabled="!can" @click="fmt.toggle(m.id)" />
                </div>
            </div>
            <ColorField :model-value="color" label="Colour" :disabled="!can" @update:model-value="(v) => fmt.set('color', v)" />
        </PanelSection>

        <PanelSection id="text.align" title="Alignment and lists">
            <Segmented :model-value="align" label="Horizontal" :options="ALIGN" :disabled="!can" @update:model-value="(v) => fmt.set('align', v)" />
            <Segmented :model-value="valign" label="Vertical" :options="VALIGN" :disabled="!can" @update:model-value="(v) => pc.set('valign', v, { label: 'Vertical alignment' })" />
            <div class="pp-group" role="group" aria-label="Lists">
                <span class="pp-cap">Lists</span>
                <div class="pp-tools">
                    <IconButton icon="p-bullets" label="Bulleted list" :pressed="listKind === 'bullet'" :disabled="!can" @click="setList('bullet')" />
                    <IconButton icon="p-numbers" label="Numbered list" :pressed="listKind === 'ordered'" :disabled="!can" @click="setList('ordered')" />
                    <span class="pp-sep" aria-hidden="true"></span>
                    <IconButton icon="p-outdent" label="Decrease indent" :disabled="!can || !(hasList || live)" @click="indent(-1)" />
                    <IconButton icon="p-indent" label="Increase indent" :disabled="!can || !(hasList || live)" @click="indent(1)" />
                </div>
            </div>
        </PanelSection>

        <PanelSection id="text.space" title="Spacing" resettable reset-label="Reset" @reset="pc.setMany({ lh: 1.25, ls: 0, ps: 0.4 }, { label: 'Reset spacing' })">
            <div class="c-grid2">
                <NumberField label="Line" unit="x" :model-value="pc.value('lh', { fallback: 1.25 })" :min="0.5" :max="5" :step="0.05" :disabled="!can" @update:model-value="(v) => pc.set('lh', v, { label: 'Line height' })" />
                <NumberField label="Letter" unit="em" :model-value="pc.value('ls', { fallback: 0 })" :min="-1" :max="5" :step="0.01" :disabled="!can" @update:model-value="(v) => pc.set('ls', v, { label: 'Letter spacing' })" />
            </div>
            <NumberField label="Paragraph" unit="em" :model-value="pc.value('ps', { fallback: 0.4 })" :min="0" :max="5" :step="0.05" :disabled="!can" @update:model-value="(v) => pc.set('ps', v, { label: 'Paragraph spacing' })" />
        </PanelSection>

        <PanelSection id="text.fit" title="Fit and effects" :state="fit === 'shrink' ? 'shrink' : ''">
            <Toggle :model-value="fit === 'mixed' ? 'mixed' : fit === 'shrink'" label="Shrink text to fit the box" :disabled="!can" hint="The player scales the type down so everything fits. Your size stays as the largest." @update:model-value="(v) => pc.set('fit', v ? 'shrink' : 'none', { label: 'Text fit' })" />
            <div v-if="fitted" class="pp-readout" role="status">
                <Ico name="p-fit" size="sm" />
                <span v-if="fitted.shrunk">Drawn at <span class="pr-state">{{ fitted.size }} px</span> ({{ fitted.pct }} percent of {{ single.size }})</span>
                <span v-else>Fits at full size, <span class="pr-state">{{ fitted.size }} px</span></span>
            </div>
            <Segmented :model-value="pc.value('fx', { fallback: 'none' })" label="Effect" :options="[{ value: 'none', label: 'None' }, { value: 'outline', label: 'Outline' }, { value: 'highlight', label: 'Highlight' }]" :disabled="!can" @update:model-value="(v) => pc.set('fx', v, { label: 'Text effect' })" />
        </PanelSection>

        <PanelSection v-if="hasTextBox" id="text.box" title="Box">
            <FillControl :model-value="boxPc.value('fill')" label="Fill" :disabled="!can" @update:model-value="(v) => boxPc.set('fill', v, { label: 'Box fill' })" />
            <ColorField :model-value="strokeNow === undefined ? null : strokeNow" label="Stroke" allow-none :disabled="!can" @update:model-value="setStroke" />
            <div class="c-grid2">
                <NumberField label="Width" unit="px" :model-value="boxPc.value('sw', { fallback: 0 })" :min="0" :max="500" :step="1" :disabled="!can || !strokeNow" @update:model-value="(v) => boxPc.set('sw', v, { label: 'Stroke width' })" />
                <NumberField label="Corners" unit="px" :model-value="boxPc.value('radius', { fallback: 0 })" :min="0" :max="5000" :step="1" :disabled="!can" @update:model-value="(v) => boxPc.set('radius', v, { label: 'Corner radius' })" />
            </div>
            <NumberField label="Padding" unit="px" :model-value="pc.value('pad', { fallback: 0 })" :min="0" :max="2000" :step="1" :disabled="!can" @update:model-value="(v) => pc.set('pad', v, { label: 'Padding' })" />
        </PanelSection>
        <PanelSection v-else id="text.box" title="Padding">
            <NumberField label="Padding" unit="px" :model-value="pc.value('pad', { fallback: 16 })" :min="0" :max="2000" :step="1" :disabled="!can" hint="Fill, stroke and corners belong to the shape: see the Shape tab." @update:model-value="(v) => pc.set('pad', v, { label: 'Padding' })" />
        </PanelSection>

        <PanelSection id="text.link" title="Link" :state="inlineLink ? 'on selection' : (linkNow && !mixed(linkNow) ? 'on' : '')">
            <Field v-slot="{ id, describedby }" :label="live ? 'Link for the selected words' : 'Address for the whole element'" :error="linkError" hint="https://example.com or #slide:id of a slide in this deck">
                <input :id="id" v-model="linkText" class="pr-field" type="url" inputmode="url" autocomplete="off" spellcheck="false" :aria-describedby="describedby" :disabled="!can" placeholder="https://" @keydown.enter.prevent="commitLink" @blur="commitLink" />
            </Field>
            <div v-if="linkText || inlineLink" class="pp-btns">
                <button type="button" class="pr-btn pr-btn--sm" :disabled="!can" @click="clearLink"><span class="pp-btn-ico"><Ico name="p-unlink" size="sm" />Remove link</span></button>
            </div>
        </PanelSection>

        <Dialog v-model:open="confirmOpen" title="Apply this style to the deck?" size="sm">
            <div v-if="plan && roleStyle" class="tx-confirm">
                <p>This changes <b>{{ plan.changing }}</b> {{ plan.changing === 1 ? 'text' : 'texts' }} with the role <b>{{ single.role }}</b> on <b>{{ plan.slides }}</b> {{ plan.slides === 1 ? 'slide' : 'slides' }}.</p>
                <dl class="tx-dl">
                    <div><dt>Font</dt><dd>{{ roleStyle.patch.font === '@heading' ? 'Heading font' : roleStyle.patch.font === '@body' ? 'Body font' : roleStyle.patch.font }}</dd></div>
                    <div><dt>Size</dt><dd>{{ roleStyle.patch.size }} px</dd></div>
                    <div><dt>Weight</dt><dd>{{ roleStyle.patch.weight ?? 'theme default' }}</dd></div>
                    <div><dt>Colour</dt><dd>{{ roleStyle.patch.color }}</dd></div>
                </dl>
                <p v-if="plan.locked" class="pp-note">{{ plan.locked }} locked {{ plan.locked === 1 ? 'text is' : 'texts are' }} skipped.</p>
                <p class="pp-note">Words you coloured or resized one by one lose that formatting. Undo restores everything in one step.</p>
            </div>
            <template #footer="{ close }">
                <button type="button" class="pr-btn" data-autofocus @click="close()">Cancel</button>
                <button type="button" class="pr-btn pr-btn--primary" @click="applyToRole">Apply to {{ plan ? plan.changing : 0 }}</button>
            </template>
        </Dialog>
    </div>
</template>

<style>
.tx-chip { display: grid; place-items: center; height: 30px; line-height: 1; overflow: hidden; }
.tx-px { color: var(--muted); }
.tx-confirm { display: grid; gap: 12px; font-size: var(--t-md); }
.tx-confirm p { margin: 0; }
.tx-dl { display: grid; gap: 6px; margin: 0; padding: 10px 12px; border: 1px solid var(--line); border-radius: var(--r-2); background: var(--raised); }
.tx-dl > div { display: flex; justify-content: space-between; gap: 12px; }
.tx-dl dt { color: var(--muted); font-size: var(--t-sm); }
.tx-dl dd { margin: 0; font-family: var(--f-mono); font-size: var(--t-sm); }
</style>
