<script setup>
// A visual picker for the picture masks: a radio group of small shapes, drawn with the same clip paths the renderer uses.
// Arrow keys move and select, Home and End jump. Small enough to live next to the panel that uses it.
import { ref } from 'vue';
import { maskClip } from '../../../render/geometry.js';
import { MASKS } from './lib/image.js';
import './media.css';

const props = defineProps({
    modelValue: { type: String, default: 'none' }, // a mask id, or 'mixed'
    disabled: { type: Boolean, default: false },
    label: { type: String, default: 'Mask shape' },
});
const emit = defineEmits(['update:modelValue']);
const group = ref(null);
const current = (id) => (props.modelValue === id) || (id === 'none' && (!props.modelValue || props.modelValue === 'none'));
const tab = (id, i) => (props.modelValue === 'mixed' ? i === 0 : current(id));
const style = (id) => {
    const clip = maskClip(id, 6);
    return clip ? { clipPath: clip } : {};
};
function onKey(e) {
    const keys = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
    const btns = [...group.value.querySelectorAll('[role="radio"]')];
    const i = btns.indexOf(document.activeElement);
    let n = null;
    if (keys[e.key]) n = (i + keys[e.key] + btns.length) % btns.length;
    else if (e.key === 'Home') n = 0;
    else if (e.key === 'End') n = btns.length - 1;
    if (n === null) return;
    e.preventDefault();
    btns[n].focus();
    emit('update:modelValue', MASKS[n].id);
}
</script>

<template>
    <div ref="group" class="pm-masks" :class="{ 'is-disabled': disabled }" role="radiogroup" :aria-label="label" @keydown="onKey">
        <button
            v-for="(m, i) in MASKS" :key="m.id" type="button" role="radio" class="pm-mask" :aria-checked="current(m.id)" :tabindex="tab(m.id, i) ? 0 : -1"
            :disabled="disabled" @click="emit('update:modelValue', m.id)"
        >
            <span class="pm-mask-s" :style="style(m.id)" aria-hidden="true"></span>
            <span>{{ m.label }}</span>
        </button>
    </div>
</template>
