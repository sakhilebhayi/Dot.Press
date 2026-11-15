<script setup>
// DotSearchRow: one row of the search dropdown (0.6.0). Same markup as blade/search-row.blade.php; js/dot-search-results.js drives it from the keyboard.
// Props: href (omit for a <button> row), label, code (the reference, e.g. FIIT-Q-000001; not `ref`, which Vue reserves), tag, action (the bold action row). Slot `icon`: a leading <svg class="dsr-icon">. Other attributes land on the row.
defineProps({
    href: { type: String, default: null },
    label: { type: String, default: '' },
    code: { type: String, default: null },
    tag: { type: String, default: null },
    action: { type: Boolean, default: false },
});
</script>

<template>
    <a v-if="href !== null" class="dsr-item" :class="{ 'dsr-item--action': action }" :href="href" role="option"><slot name="icon" /><span v-if="code" class="dsr-ref">{{ code }}</span><span class="dsr-label">{{ label }}</span><span v-if="tag" class="dsr-tag">{{ tag }}</span></a>
    <button v-else type="button" class="dsr-item" :class="{ 'dsr-item--action': action }" role="option"><slot name="icon" /><span v-if="code" class="dsr-ref">{{ code }}</span><span class="dsr-label">{{ label }}</span><span v-if="tag" class="dsr-tag">{{ tag }}</span></button>
</template>
