<script setup>
// Severity as a shape and a word, never colour alone: issue = solid square, warning = hollow square, note = ring.
defineProps({ severity: { type: String, default: 'info' }, word: { type: String, default: '' } });
</script>

<template>
    <span class="iq-sev" :data-sev="severity"><span class="iq-sev-shape" aria-hidden="true"></span><span class="iq-sev-word pr-state">{{ word }}</span></span>
</template>
