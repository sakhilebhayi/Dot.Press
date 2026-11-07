<script setup>
// The same line while presenting. Passed slides are solid, the one on screen is amber, the ones to come are hollow.
// Left and right arrows move through the sample deck when the player has focus.
import { computed, ref } from 'vue';
import SlideSheet from './SlideSheet.vue';
import SpineLine from './SpineLine.vue';
import { SAMPLE_DECK } from './sampleDeck.js';

const slides = SAMPLE_DECK.slides;
const at = ref(0);
const beads = computed(() => slides.map((s, i) => ({ state: i < at.value ? 'solid' : 'hollow', label: `Go to slide ${i + 1}: ${s.title}` })));
const go = (i) => { at.value = Math.max(0, Math.min(slides.length - 1, i)); };
function onKey(e) {
    if (e.key === 'ArrowRight' || e.key === 'PageDown') { e.preventDefault(); go(at.value + 1); }
    else if (e.key === 'ArrowLeft' || e.key === 'PageUp') { e.preventDefault(); go(at.value - 1); }
    else if (e.key === 'Home') { e.preventDefault(); go(0); }
    else if (e.key === 'End') { e.preventDefault(); go(slides.length - 1); }
}
</script>

<template>
    <div class="lp-play" role="group" aria-label="Sample presentation" aria-roledescription="carousel" tabindex="0" @keydown="onKey">
        <SlideSheet :slide="slides[at]" :deck="SAMPLE_DECK.deck" :label="`Slide ${at + 1} of ${slides.length}: ${slides[at].title}`" />
        <div class="lp-play-bar">
            <button type="button" class="lp-btn lp-btn--icon" :disabled="at === 0" aria-label="Previous slide" @click="go(at - 1)">
                <svg viewBox="0 0 24 24" class="pr-ico" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7" /></svg>
            </button>
            <SpineLine :beads="beads" :current="at" label="Slides in the sample presentation" @select="go" />
            <button type="button" class="lp-btn lp-btn--icon" :disabled="at === slides.length - 1" aria-label="Next slide" @click="go(at + 1)">
                <svg viewBox="0 0 24 24" class="pr-ico" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7" /></svg>
            </button>
        </div>
        <p class="pr-state lp-play-count" aria-live="polite">slide {{ at + 1 }} of {{ slides.length }}<span class="lp-hint-key"> · use the arrow keys</span></p>
    </div>
</template>
