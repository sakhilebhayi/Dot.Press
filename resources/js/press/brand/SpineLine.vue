<script setup>
// A horizontal line of beads: the page's own idea in miniature, and the progress line of the player. Each bead is a button.
// states: 'hollow' | 'solid' per bead. current: the bead that is amber. The verdict sits inside the amber bead.
defineProps({
    beads: { type: Array, required: true }, // [{ state: 'hollow' | 'solid', label }]
    current: { type: Number, default: 0 },
    label: { type: String, default: 'Slides' },
});
defineEmits(['select']);
</script>

<template>
    <ol class="lp-line" :aria-label="label" :style="{ '--n': beads.length }">
        <li v-for="(b, i) in beads" :key="i">
            <button type="button" class="lp-line-b" :aria-label="b.label" :aria-current="i === current ? 'true' : undefined" @click="$emit('select', i)">
                <span class="pr-bead" :data-state="i === current ? 'current' : b.state" :data-evidence="b.state" aria-hidden="true" />
            </button>
        </li>
    </ol>
</template>
