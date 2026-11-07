<script setup>
// Icons: search over the built-in set (id, name and tags), categories derived in iconModel.js, arrow-key navigation in the grid.
import { computed, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import { SelectMenu, EmptyState } from '../../../ui/controls/index.js';
import { ICONS } from '../../../icons.js';
import Ico from '../../ui/Ico.vue';
import { ICON_CATEGORIES, categorize, searchIcons } from './iconModel.js';
import { useInsert } from './useInsert.js';
import { useRovingGrid } from './useRovingGrid.js';

const editor = useEditorContext();
const ins = useInsert(editor);
const query = ref('');
const category = ref('all');
const grid = ref(null);
const hot = ref(null);

const counts = categorize(ICONS);
const options = ICON_CATEGORIES.map((c) => ({ value: c.id, label: c.label, hint: String(c.id === 'all' ? ICONS.length : counts.get(c.id).length) }));
const shown = computed(() => searchIcons(ICONS, query.value, category.value));
const nav = useRovingGrid(grid, { count: () => shown.value.length });
const caption = computed(() => hot.value || shown.value[Math.min(nav.active.value, shown.value.length - 1)] || null);
const onSearchKey = (e) => {
    if (e.key === 'ArrowDown' && shown.value.length) { e.preventDefault(); grid.value?.querySelector('[data-cell][tabindex="0"]')?.focus(); }
    if (e.key === 'Enter' && shown.value.length === 1) { e.preventDefault(); ins.insertIcon(shown.value[0].id); }
};
</script>

<template>
    <section class="ip-sec" aria-labelledby="ip-icons-h">
        <h3 id="ip-icons-h" class="ip-h">Icons <span class="pr-state">{{ shown.length }} of {{ ICONS.length }}</span></h3>
        <div class="ip-search" style="margin-top: 10px">
            <input v-model="query" type="search" class="pr-field" placeholder="Search icons, such as mine or growth" aria-label="Search icons" autocomplete="off" spellcheck="false" @keydown="onSearchKey" />
            <SelectMenu v-model="category" :options="options" label="Category" hide-label />
        </div>
        <div v-if="shown.length" ref="grid" class="ip-grid ip-grid--icons" role="group" aria-label="Icons" style="margin-top: 10px" @keydown="nav.onKeydown" @focusin="nav.onFocusin">
            <button
                v-for="(ic, i) in shown" :key="ic.id" type="button" class="ip-item ip-cell" data-cell :data-i="i" :tabindex="nav.tab(i)" draggable="true" :aria-label="ic.label" :title="ic.label"
                @mouseenter="hot = ic" @mouseleave="hot = null"
                @click="ins.insertIcon(ic.id)"
                @dragstart="ins.dragStart($event, { kind: 'insert', what: 'icon', name: ic.id }, ic.label)"
                @dragend="ins.dragEnd"
            ><Ico :name="ic.id" /></button>
        </div>
        <EmptyState v-else compact :title="`No icon for “${query.trim()}”.`" body="Try a plainer word, such as chart, people or safety.">
            <button type="button" class="pr-btn pr-btn--sm" @click="query = ''; category = 'all'">Clear search</button>
        </EmptyState>
        <p v-if="shown.length" class="ip-cap pr-state" aria-live="off">{{ caption ? caption.label : '' }}</p>
    </section>
</template>
