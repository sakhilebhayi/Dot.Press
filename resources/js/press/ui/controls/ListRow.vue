<script setup>
// One row of a list: optional drag handle, content, trailing actions. Used by ReorderList; also fine on its own (handle off).
import Ico from '../../editor/ui/Ico.vue';

defineProps({
    selected: { type: Boolean, default: false },
    handle: { type: Boolean, default: false },
    handleLabel: { type: String, default: 'Reorder' },
    dragging: { type: Boolean, default: false },
    interactive: { type: Boolean, default: false }, // the whole row is a button
});
const emit = defineEmits(['select', 'handle-down', 'handle-key']);
</script>

<template>
    <li class="c-row" :class="{ 'is-selected': selected, 'is-dragging': dragging }" :aria-current="selected ? 'true' : undefined">
        <button v-if="handle" type="button" class="c-row-h" :aria-label="`${handleLabel}. Use the arrow keys to move it up or down.`" data-nodrag-row @pointerdown="emit('handle-down', $event)" @keydown="emit('handle-key', $event)"><Ico name="more" size="sm" class="c-row-grip" /></button>
        <button v-if="interactive" type="button" class="c-row-c is-btn" @click="emit('select')"><slot /></button>
        <div v-else class="c-row-c"><slot /></div>
        <span v-if="$slots.actions" class="c-row-a"><slot name="actions" /></span>
    </li>
</template>
