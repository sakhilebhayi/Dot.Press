<script setup>
// Plays the slide's sequence on a copy of the slide with the real player runtime: the entry group first, then each
// click in turn. "Play" runs every click by itself; "Next click" steps by hand like pressing Next while presenting.
import { computed, onBeforeUnmount, ref } from 'vue';
import { reducedMotion } from '../../../render/env.js';
import { buildSequence } from '../../../player/sequence.js';
import MiniStage from '../motion/MiniStage.vue';

const props = defineProps({
    deck: { type: Object, required: true },
    assets: { type: Object, default: () => ({}) },
    slide: { type: Object, required: true },
});
const copy = computed(() => [{ ...JSON.parse(JSON.stringify(props.slide)), hidden: false, transition: null }]);
const clicks = computed(() => buildSequence(props.slide).clicks);
const pos = ref({ slide: 0, step: 0 });
const key = ref(0);
const auto = ref(false);
const settledStep = ref(0);
let timer = null;
let alive = true;

const clear = () => { if (timer) { clearTimeout(timer); timer = null; } };
function restart(autoRun) {
    clear();
    key.value += 1;
    pos.value = { slide: 0, step: 0 };
    settledStep.value = 0;
    auto.value = autoRun;
}
function next() {
    auto.value = false;
    clear();
    if (pos.value.step < clicks.value) pos.value = { slide: 0, step: pos.value.step + 1 };
}
function onSettled(p) {
    settledStep.value = p.step;
    if (!auto.value) return;
    clear();
    if (p.step >= clicks.value) { auto.value = false; return; }
    timer = setTimeout(() => { if (alive && auto.value) pos.value = { slide: 0, step: p.step + 1 }; }, 650);
}
restart(true);
onBeforeUnmount(() => { alive = false; clear(); });
const status = computed(() => (clicks.value === 0 ? 'No clicks. Everything plays by itself.' : `Click ${Math.min(pos.value.step, clicks.value)} of ${clicks.value}`));
</script>

<template>
    <div class="ps">
        <MiniStage :key="key" :deck="deck" :slides="copy" :assets="assets" :position="pos" label="Animation sequence preview" @settled="onSettled" />
        <p class="ps-status pr-state" role="status">{{ status }}</p>
        <p v-if="reducedMotion" class="pr-small ps-note">Reduced motion is on: entrances and exits appear with a short fade and emphasis is skipped.</p>
        <div class="ps-bar">
            <button type="button" class="pr-btn pr-btn--sm pr-btn--primary" data-autofocus @click="restart(true)">Play from start</button>
            <button type="button" class="pr-btn pr-btn--sm" :disabled="pos.step >= clicks" @click="next">Next click</button>
        </div>
    </div>
</template>

<style>
.ps { display: grid; gap: 8px; padding: 12px; width: 100%; min-width: 0; }
.ps-status { margin: 0; color: var(--muted); }
.ps-note { margin: 0; color: var(--ink-2); }
.ps-bar { display: flex; gap: 8px; }
.ps-bar .pr-btn { flex: 1; }
@media (pointer: coarse) { .ps-bar .pr-btn { --h: 44px; } }
.ps { grid-template-columns: minmax(0, 1fr); }
</style>
