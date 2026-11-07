<script setup>
// A number with a label you can drag to scrub (shift x10, alt x0.1), arrow keys (shift x10), units, min, max, step and
// a mixed state for multi selection (modelValue === 'mixed'). Commits on blur and Enter; Escape reverts.
import { computed, ref, useId, watch } from 'vue';
import { MIXED } from './mixed.js';
import { formatNumber, nudge, parseNumber, scrub, snap } from './numbers.js';

const props = defineProps({
    modelValue: { type: [Number, String], default: null },
    label: { type: String, default: '' },
    hideLabel: { type: Boolean, default: false },
    unit: { type: String, default: '' },
    min: { type: Number, default: -Infinity },
    max: { type: Number, default: Infinity },
    step: { type: Number, default: 1 },
    pxPerStep: { type: Number, default: 4 },
    placeholder: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue', 'commit']);
const uid = useId();
const id = computed(() => `n${uid}`);
const mixed = computed(() => props.modelValue === MIXED);
const shown = () => (mixed.value || props.modelValue === null || props.modelValue === '' ? '' : formatNumber(Number(props.modelValue), props.step));
const text = ref(shown());
const editing = ref(false);
watch(() => props.modelValue, () => { if (!editing.value) text.value = shown(); });
const bounds = () => ({ min: props.min, max: props.max, step: props.step });
const current = () => (mixed.value || props.modelValue === null ? null : Number(props.modelValue));

function commit() {
  editing.value = false;
  const n = parseNumber(text.value);
  if (n === null) { text.value = shown(); return; }
  const v = snap(n, bounds());
  text.value = formatNumber(v, props.step);
  if (v !== current()) emit('update:modelValue', v);
  emit('commit', v);
}
function onKey(e) {
  if (e.key === 'Enter') { e.preventDefault(); commit(); e.target.select(); }
  else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); text.value = shown(); editing.value = false; e.target.blur(); }
  else if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
    e.preventDefault();
    const base = parseNumber(text.value) ?? current();
    const v = nudge(base, e.key === 'ArrowUp' ? 1 : -1, { ...bounds(), shift: e.shiftKey });
    text.value = formatNumber(v, props.step);
    emit('update:modelValue', v);
  }
}

// scrubbing: drag the label sideways
let drag = null;
function down(e) {
  if (props.disabled || e.button !== 0) return;
  drag = { x: e.clientX, start: current() ?? (Number.isFinite(props.min) ? props.min : 0), moved: false };
  e.currentTarget.setPointerCapture?.(e.pointerId);
}
function move(e) {
  if (!drag) return;
  const dx = e.clientX - drag.x;
  if (!drag.moved && Math.abs(dx) < 3) return;
  drag.moved = true;
  const v = scrub(drag.start, dx, { ...bounds(), pxPerStep: props.pxPerStep, shift: e.shiftKey, alt: e.altKey });
  text.value = formatNumber(v, props.step);
  if (v !== current()) emit('update:modelValue', v);
}
function up() {
  if (drag?.moved) emit('commit', current());
  drag = null;
}
</script>

<template>
    <div class="c-num" :class="{ 'is-mixed': mixed, 'is-disabled': disabled, 'has-error': !!error }">
        <div class="c-num-row">
            <label v-if="label" :for="id" class="c-num-l" :class="{ 'pr-sr': hideLabel }" :title="disabled ? '' : 'Drag sideways to change'" @pointerdown="down" @pointermove="move" @pointerup="up" @pointercancel="up">{{ label }}</label>
            <input
                :id="id" v-model="text" class="c-num-in" type="text" inputmode="decimal" autocomplete="off" spellcheck="false" :disabled="disabled" :placeholder="mixed ? 'Mixed' : placeholder"
                :aria-label="label ? undefined : 'Value'" :aria-invalid="!!error || undefined" :aria-describedby="error ? `${id}-e` : hint ? `${id}-h` : undefined"
                @focus="editing = true; $event.target.select()" @blur="commit" @keydown="onKey"
            />
            <span v-if="unit" class="c-num-u pr-state" aria-hidden="true">{{ unit }}</span>
        </div>
        <p v-if="error" :id="`${id}-e`" class="c-error" role="alert">{{ error }}</p>
        <p v-else-if="hint" :id="`${id}-h`" class="c-hint">{{ hint }}</p>
    </div>
</template>
