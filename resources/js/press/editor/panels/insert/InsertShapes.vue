<script setup>
import { computed, onMounted, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import { lineGeometry, shapePath } from '../../../render/geometry.js';
import { resolveColor } from '../../core/theme.js';
import { SHAPES, SHAPE_LABELS } from './insertModel.js';
import { useInsert } from './useInsert.js';
import { useRovingGrid } from './useRovingGrid.js';

const props = defineProps({ focusKind: { type: String, default: null } });
const editor = useEditorContext();
const ins = useInsert(editor);
const grid = ref(null);
const accent = computed(() => resolveColor('@accent', editor.deck.value?.theme) || 'var(--ink-2)');
const nav = useRovingGrid(grid, { count: () => SHAPES.length });
const isLine = (k) => k === 'line' || k === 'arrow';
const BOX = { rect: [44, 30], round: [44, 30], callout: [44, 32], chevron: [44, 28], 'arrow-r': [44, 28], 'arrow-l': [44, 28] };
const sized = (k) => BOX[k] || [36, 36];
const pathOf = (k) => { const [w, h] = sized(k); return shapePath(k, w, h, k === 'rect' ? 0 : 8); };
const lineOf = (k) => lineGeometry(40, 0, 1, { s: 'none', e: k === 'arrow' ? 'arrow' : 'none' }, k);
onMounted(() => {
    if (props.focusKind) {
        const k = String(props.focusKind).split(':')[1];
        const i = SHAPES.indexOf(k);
        if (i >= 0) nav.active.value = i;
    }
});
</script>

<template>
    <section aria-labelledby="ip-shapes-h" class="ip-sec">
        <h3 id="ip-shapes-h" class="ip-h">Shapes <span class="pr-state">{{ SHAPES.length }}</span></h3>
        <p class="ip-note">Shapes take the theme accent. Lines and arrows draw in the ink colour.</p>
        <div ref="grid" class="ip-grid ip-grid--shapes" role="group" aria-label="Shapes" style="margin-top: 10px" @keydown="nav.onKeydown" @focusin="nav.onFocusin">
            <button
                v-for="(k, i) in SHAPES" :key="k" type="button" class="ip-item ip-cell" data-cell :data-i="i" :tabindex="nav.tab(i)" draggable="true" :aria-label="SHAPE_LABELS[k]" :title="SHAPE_LABELS[k]"
                @click="ins.insertShape(k)"
                @dragstart="ins.dragStart($event, { kind: 'insert', what: 'shape', shape: k }, SHAPE_LABELS[k])"
                @dragend="ins.dragEnd"
            >
                <svg v-if="isLine(k)" viewBox="-4 -14 52 28" aria-hidden="true" focusable="false">
                    <g transform="translate(2 0)">
                        <line :x1="lineOf(k).x1" :x2="lineOf(k).x2" :y1="0" :y2="0" class="ip-shape-line" stroke-width="3" stroke-linecap="round" />
                        <path v-for="(m, j) in lineOf(k).marks" :key="j" :d="m.d" class="ip-shape-svg" />
                    </g>
                </svg>
                <svg v-else :viewBox="`-2 -2 ${sized(k)[0] + 4} ${sized(k)[1] + 4}`" aria-hidden="true" focusable="false"><path :d="pathOf(k)" class="ip-shape-svg" :style="{ fill: accent }" /></svg>
            </button>
        </div>
    </section>
</template>
