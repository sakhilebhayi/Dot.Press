<script setup>
// The ink button. While it is disabled for a request it shows the progress bead: a hollow ring that fills solid.
defineProps({
    type: {
        type: String,
        default: 'submit',
    },
});
</script>

<template>
    <button :type="type" class="pr-btn pr-btn--primary" :aria-busy="$attrs.disabled !== undefined && $attrs.disabled !== false ? 'true' : undefined">
        <span v-if="$attrs.disabled !== undefined && $attrs.disabled !== false" class="pr-busy" aria-hidden="true" />
        <slot />
    </button>
</template>
