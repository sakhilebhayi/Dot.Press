<script setup>
// Column widths as a bar: one segment per column, a handle on every edge. Drag a handle, or focus it and use the arrow keys
// (1 percent, Shift 5 percent). Widths are fractions that always sum to one; dragging trades width between the two neighbours.
import { computed, ref } from 'vue';
import { moveEdge } from './lib/cells.js';
import '../image/media.css';

const props = defineProps({
    colw: { type: Array, required: true },
    disabled: { type: Boolean, default: false },
    label: { type: String, default: 'Column widths' },
});
const emit = defineEmits(['update:colw', 'commit']);
const bar = ref(null);
const drag = ref(null); // {i, x, start}
const edges = computed(() => {
    let acc = 0;
    return props.colw.slice(0, -1).map((w) => { acc += w; return acc; });
});
const pct = (v) => `${Math.round(v * 100)}%`;

function down(e, i) {
    if (props.disabled) return;
    e.preventDefault();
    e.currentTarget.setPointerCapture?.(e.pointerId);
    e.currentTarget.focus();
    drag.value = { i, x: e.clientX, start: props.colw.slice(), width: bar.value.getBoundingClientRect().width || 1 };
}
function move(e) {
    const d = drag.value;
    if (!d) return;
    emit('update:colw', moveEdge(d.start, d.i, (e.clientX - d.x) / d.width));
}
function up() {
    if (!drag.value) return;
    drag.value = null;
    emit('commit');
}
function key(e, i) {
    if (props.disabled) return;
    const step = e.shiftKey ? 0.05 : 0.01;
    const d = e.key === 'ArrowLeft' ? -step : e.key === 'ArrowRight' ? step : 0;
    if (!d) return;
    e.preventDefault();
    emit('update:colw', moveEdge(props.colw, i, d));
}
</script>

<template>
    <div class="pm-cols">
        <div ref="bar" class="pm-cols-bar" role="group" :aria-label="label">
            <span v-for="(w, i) in colw" :key="i" class="pm-cols-seg pr-state" :style="{ flex: `${w} 1 0` }">{{ colw.length > 12 ? '' : pct(w) }}</span>
            <button
                v-for="(e, i) in edges" :key="`h${i}`" type="button" class="pm-cols-h" :class="{ 'is-drag': drag && drag.i === i }" :style="{ left: `${e * 100}%` }" role="slider"
                :aria-label="`Edge between column ${i + 1} and column ${i + 2}`" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="Math.round(e * 100)" :aria-valuetext="`${pct(colw[i])} and ${pct(colw[i + 1])}`"
                :disabled="disabled" @pointerdown="down($event, i)" @pointermove="move" @pointerup="up" @pointercancel="up" @keydown="key($event, i)"
            ></button>
        </div>
    </div>
</template>
