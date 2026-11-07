<script setup>
defineProps({
    value: String,
});
</script>

<template>
    <label class="pr-label">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
