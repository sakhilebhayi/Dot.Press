<script setup>
// An on/off switch with its label. modelValue true | false | 'mixed'.
import { computed, useId } from 'vue';
import { MIXED } from './mixed.js';

const props = defineProps({
    modelValue: { type: [Boolean, String], default: false },
    label: { type: String, required: true },
    hint: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const uid = useId();
const mixed = computed(() => props.modelValue === MIXED);
const checked = computed(() => (mixed.value ? 'mixed' : String(!!props.modelValue)));
</script>

<template>
    <div class="c-tog" :class="{ 'is-on': modelValue === true, 'is-mixed': mixed, 'is-disabled': disabled }">
        <button :id="`t${uid}`" type="button" role="switch" class="c-tog-b" :aria-checked="checked" :aria-describedby="hint ? `t${uid}-h` : undefined" :disabled="disabled" @click="emit('update:modelValue', mixed ? true : !modelValue)">
            <span class="c-tog-track" aria-hidden="true"><span class="c-tog-knob"></span></span>
            <span class="c-tog-l">{{ label }}</span>
            <span v-if="mixed" class="c-tog-m pr-state">mixed</span>
        </button>
        <p v-if="hint" :id="`t${uid}-h`" class="c-hint">{{ hint }}</p>
    </div>
</template>
