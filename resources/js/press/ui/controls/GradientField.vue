<script setup>
// A linear gradient: a bar with draggable stops (click the bar to add one, Delete removes), the colour and position of the
// selected stop, and the angle. modelValue is the spec gradient {g: 'linear', a: degrees, stops: [[0..1, colour], ...]}.
import { computed, ref } from 'vue';
import Field from './Field.vue';
import NumberField from './NumberField.vue';
import ColorField from './ColorField.vue';
import IconButton from './IconButton.vue';
import { resolveColor } from '../../editor/core/theme.js';
import { addStop, gradientCss, moveStop, newGradient, normAngle, removeStop, setStopColor, sortStops } from './colors.js';
import { useDeckTheme } from './themeKit.js';

const props = defineProps({
    modelValue: { type: Object, default: null },
    label: { type: String, default: 'Gradient' },
    hideLabel: { type: Boolean, default: false },
    theme: { type: Object, default: null },
});
const emit = defineEmits(['update:modelValue']);
const getTheme = useDeckTheme(props.theme);
const g = computed(() => props.modelValue || newGradient());
const sel = ref(0);
const bar = ref(null);
const css = (c) => resolveColor(c, getTheme());
const barStyle = computed(() => ({ backgroundImage: gradientCss(g.value, css) }));
const cur = computed(() => g.value.stops[Math.min(sel.value, g.value.stops.length - 1)]);
const set = (next) => emit('update:modelValue', next);
const posOf = (e) => { const r = bar.value.getBoundingClientRect(); return (e.clientX - r.left) / r.width; };

function barDown(e) {
  if (e.target.closest('.c-gs')) return;
  const pos = posOf(e);
  const near = sortStops(g.value.stops).reduce((a, s) => (Math.abs(s[0] - pos) < Math.abs(a[0] - pos) ? s : a));
  const next = addStop(g.value, pos, near[1]);
  if (next === g.value) return;
  set(next);
  sel.value = next.stops.findIndex((s) => s[0] === Math.round(pos * 1000) / 1000);
}
function stopDown(e, i) {
  e.preventDefault();
  sel.value = i;
  e.currentTarget.focus();
  let idx = i;
  const move = (ev) => { const p = Math.min(1, Math.max(0, posOf(ev))); const next = moveStop(g.value, idx, p); set(next); const q = Math.round(p * 1000) / 1000; idx = next.stops.findIndex((s, k) => s[0] === q && k === idx) >= 0 ? idx : next.stops.findIndex((s) => s[0] === q); sel.value = idx; };
  const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); };
  window.addEventListener('pointermove', move);
  window.addEventListener('pointerup', up);
}
function stopKey(e, i) {
  const d = { ArrowLeft: -1, ArrowRight: 1 }[e.key];
  if (d !== undefined) { e.preventDefault(); set(moveStop(g.value, i, g.value.stops[i][0] + d * (e.shiftKey ? 0.1 : 0.01))); }
  else if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); remove(i); }
}
function remove(i = sel.value) { const next = removeStop(g.value, i); if (next !== g.value) { set(next); sel.value = Math.max(0, i - 1); } }
const label = (s, i) => `Stop ${i + 1} at ${Math.round(s[0] * 100)}%`;
</script>

<template>
    <Field v-slot="{ id }" :label="props.label" :hide-label="hideLabel">
        <div class="c-grad" role="group" :aria-labelledby="undefined" :aria-label="props.label">
            <div :id="id" ref="bar" class="c-grad-bar" :style="barStyle" @pointerdown="barDown">
                <button v-for="(s, i) in g.stops" :key="i" type="button" class="c-gs" :class="{ 'is-on': i === sel }" :style="{ left: s[0] * 100 + '%', backgroundColor: css(s[1]) || 'transparent' }" :aria-label="label(s, i)" :aria-pressed="i === sel" @pointerdown="stopDown($event, i)" @keydown="stopKey($event, i)" @focus="sel = i"></button>
            </div>
            <div class="c-grad-row">
                <ColorField :model-value="cur[1]" label="Stop colour" :theme="theme" @update:model-value="set(setStopColor(g, sel, $event || '#000000'))" />
                <NumberField :model-value="Math.round(cur[0] * 100)" label="At" unit="%" :min="0" :max="100" @update:model-value="set(moveStop(g, sel, $event / 100))" />
                <IconButton icon="trash" label="Remove this stop" size="sm" :disabled="g.stops.length <= 2" @click="remove()" />
            </div>
            <NumberField :model-value="g.a" label="Angle" unit="°" :min="0" :max="360" @update:model-value="set({ ...g, a: normAngle($event) })" />
        </div>
    </Field>
</template>
