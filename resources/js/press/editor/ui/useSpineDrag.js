/**
 * Pointer driven reordering for the Spine (claims list and sheets grid).
 *
 *   const drag = useSpineDrag({ listEl, scroller, getRows, getView, canDrag, idsFor, onDrop })
 *   drag.state   reactive {active, ids, label, x, y, gap, indicator: {x, y, w, h, vertical}}
 *   drag.begin(event, slideId)    call from pointerdown on a row
 *
 * A mouse starts a drag after 5 px from anywhere on the row; touch starts from the rail handle only (the caller marks
 * it with data-handle) so the list still scrolls under a finger. Escape cancels. The list scrolls itself when the
 * pointer nears an edge. On drop `onDrop(movingIds, gap)` is called with the gap index in the row list.
 */
import { onBeforeUnmount, reactive } from 'vue';
import { dropGap, gridGap } from './spineModel.js';

const THRESHOLD = 5;
const EDGE = 48;

export function useSpineDrag({ listEl, scroller, getRows, getView, canDrag, idsFor, labelFor, onDrop }) {
  const state = reactive({ active: false, ids: [], label: '', x: 0, y: 0, gap: -1, indicator: null });
  let pending = null;
  let raf = 0;
  let last = { x: 0, y: 0 };

  const rowEls = () => [...listEl.value.querySelectorAll('[data-row]')];

  function measure(e) {
    const els = rowEls();
    const rects = els.map((el) => {
      const r = el.getBoundingClientRect();
      return { left: r.left, right: r.right, top: r.top, bottom: r.bottom, header: el.dataset.row === 'section' };
    });
    let gap;
    if (getView() === 'sheets') gap = gridGap(rects, e.clientX, e.clientY);
    else gap = dropGap(rects.map((r) => (r.top + r.bottom) / 2), e.clientY);
    const host = listEl.value.getBoundingClientRect();
    let indicator = null;
    if (rects.length) {
      if (getView() === 'sheets') {
        const next = rects[gap];
        const prev = rects[gap - 1];
        if (next && !next.header) indicator = { x: next.left - host.left - 4, y: next.top - host.top, w: 2, h: next.bottom - next.top, vertical: true };
        else if (prev && !prev.header) indicator = { x: prev.right - host.left + 3, y: prev.top - host.top, w: 2, h: prev.bottom - prev.top, vertical: true };
        else if (next) indicator = { x: next.left - host.left, y: next.top - host.top - 3, w: next.right - next.left, h: 2, vertical: false };
        else if (prev) indicator = { x: prev.left - host.left, y: prev.bottom - host.top + 1, w: prev.right - prev.left, h: 2, vertical: false };
      } else {
        const y = gap === 0 ? rects[0].top - 1 : rects[gap - 1].bottom + 1;
        indicator = { x: 0, y: y - host.top, w: host.width, h: 2, vertical: false };
      }
    }
    state.gap = gap;
    state.indicator = indicator;
  }

  function autoscroll() {
    cancelAnimationFrame(raf);
    if (!state.active) return;
    const sc = scroller.value;
    const r = sc.getBoundingClientRect();
    let dy = 0;
    if (last.y < r.top + EDGE) dy = -Math.ceil((r.top + EDGE - last.y) / 4);
    else if (last.y > r.bottom - EDGE) dy = Math.ceil((last.y - (r.bottom - EDGE)) / 4);
    if (dy) {
      sc.scrollTop += dy;
      measure({ clientX: last.x, clientY: last.y });
      raf = requestAnimationFrame(autoscroll);
    }
  }

  function onMove(e) {
    last = { x: e.clientX, y: e.clientY };
    if (!state.active) {
      if (Math.hypot(e.clientX - pending.x0, e.clientY - pending.y0) < THRESHOLD) return;
      state.active = true;
      state.ids = pending.ids;
      state.label = labelFor(pending.id, pending.ids);
      document.body.classList.add('sp-dragging');
      try { pending.target.setPointerCapture?.(pending.pointerId); } catch { /* the window listeners still work */ }
    }
    state.x = e.clientX;
    state.y = e.clientY;
    measure(e);
    autoscroll();
  }

  function end(commit) {
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onUp);
    window.removeEventListener('pointercancel', onCancel);
    window.removeEventListener('keydown', onKey, true);
    cancelAnimationFrame(raf);
    document.body.classList.remove('sp-dragging');
    const was = state.active;
    const result = { ids: state.ids, gap: state.gap };
    state.active = false;
    state.indicator = null;
    state.gap = -1;
    pending = null;
    if (was && commit && result.gap >= 0) onDrop(result.ids, result.gap);
    return was;
  }
  const onUp = () => end(true);
  const onCancel = () => end(false);
  function onKey(e) {
    if (e.key === 'Escape' && state.active) { e.preventDefault(); e.stopPropagation(); end(false); }
  }

  function begin(e, slideId) {
    if (!canDrag() || e.button !== 0) return;
    if (e.pointerType === 'touch' && !e.target.closest('[data-handle]')) return;
    if (e.target.closest('input, textarea, [data-nodrag]')) return;
    pending = { id: slideId, ids: idsFor(slideId), x0: e.clientX, y0: e.clientY, pointerId: e.pointerId, target: e.currentTarget };
    last = { x: e.clientX, y: e.clientY };
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
    window.addEventListener('pointercancel', onCancel);
    window.addEventListener('keydown', onKey, true);
  }

  onBeforeUnmount(() => end(false));
  return { state, begin };
}
