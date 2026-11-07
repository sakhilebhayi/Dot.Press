/**
 * The framework free editor store. It ties the document, history, selection, view state and the sync
 * engine together. State is immutable: every change makes a new `state` object, and unchanged parts
 * keep their identity, so a Vue `shallowRef` (or any other binding) sees exactly the real changes.
 *
 *   const store = createStore({ doc?, sync?, history?, rng?, now? })
 *     doc    a normalised document (createDoc) when no sync engine is given (standalone / tests)
 *     sync   a sync engine (sync.js). Without it the store applies ops locally and nothing is saved
 *
 * State
 *   { doc, slideId, selection: {slide, ids}, zoom, zoomMode: 'fit'|'manual', viewport: {w, h}, pan: {x, y},
 *     grid, guides, canUndo, canRedo, undoLabel, redoLabel, sync: <sync state> | null, notice }
 *
 * Reading:   getState(), subscribe(fn) -> unsubscribe, slide(), selectedElements()
 * Editing:   dispatch(ops, {undoKey, label}) is the one way in. Everything below calls it.
 *   undo(), redo(), transaction(label, fn)
 *   select(ids, mode), clickElement(elId, {shift, meta}), clearSelection(), selectAll(), selectStep(dir), marquee(rect, mods)
 *   setSlide(id), nextSlide(), prevSlide()
 *   addSlide({layout, after}), duplicateSlide(id?), deleteSlide(id?), moveSlide(id, after), setSlideProps(id, props)
 *   addElement(el, {select, index}), patch(patches, {undoKey, label}), nudge(dx, dy), moveSelection(dx, dy, {undoKey})
 *   copy() -> {text}, cut() -> {text}, paste({text, mode}), pastePreview({text, mode}), duplicate(), deleteSelection()
 *   copySlides(ids?) -> {text}, pasteSlides({text, mode})
 *   group(), ungroup(), forward(), backward(), front(), back(), toggleLock(), toggleHide(), flip('h'|'v')
 *   align(mode, {to}), distribute(axis, {to, mode}), tidy(), matchSize(what)
 *   applyLayout(layout), setTheme(theme), resizeDeck(w, h, mode), setDeckProps(props)
 *   setViewport(w, h), setZoom(z), zoomIn(), zoomOut(), zoomFit(), zoom100(), setPan(x, y), toggleGrid(), toggleGuides()
 *   runCommand(cmd, args), handleKey(event, scope, {mac}) -> true when a shortcut was handled
 *   destroy()
 */
import { applyOps, dupToAdd } from './ops.js';
import { createHistory } from './history.js';
import { newId, findSlide } from './doc.js';
import {
  select as selSelect, clickSelect, selectAll as selAll, selectStep as selStep, marquee as selMarquee, prune, selected, emptySelection,
} from './selection.js';
import {
  layerOps, groupOps, ungroupOps, toggleOps, removeOps, moveOps, patchOps,
} from './arrange.js';
import { alignPatches, distributePatches, tidyPatches, matchSizePatches } from './align.js';
import { flipPatch, flipGroup } from './geometry.js';
import { bounds } from './doc.js';
import { safeMargin } from './margins.js';
import {
  serializeElements, serializeSlides, toClipboardText, classifyText, pasteElements, pasteSlides, duplicateElements,
  textElementFromString, imageElementFromUrl, conformPreview,
} from './clipboard.js';
import { applyLayout as planLayout } from './layouts.js';
import { applyTheme } from './theme.js';
import { matchShortcut } from './keys.js';

const ZOOM_STEPS = [0.1, 0.25, 0.33, 0.5, 0.67, 0.75, 1, 1.25, 1.5, 2, 3, 4];

function localEngine(doc, makeId) {
  let view = doc;
  const subs = new Set();
  return {
    dispatch(ops) {
      const list = [];
      for (const op of ops) {
        if (op?.op === 'slide.dup') { const add = dupToAdd(view, op, { makeId }); if (add) list.push(add); } else list.push(op);
      }
      const r = applyOps(view, list, { makeId });
      view = r.doc;
      subs.forEach((f) => f(view, { source: 'local' }));
      return r;
    },
    getView: () => view,
    onChange: (f) => { subs.add(f); return () => subs.delete(f); },
    onIdMap: () => () => {},
    onState: () => () => {},
    get state() { return null; },
  };
}

export function createStore(opts = {}) {
  const rng = opts.rng || Math.random;
  const engine = opts.sync || localEngine(opts.doc, (taken, prefix) => newId(taken, prefix, rng));
  const history = opts.history || createHistory({ now: opts.now || Date.now });
  const subs = new Set();
  const first = engine.getView();
  let clip = null; // {payload, repeat}
  let dupRepeat = 0;
  let state = {
    doc: first,
    slideId: first.slides[0].id,
    selection: emptySelection(first.slides[0].id),
    zoom: 1, zoomMode: 'fit', viewport: { w: 0, h: 0 }, pan: { x: 0, y: 0 },
    grid: false, guides: true,
    canUndo: false, canRedo: false, undoLabel: '', redoLabel: '',
    sync: engine.state, notice: null,
  };

  function set(patch) {
    const next = { ...state, ...patch };
    let changed = false;
    for (const k of Object.keys(patch)) if (patch[k] !== state[k]) { changed = true; break; }
    if (!changed) return;
    state = next;
    subs.forEach((f) => f(state));
  }
  const hist = () => ({
    canUndo: history.canUndo(), canRedo: history.canRedo(), undoLabel: history.undoLabel(), redoLabel: history.redoLabel(),
  });
  const slideNow = () => findSlide(state.doc, state.slideId);
  const ids = (given) => given || state.selection.ids;

  function reconcile(doc, extra = {}) {
    let slideId = state.slideId;
    if (!findSlide(doc, slideId)) {
      const old = state.doc.slides.findIndex((s) => s.id === slideId);
      slideId = doc.slides[Math.min(Math.max(old, 0), doc.slides.length - 1)].id;
    }
    let selection = state.selection.slide === slideId ? prune(doc, state.selection) : emptySelection(slideId);
    if (selection.slide !== slideId) selection = emptySelection(slideId);
    set({ doc, slideId, selection, ...hist(), ...extra });
  }

  const offChange = engine.onChange((view) => reconcile(view, { sync: engine.state }));
  const offState = engine.onState((s) => set({ sync: s }));
  const offMap = engine.onIdMap((map) => {
    history.remapSlideIds(map);
    const slideId = map[state.slideId] || state.slideId;
    const selection = map[state.selection.slide] ? { ...state.selection, slide: map[state.selection.slide] } : state.selection;
    set({ slideId, selection });
  });

  // ---- the one way in ---------------------------------------------------------------------------
  function dispatch(ops, { undoKey = null, label = '', record = true, meta = null } = {}) {
    if (!ops.length) return null;
    const selBefore = state.selection;
    const result = engine.dispatch(ops);
    if (!result.applied.length) return null;
    if (record && result.inverse.length) {
      history.record({ inverse: result.inverse, guards: result.guards, label, key: undoKey, meta: meta || { selection: selBefore, slideId: state.slideId } });
    }
    reconcile(engine.getView(), { sync: engine.state });
    return result;
  }

  function runHistory(dir) {
    const r = dir === 'undo' ? history.undo(state.doc) : history.redo(state.doc);
    if (!r) return false;
    engine.dispatch(r.ops);
    const doc = engine.getView();
    let patch = {};
    const m = r.meta;
    if (m?.slideId && findSlide(doc, m.slideId)) {
      const sel = m.selection?.slide === m.slideId ? prune(doc, m.selection) : emptySelection(m.slideId);
      patch = { slideId: m.slideId, selection: sel };
    }
    reconcile(doc, { sync: engine.state, ...patch });
    return true;
  }

  const store = {
    getState: () => state,
    subscribe(fn) { subs.add(fn); return () => subs.delete(fn); },
    slide: slideNow,
    selectedElements: () => selected(state.doc, state.selection),
    dispatch,
    undo: () => { history.breakCoalescing(); return runHistory('undo'); },
    redo: () => { history.breakCoalescing(); return runHistory('redo'); },
    transaction(label, fn) {
      history.begin(label);
      try { return fn(); } finally { history.commit(); set(hist()); }
    },

    // selection
    select(idList, mode = 'replace', opts) { set({ selection: selSelect(state.doc, state.selection, state.slideId, idList, mode, opts) }); },
    clickElement(elId, mods = {}) { set({ selection: clickSelect(state.doc, state.selection, state.slideId, elId, mods) }); },
    clearSelection() { if (state.selection.ids.length) set({ selection: emptySelection(state.slideId) }); },
    selectAll() { set({ selection: selAll(state.doc, state.slideId) }); },
    selectStep(dir = 1) { set({ selection: selStep(state.doc, state.selection, dir) }); },
    marquee(rect, { shift = false, meta = false, contain = false } = {}) {
      const hit = selMarquee(state.doc, state.slideId, rect, { contain });
      set({ selection: selSelect(state.doc, state.selection, state.slideId, hit, shift || meta ? 'add' : 'replace') });
    },

    // slides
    setSlide(id) {
      if (!findSlide(state.doc, id) || id === state.slideId) return;
      history.breakCoalescing();
      set({ slideId: String(id), selection: emptySelection(String(id)) });
    },
    nextSlide() { stepSlide(1); },
    prevSlide() { stepSlide(-1); },
    addSlide({ layout = 'blank', after, props = {} } = {}) {
      const tid = newId(state.doc.slides.map((s) => s.id), 's', rng);
      const anchor = after === undefined ? state.slideId : after;
      dispatch([{ op: 'slide.add', tid, after: anchor, slide: { layout, ...props } }], { label: 'New slide' });
      set({ slideId: tid, selection: emptySelection(tid) });
      return tid;
    },
    duplicateSlide(id = state.slideId) {
      const tid = newId(state.doc.slides.map((s) => s.id), 's', rng);
      const r = dispatch([{ op: 'slide.dup', id, tid, after: id }], { label: 'Duplicate slide' });
      if (r) set({ slideId: tid, selection: emptySelection(tid) });
      return r ? tid : null;
    },
    deleteSlide(id = state.slideId) {
      return !!dispatch([{ op: 'slide.remove', id }], { label: 'Delete slide' });
    },
    moveSlide(id, after) { dispatch([{ op: 'slide.move', id, after }], { label: 'Move slide' }); },
    setSlideProps(id, props, o = {}) { dispatch([{ op: 'slide.set', id, ...props }], { label: 'Slide', ...o }); },

    // elements
    addElement(el, { select = true, index } = {}) {
      const s = slideNow();
      const e = el.id ? el : { ...el, id: newId(s.els.map((x) => x.id), 'e', rng) };
      dispatch([{ op: 'el.add', slide: s.id, el: e, ...(index === undefined ? {} : { index }) }], { label: 'Add' });
      if (select) set({ selection: selSelect(state.doc, state.selection, s.id, [e.id]) });
      return e.id;
    },
    patch(patches, { undoKey = null, label = 'Edit' } = {}) {
      return dispatch(patchOps(slideNow(), patches), { undoKey, label });
    },
    moveSelection(dx, dy, { undoKey = null } = {}) {
      return dispatch(moveOps(slideNow(), ids(), dx, dy), { undoKey, label: 'Move' });
    },
    nudge(dx, dy) { return store.moveSelection(dx, dy, { undoKey: `nudge:${state.selection.ids.join(',')}` }); },
    deleteSelection() {
      const r = dispatch(removeOps(slideNow(), ids()), { label: 'Delete' });
      if (r) set({ selection: emptySelection(state.slideId) });
      return !!r;
    },

    // clipboard
    copy() {
      if (!state.selection.ids.length) return null;
      clip = { payload: serializeElements(state.doc, state.slideId, state.selection.ids), repeat: 0 };
      return { text: toClipboardText(clip.payload) };
    },
    cut() {
      const c = store.copy();
      if (c) store.deleteSelection();
      return c;
    },
    pastePreview({ text, mode = 'conform' } = {}) {
      const payload = payloadFrom(text);
      if (!payload || payload.kind !== 'dot-press/elements') return null;
      return conformPreview(payload.els, payload.theme, state.doc.deck.theme, mode).report;
    },
    paste({ text, mode = 'conform' } = {}) {
      const kind = text ? classifyText(text) : null;
      const s = slideNow();
      if (kind && kind.kind !== 'payload') {
        const el = kind.kind === 'image-url' ? imageElementFromUrl(state.doc.deck, kind.url, { taken: s.els.map((e) => e.id), rng })
          : textElementFromString(state.doc.deck, kind.text.replace(/\r\n/g, '\n'), { taken: s.els.map((e) => e.id), rng });
        store.addElement(el);
        return { ids: [el.id], report: null };
      }
      const payload = kind ? kind.payload : clip?.payload;
      if (!payload) return null;
      if (payload.kind === 'dot-press/slides') return store.pasteSlides({ text: toClipboardText(payload), mode });
      const repeat = clip && clip.payload === payload ? clip.repeat : 0;
      const r = pasteElements(state.doc, payload, { slideId: s.id, repeat, mode, rng });
      if (!r.ops.length) return null;
      dispatch(r.ops, { label: 'Paste' });
      if (clip && clip.payload === payload) clip.repeat += 1;
      set({ selection: selSelect(state.doc, state.selection, s.id, r.ids) });
      return r;
    },
    pasteSlides({ text, mode = 'conform' } = {}) {
      const payload = text ? classifyText(text)?.payload : clip?.payload;
      if (!payload || payload.kind !== 'dot-press/slides') return null;
      const r = pasteSlides(state.doc, payload, { after: state.slideId, mode, rng });
      dispatch(r.ops, { label: 'Paste slides' });
      if (r.ids.length) set({ slideId: r.ids[r.ids.length - 1], selection: emptySelection(r.ids[r.ids.length - 1]) });
      return r;
    },
    copySlides(slideIds = [state.slideId]) {
      clip = { payload: serializeSlides(state.doc, slideIds), repeat: 0 };
      return { text: toClipboardText(clip.payload) };
    },
    duplicate() {
      if (!state.selection.ids.length) return null;
      const r = duplicateElements(state.doc, state.slideId, state.selection.ids, { repeat: dupRepeat, rng });
      dupRepeat += 1;
      dispatch(r.ops, { label: 'Duplicate' });
      set({ selection: selSelect(state.doc, state.selection, state.slideId, r.ids) });
      return r.ids;
    },

    // arrange
    group() {
      const r = dispatch(groupOps(slideNow(), state.selection.ids, newId([...state.doc.slides.flatMap((s) => s.els.map((e) => e.group || e.id))], 'g', rng)), { label: 'Group' });
      if (r) set({ selection: selSelect(state.doc, state.selection, state.slideId, state.selection.ids) });
      return !!r;
    },
    ungroup() { return !!dispatch(ungroupOps(slideNow(), state.selection.ids), { label: 'Ungroup' }); },
    forward() { return !!dispatch(layerOps(slideNow(), state.selection.ids, 'forward'), { label: 'Bring forward' }); },
    backward() { return !!dispatch(layerOps(slideNow(), state.selection.ids, 'backward'), { label: 'Send backward' }); },
    front() { return !!dispatch(layerOps(slideNow(), state.selection.ids, 'front'), { label: 'Bring to front' }); },
    back() { return !!dispatch(layerOps(slideNow(), state.selection.ids, 'back'), { label: 'Send to back' }); },
    /** Layer commands by explicit ids (the layers panel). */
    layer(how, idList) { return !!dispatch(layerOps(slideNow(), idList, how), { label: 'Reorder' }); },
    toggleLock(idList) { return !!dispatch(toggleOps(slideNow(), ids(idList), 'lock'), { label: 'Lock' }); },
    toggleHide(idList) { return !!dispatch(toggleOps(slideNow(), ids(idList), 'hide'), { label: 'Hide' }); },
    flip(axis) {
      const els = store.selectedElements();
      let patches;
      if (els.length === 1) { const p = flipPatch(els[0], axis); patches = p ? [{ id: els[0].id, p }] : []; } else patches = flipGroup(els, axis, bounds(els));
      return !!dispatch(patchOps(slideNow(), patches), { label: 'Flip' });
    },
    align(mode, { to = 'selection' } = {}) {
      const els = store.selectedElements();
      const deck = state.doc.deck;
      const patches = alignPatches(els, mode, { to, slide: deck, margin: to === 'slide' ? safeMargin(deck) : 0 });
      return !!dispatch(patchOps(slideNow(), patches), { label: 'Align' });
    },
    distribute(axis, { to = 'selection', mode = 'gaps' } = {}) {
      const deck = state.doc.deck;
      const patches = distributePatches(store.selectedElements(), axis, { to, mode, slide: deck, margin: safeMargin(deck) });
      return !!dispatch(patchOps(slideNow(), patches), { label: 'Distribute' });
    },
    tidy() { return !!dispatch(patchOps(slideNow(), tidyPatches(store.selectedElements())), { label: 'Tidy up' }); },
    matchSize(what = 'both') { return !!dispatch(patchOps(slideNow(), matchSizePatches(store.selectedElements(), what)), { label: 'Match size' }); },

    // deck level
    applyLayout(layout) {
      const r = planLayout(state.doc, state.slideId, layout, { rng });
      if (r.ops.length) dispatch(r.ops, { label: 'Apply layout' });
      return r.report;
    },
    setTheme(theme) {
      const r = applyTheme(state.doc, theme);
      dispatch(r.ops, { label: 'Change theme' });
      return r.report;
    },
    resizeDeck(w, h, mode = 'scale') { return dispatch([{ op: 'deck.resize', w, h, mode }], { label: 'Resize slides' }); },
    setDeckProps(props, o = {}) { return dispatch([{ op: 'deck.set', ...props }], { label: 'Deck', ...o }); },

    // view
    setViewport(w, h) { set({ viewport: { w, h }, ...(state.zoomMode === 'fit' ? { zoom: fitZoom({ w, h }) } : {}) }); },
    setZoom(z) { set({ zoom: clampZoom(z), zoomMode: 'manual' }); },
    zoomIn() { set({ zoom: stepZoom(state.zoom, 1), zoomMode: 'manual' }); },
    zoomOut() { set({ zoom: stepZoom(state.zoom, -1), zoomMode: 'manual' }); },
    zoomFit() { set({ zoom: fitZoom(state.viewport), zoomMode: 'fit', pan: { x: 0, y: 0 } }); },
    zoom100() { set({ zoom: 1, zoomMode: 'manual' }); },
    setPan(x, y) { set({ pan: { x, y } }); },
    toggleGrid() { set({ grid: !state.grid }); },
    toggleGuides() { set({ guides: !state.guides }); },

    runCommand(cmd, args) {
      const fn = COMMANDS[cmd];
      if (!fn) return false;
      const r = fn(store, args);
      return r !== false;
    },
    handleKey(event, scope = 'canvas', { mac = false } = {}) {
      const m = matchShortcut(event, scope, { mac });
      if (!m) return false;
      return store.runCommand(m.cmd, m.args);
    },
    destroy() { offChange(); offState(); offMap(); subs.clear(); },
  };

  function stepSlide(dir) {
    const i = state.doc.slides.findIndex((s) => s.id === state.slideId);
    const next = state.doc.slides[i + dir];
    if (next) store.setSlide(next.id);
  }
  const clampZoom = (z) => Math.min(4, Math.max(0.1, z));
  const stepZoom = (z, dir) => {
    const cur = ZOOM_STEPS.findIndex((s) => s >= z - 1e-6);
    if (dir > 0) { const n = ZOOM_STEPS.find((s) => s > z + 1e-6); return n ?? 4; }
    const lower = ZOOM_STEPS.filter((s) => s < z - 1e-6);
    return lower.length ? lower[lower.length - 1] : (cur === 0 ? ZOOM_STEPS[0] : 0.1);
  };
  function fitZoom(vp) {
    const d = state.doc.deck;
    if (!vp.w || !vp.h) return state.zoom;
    return clampZoom(Math.min((vp.w - 48) / d.w, (vp.h - 48) / d.h));
  }
  function payloadFrom(text) {
    if (text) return classifyText(text)?.payload || null;
    return clip?.payload || null;
  }

  const COMMANDS = {
    'edit.undo': (s) => s.undo(), 'edit.redo': (s) => s.redo(),
    'edit.copy': (s) => !!s.copy(), 'edit.cut': (s) => !!s.cut(), 'edit.paste': (s) => !!s.paste(),
    'edit.duplicate': (s) => !!s.duplicate(), 'edit.delete': (s) => s.deleteSelection(),
    'edit.selectAll': (s) => { s.selectAll(); },
    'edit.deselect': (s) => { s.clearSelection(); },
    'select.next': (s) => { s.selectStep(1); }, 'select.prev': (s) => { s.selectStep(-1); },
    'arrange.group': (s) => s.group(), 'arrange.ungroup': (s) => s.ungroup(),
    'arrange.forward': (s) => s.forward(), 'arrange.backward': (s) => s.backward(),
    'arrange.front': (s) => s.front(), 'arrange.back': (s) => s.back(),
    'arrange.lock': (s) => s.toggleLock(), 'arrange.hide': (s) => s.toggleHide(),
    'arrange.flipH': (s) => s.flip('h'), 'arrange.flipV': (s) => s.flip('v'),
    'align.left': (s) => s.align('left'), 'align.center': (s) => s.align('center'), 'align.right': (s) => s.align('right'),
    'align.top': (s) => s.align('top'), 'align.middle': (s) => s.align('middle'), 'align.bottom': (s) => s.align('bottom'),
    'slide.new': (s) => { s.addSlide(); }, 'slide.newAnywhere': (s) => { s.addSlide(); },
    'slide.duplicate': (s) => !!s.duplicateSlide(), 'slide.duplicateAnywhere': (s) => !!s.duplicateSlide(),
    'slide.delete': (s) => s.deleteSlide(),
    'slide.next': (s) => { s.nextSlide(); }, 'slide.prev': (s) => { s.prevSlide(); },
    'slide.nextSpine': (s) => { s.nextSlide(); }, 'slide.prevSpine': (s) => { s.prevSlide(); },
    'view.zoomIn': (s) => { s.zoomIn(); }, 'view.zoomOut': (s) => { s.zoomOut(); },
    'view.zoomFit': (s) => { s.zoomFit(); }, 'view.zoom100': (s) => { s.zoom100(); },
    'view.grid': (s) => { s.toggleGrid(); }, 'view.guides': (s) => { s.toggleGuides(); },
  };
  for (const c of ['left', 'right', 'up', 'down', 'left10', 'right10', 'up10', 'down10']) {
    COMMANDS[`nudge.${c}`] = (s, a) => s.nudge(a.dx, a.dy);
  }
  state = { ...state, ...hist() };
  return store;
}
