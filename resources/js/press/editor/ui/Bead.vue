<script setup>
// A bead. state: hollow | solid | current | issue. For current, `evidence` says what sits inside the amber.
defineProps({
    state: { type: String, default: 'hollow' },
    evidence: { type: String, default: 'hollow' },
    hidden: { type: Boolean, default: false },
});
</script>

<template>
    <span class="pr-bead" :data-state="state" :data-evidence="evidence" :data-hidden="hidden ? 'true' : null" aria-hidden="true"></span>
</template>
