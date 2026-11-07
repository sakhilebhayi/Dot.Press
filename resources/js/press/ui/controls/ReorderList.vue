<script setup>
// A list whose rows can be reordered by dragging the handle (mouse, touch, pen) or with the keyboard (focus the handle, then
// ArrowUp or ArrowDown; Alt+Arrow on the row works too). It never mutates `items`: it emits `update:items` with the new
// order and `reorder` with {key, from, to}.
//   slot default ({item, index}) the row content; slot actions ({item, index}) trailing buttons
import { nextTick, ref } from 'vue';
import ListRow from './ListRow.vue';
import { dropIndex, moveItem, stepIndex } from './reorder.js';

const props = defineProps({
    items: { type: Array, required: true },
    itemKey: { type: [String, Function], default: 'id' },
    label: { type: String, default: 'List' },
    selectedKey: { type: [String, Number], default: null },
    handleLabel: { type: Function, default: (item) => 'Reorder' },
    interactive: { type: Boolean, default: false },
});
const emit = defineEmits(['update:items', 'reorder', 'select']);
const root = ref(null);
const dragKey = ref(null);
const gap = ref(-1);
const key = (item) => (typeof props.itemKey === 'function' ? props.itemKey(item) : item[props.itemKey]);

function apply(from, to) {
  if (from === to) return;
  const next = moveItem(props.items, from, to);
  emit('update:items', next);
  emit('reorder', { key: key(props.items[from]), from, to });
}
async function stepKey(e, i) {
  const d = { ArrowUp: -1, ArrowDown: 1 }[e.key];
  if (d === undefined) return;
  e.preventDefault();
  const to = stepIndex(i, d, props.items.length);
  if (to === i) return;
  const k = key(props.items[i]);
  apply(i, to);
  await nextTick();
  root.value?.querySelector(`[data-key="${CSS.escape(String(k))}"] .c-row-h`)?.focus();
}
function down(e, i) {
  if (e.button !== 0) return;
  e.preventDefault();
  const from = i;
  dragKey.value = key(props.items[i]);
  const mids = () => [...root.value.children].filter((c) => c.matches('.c-row')).map((c) => { const r = c.getBoundingClientRect(); return (r.top + r.bottom) / 2; });
  const onMove = (ev) => { gap.value = dropIndex(mids(), ev.clientY, from); };
  const finish = (commit) => {
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onUp);
    window.removeEventListener('pointercancel', onCancel);
    window.removeEventListener('keydown', onEsc, true);
    const to = gap.value;
    dragKey.value = null; gap.value = -1;
    if (commit && to >= 0) apply(from, to);
  };
  const onUp = () => finish(true);
  const onCancel = () => finish(false);
  const onEsc = (ev) => { if (ev.key === 'Escape') { ev.stopPropagation(); finish(false); } };
  window.addEventListener('pointermove', onMove);
  window.addEventListener('pointerup', onUp);
  window.addEventListener('pointercancel', onCancel);
  window.addEventListener('keydown', onEsc, true);
}
</script>

<template>
    <ul ref="root" class="c-list" :class="{ 'is-dragging': dragKey !== null }" role="list" :aria-label="label">
        <ListRow
            v-for="(item, i) in items" :key="key(item)" :data-key="key(item)" :selected="selectedKey !== null && key(item) === selectedKey" handle :handle-label="handleLabel(item)" :dragging="dragKey === key(item)"
            :interactive="interactive" :class="{ 'is-target': dragKey !== null && gap === i }" @select="emit('select', item)" @handle-down="down($event, i)" @handle-key="stepKey($event, i)"
        >
            <slot :item="item" :index="i" />
            <template v-if="$slots.actions" #actions><slot name="actions" :item="item" :index="i" /></template>
        </ListRow>
    </ul>
</template>
