<script setup>
// Text styles built from the deck's own theme: the preview uses the theme fonts and colours, the inserted box uses tokens (@heading, @ink).
import { computed } from 'vue';
import { useEditorContext } from '../../context.js';
import { resolveColor, resolveFont } from '../../core/theme.js';
import { TEXT_PRESETS } from './insertModel.js';
import { useInsert } from './useInsert.js';

const editor = useEditorContext();
const ins = useInsert(editor);
const theme = computed(() => editor.deck.value?.theme || {});
const deckH = computed(() => editor.deck.value?.h || 1080);
const hw = computed(() => theme.value.t?.hw ?? 700);

const PX = { title: 26, heading: 20, body: 15, caption: 12, quote: 19, bullets: 14, numbered: 14 };
const SAMPLE = { title: 'Title', heading: 'Heading', body: 'Body text', caption: 'Caption', quote: '“A quote worth keeping.”' };

function previewStyle(p) {
    return {
        fontFamily: `"${resolveFont(p.font, theme.value)}", system-ui, sans-serif`,
        fontSize: `${PX[p.id]}px`,
        fontWeight: p.weight ?? (p.heading ? hw.value : 400),
        lineHeight: p.lh,
        color: resolveColor(p.color || '@ink', theme.value) || 'inherit',
        fontStyle: p.id === 'quote' ? 'italic' : 'normal',
    };
}
const surface = computed(() => ({ background: resolveColor('@bg', theme.value) || 'var(--sheet)' }));
</script>

<template>
    <section class="ip-sec" aria-labelledby="ip-text-h">
        <h3 id="ip-text-h" class="ip-h">Text styles <span class="pr-state">{{ TEXT_PRESETS.length }}</span></h3>
        <p class="ip-note">Styles follow the deck's theme fonts and colours. Click to add, or drag onto the slide.</p>
        <ul class="ip-list" style="margin-top: 10px">
            <li v-for="p in TEXT_PRESETS" :key="p.id">
                <button
                    type="button" class="ip-item ip-text" draggable="true" :aria-label="`Add ${p.label.toLowerCase()}: ${p.hint}`"
                    @click="ins.insertText(p.id)"
                    @dragstart="ins.dragStart($event, { kind: 'insert', what: 'text', preset: p.id }, p.label)"
                    @dragend="ins.dragEnd"
                >
                    <span class="ip-text-prev" :style="surface" aria-hidden="true">
                        <ul v-if="p.id === 'bullets'" :style="previewStyle(p)"><li>First point</li><li>Second point</li></ul>
                        <ol v-else-if="p.id === 'numbered'" :style="previewStyle(p)"><li>First step</li><li>Second step</li></ol>
                        <span v-else :style="previewStyle(p)">{{ SAMPLE[p.id] }}</span>
                    </span>
                    <span class="ip-text-meta"><span class="ip-name">{{ p.label }}</span><span class="pr-state">{{ Math.round(p.sizeF * deckH) }} px</span></span>
                </button>
            </li>
        </ul>
    </section>
</template>
