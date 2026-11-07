<script setup>
// The five ways out as one choice list (a radio group with arrow keys). Local to the export panel: the control kit
// has no vertical list of choices with a second line of text.
import { nextTick, ref } from 'vue';
import Ico from '../../ui/Ico.vue';

const props = defineProps({
    modelValue: { type: String, required: true },
    options: { type: Array, required: true }, // [{id, label, blurb, icon}]
    label: { type: String, default: 'Format' },
});
const emit = defineEmits(['update:modelValue']);
const root = ref(null);
async function key(e, i) {
    const n = props.options.length;
    const to = { ArrowDown: i + 1, ArrowRight: i + 1, ArrowUp: i - 1, ArrowLeft: i - 1, Home: 0, End: n - 1 }[e.key];
    if (to === undefined) return;
    e.preventDefault();
    const j = (to + n) % n;
    emit('update:modelValue', props.options[j].id);
    await nextTick();
    root.value.querySelectorAll('[role=radio]')[j]?.focus();
}
</script>

<template>
    <div ref="root" class="xp-fmt" role="radiogroup" :aria-label="label">
        <button
            v-for="(o, i) in options" :key="o.id" type="button" role="radio" class="xp-fmt-b" :class="{ 'is-on': o.id === modelValue }" :aria-checked="o.id === modelValue"
            :tabindex="o.id === modelValue ? 0 : -1" @click="emit('update:modelValue', o.id)" @keydown="key($event, i)"
        >
            <span class="xp-fmt-mark" aria-hidden="true"><Ico :name="o.icon" size="sm" /></span>
            <span class="xp-fmt-t"><span class="xp-fmt-l">{{ o.label }}</span><span v-if="o.id === modelValue" class="xp-fmt-d">{{ o.blurb }}</span></span>
        </button>
    </div>
</template>
