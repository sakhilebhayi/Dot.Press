<script setup>
// A real proposal on a real slide. The deck review proposed "Set this title to 60" for the title slide of the sample deck
// (it is 112 there and 60 on most slides). Accepting applies exactly that operation to the slide the renderer draws, and
// undo puts it back. Nothing else on the slide moves.
import { computed, ref } from 'vue';
import SlideSheet from './SlideSheet.vue';
import { SAMPLE_DECK, SAMPLE_REVIEW } from './sampleDeck.js';
import { applyTitleSize, titleSize } from './landingModel.js';

const slide = SAMPLE_DECK.slides[0];
const issue = SAMPLE_REVIEW.find((r) => r.kind === 'role-size');
const from = titleSize(slide);
const to = 60;
const accepted = ref(false);
const shown = computed(() => (accepted.value ? applyTitleSize(slide, to) : slide));
</script>

<template>
    <div class="lp-prop">
        <div class="lp-prop-stage">
            <SlideSheet :slide="shown" :deck="SAMPLE_DECK.deck" label="Sample title slide" />
        </div>
        <div class="lp-prop-body">
            <p class="pr-state lp-prop-state" aria-live="polite">
                <span class="pr-bead pr-bead--sm" :data-state="accepted ? 'solid' : 'hollow'" aria-hidden="true" />
                {{ accepted ? 'accepted' : 'proposed' }}
            </p>
            <h3 class="lp-h3">{{ issue.fix }}</h3>
            <p class="lp-p">{{ issue.message }} {{ issue.why }}</p>
            <dl class="lp-diff">
                <div><dt>Slide 1, title size</dt><dd><s>{{ from }}</s><span class="pr-sr"> changed to </span><b>{{ to }}</b></dd></div>
            </dl>
            <div class="lp-actions">
                <button v-if="!accepted" type="button" class="lp-cta" @click="accepted = true"><i class="lp-cta-bead" aria-hidden="true" />Accept this change</button>
                <button v-else type="button" class="lp-btn" @click="accepted = false">Undo</button>
                <span class="lp-prop-note">One element changes. The rest of the deck is untouched.</span>
            </div>
        </div>
    </div>
</template>
