<script setup>
// The transition picker as a visual list: one tile per transition with a small schematic. A radiogroup (arrow keys,
// Home and End) because the kit has no picker with pictures; everything else on the panel is kit controls.
import { nextTick, ref } from 'vue';
import TransitionGlyph from './TransitionGlyph.vue';
import { TRANSITION_OPTIONS } from './transitionModel.js';

const props = defineProps({
    modelValue: { type: String, default: 'none' },
    dir: { type: String, default: 'r' },
    disabled: { type: Boolean, default: false },
    label: { type: String, default: 'Transition' },
});
const emit = defineEmits(['update:modelValue']);
const root = ref(null);
const items = TRANSITION_OPTIONS;
const tab = (i) => (items[i].type === props.modelValue ? 0 : -1);

async function key(e, i) {
    const n = items.length;
    const to = { ArrowRight: i + 1, ArrowDown: i + 2, ArrowLeft: i - 1, ArrowUp: i - 2, Home: 0, End: n - 1 }[e.key];
    if (to === undefined || to < 0 || to >= n) return;
    e.preventDefault();
    emit('update:modelValue', items[to].type);
    await nextTick();
    root.value.querySelectorAll('[role=radio]')[to]?.focus();
}
</script>

<template>
    <div ref="root" class="tl" role="radiogroup" :aria-label="label" :class="{ 'is-disabled': disabled }">
        <button
            v-for="(o, i) in items" :key="o.type" type="button" role="radio" class="tl-t" :class="{ 'is-on': o.type === modelValue }" :aria-checked="o.type === modelValue" :tabindex="tab(i)" :disabled="disabled"
            @click="emit('update:modelValue', o.type)" @keydown="key($event, i)"
        >
            <TransitionGlyph :type="o.type" :dir="dir" />
            <span class="tl-l">{{ o.label }}</span>
        </button>
    </div>
</template>

<style>
.tl { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; }
.tl.is-disabled { opacity: 0.45; pointer-events: none; }
.tl-t { display: flex; align-items: center; gap: 10px; min-height: 44px; padding: 0 10px; border: 1px solid var(--line); border-radius: var(--r-2); background: var(--raised); color: var(--ink-2); font-size: var(--t-sm); font-weight: 500; text-align: left; transition: background-color var(--d-1) var(--ease-snap), border-color var(--d-1), color var(--d-1); }
.tl-t:hover { border-color: var(--line-strong); color: var(--ink); background: var(--sheet); }
.tl-t.is-on { background: var(--sheet); border-color: var(--ink); color: var(--ink); box-shadow: inset 3px 0 0 var(--ink); }
.tl-t:focus-visible { outline: 2px solid var(--focus); outline-offset: 1px; }
.tl-t .tg { flex: none; }
.tl-l { min-width: 0; }
</style>
