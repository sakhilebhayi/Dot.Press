<script setup>
// One presentation as a row of the ledger: its first slide (drawn lazily by the real renderer), the title as the row's
// link, the status as a bead and a word, the figures, the star and the row menu. A click selects the row (the stage
// shows it); Enter or a double click opens it. On a phone the row is the link.
import { computed, nextTick, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import LazyThumb from '../editor/ui/LazyThumb.vue';
import DropMenu from '../editor/ui/DropMenu.vue';
import Bead from '../editor/ui/Bead.vue';
import Ico from '../editor/ui/Ico.vue';
import { previewDeck, previewSlide, relativeTime, sizeLabel, statusBead } from './libraryModel.js';

const props = defineProps({
    row: { type: Object, required: true },
    focusable: { type: Boolean, default: false },
    selected: { type: Boolean, default: false },
    archived: { type: Boolean, default: false },
    renaming: { type: Boolean, default: false },
    order: { type: Number, default: 0 },
});
const emit = defineEmits(['select', 'favourite', 'rename-start', 'rename', 'rename-cancel', 'duplicate', 'archive', 'restore', 'delete']);

const bead = computed(() => statusBead(props.row.status));
const deck = computed(() => previewDeck(props.row));
const slide = computed(() => previewSlide(props.row));
const href = computed(() => route('press.editor', { deck: props.row.id }));
const edited = computed(() => relativeTime(props.archived ? props.row.archived_at : props.row.updated_at));
const slides = computed(() => `${props.row.slides} ${props.row.slides === 1 ? 'slide' : 'slides'}`);
const phoneLine = computed(() => [bead.value.word, slides.value, edited.value ? (props.archived ? `archived ${edited.value}` : edited.value) : ''].filter(Boolean).join(', '));
const label = computed(() => `${props.row.title}. ${bead.value.word}. ${slides.value}, ${sizeLabel(props.row.w, props.row.h)}${edited.value ? `, ${props.archived ? 'archived' : 'edited'} ${edited.value}` : ''}`);

const menu = computed(() => (props.archived
    ? [
        { label: 'Restore', icon: 'restore', run: () => emit('restore') },
        { separator: true },
        { label: 'Delete permanently', icon: 'trash', danger: true, run: () => emit('delete') },
    ]
    : [
        { label: 'Open', icon: 'arrow-right', href: href.value },
        { label: 'Rename', icon: 'pen', shortcut: 'F2', run: () => emit('rename-start') },
        { label: 'Duplicate', icon: 'duplicate', run: () => emit('duplicate') },
        { label: props.row.favourite ? 'Remove from favourites' : 'Add to favourites', icon: 'star', run: () => emit('favourite') },
        { separator: true },
        { label: 'Archive', icon: 'archive', danger: true, run: () => emit('archive') },
    ]));

const phone = () => typeof window !== 'undefined' && window.matchMedia('(max-width: 639px)').matches;
const open = () => router.visit(href.value);
// A click on the row selects it. Keyboard activation (detail 0), a modified click and a phone tap follow the link.
function onLink(e) {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return; // the browser opens it in a new tab
    e.preventDefault();
    if (e.detail === 0 || phone()) open(); else emit('select');
}
function onRow(e) { if (!e.target.closest('a, button, input')) emit('select'); }
function onDouble(e) { if (!e.target.closest('button, input')) open(); }

const input = ref(null);
const draft = ref('');
let settled = false;
watch(() => props.renaming, async (on) => {
    if (!on) return;
    settled = false;
    draft.value = props.row.title;
    await nextTick();
    input.value?.focus();
    input.value?.select();
}, { immediate: true });
function commit() { if (settled) return; settled = true; emit('rename', draft.value.trim()); }
function cancel() { settled = true; emit('rename-cancel'); }
const menuRef = ref(null);
defineExpose({ openMenu: () => menuRef.value?.show() });
</script>

<template>
    <tr class="lr" :class="{ 'is-archived': archived }" :data-id="row.id" :aria-selected="selected" :style="{ '--i': order }" @click="onRow" @dblclick="onDouble">
        <td class="lr-t">
            <div class="lr-cell">
                <span class="lr-thw" aria-hidden="true"><span class="lr-th" :style="{ '--ar': deck.w / deck.h }"><LazyThumb :slide="slide" :deck="deck" /></span></span>
                <span class="lr-tx">
                    <input v-if="renaming" ref="input" v-model="draft" class="lr-edit" maxlength="255" aria-label="Presentation title" @keydown.enter.prevent="commit" @keydown.esc.prevent="cancel" @blur="commit" />
                    <a v-else :href="href" class="lr-link" :tabindex="focusable ? 0 : -1" :aria-label="label" data-row @click="onLink">{{ row.title }}</a>
                    <span class="lr-sub"><span class="lr-owner">{{ row.owner }}</span><span class="lr-phone"><Bead :state="bead.state" :evidence="bead.state" />{{ phoneLine }}</span></span>
                </span>
            </div>
        </td>
        <td class="lr-status"><span class="lr-st"><Bead :state="bead.state" :evidence="bead.state" /><span class="pr-state">{{ bead.word }}</span></span></td>
        <td class="lr-n lr-slides">{{ row.slides }}</td>
        <td class="lr-size">{{ sizeLabel(row.w, row.h) }}</td>
        <td class="lr-n lr-edited">{{ edited }}</td>
        <td class="lr-act">
            <button v-if="!archived" type="button" class="lr-star" :aria-pressed="!!row.favourite" :aria-label="`Favourite ${row.title}`" tabindex="-1" @click.stop="emit('favourite')">
                <Ico name="star2" size="sm" :stroke="row.favourite ? 2 : 1.75" />
            </button>
            <DropMenu ref="menuRef" :items="menu" align="end" :label="`Actions for ${row.title}`">
                <template #trigger="{ toggle, open: isOpen }">
                    <button type="button" class="lr-more" :aria-expanded="isOpen" aria-haspopup="menu" :aria-label="`Actions for ${row.title}`" tabindex="-1" @click.stop="toggle"><Ico name="more" /></button>
                </template>
            </DropMenu>
        </td>
    </tr>
</template>
