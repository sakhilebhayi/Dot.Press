<script setup>
// The page's own spine: one bead per section. The line fills as the reader scrolls, beads turn solid as their section is
// passed and the one the reader is in is amber. On a wide screen it runs down the left edge; on a phone it runs across
// the top, under the header. It is the page's navigation: every bead is a link.
import { sectionState } from './landingModel.js';

defineProps({
    sections: { type: Array, required: true },
    current: { type: Number, default: 0 },
    fill: { type: Number, default: 0 },
});
</script>

<template>
    <nav class="lp-rail" aria-label="Sections of this page" :style="{ '--fill': fill }">
        <div class="lp-rail-track" aria-hidden="true"><i class="lp-rail-fill" /></div>
        <ol class="lp-rail-list">
            <li v-for="(s, i) in sections" :key="s.id" class="lp-rail-item" :data-state="sectionState(i, current)">
                <a :href="`#${s.id}`" class="lp-rail-link" :aria-current="i === current ? 'location' : undefined">
                    <span class="pr-bead" :data-state="sectionState(i, current)" :data-evidence="i === current ? 'solid' : undefined" aria-hidden="true" />
                    <span class="lp-rail-label">{{ s.label }}</span>
                </a>
            </li>
        </ol>
    </nav>
</template>
