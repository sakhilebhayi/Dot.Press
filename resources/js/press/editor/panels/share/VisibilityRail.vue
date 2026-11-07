<script setup>
// The four steps of who can open the deck, on a rail: Private, Shared, Team, Public link. A radiogroup (arrow keys move and
// choose). The selected step is the amber bead; a step further along the rail reaches more people.
import { nextTick, ref } from 'vue';
import { LEVELS } from './sharingModel.js';

const props = defineProps({
    modelValue: { type: String, required: true },
    disabled: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const root = ref(null);
const index = () => LEVELS.findIndex((l) => l.value === props.modelValue);

async function key(e) {
    const i = index();
    const to = { ArrowDown: i + 1, ArrowRight: i + 1, ArrowUp: i - 1, ArrowLeft: i - 1, Home: 0, End: LEVELS.length - 1 }[e.key];
    if (to === undefined || props.disabled) return;
    e.preventDefault();
    const j = (to + LEVELS.length) % LEVELS.length;
    emit('update:modelValue', LEVELS[j].value);
    await nextTick();
    root.value?.querySelectorAll('[role=radio]')[j]?.focus();
}
</script>

<template>
    <div ref="root" class="shv" role="radiogroup" aria-label="Who can open this deck" :aria-busy="busy" @keydown="key">
        <button
            v-for="(l, i) in LEVELS" :key="l.value" type="button" role="radio" class="shv-step" :class="{ 'is-on': l.value === modelValue, 'is-before': i < index() }" :aria-checked="l.value === modelValue"
            :tabindex="l.value === modelValue ? 0 : -1" :disabled="disabled" @click="emit('update:modelValue', l.value)"
        >
            <span class="pr-bead shv-bead" :data-state="l.value === modelValue ? 'current' : (i < index() ? 'solid' : 'hollow')" :data-evidence="l.value === modelValue ? 'solid' : 'hollow'" aria-hidden="true"></span>
            <span class="shv-t"><span class="shv-name">{{ l.label }}</span><span class="shv-short">{{ l.short }}</span></span>
            <span class="pr-state shv-here" aria-hidden="true">{{ l.value === modelValue ? 'here' : '' }}</span>
        </button>
    </div>
</template>

<style scoped>
.shv { position: relative; display: grid; }
.shv-step { position: relative; display: grid; grid-template-columns: 16px minmax(0, 1fr) auto; align-items: center; gap: 12px; min-height: 48px; padding: 6px 8px 6px 4px; border: 1px solid transparent; border-radius: var(--r-2); background: transparent; color: var(--ink); text-align: left; }
.shv::before { content: ''; position: absolute; left: 11.25px; top: 24px; bottom: 24px; width: 1.5px; background: var(--line-strong); }
.shv-step:hover:not(:disabled) { background: var(--raised); }
.shv-step.is-on { background: var(--raised); border-color: var(--line-strong); }
.shv-step:disabled { opacity: 0.5; }
.shv-bead { position: relative; z-index: 1; justify-self: center; }
.shv-bead[data-state='hollow'] { background-color: var(--sheet); }
.shv-t { display: grid; gap: 1px; min-width: 0; }
.shv-name { font-size: var(--t-sm); font-weight: 600; }
.shv-short { font-size: var(--t-sm); color: var(--muted); }
.shv-here { color: var(--ink); }
@media (pointer: coarse) { .shv-step { min-height: 56px; } }
</style>
