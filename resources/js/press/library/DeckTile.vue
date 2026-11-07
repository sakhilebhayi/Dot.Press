<script setup>
// One presentation on the wall: its first slide, a bead for its status, the title, a line of facts.
import { computed, nextTick, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { SlideView } from '../render/index.js';
import DropMenu from '../editor/ui/DropMenu.vue';
import Bead from '../editor/ui/Bead.vue';
import Ico from '../editor/ui/Ico.vue';
import { previewDeck, previewSlide, relativeTime, sizeLabel, statusBead } from './libraryModel.js';

const props = defineProps({
    row: { type: Object, required: true },
    focusable: { type: Boolean, default: false },
    archived: { type: Boolean, default: false },
    renaming: { type: Boolean, default: false },
});
const emit = defineEmits(['favourite', 'rename-start', 'rename', 'rename-cancel', 'duplicate', 'archive', 'restore', 'delete']);

const bead = computed(() => statusBead(props.row.status));
const deck = computed(() => previewDeck(props.row));
const slide = computed(() => previewSlide(props.row));
const href = computed(() => route('press.editor', { deck: props.row.id }));
const facts = computed(() => {
    const parts = [`${props.row.slides} ${props.row.slides === 1 ? 'slide' : 'slides'}`, sizeLabel(props.row.w, props.row.h)];
    const when = relativeTime(props.archived ? props.row.archived_at : props.row.updated_at);
    if (when) parts.push(props.archived ? `archived ${when}` : when);
    return parts.join(' · ');
});
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
    <li class="dt" :class="{ 'is-archived': archived }" :data-id="row.id">
        <Link :href="href" class="dt-sheet" :tabindex="focusable ? 0 : -1" :aria-label="`${row.title}. ${bead.word}. ${facts}`" data-tile>
            <div class="dt-thumb" :style="{ aspectRatio: `${deck.w} / ${deck.h}` }"><SlideView :slide="slide" :deck="deck" mode="thumb" /></div>
        </Link>
        <button v-if="!archived" type="button" class="dt-fav" :class="{ 'is-on': row.favourite }" :aria-pressed="row.favourite" :aria-label="`Favourite ${row.title}`" tabindex="-1" @click="emit('favourite')">
            <Ico name="star2" size="sm" :stroke="row.favourite ? 2 : 1.75" />
        </button>
        <div class="dt-rail" aria-hidden="true"><Bead :state="bead.state" :evidence="bead.state" /></div>
        <div class="dt-meta">
            <input v-if="renaming" ref="input" v-model="draft" class="dt-edit" maxlength="255" aria-label="Presentation title" @keydown.enter.prevent="commit" @keydown.esc.prevent="cancel" @blur="commit" />
            <h3 v-else class="dt-title" @dblclick="!archived && emit('rename-start')">{{ row.title }}</h3>
            <p class="dt-facts pr-state">{{ facts }}</p>
            <p class="dt-state pr-state"><span>{{ bead.word }}</span><span v-if="row.owner"> · {{ row.owner }}</span></p>
        </div>
        <DropMenu ref="menuRef" :items="menu" align="end" :label="`Actions for ${row.title}`">
            <template #trigger="{ toggle, open }">
                <button type="button" class="dt-more" :aria-expanded="open" aria-haspopup="menu" :aria-label="`Actions for ${row.title}`" tabindex="-1" @click="toggle"><Ico name="more" /></button>
            </template>
        </DropMenu>
    </li>
</template>
