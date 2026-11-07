<script setup>
// Slide size. Pick a preset or a custom size, then how the existing slides should change: each of the three modes shows
// the current slide before and after, drawn by SlideView on a copy of the document with the same geometry the server uses.
// Confirming sends deck.resize; the server then reloads every open editor.
import { computed, ref, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import {
    Dialog, InlineNotice, NumberField, PanelSection,
} from '../../../ui/controls';
import { SlideView } from '../../../render/index.js';
import { columnsOf, gridTarget } from '../theme/gridNav.js';
import {
    DEFAULT_MODE, MAX_DIM, MIN_DIM, MODES, PRESETS, modeFact, presetFor, previewPair, ratioLabel, validSize,
} from './sizeModel.js';
import '../theme/design.css';

const editor = useEditorContext();
const { store } = editor;

const deck = computed(() => editor.deck.value);
const doc = computed(() => editor.state.value.doc);
const canEdit = computed(() => !editor.readOnly && !!editor.can.update);
const slideCount = computed(() => doc.value.slides.length);

const target = ref({ w: deck.value.w, h: deck.value.h });
const presetId = ref(presetFor(deck.value.w, deck.value.h));
const mode = ref(DEFAULT_MODE);
const confirm = ref(false);
const busy = ref(false);
const done = ref('');

const same = computed(() => target.value.w === deck.value.w && target.value.h === deck.value.h);
const valid = computed(() => validSize(target.value.w, target.value.h));
const ready = computed(() => valid.value && !same.value);
const modeInfo = computed(() => MODES.find((m) => m.id === mode.value));

const previews = computed(() => {
    if (!ready.value || !doc.value.slides.length) return [];
    const id = editor.state.value.slideId;
    return MODES.map((m) => ({ ...m, ...previewPair(doc.value, id, target.value.w, target.value.h, m.id), fact: modeFact(doc.value, target.value.w, target.value.h, m.id) }));
});

function usePreset(p) {
    presetId.value = p.id;
    target.value = { w: p.w, h: p.h };
}
function useCustom() {
    presetId.value = 'custom';
}
function setDim(key, v) {
    const n = Number(v);
    if (!Number.isFinite(n)) return;
    target.value = { ...target.value, [key]: Math.round(n) };
}
watch(() => [deck.value.w, deck.value.h], ([w, h]) => {
    // the server resized (here or by someone else): follow it
    target.value = { w, h };
    presetId.value = presetFor(w, h);
});

const choices = computed(() => [...PRESETS, { id: 'custom', name: 'Custom', ratio: ratioLabel(target.value.w, target.value.h), w: target.value.w, h: target.value.h }]);
async function onPresetKey(e) {
    const cards = [...e.currentTarget.querySelectorAll('[role="radio"]')];
    const i = cards.indexOf(document.activeElement);
    if (i < 0) return;
    const j = gridTarget(e.key, i, cards.length, columnsOf(cards.map((c) => c.getBoundingClientRect().top)));
    if (j === null) return;
    e.preventDefault();
    if (choices.value[j].id === 'custom') useCustom(); else usePreset(choices.value[j]);
    await Promise.resolve();
    cards[j].focus();
}
async function onModeKey(e) {
    const to = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[e.key];
    if (!to) return;
    e.preventDefault();
    const i = MODES.findIndex((m) => m.id === mode.value);
    mode.value = MODES[(i + to + MODES.length) % MODES.length].id;
    await Promise.resolve();
    e.currentTarget.parentElement.querySelectorAll('[role="radio"]')[MODES.findIndex((m) => m.id === mode.value)]?.focus();
}

const boxWidth = (d, cap = 112) => `min(100%, ${Math.round((d.w / d.h) * cap)}px)`;

async function resize(close) {
    if (!ready.value || !canEdit.value) return;
    busy.value = true;
    const { w, h } = target.value;
    const m = modeInfo.value;
    const r = store.resizeDeck(w, h, mode.value);
    busy.value = false;
    close();
    if (!r) {
        editor.toast('The server did not accept that size. Use whole numbers from 320 to 7680.', { kind: 'error' });
        return;
    }
    done.value = `Resized ${slideCount.value} ${slideCount.value === 1 ? 'slide' : 'slides'} to ${w} x ${h} (${m.label.toLowerCase()}).`;
    editor.toast(`Slides resized to ${w} x ${h}. The editor reloads to show them.`, { kind: 'ok' });
    editor.announce(done.value);
}
</script>

<template>
    <div class="dz dz-size">
        <div class="dz-pad c-stack">
            <p class="pr-small">
                The slides are <span class="pr-state">{{ deck.w }} x {{ deck.h }}</span> now ({{ ratioLabel(deck.w, deck.h) }}).
            </p>
            <InlineNotice v-if="done" kind="ok">{{ done }}</InlineNotice>
            <InlineNotice kind="warn" title="This changes every slide">
                The size belongs to the whole deck. All {{ slideCount }} {{ slideCount === 1 ? 'slide' : 'slides' }} are moved to fit, and everyone editing this deck sees the editor reload.
            </InlineNotice>
        </div>

        <PanelSection id="size.new" title="New size" :state="valid ? `${target.w} x ${target.h}` : 'check size'" :collapsible="false">
            <div class="dz-presets" role="radiogroup" aria-label="Slide size" @keydown="onPresetKey">
                <button
                    v-for="p in choices" :key="p.id" type="button" role="radio" class="dz-preset" :aria-checked="presetId === p.id" :tabindex="presetId === p.id ? 0 : -1"
                    :disabled="!canEdit" @click="p.id === 'custom' ? useCustom() : usePreset(p)"
                >
                    <span class="dz-preset-shape" aria-hidden="true">
                        <i :style="{ width: `${Math.round(p.w / Math.max(p.w, p.h) * 34)}px`, height: `${Math.round(p.h / Math.max(p.w, p.h) * 34)}px` }"></i>
                    </span>
                    <span class="dz-preset-n">{{ p.name }}</span>
                    <span class="pr-state pr-muted">{{ p.id === 'custom' ? 'your numbers' : `${p.w} x ${p.h}` }}</span>
                </button>
            </div>
            <div v-if="presetId === 'custom'" class="c-grid2">
                <NumberField :model-value="target.w" label="Width" unit="px" :min="MIN_DIM" :max="MAX_DIM" :step="10" :disabled="!canEdit" @update:model-value="setDim('w', $event)" />
                <NumberField :model-value="target.h" label="Height" unit="px" :min="MIN_DIM" :max="MAX_DIM" :step="10" :disabled="!canEdit" @update:model-value="setDim('h', $event)" />
            </div>
            <p v-if="presetId === 'custom'" class="c-hint">Whole numbers from {{ MIN_DIM }} to {{ MAX_DIM }}.</p>
            <InlineNotice v-if="!valid" kind="error">Width and height must be whole numbers from {{ MIN_DIM }} to {{ MAX_DIM }}.</InlineNotice>
            <p v-else-if="same" class="c-hint">That is the size the slides already have.</p>
            <p v-else class="c-hint">The shape changes from {{ ratioLabel(deck.w, deck.h) }} to {{ ratioLabel(target.w, target.h) }}.</p>
        </PanelSection>

        <PanelSection v-if="ready" id="size.mode" title="What happens to the slides" :state="`slide ${doc.slides.findIndex((s) => s.id === editor.state.value.slideId) + 1}`" :collapsible="false">
            <ul class="dz-modes" role="radiogroup" aria-label="How the slides change">
                <li v-for="m in previews" :key="m.id" class="dz-mode" :data-on="mode === m.id">
                    <button
                        type="button" role="radio" class="dz-mode-h" :aria-checked="mode === m.id" :tabindex="mode === m.id ? 0 : -1" :disabled="!canEdit"
                        @click="mode = m.id" @keydown="onModeKey"
                    >
                        <span class="pr-bead" :data-state="mode === m.id ? 'solid' : 'hollow'" aria-hidden="true"></span>{{ m.label }}
                    </button>
                    <div class="dz-ba">
                        <figure class="dz-ba-c">
                            <div class="dz-ba-s"><div :style="{ width: boxWidth(m.before.deck) }"><SlideView :slide="m.before.slide" :deck="m.before.deck" :assets="editor.assets" mode="thumb" /></div></div>
                            <figcaption>before {{ m.before.deck.w }} x {{ m.before.deck.h }}</figcaption>
                        </figure>
                        <figure class="dz-ba-c">
                            <div class="dz-ba-s"><div :style="{ width: boxWidth(m.after.deck) }"><SlideView :slide="m.after.slide" :deck="m.after.deck" :assets="editor.assets" mode="thumb" /></div></div>
                            <figcaption>after {{ m.after.deck.w }} x {{ m.after.deck.h }}</figcaption>
                        </figure>
                    </div>
                    <p class="pr-small">{{ m.text }}</p>
                    <p class="c-hint">{{ m.fact }}</p>
                </li>
            </ul>
        </PanelSection>

        <div class="dz-bar" role="group" aria-label="Resize the deck">
            <p class="pr-small dz-bar-s">
                <template v-if="ready">Resize {{ slideCount }} {{ slideCount === 1 ? 'slide' : 'slides' }} to {{ target.w }} x {{ target.h }}: {{ modeInfo.label.toLowerCase() }}.</template>
                <template v-else>Choose a different size to continue.</template>
            </p>
            <p v-if="!canEdit" class="c-hint">You can look at the size but your role does not allow changing it.</p>
            <div class="dz-bar-a">
                <button type="button" class="pr-btn pr-btn--sm pr-btn--primary" :disabled="!ready || !canEdit || busy" @click="confirm = true">Resize slides</button>
            </div>
        </div>

        <Dialog v-model:open="confirm" title="Resize every slide?" size="sm">
            <p class="pr-small">
                All {{ slideCount }} {{ slideCount === 1 ? 'slide' : 'slides' }} go from {{ deck.w }} x {{ deck.h }} to {{ target.w }} x {{ target.h }}.
                Mode: <strong>{{ modeInfo.label }}</strong>. {{ modeInfo.text }}
            </p>
            <p class="pr-small">Everyone editing this deck will see the editor reload once the server has resized it.</p>
            <template #footer="{ close }">
                <button type="button" class="pr-btn" @click="close">Cancel</button>
                <button type="button" class="pr-btn pr-btn--primary" :disabled="busy" @click="resize(close)">Resize {{ slideCount }} {{ slideCount === 1 ? 'slide' : 'slides' }}</button>
            </template>
        </Dialog>
    </div>
</template>
