<script setup>
// The big "continue" sheet: the presentation you opened last.
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { SlideView } from '../render/index.js';
import Bead from '../editor/ui/Bead.vue';
import Ico from '../editor/ui/Ico.vue';
import { previewDeck, previewSlide, relativeTime, sizeLabel, statusBead } from './libraryModel.js';

const props = defineProps({ row: { type: Object, required: true } });
const bead = computed(() => statusBead(props.row.status));
const deck = computed(() => previewDeck(props.row));
const slide = computed(() => previewSlide(props.row));
const href = computed(() => route('press.editor', { deck: props.row.id }));
const opened = computed(() => relativeTime(props.row.last_opened_at));
</script>

<template>
    <section class="cs" aria-labelledby="cs-title">
        <Link :href="href" class="cs-sheet" tabindex="-1" aria-hidden="true">
            <div class="cs-thumb" :style="{ aspectRatio: `${deck.w} / ${deck.h}` }"><SlideView :slide="slide" :deck="deck" mode="thumb" eager /></div>
        </Link>
        <div class="cs-text">
            <p class="cs-state pr-state"><Bead :state="bead.state" :evidence="bead.state" /> {{ bead.word }}<span v-if="opened"> · opened {{ opened }}</span></p>
            <h2 id="cs-title" class="cs-title pr-display">{{ row.title }}</h2>
            <p class="cs-facts pr-state">{{ row.slides }} {{ row.slides === 1 ? 'slide' : 'slides' }} · {{ sizeLabel(row.w, row.h) }}<span v-if="row.owner"> · {{ row.owner }}</span></p>
            <div class="cs-actions">
                <Link :href="href" class="pr-btn pr-btn--primary cs-go"><span>Continue editing</span><Ico name="arrow-right" size="sm" /></Link>
            </div>
        </div>
    </section>
</template>
