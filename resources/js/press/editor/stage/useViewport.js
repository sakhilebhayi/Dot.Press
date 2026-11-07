// Viewport state for the stage: size, zoom, pan, and the two way mirror with the store's own view state.
//
//   const vp = useViewport(editor, rootRef)
//   vp.size            ref {w, h} of the viewport element
//   vp.zoom            computed number (the effective zoom, 'fit' resolved)
//   vp.origin          computed {x, y} screen position of the slide's top left
//   vp.toSlide(pt) / vp.toScreen(pt)
//   vp.setView({zoom, pan}, {animate})   set the view (zoom a number) and mirror it to ui and store
//   vp.panBy(dx, dy) / vp.zoomBy(factor, anchor) / vp.zoomTo(z, anchor)
//   vp.fit() / vp.zoomToRect(rect)
//
// ui.zoom is 'fit' or a number and is what the dock binds to. The store keeps its own zoom, zoomMode and pan so its
// keyboard commands (view.zoomIn, view.zoomFit ...) work; the two are kept equal here. ui.zoomValue is the effective
// zoom as a number for readouts.
import {
  computed, onBeforeUnmount, onMounted, shallowRef, watch,
} from 'vue';
import {
  effectiveZoom, sheetOrigin, toSlide as v2s, toScreen as s2v, zoomAtPoint, clampPan, zoomToRect as rectView, fitZoom, clampZoom,
} from './lib/view.js';

export function useViewport(editor, rootRef, deckRef) {
  const { store, ui } = editor;
  const size = shallowRef({ w: 0, h: 0 });
  const deck = () => deckRef.value;

  const zoom = computed(() => effectiveZoom(ui.zoom, size.value, deck()));
  const pan = computed(() => ({ x: ui.panX || 0, y: ui.panY || 0 }));
  const origin = computed(() => sheetOrigin(size.value, deck(), zoom.value, pan.value));

  let ro = null;
  function measure() {
    const el = rootRef.value;
    if (!el) return;
    const r = el.getBoundingClientRect();
    const w = Math.round(r.width);
    const h = Math.round(r.height);
    if (w !== size.value.w || h !== size.value.h) {
      size.value = { w, h };
      store.setViewport(w, h);
    }
  }
  onMounted(() => {
    measure();
    if (typeof ResizeObserver !== 'undefined') {
      ro = new ResizeObserver(measure);
      ro.observe(rootRef.value);
    }
  });
  onBeforeUnmount(() => ro && ro.disconnect());

  // ---- mirror with the store ---------------------------------------------------------------------
  let lock = false;
  const withLock = (fn) => { if (lock) return; lock = true; try { fn(); } finally { lock = false; } };

  const offStore = store.subscribe((s) => {
    withLock(() => {
      const wantZoom = s.zoomMode === 'fit' ? 'fit' : s.zoom;
      if (ui.zoom !== wantZoom) ui.zoom = wantZoom;
      if (ui.panX !== s.pan.x) ui.panX = s.pan.x;
      if (ui.panY !== s.pan.y) ui.panY = s.pan.y;
    });
  });
  onBeforeUnmount(offStore);

  watch(() => [ui.zoom, ui.panX, ui.panY], () => {
    withLock(() => {
      const s = store.getState();
      if (ui.zoom === 'fit') {
        // fit always means centred
        if (s.zoomMode !== 'fit' || s.pan.x || s.pan.y) store.zoomFit();
        if (ui.panX || ui.panY) { ui.panX = 0; ui.panY = 0; }
        return;
      }
      if (typeof ui.zoom === 'number' && (s.zoomMode !== 'manual' || s.zoom !== ui.zoom)) store.setZoom(ui.zoom);
      const px = ui.panX || 0;
      const py = ui.panY || 0;
      const now = store.getState();
      if (now.pan.x !== px || now.pan.y !== py) store.setPan(px, py);
    });
  });

  watch(zoom, (z) => { ui.zoomValue = z; }, { immediate: true });

  // ---- operations --------------------------------------------------------------------------------
  function setView(next, { fitMode = false } = {}) {
    if (fitMode) {
      ui.zoom = 'fit';
      ui.panX = 0;
      ui.panY = 0;
      return;
    }
    const z = clampZoom(next.zoom);
    const p = clampPan(next.pan, size.value, deck(), z);
    ui.zoom = Math.round(z * 10000) / 10000;
    ui.panX = Math.round(p.x * 100) / 100;
    ui.panY = Math.round(p.y * 100) / 100;
  }

  const api = {
    size, zoom, pan, origin,
    toSlide: (pt) => v2s(pt, origin.value, zoom.value),
    toScreen: (pt) => s2v(pt, origin.value, zoom.value),
    setView,
    panBy(dx, dy) {
      setView({ zoom: zoom.value, pan: { x: pan.value.x + dx, y: pan.value.y + dy } });
    },
    zoomTo(z, anchor = { x: size.value.w / 2, y: size.value.h / 2 }) {
      const r = zoomAtPoint({ zoom: zoom.value, pan: pan.value, vp: size.value, deck: deck() }, z, anchor);
      setView(r);
    },
    zoomBy(factor, anchor) { api.zoomTo(zoom.value * factor, anchor); },
    fit() { setView(null, { fitMode: true }); },
    zoomToRect(rect, opts) {
      if (!rect) return api.fit();
      setView(rectView(rect, size.value, deck(), opts));
      return null;
    },
    fitZoom: () => fitZoom(size.value, deck()),
    measure,
  };
  return api;
}
