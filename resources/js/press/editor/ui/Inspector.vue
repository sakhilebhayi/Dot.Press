<script setup>
// The inspector drawer: a tab strip of the panels offered for the current context, and the panel itself.
import { computed, nextTick, ref, watch } from 'vue';
import { useEditorContext } from '../context.js';
import PanelHost from '../panels/PanelHost.vue';
import DropMenu from './DropMenu.vue';
import Ico from './Ico.vue';

const editor = useEditorContext();
const { ui } = editor;
const tabs = editor.panelList;
const active = editor.activePanelId;
const strip = ref(null);

const groups = computed(() => {
    const out = [];
    for (const p of tabs.value) {
        const last = out[out.length - 1];
        if (last && last.scope === p.scope) last.items.push(p); else out.push({ scope: p.scope, items: [p] });
    }
    return out;
});
const scopeWord = { selection: 'Selection', slide: 'Slide', deck: 'Deck' };
const current = computed(() => tabs.value.find((p) => p.id === active.value) || null);
// every panel by name, grouped by scope: the icon strip is a shortcut, this list is how a panel is found
const pickItems = computed(() => groups.value.flatMap((g) => [
    { heading: scopeWord[g.scope] || g.scope },
    ...g.items.map((p) => ({ label: p.label, icon: p.icon, checked: p.id === active.value, run: () => { choose(p.id); nextTick(() => strip.value?.querySelector('[aria-selected="true"]')?.focus?.()); } })),
]));

function choose(id) { ui.inspector.panel = id; ui.inspector.props = null; }
function onTabKey(e) {
    const btns = [...strip.value.querySelectorAll('[role="tab"]')];
    const i = btns.indexOf(document.activeElement);
    const n = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: btns.length - 1 }[e.key];
    if (n === undefined) return;
    e.preventDefault();
    const t = btns[(n + btns.length) % btns.length];
    t.focus();
    t.click();
}
watch(active, async () => {
    await nextTick();
    // Scroll only the strip. scrollIntoView would also scroll the app shell sideways while the drawer slides in.
    const box = strip.value;
    const tab = box?.querySelector('[aria-selected="true"]');
    if (!box || !tab) return;
    const left = tab.offsetLeft - box.offsetLeft;
    if (left < box.scrollLeft) box.scrollLeft = Math.max(0, left - 8);
    else if (left + tab.offsetWidth > box.scrollLeft + box.clientWidth) box.scrollLeft = left + tab.offsetWidth - box.clientWidth + 8;
});
async function close() {
    editor.closePanel();
    await nextTick();
}
</script>

<template>
    <aside class="in" aria-label="Inspector">
        <h2 class="pr-sr">{{ current ? current.label : 'Inspector' }}</h2>
        <header class="in-head">
            <DropMenu :items="pickItems" label="Inspector panels" :min-width="230">
                <template #trigger="{ toggle, open }">
                    <button type="button" class="in-pick" aria-haspopup="menu" :aria-expanded="open" :aria-label="current ? `${current.label} panel. Choose another panel` : 'Choose a panel'" @click="toggle">
                        <Ico v-if="current?.icon" :name="current.icon" size="sm" />
                        <span class="in-pick-l">{{ current ? current.label : 'Panels' }}</span>
                        <Ico name="chevronDown" size="sm" />
                    </button>
                </template>
            </DropMenu>
            <div ref="strip" class="in-tabs pr-scroll" role="tablist" aria-label="Panels" @keydown="onTabKey">
                <template v-for="(g, gi) in groups" :key="g.scope">
                    <span v-if="gi > 0" class="in-sep" aria-hidden="true"></span>
                    <button
                        v-for="p in g.items" :key="p.id" type="button" role="tab" class="in-tab" :class="{ 'is-on': p.id === active }" :aria-selected="p.id === active" :tabindex="p.id === active ? 0 : -1"
                        :aria-label="p.label" :data-tip="`${scopeWord[p.scope]}: ${p.label}`" @click="choose(p.id)"
                    >
                        <Ico v-if="p.icon" :name="p.icon" size="sm" />
                        <span v-else>{{ p.label }}</span>
                    </button>
                </template>
            </div>
            <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon pr-btn--sm in-close" aria-label="Close inspector" @click="close"><Ico name="close" /></button>
        </header>
        <PanelHost v-if="active" :id="active" :panel-props="ui.inspector.props" />
        <div v-else class="in-empty">
            <p class="in-empty-t pr-title">Nothing to inspect.</p>
            <p class="pr-small pr-muted">Select something on the slide and its controls appear here.</p>
        </div>
    </aside>
</template>
