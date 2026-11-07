<script setup>
// Inspector panel for icons: search the built in set, pick one, then colour, stroke width and size. The grid is one group of buttons
// with a single tab stop; arrow keys move focus across it, Home and End jump, Enter or Space picks (moving never changes the icon).
import { computed, nextTick, onMounted, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import {
    PanelSection, NumberField, Slider, ColorField, Field, usePanelChange, MIXED,
} from '../../../ui/controls/index.js';
import Ico from '../../ui/Ico.vue';
import { ICONS, getIcon, DEFAULT_ICON_SW } from '../../../icons.js';
import { searchIcons, gridStep, sizePatch, SW_RANGE } from './lib/icons.js';
import '../image/media.css';

const COLS = 6;
const editor = useEditorContext();
const pc = usePanelChange(editor);
const els = pc.elements;
const q = ref('');
const grid = ref(null);
const shown = computed(() => searchIcons(ICONS, q.value));
const name = computed(() => pc.value('name'));
const current = computed(() => (name.value && name.value !== MIXED ? getIcon(name.value) : null));
const focusIndex = ref(0);
const tabIndex = computed(() => {
    const i = shown.value.findIndex((ic) => ic.id === name.value);
    return i >= 0 ? i : Math.min(focusIndex.value, Math.max(0, shown.value.length - 1));
});

function choose(ic) {
    if (pc.set('name', ic.id, { label: 'Change icon' })) editor.announce(`${ic.label} icon`);
}
async function onKey(e) {
    if (!shown.value.length) return;
    const btns = [...grid.value.querySelectorAll('.pm-icon')];
    const at = btns.indexOf(document.activeElement);
    const next = gridStep(at < 0 ? tabIndex.value : at, e.key, COLS, shown.value.length);
    if (next === null) return;
    e.preventDefault();
    focusIndex.value = next;
    await nextTick();
    btns[next]?.focus();
    btns[next]?.scrollIntoView({ block: 'nearest' });
}
function setSize(v) {
    pc.each((el) => sizePatch(el, v, editor.deck.value) || {}, { label: 'Icon size', undoKey: `panel:size:${els.value.map((e) => e.id).join(',')}` });
}
const size = computed(() => {
    void editor.state.value;
    const ws = els.value.map((e) => Math.round(Math.max(e.w, e.h)));
    return ws.every((w) => w === ws[0]) ? ws[0] : MIXED;
});
const sizeMax = computed(() => Math.round(Math.min(editor.deck.value?.w || 1920, editor.deck.value?.h || 1080)));
onMounted(async () => {
    await nextTick();
    grid.value?.querySelector('.pm-icon[aria-pressed="true"]')?.scrollIntoView({ block: 'nearest' });
});
const hint = computed(() => (q.value && !shown.value.length ? '' : `${shown.value.length} of ${ICONS.length}`));
</script>

<template>
    <div class="pm-panel" data-panel-root="icon">
        <PanelSection id="icon.pick" title="Icon" :state="hint">
            <div class="pm-icon-now">
                <span class="pm-icon-big" aria-hidden="true"><Ico v-if="current" :name="current.id" /><Ico v-else name="icon" /></span>
                <span class="pr-small">{{ current ? current.label : name === MIXED ? 'Several icons' : 'No icon' }}</span>
            </div>
            <Field v-slot="{ id }" label="Search icons" hide-label>
                <input :id="id" v-model="q" class="pr-field" type="search" placeholder="Search: arrow, user, chart" autocomplete="off" />
            </Field>
            <div v-if="shown.length" class="pm-icons-wrap">
                <div ref="grid" class="pm-icons" role="group" aria-label="Icons" @keydown="onKey">
                    <button
                        v-for="(ic, i) in shown" :key="ic.id" type="button" class="pm-icon" :aria-pressed="name === ic.id" :aria-label="ic.label" :title="ic.label"
                        :tabindex="i === tabIndex ? 0 : -1" :disabled="!pc.canEdit.value" @click="choose(ic)" @focus="focusIndex = i"
                    >
                        <Ico :name="ic.id" />
                    </button>
                </div>
            </div>
            <p v-else class="c-hint">No icon matches "{{ q }}". Try a simpler word, like arrow or user.</p>
        </PanelSection>

        <PanelSection id="icon.style" title="Colour and size">
            <div class="c-stack">
                <ColorField label="Colour" :model-value="pc.value('color', { fallback: '@ink' })" :disabled="!pc.canEdit.value" @update:model-value="(v) => pc.set('color', v, { label: 'Icon colour' })" />
                <Slider label="Stroke width" :min="SW_RANGE.min" :max="SW_RANGE.max" :step="SW_RANGE.step" :model-value="pc.value('sw', { fallback: DEFAULT_ICON_SW })" :disabled="!pc.canEdit.value" @update:model-value="(v) => pc.set('sw', v, { label: 'Stroke width' })" />
                <NumberField label="Size" unit="px" :min="8" :max="sizeMax" :model-value="size" :disabled="!pc.canEdit.value" hint="The icon stays square and keeps its centre." @update:model-value="setSize" />
            </div>
        </PanelSection>

        <PanelSection id="icon.alt" title="Description">
            <Field v-slot="{ id }" label="Alt text" hint="Leave empty when the icon only decorates; screen readers then skip it.">
                <input :id="id" class="pr-field" type="text" maxlength="500" :value="pc.value('alt') === MIXED ? '' : pc.value('alt') || ''" :placeholder="pc.value('alt') === MIXED ? 'Mixed' : ''" :disabled="!pc.canEdit.value" @input="(e) => pc.set('alt', e.target.value === '' ? null : e.target.value, { label: 'Alt text' })" />
            </Field>
        </PanelSection>
    </div>
</template>
