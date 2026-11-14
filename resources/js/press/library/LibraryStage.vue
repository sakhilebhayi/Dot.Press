<script setup>
// The stage: the selected presentation, its first slide on the rail with its bead, its facts and the actions that
// belong to it. Every fact is a field of the row the API sent; a field with no value is left out, never drawn as a dash.
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import LazyThumb from '../editor/ui/LazyThumb.vue';
import Bead from '../editor/ui/Bead.vue';
import { previewDeck, previewSlide, relativeTime, sizeLabel, statusBead } from './libraryModel.js';

const props = defineProps({
    row: { type: Object, required: true },
    archived: { type: Boolean, default: false },
    canCreate: { type: Boolean, default: false },
});
const emit = defineEmits(['duplicate', 'archive', 'restore', 'delete', 'rename-start']);

const bead = computed(() => statusBead(props.row.status));
const deck = computed(() => previewDeck(props.row));
const slide = computed(() => previewSlide(props.row));
const href = computed(() => route('press.editor', { deck: props.row.id }));
const present = computed(() => route('press.player', { deck: props.row.id }));
const VIS = { private: 'Private', team: 'Team', link: 'Anyone with the link' };
const facts = computed(() => [
    ['Owner', props.row.owner || ''],
    ['Slides', String(props.row.slides ?? '')],
    ['Size', sizeLabel(props.row.w, props.row.h)],
    ['Visibility', VIS[props.row.visibility] || ''],
    [props.archived ? 'Archived' : 'Edited', relativeTime(props.archived ? props.row.archived_at : props.row.updated_at)],
    ['Last opened', relativeTime(props.row.last_opened_at)],
].filter(([, v]) => v));
</script>

<template>
    <div class="st" :data-id="row.id">
        <Link :href="href" class="st-prev" :class="{ 'is-archived': archived }" tabindex="-1" aria-hidden="true" :style="{ aspectRatio: `${deck.w} / ${deck.h}`, width: deck.h > deck.w ? 'min(60%, 200px)' : null }"><LazyThumb :slide="slide" :deck="deck" eager /></Link>
        <div class="st-rail" aria-hidden="true"><Bead :state="bead.state" :evidence="bead.state" /></div>
        <h2 class="st-title"><Link :href="href">{{ row.title }}</Link></h2>
        <div class="st-acts">
            <template v-if="archived">
                <button v-if="canCreate" type="button" class="pr-btn" @click="emit('restore')">Restore</button>
                <button v-if="canCreate" type="button" class="pr-btn pr-btn--ghost pr-btn--danger st-push" @click="emit('delete')">Delete permanently</button>
            </template>
            <template v-else>
                <Link :href="href" class="pr-btn">Open</Link>
                <Link :href="present" class="pr-btn">Present</Link>
                <template v-if="canCreate">
                    <button type="button" class="pr-btn pr-btn--ghost" @click="emit('rename-start')">Rename</button>
                    <button type="button" class="pr-btn pr-btn--ghost" @click="emit('duplicate')">Duplicate</button>
                    <button type="button" class="pr-btn pr-btn--ghost pr-btn--danger st-push" @click="emit('archive')">Archive</button>
                </template>
            </template>
        </div>
        <dl class="st-rows">
            <dt>Status</dt><dd><span class="lr-st"><Bead :state="bead.state" :evidence="bead.state" /><span class="pr-state">{{ bead.word }}</span></span></dd>
            <template v-for="[k, v] in facts" :key="k"><dt>{{ k }}</dt><dd>{{ v }}</dd></template>
        </dl>
    </div>
</template>
