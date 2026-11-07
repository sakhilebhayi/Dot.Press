<script setup>
// The "new slide" layout chooser: a small popover of layout glyphs. Without a layout library it says so and adds a
// blank slide; with one (GET /api/layout-library) it applies the chosen layout to the new slide.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import { GLYPH_H, GLYPH_W, mergeLibrary } from './layoutChooser.js';
import { useEditorContext } from '../context.js';

const props = defineProps({
    anchor: { type: Object, required: true }, // {left, top, right, bottom} client rect of the thing that opened it
    place: { type: String, default: 'side' }, // side: right of the anchor, up: above it
});
const emit = defineEmits(['pick', 'close']);
const editor = useEditorContext();

const hasLibrary = shallowRef(false);
const items = shallowRef(mergeLibrary(null));
const box = ref(null);
const pos = ref({ left: 0, top: 0 });

let cache = globalThis.__pressLayoutLibrary;
async function load() {
    if (cache === undefined) {
        cache = editor.api.get('/api/layout-library').then((res) => res, () => null);
        globalThis.__pressLayoutLibrary = cache;
    }
    const res = await cache;
    const merged = mergeLibrary(res);
    if (merged.some((i) => i.def)) { items.value = merged; hasLibrary.value = true; }
}

function place() {
    const el = box.value;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const a = props.anchor;
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    let left;
    let top;
    if (props.place === 'up') {
        left = a.left;
        top = a.top - r.height - 8;
    } else {
        left = a.right + 10;
        top = a.top - 8;
    }
    if (left + r.width > vw - 8) left = Math.max(8, vw - r.width - 8);
    if (top + r.height > vh - 8) top = Math.max(8, vh - r.height - 8);
    if (top < 8) top = 8;
    pos.value = { left, top };
}

const cols = 4;
function onKey(e) {
    const btns = [...box.value.querySelectorAll('button[data-layout]')];
    const i = btns.indexOf(document.activeElement);
    const move = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: cols, ArrowUp: -cols }[e.key];
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); emit('close'); return; }
    if (move !== undefined) {
        e.preventDefault();
        const n = Math.min(btns.length - 1, Math.max(0, i + move));
        btns[n]?.focus();
    }
    if (e.key === 'Tab') { e.preventDefault(); btns[(i + (e.shiftKey ? -1 : 1) + btns.length) % btns.length]?.focus(); }
}
function onDocPointer(e) {
    if (box.value && !box.value.contains(e.target)) emit('close');
}
const shape = { t: 'var(--ink)', b: 'var(--line-strong)', m: 'var(--line)', k: 'var(--ink-2)' };
const title = computed(() => 'New slide');

onMounted(async () => {
    await nextTick();
    place();
    box.value?.querySelector('button[data-layout]')?.focus();
    load().then(() => nextTick(place));
    setTimeout(() => document.addEventListener('pointerdown', onDocPointer, true), 0);
    window.addEventListener('resize', place);
});
onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocPointer, true);
    window.removeEventListener('resize', place);
});
</script>

<template>
    <Teleport to="body">
        <div ref="box" class="pr lc pr-menu" role="dialog" aria-label="Choose a layout" data-no-shortcuts :style="{ left: pos.left + 'px', top: pos.top + 'px' }" @keydown="onKey">
            <p class="lc-title pr-small">{{ title }}</p>
            <div class="lc-grid">
                <button v-for="it in items" :key="it.id" type="button" class="lc-item" :data-layout="it.id" :aria-label="it.name" @click="emit('pick', hasLibrary ? it : { id: 'blank', name: 'Blank', def: null })">
                    <svg class="lc-glyph" :viewBox="`0 0 ${GLYPH_W} ${GLYPH_H}`" aria-hidden="true">
                        <rect x="0.5" y="0.5" :width="GLYPH_W - 1" :height="GLYPH_H - 1" rx="1" fill="var(--sheet)" stroke="var(--line-strong)" />
                        <rect v-for="(r, i) in it.glyph" :key="i" :x="r.x" :y="r.y" :width="r.w" :height="r.h" rx="0.4" :fill="shape[r.k]" :opacity="r.k === 't' ? 0.9 : 0.55" />
                    </svg>
                    <span class="lc-name">{{ it.name }}</span>
                </button>
            </div>
            <p v-if="!hasLibrary" class="lc-note pr-hint">The layout list could not be loaded, so any choice adds a blank slide. Reload the page to try again.</p>
        </div>
    </Teleport>
</template>

<style>
.lc { position: fixed; z-index: 120; width: 340px; padding: 12px; }
.lc-title { color: var(--ink-2); margin-bottom: 8px; }
.lc-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
.lc-item { display: grid; gap: 4px; padding: 6px 4px 5px; border: 1px solid transparent; border-radius: var(--r-2); background: transparent; text-align: center; min-height: 44px; }
.lc-item:hover { background: var(--raised); border-color: var(--line); }
.lc-glyph { width: 100%; height: auto; display: block; }
.lc-name { font-size: var(--t-xs); line-height: 1.15; color: var(--ink-2); font-weight: 500; }
.lc-note { margin-top: 10px; }
@media (max-width: 767px) {
    .lc { left: 8px !important; right: 8px; top: auto !important; bottom: 8px; width: auto; max-height: 70dvh; overflow: auto; }
}
</style>
