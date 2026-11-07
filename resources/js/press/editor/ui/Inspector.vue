<script setup>
// The inspector drawer: a tab strip of the panels offered for the current context, and the panel itself.
import { computed, nextTick, ref, watch } from 'vue';
import { useEditorContext } from '../context.js';
import PanelHost from '../panels/PanelHost.vue';
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
    strip.value?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
});
async function close() {
    editor.closePanel();
    await nextTick();
}
</script>

<template>
    <aside class="in" aria-label="Inspector">
        <header class="in-head">
            <div ref="strip" class="in-tabs pr-scroll" role="tablist" aria-label="Panels" @keydown="onTabKey">
                <template v-for="(g, gi) in groups" :key="g.scope">
                    <span v-if="gi > 0" class="in-sep" aria-hidden="true"></span>
                    <button
                        v-for="p in g.items" :key="p.id" type="button" role="tab" class="in-tab" :class="{ 'is-on': p.id === active }" :aria-selected="p.id === active" :tabindex="p.id === active ? 0 : -1"
                        :title="`${scopeWord[p.scope]}: ${p.label}`" @click="choose(p.id)"
                    >
                        <Ico v-if="p.icon" :name="p.icon" size="sm" />
                        <span :class="{ 'pr-sr': p.icon && p.id !== active }">{{ p.label }}</span>
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
