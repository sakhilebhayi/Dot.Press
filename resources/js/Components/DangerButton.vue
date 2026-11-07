<script setup>
// The destructive action. Solid red with a word, never red alone; it only ever opens or confirms a confirmation.
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button :type="type" class="pr-btn pr-btn--danger-solid" :aria-busy="$attrs.disabled !== undefined && $attrs.disabled !== false ? 'true' : undefined">
        <span v-if="$attrs.disabled !== undefined && $attrs.disabled !== false" class="pr-busy" aria-hidden="true" />
        <slot />
    </button>
</template>
