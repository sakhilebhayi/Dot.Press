<script setup>
// Plays the slide transition between two slide copies inside the panel, with the real player runtime. It runs once
// when the transition changes, can loop until stopped, and under reduced motion shows the instant state instead.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { reducedMotion } from '../../../render/env.js';
import Ico from '../../ui/Ico.vue';
import MiniStage from './MiniStage.vue';
import { HOLD_AFTER, HOLD_BEFORE } from './transitionModel.js';

const props = defineProps({
    deck: { type: Object, required: true },
    assets: { type: Object, default: () => ({}) },
    from: { type: Object, required: true },
    to: { type: Object, required: true },
    /** the transition under edit (null = a cut); it is put on the arriving copy so a what-if previews like the saved one */
    transition: { type: Object, default: null },
    fromNumber: { type: Number, default: 1 },
    toNumber: { type: Number, default: 2 },
});

const copy = (s) => JSON.parse(JSON.stringify(s));
const slides = computed(() => [{ ...copy(props.from), hidden: false, transition: null }, { ...copy(props.to), hidden: false, transition: props.transition ? { ...props.transition } : null }]);
const pos = ref({ slide: 0, step: 0 });
const stageKey = ref(0);
const looping = ref(false);
const running = ref(false);
const shownSlide = computed(() => pos.value.slide);
let timer = null;
let alive = true;

const clear = () => { if (timer) { clearTimeout(timer); timer = null; } };
function showFirst() {
    clear();
    running.value = false;
    stageKey.value += 1;
    pos.value = { slide: 0, step: 0 };
}
function runOnce() {
    clear();
    if (reducedMotion.value) { showFirst(); return; }
    stageKey.value += 1;
    pos.value = { slide: 0, step: 0 };
    running.value = true;
    timer = setTimeout(() => { if (alive) pos.value = { slide: 1, step: 0 }; }, HOLD_BEFORE);
}
function onSettled(p) {
    if (!running.value || p.slide !== 1) return;
    clear();
    timer = setTimeout(() => {
        if (!alive) return;
        if (looping.value) runOnce();
        else running.value = false;
    }, HOLD_AFTER);
}
function toggleLoop() {
    looping.value = !looping.value;
    if (looping.value) runOnce();
    else if (!running.value) clear();
}
function flip() {
    pos.value = { slide: pos.value.slide === 0 ? 1 : 0, step: 0 };
}

// A changed transition (or other slide) restarts the preview from the first slide.
watch(() => JSON.stringify([props.transition, props.from.id, props.to.id]), () => { if (reducedMotion.value) showFirst(); else runOnce(); }, { flush: 'post' });
watch(reducedMotion, (r) => { if (r) { looping.value = false; showFirst(); } else runOnce(); });
runOnce();
onBeforeUnmount(() => { alive = false; clear(); });

const live = computed(() => (reducedMotion.value
    ? 'Reduced motion is on, so the preview shows the instant change instead of the movement.'
    : looping.value ? 'Looping. Stop it with the Loop button.' : running.value ? 'Playing the transition.' : 'Press Replay to see it again.'));
defineExpose({ runOnce });
</script>

<template>
    <div class="tp">
        <MiniStage :key="stageKey" :deck="deck" :slides="slides" :assets="assets" :position="pos" :animate="!reducedMotion" :label="`Transition preview from slide ${fromNumber} to slide ${toNumber}`" @settled="onSettled" />
        <div class="tp-bar">
            <span class="tp-now pr-state" aria-hidden="true">
                <i class="tp-dot" :class="{ 'is-on': shownSlide === 0 }"></i><i class="tp-dot" :class="{ 'is-on': shownSlide === 1 }"></i>
                <span>{{ fromNumber }} to {{ toNumber }}</span>
            </span>
            <span class="tp-btns">
                <template v-if="reducedMotion">
                    <button type="button" class="pr-btn pr-btn--sm" @click="flip">{{ shownSlide === 0 ? 'Show slide ' + toNumber : 'Show slide ' + fromNumber }}</button>
                </template>
                <template v-else>
                    <button type="button" class="pr-btn pr-btn--sm" @click="runOnce"><Ico name="redo" size="sm" /> Replay</button>
                    <button type="button" class="pr-btn pr-btn--sm tp-loop" :aria-pressed="looping" @click="toggleLoop">{{ looping ? 'Stop loop' : 'Loop' }}</button>
                </template>
            </span>
        </div>
        <p class="tp-live pr-small" :class="{ 'is-note': reducedMotion }" role="status">{{ live }}</p>
    </div>
</template>

<style>
.tp { display: grid; gap: 8px; }
.tp-bar { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-height: 32px; }
.tp-now { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); }
.tp-dot { width: 8px; height: 8px; border-radius: 50%; border: 1.5px solid var(--ink); box-sizing: border-box; }
.tp-dot.is-on { background: var(--amber); border-color: var(--ink); }
.tp-now > span { margin-left: 4px; }
.tp-btns { display: inline-flex; align-items: center; gap: 6px; }
.tp-live { margin: 0; color: var(--muted); }
.tp-live.is-note { color: var(--ink-2); }
@media (pointer: coarse) { .tp-btns .pr-btn { --h: 44px; } }
.tp { grid-template-columns: minmax(0, 1fr); }
</style>
