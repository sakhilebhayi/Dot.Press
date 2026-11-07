// Pointer gestures for the stage: select, move, resize, rotate, line ends, marquee, draw, pan, pinch, long press, wheel.
//
// All geometry comes from ./lib (and the core). This file only turns pointer events into those calls and applies the result.
//
// How a drag stays fast (decision, also in the header of EditorStage.vue):
//   - move and rotate write `transform` straight onto the dragged elements' wrappers (`.p-el`), nothing else runs;
//   - resize, line ends and drawing hand a `preview` patch map to the stage, which gives SlideView a slide with those
//     patches applied for this frame (Vue re-renders only the changed elements, their content reflows live);
//   - the store is written ONCE, on pointer up, with a unique undo key, so a drag is one undo step and the sync engine
//     sends one op batch per gesture. The store does not need 60 ms batches; collaborators see the result on release.
//   - will-change is set on the dragged wrappers for the duration of the gesture only.
import { shallowRef } from 'vue';
import { pickTop, pickBelow, lockedAt } from './lib/hit.js';
import {
  selectionFrame, screenBox, handleLayout, hitHandle, cursorFor, lineEnds, hitLineEnd,
} from './lib/handles.js';
import {
  beginMove, stepMove, beginResize, stepResize, beginRotate, stepRotate, stepLineEnd,
} from './lib/transform.js';
import { buildFromTool, isClick, NEEDS_FILE, toolKind } from './lib/draw.js';
import { pinchStep, wheelZoomFactor, normalizeWheel } from './lib/view.js';
import { moveGuide } from './lib/guides.js';
import { safeMargin } from '../core/margins.js';
import { cloneElements } from '../core/clipboard.js';
import { usedIds } from '../core/doc.js';
import { marquee as marqueeHits } from '../core/selection.js';
import { rotatedBounds, unionBounds } from '../core/geometry.js';

export const EMPTY_LIVE = Object.freeze({
  mode: null, patches: null, guides: [], labels: [], readout: '', readoutAt: null, marquee: null, marqueeIds: [], draw: null, dropTarget: null, guideDrag: null,
});

const THRESHOLD = { mouse: 3, pen: 4, touch: 8 };
const LONG_PRESS_MS = 520;

export function useGestures(env) {
  const {
    editor, store, ui, vp, rootRef, viewRef, hooks,
  } = env;
  const live = shallowRef(EMPTY_LIVE);
  const preview = shallowRef(null);
  const hover = shallowRef({ id: null, handle: null, lockedId: null });
  const cursor = shallowRef('default');
  const pointers = new Map();
  let g = null;
  let raf = 0;
  let pendingEv = null;
  let rect = null;
  let spaceDown = false;
  let counter = 0;
  let longTimer = 0;
  let lastCursorKey = '';

  const canEdit = () => env.canEdit();
  const state = () => store.getState();
  const slide = () => store.slide();
  const deck = () => state().doc.deck;
  const zoom = () => vp.zoom.value;
  const selIds = () => state().selection.ids;
  const selEls = () => store.selectedElements();
  const local = (e) => ({ x: e.clientX - rect.left, y: e.clientY - rect.top });
  const slidePt = (pt) => vp.toSlide(pt);
  const isCoarse = (e) => (e ? e.pointerType === 'touch' : false);
  const gridUnit = () => (ui.grid ? Math.max(4, Math.round(deck().w / 96)) : 0);
  const snapOn = (e) => ui.snap !== false && !(e.metaKey || e.ctrlKey);
  const showGuides = () => ui.guides !== false;

  function setLive(next) { live.value = next; }
  function resetLive() { live.value = EMPTY_LIVE; }

  // ---- helpers ------------------------------------------------------------------------------------
  function nodesOf() { return viewRef.value?.nodes?.() || new Map(); }

  function writeTransform(node, x, y, rot) {
    node.style.transform = `translate(${x}px, ${y}px)${rot ? ` rotate(${+Number(rot).toFixed(3)}deg)` : ''}`;
  }

  function patchMap(list) {
    const m = new Map();
    for (const p of list) m.set(p.id, p.p);
    return m;
  }

  function selectionLayout(e) {
    const frame = selectionFrame(selEls().map((el) => ({ ...el, ...(live.value.patches?.get(el.id) || {}) })));
    if (!frame || frame.line) return { frame, layout: null };
    const sb = screenBox(frame.box, vp.origin.value, zoom());
    return { frame, layout: handleLayout(sb, { coarse: isCoarse(e) }) };
  }

  // ---- hover and cursor --------------------------------------------------------------------------
  function updateHover(e) {
    const pt = local(e);
    let h = { id: null, handle: null, lockedId: null };
    let c = 'default';
    const tool = ui.tool;
    if (spaceDown || tool === 'hand') c = 'grab';
    else if (tool !== 'select' && canEdit()) c = tool === 'text' ? 'text' : 'crosshair';
    else if (!ui.cropping) {
      const sp = slidePt(pt);
      if (canEdit() && selIds().length) {
        const els = selEls();
        if (els.length === 1 && selectionFrame(els).line) {
          const k = hitLineEnd(lineEnds(els[0]), pt, vp.origin.value, zoom(), 10);
          if (k) { h = { ...h, handle: `line-${k}` }; c = 'crosshair'; }
        } else {
          const { layout } = selectionLayout(e);
          const hit = layout ? hitHandle(layout, pt, { coarse: isCoarse(e) }) : null;
          if (hit) {
            h = { ...h, handle: hit.kind === 'rotate' ? 'rotate' : hit.handle };
            c = hit.kind === 'rotate' ? 'var(--es-cursor-rotate, alias)' : cursorFor(hit.handle, layout.rot);
          }
        }
      }
      if (!h.handle) {
        const id = pickTop(slide(), sp, { pad: 6 / zoom() });
        if (id) {
          h = { ...h, id };
          c = selIds().includes(id) && canEdit() ? 'move' : 'default';
        } else {
          const lk = lockedAt(slide(), sp, 2 / zoom());
          if (lk) { h = { ...h, lockedId: lk }; c = 'not-allowed'; }
        }
      }
    }
    ui.pointer = e.pointerType === 'touch' ? null : slidePt(pt);
    const key = `${h.id}|${h.handle}|${h.lockedId}`;
    if (key !== `${hover.value.id}|${hover.value.handle}|${hover.value.lockedId}`) hover.value = h;
    if (c !== lastCursorKey) { lastCursorKey = c; cursor.value = c; }
  }

  // ---- gesture lifecycle -------------------------------------------------------------------------
  function endCapture() {
    for (const id of pointers.keys()) {
      try { rootRef.value?.releasePointerCapture(id); } catch { /* already released */ }
    }
  }

  function clearGesture() {
    g = null;
    clearTimeout(longTimer);
    preview.value = null;
    resetLive();
    ui.dragging = null;
    lastCursorKey = '';
  }

  /** Put every dragged wrapper back where the document says it is. */
  function revertDom() {
    if (!g?.nodes) return;
    for (const [id, node] of g.nodes) {
      if (g.ghosts) { node.remove(); continue; }
      const el = g.els?.find((x) => x.id === id);
      if (el) writeTransform(node, el.x, el.y, el.rot);
      node.style.willChange = '';
    }
  }

  function cancel() {
    if (!g) return false;
    revertDom();
    clearGesture();
    return true;
  }

  // ---- pan and pinch -----------------------------------------------------------------------------
  function startPan(e) {
    g = { type: 'pan', start: local(e), pan: { ...vp.pan.value }, zoom: zoom() };
    cursor.value = 'grabbing';
  }

  function startPinch() {
    cancel();
    const [a, b] = [...pointers.values()];
    g = { type: 'pinch', prev: { a: { ...a.pt }, b: { ...b.pt } } };
    setLive({ ...EMPTY_LIVE, mode: 'pinch' });
  }

  function framePinch() {
    const [a, b] = [...pointers.values()];
    if (!a || !b) return;
    const next = { a: { ...a.pt }, b: { ...b.pt } };
    const s = pinchStep(g.prev, next);
    const z0 = zoom();
    // zoom about the midpoint, then follow the midpoint's motion
    vp.zoomTo(z0 * s.scale, { x: s.cx - s.dx, y: s.cy - s.dy });
    vp.panBy(s.dx, s.dy);
    g.prev = next;
  }

  // ---- pointer down ------------------------------------------------------------------------------
  function onPointerDown(e) {
    const t = e.target;
    if (t.closest?.('[data-es-ui]')) return; // bars, menus, the text editor, the crop layer, rulers handle themselves
    rect = rootRef.value.getBoundingClientRect();
    const pt = local(e);
    // a read only deck still lets people select and copy text: a press on the text of an already selected box is the browser's
    const inText = t.closest?.('.pt-in');
    if (inText && !canEdit() && e.button === 0 && selIds().includes(inText.closest('[data-eid]')?.getAttribute('data-eid'))) return;
    pointers.set(e.pointerId, { pt, type: e.pointerType });
    try { rootRef.value.setPointerCapture(e.pointerId); } catch { /* not capturable */ }

    if (e.pointerType === 'touch' && pointers.size === 2) { startPinch(); e.preventDefault(); return; }
    if (g) return;

    if (e.button === 1 || spaceDown || ui.tool === 'hand') { e.preventDefault(); startPan(e); rootRef.value.focus({ preventScroll: true }); return; }
    if (e.button === 2) { selectUnderContextMenu(pt); return; }
    if (e.button !== 0) return;

    if (ui.editingText) hooks.finishText();
    if (ui.cropping) hooks.commitCrop();
    ui.contextMenu = null;
    if (!t.closest?.('.es-proxy')) rootRef.value.focus({ preventScroll: true });
    hooks.closePopovers?.();

    if (ui.tool !== 'select' && canEdit()) { startDraw(e, pt); return; }
    if (ui.tool !== 'select') return;
    selectDown(e, pt);
    if (e.pointerType === 'touch' && g) {
      longTimer = setTimeout(() => {
        if (g && (g.type === 'pending' || g.type === 'marquee') && !g.moved) {
          const c = { x: e.clientX, y: e.clientY };
          g = { type: 'longpress' };
          hooks.openContext(c.x, c.y);
        }
      }, LONG_PRESS_MS);
    }
  }

  function selectUnderContextMenu(pt) {
    const sp = slidePt(pt);
    const id = pickTop(slide(), sp, { pad: 6 / zoom() });
    if (id && !selIds().includes(id)) store.clickElement(id, {});
    else if (!id) store.clearSelection();
  }

  function selectDown(e, pt) {
    const sp = slidePt(pt);
    const els = selEls();
    const edit = canEdit();
    // user guides from the rulers can be grabbed
    if (ui.rulers && (ui.userGuides || []).length) {
      const gi = guideAt(pt);
      if (gi >= 0) { g = { type: 'guide', index: gi, start: pt }; e.preventDefault(); return; }
    }
    if (edit && els.length) {
      const frame = selectionFrame(els);
      if (frame.line) {
        const k = hitLineEnd(lineEnds(els[0]), pt, vp.origin.value, zoom(), isCoarse(e) ? 18 : 10);
        if (k) { startLineEnd(els[0], k); return; }
      } else {
        const { layout } = selectionLayout(e);
        const hit = layout ? hitHandle(layout, pt, { coarse: isCoarse(e) }) : null;
        if (hit?.kind === 'resize') { startResize(hit.handle); return; }
        if (hit?.kind === 'rotate') { startRotate(sp); return; }
      }
    }
    const pad = 6 / zoom();
    let id = pickTop(slide(), sp, { pad });
    if (id && e.altKey && !edit) id = pickBelow(slide(), sp, id, { pad });
    const additive = e.shiftKey || e.metaKey || e.ctrlKey;
    if (id) {
      const wasSelected = selIds().includes(id);
      if (additive) {
        if (!wasSelected) store.clickElement(id, { shift: true });
      } else if (!wasSelected) {
        store.clickElement(id, {});
      }
      g = {
        type: 'pending', id, start: pt, startSlide: sp, additive, wasSelected, moved: false, pointerType: e.pointerType,
      };
    } else {
      g = {
        type: 'marquee', start: pt, startSlide: sp, additive, moved: false, pointerType: e.pointerType, base: selIds(),
      };
    }
    if (hover.value.lockedId && !id) hooks.announce?.('That element is locked. Unlock it from the context menu to edit it.');
  }

  function guideAt(pt) {
    const guides = ui.userGuides || [];
    const o = vp.origin.value;
    const z = zoom();
    for (let i = 0; i < guides.length; i++) {
      const gd = guides[i];
      const p = gd.orient === 'v' ? o.x + gd.pos * z : o.y + gd.pos * z;
      const d = Math.abs((gd.orient === 'v' ? pt.x : pt.y) - p);
      if (d <= 4) return i;
    }
    return -1;
  }

  // ---- starting the transform gestures -----------------------------------------------------------
  function transformable() {
    return selEls().filter((el) => !el.lock);
  }

  function startResize(handle) {
    const els = transformable();
    if (!els.length) return;
    g = {
      type: 'resize', els, handle, ctx: beginResize({ slide: slide(), ids: els.map((x) => x.id), handle, deck: deck(), margin: safeMargin(deck()) }),
    };
    ui.dragging = { kind: 'resize', ids: els.map((x) => x.id) };
  }

  function startRotate(sp) {
    const els = transformable();
    if (!els.length) return;
    const nodes = nodesOf();
    g = {
      type: 'rotate', els, ctx: beginRotate({ slide: slide(), ids: els.map((x) => x.id), pointer0: sp }), nodes: new Map(els.map((x) => [x.id, nodes.get(x.id)]).filter(([, n]) => n)),
    };
    for (const n of g.nodes.values()) n.style.willChange = 'transform';
  }

  function startLineEnd(el, which) {
    g = { type: 'lineEnd', el, which, els: [el] };
  }

  function startMove(e) {
    const els = transformable();
    if (!els.length) { g = { type: 'idle' }; return; }
    const ids = els.map((x) => x.id);
    const nodes = nodesOf();
    const dup = e.altKey;
    const map = new Map();
    let ghosts = false;
    for (const el of els) {
      const node = nodes.get(el.id);
      if (!node) continue;
      if (dup) {
        const c = node.cloneNode(true);
        c.removeAttribute('data-eid');
        c.setAttribute('data-es-ghost', el.id);
        c.setAttribute('aria-hidden', 'true');
        node.parentNode.appendChild(c);
        map.set(el.id, c);
        ghosts = true;
      } else {
        map.set(el.id, node);
      }
      (dup ? map.get(el.id) : node).style.willChange = 'transform';
    }
    g = {
      type: 'move', els, nodes: map, ghosts, dup, ctx: beginMove({ slide: slide(), ids, deck: deck(), margin: safeMargin(deck()), userGuides: ui.userGuides || null }),
      start: g.start, outside: null,
    };
    ui.dragging = { kind: 'elements', ids, x: e.clientX, y: e.clientY, copy: dup, overSlide: null };
  }

  function startDraw(e, pt) {
    const sp = slidePt(pt);
    g = {
      type: 'draw', tool: ui.tool, start: pt, startSlide: sp, moved: false, pointerType: e.pointerType,
    };
  }

  // ---- frames ------------------------------------------------------------------------------------
  function onPointerMove(e) {
    const p = pointers.get(e.pointerId);
    if (p) {
      p.pt = { x: e.clientX - rect.left, y: e.clientY - rect.top };
      p.x = e.clientX;
      p.y = e.clientY;
    }
    pendingEv = e;
    if (!raf) raf = requestAnimationFrame(frame);
  }

  function frame() {
    raf = 0;
    const e = pendingEv;
    pendingEv = null;
    if (!e) return;
    // manual measurement hook: set window.__stageTiming = [] and drag; each entry is the milliseconds of JavaScript for one frame
    const timing = typeof window !== 'undefined' ? window.__stageTiming : null;
    const t0 = timing ? performance.now() : 0;
    runFrame(e);
    if (timing) timing.push(performance.now() - t0);
  }

  function runFrame(e) {
    if (!g) {
      if (e.pointerType !== 'touch') { rect = rootRef.value.getBoundingClientRect(); updateHover(e); }
      return;
    }
    const pt = local(e);
    switch (g.type) {
      case 'pan': {
        vp.setView({ zoom: g.zoom, pan: { x: g.pan.x + pt.x - g.start.x, y: g.pan.y + pt.y - g.start.y } });
        break;
      }
      case 'pinch': framePinch(); break;
      case 'pending': framePending(e, pt); break;
      case 'move': frameMove(e, pt); break;
      case 'resize': frameResize(e, pt); break;
      case 'rotate': frameRotate(e, pt); break;
      case 'lineEnd': frameLineEnd(e, pt); break;
      case 'marquee': frameMarquee(e, pt); break;
      case 'draw': frameDraw(e, pt); break;
      case 'guide': frameGuide(e, pt); break;
      default: break;
    }
  }

  function exceeded(e, pt, from) {
    const t = THRESHOLD[e.pointerType] || 3;
    return Math.hypot(pt.x - from.x, pt.y - from.y) > t;
  }

  function framePending(e, pt) {
    if (!exceeded(e, pt, g.start)) return;
    g.moved = true;
    clearTimeout(longTimer);
    if (!canEdit()) { g = { type: 'idle' }; return; }
    startMove(e);
    if (g.type === 'move') frameMove(e, pt);
  }

  function frameMove(e, pt) {
    const z = zoom();
    const dx = (pt.x - g.start.x) / z;
    const dy = (pt.y - g.start.y) / z;
    const r = stepMove(g.ctx, {
      dx, dy, zoom: z, shift: e.shiftKey, snap: snapOn(e), grid: gridUnit(),
    });
    const map = patchMap(r.patches);
    for (const p of r.patches) {
      const node = g.nodes.get(p.id);
      const el = g.els.find((x) => x.id === p.id);
      if (node && el) writeTransform(node, p.p.x, p.p.y, el.rot);
    }
    // dragging out of the stage toward the Spine: let the shell highlight the row under the pointer
    let over = null;
    if (e.clientX < rect.left || e.clientX > rect.right || e.clientY < rect.top || e.clientY > rect.bottom) {
      const hit = document.elementFromPoint(e.clientX, e.clientY);
      over = hit?.closest?.('[data-spine-slide]')?.getAttribute('data-spine-slide') || null;
    }
    if (ui.dragging) {
      ui.dragging.x = e.clientX;
      ui.dragging.y = e.clientY;
      if (ui.dragging.overSlide !== over) ui.dragging.overSlide = over;
    }
    g.over = over;
    setLive({
      ...EMPTY_LIVE,
      mode: 'move',
      patches: map,
      guides: showGuides() ? r.guides : [],
      labels: showGuides() ? r.labels : [],
      readout: r.readout,
      readoutAt: { x: pt.x + 18, y: pt.y + 22 },
    });
    g.last = r;
  }

  function frameResize(e, pt) {
    const sp = slidePt(pt);
    const r = stepResize(g.ctx, {
      pointer: sp, zoom: zoom(), shift: e.shiftKey, alt: e.altKey, snap: snapOn(e),
    });
    const map = patchMap(r.patches);
    preview.value = map;
    setLive({
      ...EMPTY_LIVE, mode: 'resize', patches: map, guides: showGuides() ? r.guides : [], readout: r.readout, readoutAt: { x: pt.x + 18, y: pt.y + 22 },
    });
    g.last = r;
  }

  function frameRotate(e, pt) {
    const sp = slidePt(pt);
    const r = stepRotate(g.ctx, { pointer: sp, shift: e.shiftKey });
    const map = patchMap(r.patches);
    for (const p of r.patches) {
      const node = g.nodes.get(p.id);
      const el = g.els.find((x) => x.id === p.id);
      if (node && el) writeTransform(node, p.p.x ?? el.x, p.p.y ?? el.y, p.p.rot ?? el.rot);
    }
    setLive({
      ...EMPTY_LIVE, mode: 'rotate', patches: map, readout: r.readout, readoutAt: { x: pt.x + 18, y: pt.y + 22 },
    });
    g.last = r;
  }

  function frameLineEnd(e, pt) {
    const sp = slidePt(pt);
    const r = stepLineEnd(g.el, g.which, sp, { shift: e.shiftKey });
    const map = patchMap(r.patches);
    preview.value = map;
    setLive({
      ...EMPTY_LIVE, mode: 'lineEnd', patches: map, readout: r.readout, readoutAt: { x: pt.x + 18, y: pt.y + 22 },
    });
    g.last = r;
  }

  function frameMarquee(e, pt) {
    if (!g.moved) {
      if (!exceeded(e, pt, g.start)) return;
      g.moved = true;
      clearTimeout(longTimer);
    }
    const a = g.start;
    const r = {
      x: Math.min(a.x, pt.x), y: Math.min(a.y, pt.y), w: Math.abs(pt.x - a.x), h: Math.abs(pt.y - a.y),
    };
    const z = zoom();
    const o = vp.origin.value;
    const sr = { x: (r.x - o.x) / z, y: (r.y - o.y) / z, w: r.w / z, h: r.h / z };
    const ids = marqueeHits(state().doc, state().slideId, sr, { contain: e.altKey });
    g.rectSlide = sr;
    g.ids = ids;
    setLive({ ...EMPTY_LIVE, mode: 'marquee', marquee: r, marqueeIds: ids });
  }

  function frameDraw(e, pt) {
    if (!g.moved) {
      if (!exceeded(e, pt, g.start)) return;
      g.moved = true;
    }
    const sp = slidePt(pt);
    const kind = toolKind(g.tool);
    if (NEEDS_FILE.has(kind) || kind === 'table' || kind === 'chart') return;
    const el = buildFromTool(g.tool, deck(), g.startSlide, sp, { shift: e.shiftKey, alt: e.altKey });
    if (!el) return;
    setLive({
      ...EMPTY_LIVE, mode: 'draw', draw: el, readout: kind === 'line' || kind === 'arrow' ? `${Math.round(el.w)}  ${Math.round(el.rot)}°` : `W ${Math.round(el.w)}   H ${Math.round(el.h)}`,
      readoutAt: { x: pt.x + 18, y: pt.y + 22 },
    });
    g.last = el;
  }

  function frameGuide(e, pt) {
    g.moved = true;
    const gd = (ui.userGuides || [])[g.index];
    if (!gd) return;
    const sp = slidePt(pt);
    const pos = gd.orient === 'v' ? sp.x : sp.y;
    setLive({ ...EMPTY_LIVE, mode: 'guide', guideDrag: { index: g.index, orient: gd.orient, pos }, readout: `${Math.round(pos)}`, readoutAt: { x: pt.x + 18, y: pt.y + 22 } });
  }

  // ---- pointer up --------------------------------------------------------------------------------
  function onPointerUp(e) {
    if (!pointers.has(e.pointerId)) return;
    pointers.delete(e.pointerId);
    try { rootRef.value.releasePointerCapture(e.pointerId); } catch { /* released */ }
    if (raf) { cancelAnimationFrame(raf); raf = 0; }
    // make sure the last position is applied before committing
    if (g && pendingEv) { rect = rootRef.value.getBoundingClientRect(); const ev = pendingEv; pendingEv = null; syncFrame(ev); }
    const done = g;
    if (!done) return;
    if (done.type === 'pinch') { if (pointers.size < 2) clearGesture(); return; }
    finish(done, e);
  }

  function syncFrame(ev) {
    pendingEv = ev;
    frame();
  }

  function finish(done, e) {
    const pt = local(e);
    const undoKey = `drag:${++counter}`;
    switch (done.type) {
      case 'pan': clearGesture(); break;
      case 'pending': {
        // a click: select, or narrow a multi selection to the element clicked
        if (done.additive) {
          if (done.wasSelected) store.clickElement(done.id, { shift: true });
        } else if (done.wasSelected && selIds().length > 1) {
          store.select([done.id]);
        } else if (done.wasSelected && e.altKey) {
          const id = pickBelow(slide(), done.startSlide, done.id, { pad: 6 / zoom() });
          if (id) store.select([id]);
        }
        hooks.clicked?.(done.id, e);
        clearGesture();
        break;
      }
      case 'move': commitMove(done, e, undoKey); break;
      case 'resize': {
        const patches = done.last?.patches || [];
        clearGesture();
        if (patches.length) store.patch(patches, { undoKey, label: 'Resize' });
        break;
      }
      case 'rotate': {
        const patches = done.last?.patches || [];
        for (const n of done.nodes.values()) n.style.willChange = '';
        clearGesture();
        if (patches.length) store.patch(patches, { undoKey, label: 'Rotate' });
        break;
      }
      case 'lineEnd': {
        const patches = done.last?.patches || [];
        clearGesture();
        if (patches.length) store.patch(patches, { undoKey, label: 'Edit line' });
        break;
      }
      case 'marquee': {
        if (done.moved) {
          store.select(done.ids || [], done.additive ? 'add' : 'replace');
        } else if (!done.additive) {
          store.clearSelection();
        }
        clearGesture();
        break;
      }
      case 'draw': finishDraw(done, pt, e); break;
      case 'guide': finishGuide(done, pt); break;
      default: clearGesture();
    }
  }

  function commitMove(done, e, undoKey) {
    const r = done.last;
    const over = done.over && done.over !== state().slideId ? done.over : null;
    const copy = done.dup;
    revertDom();
    const els = done.els;
    clearGesture();
    if (!r || (!r.delta.dx && !r.delta.dy && !copy)) return;
    // dropped on a Spine row: the shell moves or copies the elements to that slide (it can take the event over with
    // preventDefault); otherwise the stage does it with plain ops
    if (over) {
      const ids = els.map((x) => x.id);
      const ev = new CustomEvent('press:drop-elements', {
        bubbles: true, cancelable: true, detail: { ids, slideId: over, copy, from: state().slideId },
      });
      rootRef.value.dispatchEvent(ev);
      if (!ev.defaultPrevented) {
        const target = state().doc.slides.find((s) => s.id === over);
        if (target) {
          if (copy) {
            const cl = cloneElements(els, usedIds(target));
            store.dispatch(cl.els.map((el) => ({ op: 'el.add', slide: over, el })), { label: 'Copy to slide' });
          } else {
            store.dispatch(els.map((el) => ({ op: 'el.move', from: state().slideId, id: el.id, to: over })), { label: 'Move to slide' });
          }
          hooks.announce?.(`${copy ? 'Copied' : 'Moved'} to slide ${state().doc.slides.indexOf(target) + 1}`);
        }
      }
      return;
    }
    if (copy) {
      const cl = cloneElements(els, usedIds(slide()));
      const ops = cl.els.map((el) => {
        const src = els.find((x) => cl.idMap.get(x.id) === el.id);
        const p = r.patches.find((x) => x.id === src.id)?.p || {};
        return { op: 'el.add', slide: state().slideId, el: { ...el, ...p } };
      });
      store.dispatch(ops, { label: 'Duplicate' });
      store.select(cl.els.map((x) => x.id));
      hooks.announce?.(`Copied ${cl.els.length === 1 ? 'element' : `${cl.els.length} elements`}`);
      return;
    }
    store.patch(r.patches, { undoKey, label: 'Move' });
    hooks.moved?.(els.map((x) => x.id), r);
  }

  function finishDraw(done, pt, e) {
    const sp = slidePt(pt);
    const tool = done.tool;
    const kind = toolKind(tool);
    clearGesture();
    const click = !done.moved || isClick(done.startSlide, sp, zoom());
    if (NEEDS_FILE.has(kind)) { hooks.pickFiles?.(kind, done.startSlide); ui.tool = 'select'; return; }
    const taken = slide().els.map((x) => x.id);
    const el = buildFromTool(tool, deck(), done.startSlide, sp, { shift: e.shiftKey, alt: e.altKey, click, taken });
    if (!el) { ui.tool = 'select'; return; }
    ui.tool = 'select';
    if (kind === 'text') { hooks.startDraftText(el); return; }
    store.addElement(el);
    if (kind === 'chart') editor.openPanel?.('chart');
    hooks.announce?.(`Added ${kind === 'shape' ? 'shape' : kind}`);
  }

  function finishGuide(done, pt) {
    const gd = (ui.userGuides || [])[done.index];
    const sp = slidePt(pt);
    const d = deck();
    clearGesture();
    if (!gd) return;
    const pos = gd.orient === 'v' ? sp.x : sp.y;
    // dropped back on its ruler, or outside the slide: delete
    const next = moveGuide(ui.userGuides, done.index, pos, d);
    ui.userGuides = next;
  }

  function onPointerCancel(e) {
    pointers.delete(e.pointerId);
    if (raf) { cancelAnimationFrame(raf); raf = 0; }
    pendingEv = null;
    if (g) { cancel(); }
  }

  function onPointerLeave() {
    ui.pointer = null;
    if (!g) hover.value = { id: null, handle: null, lockedId: null };
  }

  // ---- wheel -------------------------------------------------------------------------------------
  function onWheel(e) {
    if (e.target.closest?.('[data-es-ui]') && !ui.cropping) return;
    e.preventDefault();
    rect = rootRef.value.getBoundingClientRect();
    if (ui.cropping && hooks.cropWheel?.(e)) return;
    const pt = local(e);
    if (e.ctrlKey || e.metaKey) {
      const pinch = e.ctrlKey && Math.abs(e.deltaY) < 50;
      vp.zoomBy(wheelZoomFactor(e.deltaY, pinch), pt);
      return;
    }
    const { dx, dy } = normalizeWheel(e, vp.size.value);
    vp.panBy(-dx, -dy);
  }

  // ---- keyboard state ----------------------------------------------------------------------------
  function setSpace(v) {
    if (spaceDown === v) return;
    spaceDown = v;
    cursor.value = v ? 'grab' : 'default';
    lastCursorKey = '';
  }

  function destroy() {
    if (raf) cancelAnimationFrame(raf);
    clearTimeout(longTimer);
    endCapture();
  }

  return {
    live, preview, hover, cursor, onPointerDown, onPointerMove, onPointerUp, onPointerCancel, onPointerLeave, onWheel, setSpace, cancel,
    active: () => !!g, destroy, isDragging: () => !!g && ['move', 'resize', 'rotate', 'lineEnd', 'draw'].includes(g.type),
    unionOf: (els) => unionBounds(els.map((x) => rotatedBounds(x))),
  };
}
