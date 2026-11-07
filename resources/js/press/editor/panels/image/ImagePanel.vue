<script setup>
// Inspector panel for pictures: source, crop and fit, mask, adjustments, flip, opacity, shadow, caption, link, alt text.
// Every change is a store patch through usePanelChange, so it is undoable, autosaved and shared; several pictures at once
// show 'mixed' where they differ and change together. Nothing here is generated: it only edits the element.
import { computed, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import {
    PanelSection, NumberField, Slider, Segmented, Toggle, ColorField, Field, IconButton, InlineNotice, usePanelChange, MIXED,
} from '../../../ui/controls/index.js';
import Ico from '../../ui/Ico.vue';
import { replacePatch } from '../../stage/lib/drop.js';
import { safeUrl } from '../../../render/media.js';
import AssetPicker from './AssetPicker.vue';
import MaskPicker from './MaskPicker.vue';
import {
    CROP_PRESETS, ADJUSTMENTS, LOW_PPI, aspectPatch, nudgePatch, effectivePpi, resetPatch, isAdjusted, defaultShadow,
} from './lib/image.js';
import './media.css';

const editor = useEditorContext();
const pc = usePanelChange(editor);
const els = pc.elements;
const single = computed(() => (els.value.length === 1 ? els.value[0] : null));
const deckW = computed(() => editor.deck.value?.w || 1920);
const deckH = computed(() => editor.deck.value?.h || 1080);
const hasStage = computed(() => !!editor.stage);

function natOf(el) {
    const a = el?.src?.asset != null ? editor.assets[el.src.asset] : null;
    return a?.w && a?.h ? { w: a.w, h: a.h } : null;
}
const asset = computed(() => (single.value?.src?.asset != null ? editor.assets[single.value.src.asset] || null : null));
const nat = computed(() => natOf(single.value));
const srcName = computed(() => {
    const s = single.value?.src;
    if (!s) return '';
    if (asset.value?.name) return asset.value.name;
    if (s.url) { try { return new URL(s.url).hostname; } catch { return 'Web picture'; } }
    return 'Picture';
});
const thumb = computed(() => asset.value?.thumb || asset.value?.url || (single.value?.src?.url && safeUrl(single.value.src.url)) || null);
const sizeWord = computed(() => (nat.value ? `${nat.value.w} x ${nat.value.h} px` : 'Size unknown'));

// ---- quality -----------------------------------------------------------------------------------------------
const lowest = computed(() => {
    void editor.state.value;
    let worst = null;
    for (const el of els.value) {
        const ppi = effectivePpi({ nat: natOf(el), crop: el.crop, box: { w: el.w, h: el.h }, fit: el.fit, deckW: deckW.value });
        if (ppi !== null && (worst === null || ppi < worst)) worst = ppi;
    }
    return worst;
});
const soft = computed(() => lowest.value !== null && lowest.value < LOW_PPI);

// ---- source ------------------------------------------------------------------------------------------------
const picking = ref(false);
function onPick(a) {
    const ok = pc.each((el) => replacePatch(el, { asset: a.id }), { label: 'Replace picture' });
    if (ok) editor.announce(`Replaced with ${a.name}`);
}
function upload() { if (single.value) editor.stage?.replacePicture?.(single.value.id); }

// ---- crop and fit ------------------------------------------------------------------------------------------
const presetNow = computed(() => {
  const e = single.value;
  const n = nat.value;
  if (!e || !n) return null;
  const c = e.crop || { w: 1, h: 1 };
  const r = (e.w / e.h);
  for (const p of CROP_PRESETS) if (p.ratio && Math.abs(r - p.ratio) < 0.01 && Math.abs((c.w * n.w) / (c.h * n.h) - p.ratio) < 0.02) return p.id;
  return null;
});
function applyPreset(id) {
    if (id === 'free') { cropOnSlide(); return; }
    let skipped = 0;
    const ok = pc.each((el) => {
        const p = aspectPatch({ el, nat: natOf(el), preset: id, deckW: deckW.value, deckH: deckH.value });
        if (!p) skipped += 1;
        return p || {};
    }, { label: id === 'orig' ? 'Show whole picture' : `Crop to ${id}`, undoKey: `panel:aspect:${els.value.map((e) => e.id).join(',')}` });
    if (ok) editor.announce(id === 'orig' ? 'Showing the whole picture' : `Cropped to ${id}`);
    else if (skipped) editor.toast('The size of this picture is not known, so it cannot be cropped to a ratio. Use Crop on slide.', { kind: 'warn' });
}
function cropOnSlide() { if (single.value) editor.stage?.beginCrop?.(single.value.id); }
function nudge(dir) {
    pc.each((el) => nudgePatch({ el, nat: natOf(el), dir }) || {}, { label: 'Move picture in frame', undoKey: `panel:nudge:${els.value.map((e) => e.id).join(',')}` });
}
const fitValue = computed(() => pc.value('fit', { fallback: 'cover' }));
const canNudge = computed(() => pc.canEdit.value && fitValue.value !== 'contain' && els.value.some((e) => natOf(e)));

// ---- adjustments ---------------------------------------------------------------------------------------------
const adjusted = computed(() => els.value.some((e) => isAdjusted(e.adj)));

// ---- shadow ----------------------------------------------------------------------------------------------------
const shadowState = computed(() => {
    void editor.state.value;
    const on = els.value.filter((e) => e.shadow).length;
    return on === 0 ? false : on === els.value.length ? true : MIXED;
});
function toggleShadow(v) {
    if (v) pc.each((el) => (el.shadow ? {} : { shadow: defaultShadow(deckW.value) }), { label: 'Add shadow' });
    else pc.reset('shadow', { label: 'Remove shadow' });
}

// ---- link and text ---------------------------------------------------------------------------------------------
const linkError = ref('');
const linkDraft = ref(null);
const linkShown = computed(() => (linkDraft.value !== null ? linkDraft.value : (pc.value('link') === MIXED ? '' : pc.value('link') || '')));
function commitLink(e) {
    const v = e.target.value.trim();
    linkDraft.value = null;
    if (v === '') { linkError.value = ''; pc.set('link', null, { label: 'Remove link' }); return; }
    if (v.startsWith('#slide:') && /^#slide:[A-Za-z0-9_-]{1,40}$/.test(v)) { linkError.value = ''; pc.set('link', v, { label: 'Set link' }); return; }
    const url = safeUrl(v.includes('://') ? v : `https://${v}`);
    if (!url) { linkError.value = 'Use an https address, or a slide link like #slide:12.'; return; }
    linkError.value = '';
    pc.set('link', url, { label: 'Set link' });
}
const altValue = computed(() => pc.value('alt'));
const decorative = computed(() => altValue.value === '');
const altMissing = computed(() => els.value.some((e) => e.alt == null));
function setDecorative(v) { pc.set('alt', v ? '' : null, { label: v ? 'Mark as decorative' : 'Add alt text' }); }
function onAlt(e) { pc.set('alt', e.target.value === '' ? null : e.target.value, { label: 'Alt text' }); }

function resetAll() {
    const ok = pc.each((el) => resetPatch(el) || {}, { label: 'Reset picture' });
    if (ok) editor.announce('Picture reset');
}
const canReset = computed(() => els.value.some((e) => resetPatch(e)));
const ratioStyle = (r) => ({ width: `${r >= 1 ? 14 : 14 * r}px`, height: `${r >= 1 ? 14 / r : 14}px` });
const fitOptions = [{ value: 'cover', label: 'Cover' }, { value: 'contain', label: 'Contain' }, { value: 'fill', label: 'Fill' }];
const fitHint = { cover: 'Fills the frame and trims the edges.', contain: 'Shows the whole picture inside the frame.', fill: 'Stretches the picture to the frame. It can distort.' };
</script>

<template>
    <div class="pm-panel" data-panel-root="image">
        <PanelSection id="image.source" :title="single ? 'Picture' : `${els.length} pictures`" :state="single ? '' : 'mixed ok'">
            <template v-if="single">
                <div class="pm-src">
                    <div class="pm-src-img"><img v-if="thumb" :src="thumb" alt="" /><Ico v-else name="image" /></div>
                    <div class="pm-src-t">
                        <span class="pm-src-n" :title="srcName">{{ srcName }}</span>
                        <span class="pm-src-s pr-state">{{ sizeWord }}</span>
                        <span v-if="lowest !== null" class="pm-src-s pr-state">{{ lowest }} ppi on the slide</span>
                    </div>
                </div>
                <div class="pm-actions">
                    <button type="button" class="pr-btn" :disabled="!pc.canEdit.value" @click="picking = true"><Ico name="folder" size="sm" />Choose from library</button>
                    <button v-if="hasStage" type="button" class="pr-btn" :disabled="!pc.canEdit.value" @click="upload"><Ico name="upload" size="sm" />Upload new</button>
                </div>
            </template>
            <InlineNotice v-if="soft" kind="warn" title="May look soft">
                {{ els.length > 1 ? 'At least one picture gets' : 'This picture gets' }} about {{ lowest }} pixels per inch at slide size. Below {{ LOW_PPI }} it can look blurry on a big screen. Use a larger file or a smaller frame.
            </InlineNotice>
        </PanelSection>

        <PanelSection id="image.crop" title="Crop and fit">
            <div class="c-stack">
                <div role="group" aria-label="Crop to a ratio">
                    <div class="pm-chips">
                        <button
                            v-for="p in CROP_PRESETS" :key="p.id" type="button" class="pm-chip" :aria-pressed="presetNow === p.id" :disabled="!pc.canEdit.value || (p.id === 'free' && !single) || (p.id !== 'free' && !els.some((e) => natOf(e)))"
                            :class="{ 'is-on': presetNow === p.id }" @click="applyPreset(p.id)"
                        >
                            <span v-if="p.ratio" class="pm-chip-r" :style="ratioStyle(p.ratio)" aria-hidden="true"></span>
                            {{ p.label }}
                        </button>
                    </div>
                    <p v-if="single && !nat" class="c-hint">The pixel size of this picture is not known, so ratios are unavailable. Use Free to crop by hand.</p>
                </div>
                <button v-if="single && hasStage" type="button" class="pr-btn" :disabled="!pc.canEdit.value" @click="cropOnSlide"><Ico name="select" size="sm" />Crop on slide</button>
                <Segmented :model-value="fitValue" label="Fit" :options="fitOptions" :disabled="!pc.canEdit.value" :hint="fitHint[fitValue] || ''" @update:model-value="(v) => pc.set('fit', v, { label: 'Picture fit' })" />
                <div>
                    <span class="c-label" id="pm-nudge-l">Position in frame</span>
                    <div class="pm-pad" role="group" aria-labelledby="pm-nudge-l" style="margin-top: 6px">
                        <span></span><IconButton icon="arrow-up" label="Move picture up" :disabled="!canNudge" @click="nudge('up')" /><span></span>
                        <IconButton icon="arrow-left" label="Move picture left" :disabled="!canNudge" @click="nudge('left')" /><span class="pm-pad-mid" aria-hidden="true"><Ico name="image" size="sm" /></span><IconButton icon="arrow-right" label="Move picture right" :disabled="!canNudge" @click="nudge('right')" />
                        <span></span><IconButton icon="arrow-down" label="Move picture down" :disabled="!canNudge" @click="nudge('down')" /><span></span>
                    </div>
                    <p class="c-hint" style="margin-top: 6px">{{ fitValue === 'contain' ? 'Nothing to move: the whole picture shows.' : 'Moves what shows inside the frame. It stops at the picture edge.' }}</p>
                </div>
            </div>
        </PanelSection>

        <PanelSection id="image.mask" title="Mask" :state="pc.value('mask', { fallback: 'none' }) === MIXED ? 'mixed' : pc.value('mask', { fallback: 'none' })">
            <MaskPicker :model-value="pc.value('mask', { fallback: 'none' })" :disabled="!pc.canEdit.value" @update:model-value="(v) => pc.set('mask', v === 'none' ? null : v, { label: 'Mask' })" />
        </PanelSection>

        <PanelSection id="image.adjust" title="Adjust" :state="adjusted ? 'changed' : ''" resettable @reset="pc.reset('adj', { label: 'Reset adjustments' })">
            <div class="c-stack">
                <Slider
                    v-for="a in ADJUSTMENTS" :key="a.key" :label="a.label" :min="a.min" :max="a.max" :step="a.step" :unit="a.unit" :disabled="!pc.canEdit.value"
                    :model-value="pc.value(`adj.${a.key}`, { fallback: 0 })" @update:model-value="(v) => pc.set(`adj.${a.key}`, v, { label: a.label })"
                />
            </div>
        </PanelSection>

        <PanelSection id="image.look" title="Flip, opacity and shadow">
            <div class="c-stack">
                <Toggle label="Flip horizontally" :model-value="pc.value('flipH', { fallback: false })" :disabled="!pc.canEdit.value" @update:model-value="(v) => pc.set('flipH', v, { label: 'Flip horizontally' })" />
                <Toggle label="Flip vertically" :model-value="pc.value('flipV', { fallback: false })" :disabled="!pc.canEdit.value" @update:model-value="(v) => pc.set('flipV', v, { label: 'Flip vertically' })" />
                <Slider label="Opacity" :min="0" :max="1" :step="0.01" :format="(v) => `${Math.round(v * 100)}%`" :model-value="pc.value('op', { fallback: 1 })" :disabled="!pc.canEdit.value" @update:model-value="(v) => pc.set('op', v, { label: 'Opacity' })" />
                <Toggle label="Drop shadow" :model-value="shadowState" :disabled="!pc.canEdit.value" @update:model-value="toggleShadow" />
                <template v-if="shadowState !== false">
                    <div class="c-grid3">
                        <NumberField label="X" :model-value="pc.value('shadow.x', { fallback: 0 })" :disabled="!pc.canEdit.value" @update:model-value="(v) => pc.set('shadow.x', v, { label: 'Shadow' })" />
                        <NumberField label="Y" :model-value="pc.value('shadow.y', { fallback: 0 })" :disabled="!pc.canEdit.value" @update:model-value="(v) => pc.set('shadow.y', v, { label: 'Shadow' })" />
                        <NumberField label="Blur" :min="0" :max="400" :model-value="pc.value('shadow.blur', { fallback: 0 })" :disabled="!pc.canEdit.value" @update:model-value="(v) => pc.set('shadow.blur', v, { label: 'Shadow' })" />
                    </div>
                    <ColorField label="Shadow colour" :model-value="pc.value('shadow.c', { fallback: '#00000040' })" :disabled="!pc.canEdit.value" @update:model-value="(v) => pc.set('shadow.c', v, { label: 'Shadow colour' })" />
                </template>
            </div>
        </PanelSection>

        <PanelSection id="image.text" title="Caption, link and alt text" :state="altMissing && !decorative ? 'alt missing' : ''">
            <div class="c-stack">
                <Field v-slot="{ id }" label="Caption" hint="Drawn under the picture.">
                    <input :id="id" class="pr-field" type="text" maxlength="500" :value="pc.value('cap') === MIXED ? '' : pc.value('cap') || ''" :placeholder="pc.value('cap') === MIXED ? 'Mixed' : ''" :disabled="!pc.canEdit.value" @input="(e) => pc.set('cap', e.target.value === '' ? null : e.target.value, { label: 'Caption' })" />
                </Field>
                <Field v-slot="{ id, describedby }" label="Link" :error="linkError" hint="An https address, or #slide:ID for a slide.">
                    <input :id="id" class="pr-field" type="text" inputmode="url" autocomplete="off" :aria-describedby="describedby" :value="linkShown" :placeholder="pc.value('link') === MIXED ? 'Mixed' : 'https://'" :disabled="!pc.canEdit.value" @input="(e) => (linkDraft = e.target.value)" @change="commitLink" />
                </Field>
                <Toggle label="Decorative, no alt text" hint="For pictures that add nothing a listener needs. A screen reader skips them." :model-value="altValue === MIXED ? MIXED : decorative" :disabled="!pc.canEdit.value" @update:model-value="setDecorative" />
                <Field v-if="!decorative" v-slot="{ id, describedby }" label="Alt text" hint="Say what the picture shows and why it is here.">
                    <textarea :id="id" class="pr-field" rows="3" maxlength="500" :aria-describedby="describedby" :value="altValue === MIXED ? '' : altValue || ''" :placeholder="altValue === MIXED ? 'Mixed' : 'A line chart rising from 2020 to 2025'" :disabled="!pc.canEdit.value" @input="onAlt"></textarea>
                </Field>
                <InlineNotice v-if="altMissing && !decorative && altValue !== MIXED" kind="warn" title="No alt text">
                    People using a screen reader get nothing for this picture. Describe it, or mark it decorative.
                </InlineNotice>
            </div>
        </PanelSection>

        <div class="pm-foot">
            <button type="button" class="pr-btn" :disabled="!pc.canEdit.value || !canReset" @click="resetAll">Reset picture</button>
            <span class="c-hint">Removes the crop, the mask and the adjustments. Undo brings them back.</span>
        </div>

        <AssetPicker v-model:open="picking" kind="image" title="Choose a picture" :current-id="single?.src?.asset ?? null" @pick="onPick" />
    </div>
</template>

<style>
.pm-panel { display: block; }
.pm-foot { display: grid; gap: 8px; justify-items: start; padding: 16px; }
.pm-chip.is-on { border-color: var(--ink); box-shadow: inset 0 0 0 1px var(--ink); background: var(--raised); }
</style>
