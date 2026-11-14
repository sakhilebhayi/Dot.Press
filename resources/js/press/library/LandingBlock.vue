<script setup>
// What a person sees first after signing in through InfoDot: the Dot.Press logo, a welcome with their name and their
// company, what the product is for, the one primary action their role has, and the presentation they were last in
// (drawn by the real slide renderer). Every word and figure comes from the `landing` prop the server builds.
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { SlideView } from '../render/index.js';
import Bead from '../editor/ui/Bead.vue';
import Ico from '../editor/ui/Ico.vue';
import { emptyCopy, landingCopy, previewDeck, previewSlide, relativeTime, sizeLabel, statusBead } from './libraryModel.js';

const props = defineProps({
    landing: { type: Object, required: true },
    row: { type: Object, default: null },
});
const emit = defineEmits(['new']);

const copy = computed(() => landingCopy(props.landing));
const primary = computed(() => props.landing.primary || null);
const bead = computed(() => statusBead(props.row?.status));
const deck = computed(() => previewDeck(props.row || {}));
const slide = computed(() => previewSlide(props.row || {}));
const href = computed(() => (props.row ? route('press.editor', { deck: props.row.id }) : null));
const when = computed(() => {
    if (!props.row) return '';
    const opened = relativeTime(props.row.last_opened_at);
    if (opened) return `opened ${opened}`;
    const edited = relativeTime(props.row.updated_at);
    return edited ? `edited ${edited}` : '';
});
</script>

<template>
    <section class="ld" aria-labelledby="ld-h">
        <div class="ld-top">
            <span class="ld-logo">
                <img class="ld-logo-i ld-logo-i--light" :src="landing.logo.light" alt="Dot.Press" width="112" height="112" loading="lazy" decoding="async" />
                <img class="ld-logo-i ld-logo-i--dark" :src="landing.logo.dark" alt="Dot.Press" width="112" height="112" loading="lazy" decoding="async" />
            </span>
            <nav class="ld-back" aria-label="Dot ecosystem">
                <a class="ld-back-a" :href="landing.infodot.home">InfoDot</a>
                <a class="ld-back-a" :href="landing.infodot.platforms">Platforms</a>
            </nav>
        </div>

        <div class="ld-body">
            <div class="ld-say">
                <h1 id="ld-h" class="ld-h pr-display">{{ copy.headline }}</h1>
                <p class="ld-p"><strong class="ld-hello">{{ copy.welcome }}</strong> {{ copy.support }}</p>
                <div v-if="primary" class="ld-act">
                    <a v-if="primary.kind === 'new'" :href="primary.href" class="pr-btn pr-btn--primary ld-go" data-primary-action aria-haspopup="dialog" @click.prevent="emit('new')"><Ico name="plus" size="sm" :stroke="2.2" /><span>{{ primary.label }}</span></a>
                    <Link v-else :href="primary.href" class="pr-btn pr-btn--primary ld-go" data-primary-action><span>{{ primary.label }}</span><Ico name="arrow-right" size="sm" /></Link>
                </div>
            </div>

            <div v-if="row" class="ld-last">
                <Link :href="href" class="ld-sheet" tabindex="-1" aria-hidden="true">
                    <div class="ld-thumb" :style="{ aspectRatio: `${deck.w} / ${deck.h}` }"><SlideView :slide="slide" :deck="deck" mode="thumb" eager /></div>
                </Link>
                <div class="ld-rail" aria-hidden="true"><Bead :state="bead.state" :evidence="bead.state" /></div>
                <div class="ld-meta">
                    <div class="ld-meta-t">
                        <h2 class="ld-title">{{ row.title }}</h2>
                        <p class="ld-facts pr-state">{{ bead.word }}, {{ row.slides }} {{ row.slides === 1 ? 'slide' : 'slides' }}, {{ sizeLabel(row.w, row.h) }}<span v-if="when">, {{ when }}</span></p>
                    </div>
                    <Link v-if="!primary || primary.kind !== 'open'" :href="href" class="pr-btn ld-open">{{ row.last_opened_at ? 'Continue editing' : 'Open' }}</Link>
                </div>
            </div>
            <!-- nothing to show: a spine with no beads (DESIGN.md, empty states) -->
            <div v-else class="ld-last ld-blank">
                <div class="ld-thumb ld-thumb--blank" aria-hidden="true"></div>
                <div class="ld-rail" aria-hidden="true"></div>
                <div class="ld-meta">
                    <div class="ld-meta-t">
                        <h2 class="ld-title">Nothing on this line yet.</h2>
                        <p class="ld-blank-s">{{ primary ? emptyCopy(null) : copy.nothing }}</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
