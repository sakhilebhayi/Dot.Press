<script setup>
// Animate: the selected element's animation steps, an "Add animation" menu, and the play order of the whole slide.
import { computed, onMounted, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import { EmptyState, IconButton, InlineNotice, NumberField, PanelSection, Popover, ReorderList, SelectMenu, Segmented, usePanelChange } from '../../../ui/controls';
import Ico from '../../ui/Ico.vue';
import PlaySequence from './PlaySequence.vue';
import {
    EASE_LABELS, FX_LABEL, MAX_STEPS, PHASE_LABEL, START_WORD, STARTS, addMenuOptions, addPatches, clearPatch, clicksSummary, elementLabel, fxOptions, hiddenAnimated, removePatch,
    reorderPatches, sequenceRows, stepPatch,
} from './sequenceModel.js';

const editor = useEditorContext();
const top = ref(null);
onMounted(() => top.value?.closest('.ph')?.scrollTo(0, 0));

const { store } = editor;
const pc = usePanelChange(editor);
const slide = editor.slide;
const sel = computed(() => pc.elements.value);
const one = computed(() => (sel.value.length === 1 ? sel.value[0] : null));
const steps = computed(() => (one.value?.anim || []).map((s, index) => ({ s, index })));
const rows = computed(() => sequenceRows(slide.value));
const summary = computed(() => clicksSummary(slide.value));
const hiddenCount = computed(() => hiddenAnimated(slide.value));
const addOptions = addMenuOptions();
const full = computed(() => sel.value.every((el) => (el.anim || []).length >= MAX_STEPS));

const GLYPH = { text: 'text', shape: 'shape', image: 'image', chart: 'chart', table: 'table', icon: 'icon', video: 'video', audio: 'audio' };
const selectedKey = computed(() => {
    const id = one.value?.id;
    return id ? rows.value.find((r) => r.elId === id)?.key ?? null : null;
});
const selectedIds = computed(() => new Set(sel.value.map((e) => e.id)));

function add(v) {
    if (!v || !pc.canEdit.value) return;
    const [ph, fx] = v.split(':');
    const patches = addPatches(slide.value, sel.value, { ph, fx });
    if (!patches.length) return;
    store.patch(patches, { label: 'Add animation' });
    editor.announce(`${PHASE_LABEL[ph]} ${FX_LABEL[fx]} added`);
}
function edit(index, change, scrub = false) {
    if (!one.value || !pc.canEdit.value) return;
    const s = one.value.anim[index];
    store.patch(stepPatch(one.value, index, change), { label: 'Animation', undoKey: scrub ? `anim:${one.value.id}:${s?.id}:${Object.keys(change).join('+')}` : null });
}
function remove(index) {
    if (!one.value || !pc.canEdit.value) return;
    store.patch(removePatch(one.value, index), { label: 'Remove animation' });
    editor.announce('Animation removed');
}
function clearAll() {
    if (!pc.canEdit.value) return;
    store.patch(sel.value.filter((e) => e.anim?.length).flatMap((e) => clearPatch(e)), { label: 'Clear animations' });
    editor.announce('Animations cleared');
}
function onReorder({ from, to }) {
    if (!pc.canEdit.value) return;
    const patches = reorderPatches(slide.value, from, to);
    if (!patches.length) return;
    store.patch(patches, { label: 'Reorder animation', undoKey: `anim-order:${slide.value.id}` });
    const moved = rows.value[from];
    editor.announce(`${elementLabel(moved.el)} moved to step ${to + 1} of ${rows.value.length}`);
}
const pick = (item) => store.select([item.elId]);
const ordered = computed(() => rows.value);
const stepWord = (r) => `${PHASE_LABEL[r.step.ph]} ${FX_LABEL[r.step.fx].toLowerCase()}, ${START_WORD[r.step.on]}`;
const anyHasAnim = computed(() => sel.value.some((e) => e.anim?.length));

// play popover
const playBtn = ref(null);
const playing = ref(false);
</script>

<template>
    <div ref="top" class="an">
        <PanelSection id="animate.steps" title="Animation" :state="one ? String(steps.length) : sel.length + ' selected'">
            <p v-if="one" class="pr-small an-who"><Ico :name="GLYPH[one.type] || 'shape'" size="sm" /><span>{{ elementLabel(one) }}</span></p>
            <InlineNotice v-else kind="info">{{ sel.length }} elements are selected. An animation you add is given to each of them and they play together. Select one element to edit its steps.</InlineNotice>

            <SelectMenu
                label="Add animation" hide-label placeholder="Add animation" :width="288" :model-value="null" :options="addOptions" :disabled="!pc.canEdit.value || full"
                @update:model-value="add"
            />
            <p v-if="full" class="pr-small c-muted">{{ one ? 'This element has' : 'Each element has' }} {{ MAX_STEPS }} steps, the most the deck keeps.</p>

            <template v-if="one">
                <EmptyState v-if="!steps.length" compact title="Not animated." body="It is on the slide from the start. Add an entrance to bring it in on a click." />
                <ol v-else class="an-steps" aria-label="Animation steps">
                    <li v-for="{ s, index } in steps" :key="s.id || index" class="an-step">
                        <header class="an-sh">
                            <span class="an-ph pr-state">{{ s.ph }}</span>
                            <SelectMenu
                                class="an-fx" :label="`Effect for step ${index + 1}`" hide-label :model-value="s.fx" :options="fxOptions(s.ph)" :disabled="!pc.canEdit.value"
                                @update:model-value="(v) => edit(index, { fx: v })"
                            />
                            <IconButton icon="trash" :label="`Remove step ${index + 1}`" size="sm" danger :disabled="!pc.canEdit.value" @click="remove(index)" />
                        </header>
                        <Segmented label="Start" :model-value="s.on" :options="STARTS" :disabled="!pc.canEdit.value" @update:model-value="(v) => edit(index, { on: v })" />
                        <div class="c-grid2">
                            <NumberField label="Delay" unit="ms" :min="0" :max="30000" :step="50" :model-value="s.delay ?? 0" :disabled="!pc.canEdit.value" @update:model-value="(v) => edit(index, { delay: v }, true)" />
                            <NumberField label="Duration" unit="ms" :min="0" :max="20000" :step="50" :model-value="s.dur ?? 500" :disabled="!pc.canEdit.value" @update:model-value="(v) => edit(index, { dur: v }, true)" />
                        </div>
                        <Segmented label="Easing" hide-label :model-value="s.ease || 'out'" :options="EASE_LABELS" :disabled="!pc.canEdit.value" @update:model-value="(v) => edit(index, { ease: v })" />
                    </li>
                </ol>
            </template>
            <button v-if="anyHasAnim" type="button" class="pr-btn pr-btn--ghost pr-btn--sm an-clear" :disabled="!pc.canEdit.value" @click="clearAll">Remove {{ sel.length > 1 ? 'their' : 'all' }} animations</button>
        </PanelSection>

        <PanelSection id="animate.sequence" title="Sequence" :state="`${summary.steps} ${summary.steps === 1 ? 'step' : 'steps'}`">
            <template #actions>
                <button ref="playBtn" type="button" class="pr-btn pr-btn--sm" :disabled="!rows.length" :aria-expanded="playing" aria-haspopup="dialog" @click="playing = !playing"><Ico name="present" size="sm" /> Play</button>
            </template>
            <EmptyState v-if="!rows.length" compact title="No steps on this slide." body="Add an animation to an element and its place in the play order shows here." />
            <template v-else>
                <ReorderList :items="ordered" item-key="key" label="Play order" :selected-key="selectedKey" interactive :handle-label="(r) => `Move ${elementLabel(r.el)}, ${FX_LABEL[r.step.fx]}`" @reorder="onReorder" @select="pick">
                    <template #default="{ item }">
                        <span class="an-r" :class="{ 'is-sel': selectedIds.has(item.elId), 'is-first': item.first }">
                            <span v-if="item.number !== null" class="an-n pr-state" :aria-label="`Click ${item.number}`">{{ item.number }}</span>
                            <span v-else-if="item.group === 0 && item.first" class="an-n an-n--auto pr-state" aria-label="Plays by itself">0</span>
                            <span v-else class="an-n an-n--join" :aria-label="item.step.on === 'with' ? 'With previous' : 'After previous'" role="img"><i></i></span>
                            <span class="an-t">
                                <span class="an-name">{{ elementLabel(item.el) }}</span>
                                <span class="an-fxl">{{ stepWord(item) }}</span>
                            </span>
                        </span>
                    </template>
                </ReorderList>
                <p class="pr-small an-clicks"><span class="pr-state">{{ summary.clicks }} {{ summary.clicks === 1 ? 'click' : 'clicks' }}</span><span v-if="summary.entry" class="pr-state">{{ summary.entry }} {{ summary.entry === 1 ? 'step plays' : 'steps play' }} by themselves</span></p>
                <p class="pr-small c-muted an-help">Drag a handle, or focus it and press the arrow keys. This changes the play order only, never how the elements are layered.</p>
                <InlineNotice v-if="hiddenCount" kind="info">{{ hiddenCount }} hidden {{ hiddenCount === 1 ? 'element has' : 'elements have' }} animations that do not play while {{ hiddenCount === 1 ? 'it is' : 'they are' }} hidden.</InlineNotice>
            </template>
        </PanelSection>

        <Popover v-model:open="playing" :anchor="playBtn" placement="bottom-end" :width="328" label="Play the slide's animations">
            <PlaySequence v-if="slide" :deck="editor.deck.value" :assets="editor.assets" :slide="slide" />
        </Popover>
    </div>
</template>

<style>
.an { padding-bottom: 24px; }
.an .c-sec-b { grid-template-columns: minmax(0, 1fr); }
.an-who { margin: 0; display: flex; align-items: center; gap: 8px; color: var(--ink-2); font-weight: 500; min-width: 0; }
.an-who span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.an-steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.an-step { display: grid; gap: 10px; padding: 10px; border: 1px solid var(--line); border-radius: var(--r-2); background: var(--raised); }
.an-sh { display: flex; align-items: center; gap: 8px; }
.an-ph { flex: none; min-width: 40px; color: var(--muted); }
.an-fx { flex: 1; min-width: 0; }
.an-clear { justify-self: start; color: var(--muted); }
.an-r { display: flex; align-items: center; gap: 10px; min-width: 0; width: 100%; }
.an-n { flex: none; display: grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; background: var(--ink); color: var(--sheet); font-size: var(--t-xs); line-height: 1; box-sizing: border-box; }
.an-n--auto { background: transparent; color: var(--ink); border: 1.5px solid var(--ink); }
.an-n--join { background: transparent; }
.an-n--join i { width: 8px; height: 8px; border-radius: 50%; border: 1.5px solid var(--muted); box-sizing: border-box; }
.an-r.is-sel .an-n--join i { border-color: var(--ink); }
.an-r { position: relative; }
.an-r.is-sel::before { content: ''; position: absolute; left: -5px; top: 50%; width: 6px; height: 6px; margin-top: -3px; border-radius: 50%; background: var(--amber); box-shadow: 0 0 0 1px var(--ink); }
.an-t { display: grid; min-width: 0; text-align: left; }
.an-name { font-size: var(--t-sm); font-weight: 500; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.an-fxl { font-size: var(--t-xs); color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.an-help { margin: 0; }
.an-clicks { margin: 0; display: flex; flex-wrap: wrap; gap: 4px 14px; color: var(--ink-2); }
.an-steps, .an-step, .an-t { grid-template-columns: minmax(0, 1fr); }
@media (pointer: coarse) { .an .pr-btn { --h: 44px; } .c-sec-actions .pr-btn { --h: 44px; } }
</style>
