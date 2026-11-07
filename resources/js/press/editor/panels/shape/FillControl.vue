<script setup>
// A fill: none, one colour or a gradient. The mode buttons keep the colour you had, so switching never throws it away.
// Used by the Shape panel (shape fill) and the Text panel (box fill).
import { computed } from 'vue';
import {
    ColorField, GradientField, Segmented,
} from '../../../ui/controls/index.js';
import { fillForMode, fillMode } from './shapeModel.js';

const props = defineProps({
    modelValue: { type: [String, Object], default: null }, // null, '#hex', '@token', a gradient object or 'mixed'
    label: { type: String, default: 'Fill' },
    disabled: { type: Boolean, default: false },
    noneLabel: { type: String, default: 'None' },
    hideLabel: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const mode = computed(() => fillMode(props.modelValue));
const OPTIONS = computed(() => [{ value: 'none', label: props.noneLabel }, { value: 'solid', label: 'Solid' }, { value: 'gradient', label: 'Gradient' }]);
const pick = (m) => emit('update:modelValue', fillForMode(m, props.modelValue));
</script>

<template>
    <div class="pp-group">
        <Segmented :model-value="mode === 'mixed' ? 'mixed' : mode" :label="label" :hide-label="hideLabel" :options="OPTIONS" :disabled="disabled" @update:model-value="pick" />
        <ColorField v-if="mode === 'solid'" :model-value="modelValue" :label="`${label} colour`" hide-label :disabled="disabled" @update:model-value="(v) => emit('update:modelValue', v)" />
        <GradientField v-else-if="mode === 'gradient'" :model-value="modelValue" :label="`${label} gradient`" hide-label @update:model-value="(v) => emit('update:modelValue', v)" />
        <p v-else-if="mode === 'mixed'" class="pp-note">The selection has different fills. Choose a mode to give them all the same one.</p>
    </div>
</template>
