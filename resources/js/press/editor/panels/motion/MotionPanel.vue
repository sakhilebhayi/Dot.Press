<script setup>
// Motion: the transition into the current slide, a looping preview, apply to all or to a section, and the morph helper.
import { computed, onMounted, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import { Dialog, EmptyState, Field, InlineNotice, NumberField, PanelSection, SelectMenu, Segmented } from '../../../ui/controls';
import Ico from '../../ui/Ico.vue';
import TransitionList from './TransitionList.vue';
import TransitionPreview from './TransitionPreview.vue';
import {
    DIR_OPTIONS, EASE_OPTIONS, MAX_DUR, MIN_DUR, changeTransition, describeTransition, isDirectional, optionFor, previewPair, readChoice, sectionTitle, targetSlideIds, transitionOps,
} from './transitionModel.js';
import { analyzeMorph, elementLabel, pairOps, suggestPartners, unpairOps } from './morphModel.js';

const editor = useEditorContext();
const top = ref(null);
onMounted(() => top.value?.closest('.ph')?.scrollTo(0, 0));

const { store } = editor;
const slide = editor.slide;
const doc = computed(() => editor.state.value.doc);
const canEdit = computed(() => !editor.readOnly && editor.can?.update !== false);

const choice = computed(() => readChoice(slide.value));
const transition = computed(() => (choice.value.type === 'none' ? null : { type: choice.value.type, dir: choice.value.dir, dur: choice.value.dur, ease: choice.value.ease }));
const pair = computed(() => (slide.value ? previewPair(doc.value, slide.value.id) : null));
const number = (s) => doc.value.slides.findIndex((x) => x.id === s?.id) + 1;
const slideNumber = computed(() => number(slide.value));
const isFirst = computed(() => slideNumber.value === 1);
const directional = computed(() => isDirectional(choice.value.type));
const summary = computed(() => describeTransition(transition.value));
const hint = computed(() => optionFor(choice.value.type).hint);

function change(c, label = 'Transition') {
    if (!canEdit.value || !slide.value) return;
    const next = changeTransition(slide.value, c);
    const ops = transitionOps(doc.value, [slide.value.id], next);
    if (!ops.length) return;
    store.dispatch(ops, { undoKey: `transition:${slide.value.id}`, label });
    editor.announce(describeTransition(next));
}
const setType = (type) => change({ type });
const setDir = (dir) => change({ dir });
const setDur = (dur) => change({ dur });
const setEase = (ease) => change({ ease });

// ---- apply to many ----
const confirm = ref({ open: false, scope: 'all' });
const scopeIds = computed(() => targetSlideIds(doc.value, slide.value, confirm.value.scope));
const scopeOps = computed(() => transitionOps(doc.value, scopeIds.value, transition.value));
const sectionName = computed(() => sectionTitle(doc.value, slide.value));
const sectionCount = computed(() => targetSlideIds(doc.value, slide.value, 'section').length);
const allCount = computed(() => doc.value.slides.length);
function ask(scope) { confirm.value = { open: true, scope }; }
function applyMany() {
    const n = scopeOps.value.length;
    if (n) {
        store.dispatch(scopeOps.value, { label: confirm.value.scope === 'all' ? 'Transition on all slides' : 'Transition on section', undoKey: null });
        editor.toast(`${transition.value ? optionFor(transition.value.type).label : 'No transition'} set on ${n} ${n === 1 ? 'slide' : 'slides'}`, { kind: 'ok' });
        editor.announce(`Transition applied to ${n} slides`);
    }
    confirm.value.open = false;
}
const scopeWord = computed(() => (confirm.value.scope === 'all' ? 'all slides' : sectionName.value ? `the section "${sectionName.value}"` : 'this section'));

// ---- morph ----
const morph = computed(() => (pair.value ? analyzeMorph(pair.value.from, pair.value.to) : null));
const morphOpen = ref(false);
function run(ops, label) {
    if (!canEdit.value || !ops.length) return;
    store.dispatch(ops, { label, undoKey: null });
}
function pairWith(bEl, aId) {
    const aEl = morph.value.onlyFrom.find((e) => e.id === aId);
    if (!aEl) return;
    run(pairOps(pair.value.from, pair.value.to, aEl, bEl), 'Pair for morph');
    editor.announce(`${elementLabel(aEl)} paired with ${elementLabel(bEl)}`);
}
function unpair(p) {
    run(unpairOps(pair.value.from, pair.value.to, p), 'Unpair morph');
    editor.announce(`${elementLabel(p.b)} unpaired`);
}
const partnerOptions = (bEl) => suggestPartners(morph.value.onlyFrom, bEl).map((a) => ({ value: a.id, label: elementLabel(a), hint: 'on slide ' + number(pair.value.from) }));
</script>

<template>
    <div ref="top" class="mo">
        <PanelSection id="motion.transition" title="Transition" :state="choice.type === 'none' ? 'cut' : choice.type">
            <p class="pr-small c-muted mo-lede">How slide {{ slideNumber }} arrives when you present.</p>
            <TransitionList :model-value="choice.type" :dir="choice.dir" :disabled="!canEdit" @update:model-value="setType" />
            <p class="pr-small c-muted mo-hint">{{ hint }}</p>
            <template v-if="choice.type !== 'none'">
                <Segmented v-if="directional" label="Enters from" :model-value="choice.dir" :options="DIR_OPTIONS" :disabled="!canEdit" @update:model-value="setDir" />
                <NumberField label="Duration" unit="ms" :min="MIN_DUR" :max="MAX_DUR" :step="50" :model-value="choice.dur" :disabled="!canEdit" @update:model-value="setDur" />
                <Segmented label="Easing" :model-value="choice.ease" :options="EASE_OPTIONS" :disabled="!canEdit" @update:model-value="setEase" />
                <p class="pr-small c-muted mo-sum">{{ summary }}</p>
            </template>
            <InlineNotice v-if="isFirst" kind="info">
                The first slide has nothing before it, so this transition never plays. Set it on the slide that follows to change how that one arrives.
            </InlineNotice>
        </PanelSection>

        <PanelSection id="motion.preview" title="Preview" :state="pair ? `${number(pair.from)} to ${number(pair.to)}` : ''">
            <TransitionPreview
                v-if="pair" :deck="editor.deck.value" :assets="editor.assets" :from="pair.from" :to="pair.to" :transition="transition"
                :from-number="number(pair.from)" :to-number="number(pair.to)"
            />
            <EmptyState v-else compact title="Add a second slide." body="A transition needs two slides to play between." />
            <p v-if="pair?.fallback" class="pr-small c-muted">Slide 1 has no slide before it, so the preview shows how slide {{ number(pair.to) }} would arrive with this setting.</p>
        </PanelSection>

        <PanelSection id="motion.apply" title="Apply to more slides" :default-open="true">
            <div class="mo-apply">
                <button type="button" class="pr-btn" :disabled="!canEdit" @click="ask('all')">All slides ({{ allCount }})</button>
                <button type="button" class="pr-btn" :disabled="!canEdit" @click="ask('section')">This section ({{ sectionCount }})</button>
            </div>
            <p class="pr-small c-muted">Copies this slide's transition, or its absence, onto the slides you choose. One undo step brings the old ones back.</p>
        </PanelSection>

        <PanelSection id="motion.morph" title="Morph pairs" :state="morph ? `${morph.pairs.length} paired` : ''" :default-open="choice.type === 'morph'">
            <p class="pr-small c-muted">Morph moves an element from where it sits on the slide before to where it sits here, and fades everything else. Elements pair when they share an id or a morph key and are the same kind.</p>
            <template v-if="morph">
                <ul v-if="morph.pairs.length" class="mo-pairs" aria-label="Paired elements">
                    <li v-for="p in morph.pairs" :key="p.bId" class="mo-pair">
                        <span class="mo-bead" aria-hidden="true"></span>
                        <span class="mo-pl"><span class="mo-pn">{{ elementLabel(p.a) }} to {{ elementLabel(p.b) }}</span><span class="pr-state mo-pw">{{ p.via === 'key' ? 'key ' + p.key : 'same id' }}</span></span>
                        <button v-if="canEdit" type="button" class="pr-btn pr-btn--sm pr-btn--ghost" :aria-label="`Unpair ${elementLabel(p.b)}`" @click="unpair(p)">Unpair</button>
                    </li>
                </ul>
                <p v-else class="pr-small mo-none"><Ico name="more" size="sm" /> Nothing is paired yet.</p>

                <div v-if="morph.onlyTo.length" class="mo-un">
                    <p class="c-label">Not paired on slide {{ number(pair.to) }}</p>
                    <div v-for="b in morph.onlyTo" :key="b.id" class="mo-unrow">
                        <span class="mo-ring" aria-hidden="true"></span>
                        <span class="mo-pn mo-un-n">{{ elementLabel(b) }}</span>
                        <SelectMenu
                            v-if="partnerOptions(b).length" :model-value="null" :options="partnerOptions(b)" placeholder="Pair with" :width="232" :disabled="!canEdit" :label="`Pair ${elementLabel(b)} with`" hide-label
                            @update:model-value="(v) => pairWith(b, v)"
                        />
                        <span v-else class="pr-small c-muted">no match</span>
                    </div>
                </div>
            </template>
            <p v-else class="pr-small c-muted">Add a second slide to pair elements.</p>
            <InlineNotice v-if="morph && choice.type !== 'morph'" kind="info">Pairs only move when this slide's transition is Morph.</InlineNotice>
        </PanelSection>

        <Dialog v-model:open="confirm.open" title="Apply this transition?" size="sm">
            <p class="mo-dl">
                <strong>{{ transition ? describeTransition(transition) : 'No transition' }}</strong>
                will be set on {{ scopeWord }}.
                <template v-if="scopeOps.length">{{ scopeOps.length }} {{ scopeOps.length === 1 ? 'slide changes' : 'slides change' }}; their current transitions are replaced.</template>
                <template v-else>Every one of them already has it, so nothing changes.</template>
            </p>
            <template #footer="{ close }">
                <button type="button" class="pr-btn" @click="close">Cancel</button>
                <button type="button" class="pr-btn pr-btn--primary" :disabled="!scopeOps.length" data-autofocus @click="applyMany">Apply</button>
            </template>
        </Dialog>
    </div>
</template>

<style>
.mo { padding-bottom: 24px; }
.mo .c-sec-b { grid-template-columns: minmax(0, 1fr); }
.mo-lede, .mo-hint, .mo-sum { margin: 0; }
.mo-apply { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.mo-apply .pr-btn { padding: 0 8px; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
.mo-pairs { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.mo-pair, .mo-unrow { display: flex; align-items: center; gap: 8px; min-height: 40px; padding: 2px 4px 2px 8px; border: 1px solid var(--line); border-radius: var(--r-2); background: var(--raised); }
.mo-pl { flex: 1; min-width: 0; display: grid; }
.mo-pn { font-size: var(--t-sm); font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mo-pw { color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mo-bead { flex: none; width: 10px; height: 10px; border-radius: 50%; background: var(--ink); }
.mo-ring { flex: none; width: 10px; height: 10px; border-radius: 50%; border: 1.5px solid var(--ink); box-sizing: border-box; }
.mo-un { display: grid; gap: 6px; }
.mo-un .c-label { margin: 0; }
.mo-un-n { flex: 1; min-width: 0; }
.mo-unrow .c-field { flex: none; width: 128px; }
.mo-none { margin: 0; color: var(--muted); display: flex; align-items: center; gap: 6px; }
.mo-dl { margin: 0; font-size: var(--t-md); line-height: 1.5; }
.mo-pairs, .mo-un { grid-template-columns: minmax(0, 1fr); }
@media (pointer: coarse) { .mo .pr-btn { --h: 44px; } .mo-pair, .mo-unrow { min-height: 48px; } }
</style>
