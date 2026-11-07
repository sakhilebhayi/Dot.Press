<script setup>
import { computed, provide, ref, shallowRef } from 'vue';
import { EDITOR_KEY } from '../../../editor/context.js';
import { DEFAULT_THEME } from '../../../editor/core/theme.js';
import ThemeToggle from '../../../editor/ui/ThemeToggle.vue';
import {
    PanelSection, Field, NumberField, Slider, Segmented, Toggle, SelectMenu, ColorField, GradientField, FontPicker, IconButton, Tabs, Popover, Dialog,
    ReorderList, ListRow, EmptyState, ProgressBead, InlineNotice, usePanelChange,
} from '../index.js';
import { newGradient } from '../colors.js';

// a fake editor, enough for usePanelChange and the theme aware controls
const els = shallowRef([{ id: 'a', type: 'text', size: 40, weight: 600, shadow: { blur: 4 } }, { id: 'b', type: 'text', size: 56, weight: 600 }]);
const log = ref([]);
const editor = {
    readOnly: false, can: { update: true }, state: shallowRef({}), selected: computed(() => els.value),
    deck: computed(() => ({ theme: DEFAULT_THEME })),
    store: { patch(patches, o) { log.value.unshift(`${o.undoKey} ${JSON.stringify(patches)}`); els.value = els.value.map((e) => { const p = patches.find((x) => x.id === e.id); if (!p) return e; const n = { ...e, ...p.p }; for (const k of Object.keys(p.p)) if (p.p[k] === null) delete n[k]; return n; }); } },
};
provide(EDITOR_KEY, editor);
const pc = usePanelChange(editor);

const n = ref(24); const mixedN = ref('mixed'); const s = ref(0.6); const seg = ref('left'); const tog = ref(true); const mtog = ref('mixed');
const sel = ref('b'); const color = ref('@accent'); const fill = ref(newGradient('#2563eb', '#f59e0b')); const font = ref('@heading'); const mfont = ref('mixed');
const tab = ref('one'); const pop = ref(false); const popBtn = ref(null); const dlg = ref(false);
const items = ref([{ id: 1, name: 'Title' }, { id: 2, name: 'Subtitle' }, { id: 3, name: 'Chart: output by week' }]);
const opts = [{ value: 'a', label: 'Alpha', hint: 'first' }, { value: 'b', label: 'Beta', hint: 'second' }, { value: 'c', label: 'Gamma', hint: 'third' }, { value: 'd', label: 'Delta', disabled: true }];
const prog = ref(0.4);
const solidColor = ref('#2563eb80');
</script>

<template>
    <div class="pr g-page">
        <header class="g-head"><h1 class="pr-display g-h1">Controls</h1><ThemeToggle /></header>
        <div class="g-cols">
            <div class="g-col">
                <PanelSection id="g.numbers" title="Numbers" state="3" resettable @reset="n = 24">
                    <div class="c-grid2"><NumberField v-model="n" label="Size" unit="px" :min="0" :max="400" /><NumberField v-model="mixedN" label="Weight" :min="100" :max="900" :step="100" /></div>
                    <NumberField :model-value="1.2" label="Line" :step="0.1" hint="Drag the label, or use the arrow keys." />
                    <NumberField :model-value="5" label="Bad" error="Use a number from 0 to 4" :min="0" :max="4" />
                    <Slider v-model="s" label="Opacity" :min="0" :max="1" :step="0.01" :format="(v) => Math.round(v * 100) + '%'" />
                    <Slider model-value="mixed" label="Mixed slider" />
                </PanelSection>
                <PanelSection id="g.choices" title="Choices">
                    <Segmented v-model="seg" label="Align" :options="[{ value: 'left', label: 'Left' }, { value: 'center', label: 'Centre' }, { value: 'right', label: 'Right' }]" />
                    <Segmented v-model="seg" label="Align icons" :options="[{ value: 'left', icon: 'list', title: 'Left' }, { value: 'center', icon: 'menu', title: 'Centre' }, { value: 'right', icon: 'grid', title: 'Right' }]" />
                    <Toggle v-model="tog" label="Shrink text to fit" hint="The player scales type down so it fits." />
                    <Toggle v-model="mtog" label="Locked" />
                    <SelectMenu v-model="sel" label="Select" :options="opts" />
                    <SelectMenu v-model="sel" label="Searchable" :options="opts" searchable />
                    <Field v-slot="{ id }" label="Plain field" hint="Field wires the label."><input :id="id" class="pr-field" value="Hello" /></Field>
                </PanelSection>
                <PanelSection id="g.notices" title="Notices and state">
                    <InlineNotice kind="info">A neutral note.</InlineNotice>
                    <InlineNotice kind="ok" title="Saved to the library">All good.</InlineNotice>
                    <InlineNotice kind="warn" title="Low contrast">Text on this fill is 2.1 to 1.</InlineNotice>
                    <InlineNotice kind="error" title="Could not export">Try again in a minute.</InlineNotice>
                    <ProgressBead label="Uploading" /><ProgressBead v-model="prog" :value="prog" label="Rendering" />
                    <EmptyState compact body="Select a slide to see its notes." />
                </PanelSection>
            </div>
            <div class="g-col">
                <PanelSection id="g.colour" title="Colour and type">
                    <ColorField v-model="color" label="Fill" allow-none allow-gradient />
                    <ColorField v-model="solidColor" label="Solid with alpha" />
                    <ColorField model-value="mixed" label="Mixed" />
                    <GradientField v-model="fill" />
                    <FontPicker v-model="font" label="Font" />
                    <FontPicker v-model="mfont" label="Mixed font" />
                </PanelSection>
                <PanelSection id="g.nav" title="Navigation and overlays">
                    <Tabs v-model="tab" label="Demo tabs" :items="[{ id: 'one', label: 'One' }, { id: 'two', label: 'Two', count: 3 }, { id: 'three', label: 'Three', icon: 'star' }]" />
                    <Tabs v-model="tab" variant="pill" label="Pill tabs" :items="[{ id: 'one', label: 'One' }, { id: 'two', label: 'Two' }, { id: 'three', label: 'Three' }]" />
                    <div class="g-row">
                        <IconButton icon="duplicate" label="Duplicate" /><IconButton icon="trash" label="Delete" danger /><IconButton icon="eye" label="Visible" :pressed="true" />
                        <button ref="popBtn" type="button" class="pr-btn" @click="pop = !pop">Popover</button>
                        <button type="button" class="pr-btn" @click="dlg = true">Dialog</button>
                    </div>
                    <Popover v-model:open="pop" :anchor="popBtn" label="Demo popover"><div style="padding: 14px; display: grid; gap: 8px; width: 240px"><p class="pr-small">Anchored, flips, traps focus.</p><button type="button" class="pr-btn pr-btn--sm" @click="pop = false">Done</button></div></Popover>
                    <Dialog v-model:open="dlg" title="A dialog" size="sm"><p>Modal, focus trapped, focus returns.</p><template #footer="{ close }"><button type="button" class="pr-btn" @click="close()">Cancel</button><button type="button" class="pr-btn pr-btn--primary" @click="close()">Save</button></template></Dialog>
                    <ReorderList v-model:items="items" label="Layers" selected-key="2" :handle-label="(i) => `Reorder ${i.name}`">
                        <template #default="{ item }">{{ item.name }}</template>
                        <template #actions><IconButton icon="eye" label="Toggle" size="sm" /></template>
                    </ReorderList>
                    <ul class="c-list"><ListRow>A plain row without a handle</ListRow></ul>
                </PanelSection>
                <PanelSection id="g.change" title="usePanelChange" :state="String(pc.value('size'))">
                    <div class="c-grid2"><NumberField :model-value="pc.value('size')" label="Size" @update:model-value="(v) => pc.set('size', v, { label: 'Font size' })" /><NumberField :model-value="pc.value('shadow.blur', { fallback: 0 })" label="Blur" @update:model-value="(v) => pc.set('shadow.blur', v)" /></div>
                    <button type="button" class="pr-btn pr-btn--sm" @click="pc.each((e) => ({ size: e.size + 2 }))">Bigger by 2 each</button>
                    <button type="button" class="pr-btn pr-btn--sm" @click="pc.reset('shadow')">Reset shadow</button>
                    <pre class="g-log pr-state">{{ log.slice(0, 4).join('\n') }}</pre>
                </PanelSection>
            </div>
        </div>
    </div>
</template>

<style>
.g-page { min-height: 100vh; padding: 24px clamp(16px, 4vw, 48px) 80px; }
.g-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.g-h1 { font-size: var(--t-2xl); }
.g-cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 32px; max-width: 900px; }
.g-col { background: var(--paper); border: 1px solid var(--line); border-radius: var(--r-3); min-width: 0; }
.g-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.g-log { white-space: pre-wrap; word-break: break-all; color: var(--muted); max-height: 110px; overflow: auto; }
</style>
