<script setup>
// The theme gallery: groups of cards in a radiogroup. Arrow keys move through the cards (and choose them), Home and End
// jump. Choosing a card never changes the deck: it only asks the panel to show the report under the grid.
import { nextTick, ref } from 'vue';
import ThemeCard from './ThemeCard.vue';
import { isCurrent, isModified } from './themeModel.js';
import { columnsOf, gridTarget } from './gridNav.js';

const props = defineProps({
    groups: { type: Array, required: true }, // [{id, title, hint?, items: [theme]}]
    deckTheme: { type: Object, default: null },
    candidateId: { type: String, default: null },
    source: { type: String, default: 'sample' },
});
const emit = defineEmits(['pick']);
const root = ref(null);

const firstId = () => props.groups.flatMap((g) => g.items)[0]?.id;
const tabbableId = () => props.candidateId || props.deckTheme?.id || firstId();

async function onKey(e) {
    const cards = [...root.value.querySelectorAll('[role="radio"]')];
    const i = cards.indexOf(document.activeElement);
    if (i < 0) return;
    const j = gridTarget(e.key, i, cards.length, columnsOf(cards.map((c) => c.getBoundingClientRect().top)));
    if (j === null) return;
    e.preventDefault();
    const theme = props.groups.flatMap((g) => g.items).find((t) => t.id === cards[j].dataset.themeId);
    if (theme) emit('pick', theme);
    await nextTick();
    cards[j].focus();
}
</script>

<template>
    <div ref="root" class="dz-gallery" role="radiogroup" aria-label="Themes" @keydown="onKey">
        <section v-for="g in groups" :key="g.id" class="dz-group" :aria-labelledby="`dzg-${g.id}`">
            <h3 :id="`dzg-${g.id}`" class="dz-group-h"><span>{{ g.title }}</span><span class="pr-state">{{ g.items.length }}</span></h3>
            <p v-if="g.hint" class="c-hint dz-group-hint">{{ g.hint }}</p>
            <div class="dz-grid">
                <ThemeCard
                    v-for="t in g.items" :key="t.id" :theme="t" :source="source" :candidate="candidateId === t.id" :current="isCurrent(deckTheme, t)"
                    :modified="isModified(deckTheme, t)" :tabbable="tabbableId() === t.id" @pick="emit('pick', t)"
                />
            </div>
        </section>
    </div>
</template>
