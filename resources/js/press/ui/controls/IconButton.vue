<script setup>
// A square icon button with a tooltip. `label` is both the accessible name and the tooltip.
import Ico from '../../editor/ui/Ico.vue';

defineProps({
    icon: { type: String, required: true },
    label: { type: String, required: true },
    pressed: { type: Boolean, default: undefined },
    disabled: { type: Boolean, default: false },
    size: { type: String, default: 'md' }, // sm | md
    tip: { type: Boolean, default: true },
    danger: { type: Boolean, default: false },
});
defineEmits(['click']);
</script>

<template>
    <button type="button" class="c-ibtn" :class="[`c-ibtn--${size}`, { 'is-danger': danger }]" :aria-label="label" :aria-pressed="pressed" :disabled="disabled" :data-tip="tip ? label : null" @click="$emit('click', $event)">
        <Ico :name="icon" :size="size === 'sm' ? 'sm' : 'md'" />
    </button>
</template>
