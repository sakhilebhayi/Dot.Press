<script setup>
// Nothing here yet: the spine with no beads. title defaults to the design line; body explains; actions slot for buttons.
defineProps({ title: { type: String, default: 'Nothing on this line yet.' }, body: { type: String, default: '' }, compact: { type: Boolean, default: false } });
</script>

<template>
    <div class="c-empty" :class="{ 'is-compact': compact }">
        <svg class="c-empty-rail" viewBox="0 0 24 96" aria-hidden="true"><line x1="12" y1="0" x2="12" y2="96" stroke="var(--line-strong)" stroke-width="1.5" /></svg>
        <div class="c-empty-t">
            <p class="c-empty-h">{{ title }}</p>
            <p v-if="body" class="c-empty-b">{{ body }}</p>
            <div v-if="$slots.default" class="c-empty-a"><slot /></div>
        </div>
    </div>
</template>
