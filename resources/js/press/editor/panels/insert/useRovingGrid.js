// Arrow-key navigation for a grid of buttons (roving tabindex). The grid element holds the cells as `[data-cell]` in order.
//
//   const g = useRovingGrid(gridRef, { count: () => n })
//   <div ref="gridRef" @keydown="g.onKeydown" @focusin="g.onFocusin"> <button data-cell :tabindex="g.tab(i)" :data-i="i">
//   g.active    ref of the index that is in the tab order
import { nextTick, ref } from 'vue';
import { moveGridIndex } from './iconModel.js';

export function useRovingGrid(gridRef, { count }) {
  const active = ref(0);
  const cols = () => {
    const el = gridRef.value;
    if (!el) return 1;
    const t = getComputedStyle(el).gridTemplateColumns;
    return Math.max(1, t && t !== 'none' ? t.split(' ').length : 1);
  };
  const tab = (i) => (i === Math.min(active.value, Math.max(0, count() - 1)) ? 0 : -1);
  function onKeydown(e) {
    if (e.altKey || e.ctrlKey || e.metaKey) return;
    const cell = e.target.closest?.('[data-cell]');
    if (!cell) return;
    const next = moveGridIndex(Number(cell.dataset.i), e.key, cols(), count());
    if (next < 0) return;
    e.preventDefault();
    active.value = next;
    nextTick(() => gridRef.value?.querySelector(`[data-cell][data-i="${next}"]`)?.focus());
  }
  function onFocusin(e) {
    const cell = e.target.closest?.('[data-cell]');
    if (cell) active.value = Number(cell.dataset.i);
  }
  return { active, tab, onKeydown, onFocusin };
}
