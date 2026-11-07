<script setup>
// The Dot.Press mark: an amber dot sitting on an ink rail (the same drawing as the library header, 32 by 12).
// rail: draw the line. tone: "amber" is the dot as the header draws it, "ink" a solid dot for one colour uses.
defineProps({
    width: { type: [Number, String], default: 32 },
    rail: { type: Boolean, default: true },
    tone: { type: String, default: 'amber', validator: (v) => ['amber', 'ink', 'hollow'].includes(v) },
    title: { type: String, default: '' },
});
</script>

<template>
    <svg class="pr-mark" :width="width" :height="Number(width) * 0.375" viewBox="0 0 32 12" :role="title ? 'img' : undefined" :aria-label="title || undefined" :aria-hidden="title ? undefined : 'true'" focusable="false" fill="none">
        <line v-if="rail" x1="0" y1="6" x2="32" y2="6" stroke="currentColor" stroke-width="1.5" />
        <circle cx="9" cy="6" r="4.25" :fill="tone === 'amber' ? 'var(--amber, #f2a100)' : tone === 'ink' ? 'currentColor' : 'var(--paper, #eef0f1)'" stroke="currentColor" stroke-width="1.5" />
    </svg>
</template>

<style>
.pr-mark { display: inline-block; flex: none; overflow: visible; color: var(--ink, #14171a); }
</style>
