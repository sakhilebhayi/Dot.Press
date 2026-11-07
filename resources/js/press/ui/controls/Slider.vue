<script setup>
// A range slider with a numeric readout. Native range input underneath: keyboard (arrows, Home, End, Page keys) and touch come free.
import { computed } from 'vue';
import { MIXED } from './mixed.js';
import { formatNumber } from './numbers.js';
import Field from './Field.vue';

const props = defineProps({
    modelValue: { type: [Number, String], default: 0 },
    label: { type: String, default: '' },
    hideLabel: { type: Boolean, default: false },
    min: { type: Number, default: 0 },
    max: { type: Number, default: 100 },
    step: { type: Number, default: 1 },
    unit: { type: String, default: '' },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    format: { type: Function, default: null }, // (value) => text for the readout, e.g. v => Math.round(v * 100) + '%'
});
const emit = defineEmits(['update:modelValue', 'commit']);
const mixed = computed(() => props.modelValue === MIXED);
const value = computed(() => (mixed.value ? props.min : Number(props.modelValue)));
const pct = computed(() => `${((Math.min(props.max, Math.max(props.min, value.value)) - props.min) / (props.max - props.min || 1)) * 100}%`);
const readout = computed(() => (mixed.value ? 'Mixed' : props.format ? props.format(value.value) : `${formatNumber(value.value, props.step)}${props.unit}`));
</script>

<template>
    <Field v-slot="{ id, describedby }" :label="label" :hide-label="hideLabel" :hint="hint" :error="error">
        <div class="c-slider" :class="{ 'is-mixed': mixed }">
            <input
                :id="id" type="range" class="c-range" :min="min" :max="max" :step="step" :value="value" :disabled="disabled" :aria-describedby="describedby" :aria-valuetext="readout"
                :style="{ '--fill': pct }" @input="emit('update:modelValue', Number($event.target.value))" @change="emit('commit', Number($event.target.value))"
            />
            <output class="c-slider-v pr-state" :for="id">{{ readout }}</output>
        </div>
    </Field>
</template>
