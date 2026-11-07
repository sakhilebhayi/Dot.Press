/**
 * Builds the `editor` object documented in ../context.js and provides it under EDITOR_KEY. Called once, from the
 * setup of Pages/Press/Editor.vue.
 *
 * What it owns
 *   - the sync engine (axios transport, guarded localStorage, recovery of unsent changes) and the store on top of it
 *   - window wiring: focus, blur, visibility, online, offline, unload flush, keyboard, native copy/cut/paste
 *   - presence (POST /api/decks/{deck}/presence every 3 s while the tab is focused)
 *   - the asset map, toasts, the live region, the inspector panel host API, UI preferences
 *
 * Degrades on purpose
 *   - localStorage throws: autosave keeps working, offline changes then live in this tab only (saveInfo says so)
 *   - viewer (can.update false): the store is given a sync whose dispatch applies nothing, so no op ever leaves the
 *     browser; keys that change the deck are ignored; comments, presenting and exporting still work
 *
 * Extra members beyond the contract (panels and the stage may use them)
 *   editor.deckId, editor.clientId, editor.mac, editor.readOnly
 *   editor.saveInfo            computed {key, label, detail, action, tone}; editor.retrySave(), editor.discardUnsent()
 *   editor.recovery            shallowRef of unsent changes from an earlier session or null; restoreRecovery(), discardRecovery()
 *   editor.toastState          shallowRef of the toast on screen; editor.announce(text) for the live region
 *   editor.reviewIssues        reactive map slideId -> open issue count (the review panel fills it); the Spine marks them
 *   editor.cursor / setCursor(x, y)   the pointer in slide units, sent with presence (the stage calls setCursor)
 *   editor.on(name, fn) / emit(name, payload)   tiny event bus. Events used: 'paste:files' (File[]), 'spine:rename',
 *                              'stage:focus'
 *   editor.hasPanel(id), editor.panelList (computed list for the current context), editor.activePanelId
 *   editor.renameDeck(title), editor.present(kind), editor.copyAudienceLink()
 *   editor.spineSelection      ref of the slide ids selected in the Spine; editor.slideIdsForAction() resolves them
 */
import { computed, onBeforeUnmount, onMounted, provide, reactive, ref, shallowRef, watch, nextTick } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { createSync, createStore, realClock, matchShortcut } from '../core/index.js';
import { EDITOR_KEY, createEditorUi } from '../context.js';
import { createApi, createKeepaliveBeacon, createTransport, safeStorage } from './transport.js';
import { createToasts } from './toasts.js';
import { saveInfo } from './saveState.js';
import { skippedNotice } from './skippedNotice.js';
import { activePanel, hasPanel, listPanels, registerPanel } from '../panels/registry.js';

const UI_PREFS_KEY = 'press:ui:v1';
const PREF_KEYS = ['grid', 'guides', 'rulers', 'margins', 'snap'];
const READ_OK = new Set([
  'edit.copy', 'edit.selectAll', 'edit.deselect', 'select.next', 'select.prev', 'slide.next', 'slide.prev', 'slide.nextSpine',
  'slide.prevSpine', 'view.zoomIn', 'view.zoomOut', 'view.zoomFit', 'view.zoom100', 'view.grid', 'view.guides', 'view.help',
  'present.start', 'present.fromStart', 'edit.find',
]);
// Copy, cut and paste belong to the browser's own clipboard events, not to keydown.
const NATIVE = new Set(['edit.copy', 'edit.cut', 'edit.paste', 'edit.pastePlain']);
const FORM = 'input, textarea, select, [contenteditable=""], [contenteditable="true"], .ProseMirror';
const FIELD_ID = /^[A-Za-z0-9_-]{1,40}$/;

const uuid = () => (globalThis.crypto?.randomUUID ? globalThis.crypto.randomUUID() : `t${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`);

/** A sync as the store sees it for a viewer: reads and polling work, dispatch applies nothing. */
function readOnlySync(sync) {
  const noop = () => ({ doc: sync.getView(), applied: [], inverse: [], guards: [], skipped: [] });
  return new Proxy(sync, {
    get(target, prop) {
      if (prop === 'dispatch') return noop;
      const v = Reflect.get(target, prop, target);
      return typeof v === 'function' ? v.bind(target) : v;
    },
  });
}

export function useEditor(props) {
  const deckId = props.document.deck.id;
  const canEdit = !!props.can?.update;
  const mac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent || '');
  const clientId = uuid();
  const storage = safeStorage();
  // A reload keeps the tab (sessionStorage) but not the client id: tell the server which id to retire, once.
  let replacesClient = null;
  try {
    const key = `press:client:${deckId}`;
    replacesClient = window.sessionStorage.getItem(key);
    window.sessionStorage.setItem(key, clientId);
  } catch { /* storage blocked: a reload then leaves a ghost for 20 seconds, nothing worse */ }
  const http = axios.create({ withCredentials: true, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
  const api = createApi(http);

  // ---- sync, store, state -----------------------------------------------------------------------
  const sync = createSync({
    transport: createTransport(http, deckId, { beacon: createKeepaliveBeacon(deckId) }),
    storage,
    clock: realClock,
    clientId,
    deckId,
  });
  sync.init(props.document);
  const store = createStore({ sync: canEdit ? sync : readOnlySync(sync) });
  if (props.startSlide) store.setSlide(String(props.startSlide));

  const state = shallowRef(store.getState());
  const offStore = store.subscribe((s) => { state.value = s; });
  const ui = createEditorUi();
  const deck = computed(() => state.value.doc.deck);
  const slide = computed(() => state.value.doc.slides.find((s) => s.id === state.value.slideId) || null);
  const selected = computed(() => { void state.value; return store.selectedElements(); });
  const assets = reactive({ ...(props.document.assets || {}) });
  watch(() => state.value.doc.assets, (a) => { if (a) Object.assign(assets, a); });

  // ---- small services -----------------------------------------------------------------------------
  const toasts = createToasts({ setTimeout: (f, ms) => window.setTimeout(f, ms), clearTimeout: (h) => window.clearTimeout(h) });
  const toastState = shallowRef(null);
  const offToasts = toasts.subscribe((t) => { toastState.value = t; });
  const toast = (message, opts) => toasts.push(message, opts);
  const live = ref('');
  const announce = (text) => { live.value = ''; nextTick(() => { live.value = text; }); };

  const listeners = new Map();
  const on = (name, fn) => { if (!listeners.has(name)) listeners.set(name, new Set()); listeners.get(name).add(fn); return () => listeners.get(name)?.delete(fn); };
  const emit = (name, payload) => { for (const fn of [...(listeners.get(name) || [])]) fn(payload); };

  const presence = shallowRef([]);
  const reviewIssues = reactive({});
  const spineSelection = ref([]);
  const recovery = shallowRef(null);
  const cursor = ref(null);

  // ---- save state ---------------------------------------------------------------------------------
  const save = computed(() => saveInfo(state.value.sync, { storageOk: storage.ok(), readOnly: !canEdit }));
  let lastKey = 'saved';
  watch(() => save.value.key, (k) => {
    if (k === lastKey) return;
    lastKey = k;
    if (k === 'saved') announce('Saved');
    else if (k === 'offline' || k === 'error') announce(save.value.label);
  });
  // An operation the server refuses is dropped from the document, so the change would undo itself unseen. Say so.
  const offSkipped = sync.onSkipped((list) => {
    const n = skippedNotice(list);
    if (!n) return;
    toast(n.message, { kind: n.kind, ms: 12000 });
    announce(n.message);
  });
  const retrySave = () => sync.retry();
  function discardUnsent() {
    const dropped = sync.discardInflight();
    toast(dropped.length === 1 ? 'Discarded the change the server refused' : `Discarded ${dropped.length} changes the server refused`, { kind: 'warn' });
  }

  // ---- ui preferences -----------------------------------------------------------------------------
  try {
    const prefs = JSON.parse(storage.get(UI_PREFS_KEY) || 'null');
    if (prefs && typeof prefs === 'object') {
      for (const k of PREF_KEYS) if (typeof prefs[k] === 'boolean') ui[k] = prefs[k];
      if (prefs.view === 'spine' || prefs.view === 'sheets') ui.spine.view = prefs.view;
    }
  } catch { /* defaults */ }
  watch(() => [...PREF_KEYS.map((k) => ui[k]), ui.spine.view], () => {
    const prefs = { view: ui.spine.view };
    for (const k of PREF_KEYS) prefs[k] = ui[k];
    storage.set(UI_PREFS_KEY, JSON.stringify(prefs));
  });
  // grid and guides live in the store as well as in ui; zoom and pan too. Keep the two in step.
  if (ui.grid !== state.value.grid) store.toggleGrid();
  if (ui.guides !== state.value.guides) store.toggleGuides();
  watch(() => [state.value.zoom, state.value.zoomMode, state.value.pan], () => {
    const s = state.value;
    ui.zoom = s.zoomMode === 'fit' ? 'fit' : s.zoom;
    ui.panX = s.pan.x;
    ui.panY = s.pan.y;
  }, { immediate: true });
  watch(() => [state.value.grid, state.value.guides], () => { ui.grid = state.value.grid; ui.guides = state.value.guides; });
  watch(() => ui.zoom, (z) => {
    if (z === 'fit') { if (state.value.zoomMode !== 'fit') store.zoomFit(); } else if (typeof z === 'number' && (state.value.zoomMode === 'fit' || state.value.zoom !== z)) store.setZoom(z);
  });
  watch(() => ui.grid, (v) => { if (v !== state.value.grid) store.toggleGrid(); });
  watch(() => ui.guides, (v) => { if (v !== state.value.guides) store.toggleGuides(); });
  watch(() => [ui.panX, ui.panY], ([x, y]) => { if (x !== state.value.pan.x || y !== state.value.pan.y) store.setPan(x, y); });

  // ---- inspector host -----------------------------------------------------------------------------
  let dismissed = false;
  let autoOpened = false;
  const wide = () => typeof window !== 'undefined' && window.matchMedia('(min-width: 1180px)').matches;
  function openPanel(id, panelProps = null) {
    ui.inspector.open = true;
    ui.inspector.panel = id || null;
    ui.inspector.props = panelProps;
    autoOpened = false;
    dismissed = false;
  }
  function closePanel() {
    if (ui.inspector.open && selected.value.length) dismissed = true;
    ui.inspector.open = false;
    ui.inspector.props = null;
    autoOpened = false;
  }
  watch(() => state.value.selection.ids.length, (n) => {
    if (n > 0) {
      if (!dismissed && !ui.inspector.open && wide()) { ui.inspector.open = true; ui.inspector.panel = null; ui.inspector.props = null; autoOpened = true; }
    } else {
      dismissed = false;
      if (autoOpened && ui.inspector.open) ui.inspector.open = false;
      autoOpened = false;
    }
  });
  /** The Inspector button: opens the panel list for the current context (selection, else slide and deck) or closes it. */
  function toggleInspector() {
    if (ui.inspector.open) closePanel();
    else openPanel(ui.inspector.panel || null);
  }
  const panelCtx = computed(() => ({
    scope: selected.value.length ? 'selection' : 'slide',
    selected: selected.value,
    slide: slide.value,
    deck: deck.value,
    can: props.can || {},
    state: state.value,
    editor,
  }));
  const panelList = computed(() => listPanels(panelCtx.value));
  const activePanelId = computed(() => activePanel(panelList.value, ui.inspector.panel)?.id || null);

  // ---- deck level actions ---------------------------------------------------------------------
  function renameDeck(title) {
    const t = String(title ?? '').trim().slice(0, 255);
    if (!canEdit || !t || t === deck.value.title) return false;
    store.setDeckProps({ title: t }, { undoKey: 'deck-title' });
    return true;
  }
  async function settle(ms = 1500) {
    await Promise.race([sync.flush(), new Promise((r) => window.setTimeout(r, ms))]);
  }
  async function present(kind = 'here') {
    await settle();
    const q = kind === 'start' ? '' : `?slide=${encodeURIComponent(state.value.slideId)}`;
    router.visit(`/decks/${deckId}/${kind === 'presenter' ? 'presenter' : 'present'}${q}`);
  }
  async function copyAudienceLink() {
    try {
      const idx = Math.max(0, state.value.doc.slides.findIndex((s) => s.id === state.value.slideId));
      const res = await api.post(`/api/decks/${deckId}/present-sessions`, { slide_index: idx });
      try {
        await navigator.clipboard.writeText(res.audience_url);
        toast('Audience link copied. It follows your slides once you present.', { kind: 'ok' });
      } catch {
        toast(`Audience link: ${res.audience_url}`, { kind: 'info', ms: 20000 });
      }
    } catch (e) {
      toast(`Could not make an audience link: ${e.message}`, { kind: 'error' });
    }
  }

  // ---- presence -----------------------------------------------------------------------------------
  let focused = typeof document === 'undefined' ? true : document.hasFocus() && !document.hidden;
  let presenceTimer = null;
  let nudgeTimer = null;
  async function beat() {
    if (!focused || document.hidden) return;
    const s = state.value;
    const body = {
      client: clientId,
      slide: /^\d+$/.test(s.slideId) ? Number(s.slideId) : null,
      selection: s.selection.ids.filter((i) => FIELD_ID.test(i)).slice(0, 50),
    };
    if (cursor.value) body.cursor = cursor.value;
    if (replacesClient) body.replaces = replacesClient;
    try {
      const res = await api.post(`/api/decks/${deckId}/presence`, body);
      replacesClient = null;
      presence.value = (res.others || []).map((o) => ({
        client: o.client, user_id: o.user_id, name: o.name, color: o.color, slide_id: o.slide != null ? String(o.slide) : null,
        selection: o.selection || [], cursor: o.cursor || null,
      }));
    } catch { /* presence is best effort */ }
  }
  const nudgeBeat = () => { window.clearTimeout(nudgeTimer); nudgeTimer = window.setTimeout(beat, 500); };
  watch(() => [state.value.slideId, state.value.selection.ids.join(',')], nudgeBeat);
  const setCursor = (x, y) => { cursor.value = x == null ? null : { x, y }; };

  // ---- window wiring ------------------------------------------------------------------------------
  function setFocus(f) {
    focused = f && !document.hidden;
    sync.setFocused(focused);
    if (!focused) sync.blur();
    else beat();
  }
  const onFocus = () => setFocus(true);
  const onBlur = () => setFocus(false);
  const onVisibility = () => setFocus(!document.hidden && document.hasFocus());
  const onOnline = () => sync.setOnline(true);
  const onOffline = () => sync.setOnline(false);
  const onUnload = () => { sync.flush({ unloading: true }); };

  function keyScope(e) {
    const t = e.target;
    if (!(t instanceof Element)) return 'canvas';
    if (t.closest(FORM)) return null;
    if (t.closest('[role="dialog"], [role="menu"], [role="menuitem"], [data-no-shortcuts]')) return null;
    if (t.closest('[data-keyscope="spine"]')) return 'spine';
    if (t.closest('[data-keyscope="stage"]') || t === document.body || t === document.documentElement) return 'canvas';
    if (t.closest('button, a, [role="button"], [role="tab"]') && !(e.metaKey || e.ctrlKey || e.altKey)) return null;
    return 'canvas';
  }
  const SHELL = {
    'view.help': () => { ui.help = !ui.help; },
    'present.start': () => { present('here'); },
    'present.fromStart': () => { present('start'); },
    'edit.rename': (_m, scope) => { if (scope !== 'spine') return false; emit('spine:rename', state.value.slideId); },
  };
  function onKeydown(e) {
    if (e.defaultPrevented || e.isComposing) return;
    if (e.key === 'Escape' && ui.help) { ui.help = false; e.preventDefault(); return; }
    const scope = keyScope(e);
    if (!scope) return;
    const m = matchShortcut(e, scope, { mac });
    if (!m || NATIVE.has(m.cmd)) return;
    if (!canEdit && !READ_OK.has(m.cmd)) return;
    const shell = SHELL[m.cmd];
    const handled = shell ? shell(m, scope) !== false : store.runCommand(m.cmd, m.args);
    if (handled) e.preventDefault();
  }

  const targetEl = (e) => (e.target instanceof Element ? e.target : null);
  const typingIn = (e) => { const t = targetEl(e); return !!t && !!t.closest(FORM); };
  const inSpine = (e) => !!targetEl(e)?.closest('[data-keyscope="spine"]');
  const hasTextSelection = () => { const s = window.getSelection?.(); return !!s && !s.isCollapsed && String(s).length > 0; };
  function slideIdsForAction() {
    const sel = spineSelection.value.filter((id) => state.value.doc.slides.some((s) => s.id === id));
    return sel.length && sel.includes(state.value.slideId) ? sel : [state.value.slideId];
  }
  function onCopy(e, cut = false) {
    if (typingIn(e) || (hasTextSelection() && !inSpine(e))) return;
    const spine = inSpine(e);
    if (cut && !canEdit) return;
    const ids = slideIdsForAction();
    const res = spine ? store.copySlides(ids) : (cut ? store.cut() : store.copy());
    if (!res?.text) return;
    e.clipboardData.setData('text/plain', res.text);
    e.preventDefault();
    if (spine && cut) { removeSlides(ids); }
  }
  const onCut = (e) => onCopy(e, true);
  function onPaste(e) {
    if (!canEdit || typingIn(e)) return;
    const files = [...(e.clipboardData?.files || [])];
    if (files.length) { emit('paste:files', files); e.preventDefault(); return; }
    const text = e.clipboardData?.getData('text/plain') || '';
    const r = inSpine(e) ? store.pasteSlides({ text }) : store.paste({ text });
    if (r) e.preventDefault();
  }

  /** Delete slides with an undo toast. The document keeps at least one slide, so the last one is never removed. */
  function removeSlides(ids) {
    if (!canEdit) return false;
    const slides = state.value.doc.slides;
    const doomed = ids.filter((id) => slides.some((s) => s.id === id));
    if (!doomed.length || doomed.length >= slides.length) {
      if (doomed.length) toast('A deck needs at least one slide', { kind: 'warn' });
      return false;
    }
    const idx = slides.findIndex((s) => s.id === doomed[0]);
    const r = store.dispatch(doomed.map((id) => ({ op: 'slide.remove', id })), { label: doomed.length === 1 ? 'Delete slide' : 'Delete slides' });
    if (!r) return false;
    const next = store.getState().doc.slides;
    if (!next.some((s) => s.id === store.getState().slideId)) store.setSlide(next[Math.min(idx, next.length - 1)].id);
    spineSelection.value = [];
    toast(doomed.length === 1 ? 'Slide deleted' : `${doomed.length} slides deleted`, { action: { label: 'Undo', run: () => store.undo() } });
    announce(doomed.length === 1 ? 'Slide deleted' : `${doomed.length} slides deleted`);
    return true;
  }

  let recoverTimer = null;
  async function checkRecovery() {
    if (!canEdit || recovery.value) return;
    try { recovery.value = await sync.recover(); } catch { recovery.value = null; }
  }
  async function restoreRecovery() {
    const rec = recovery.value;
    recovery.value = null;
    if (!rec) return;
    const n = await sync.restore(rec);
    toast(n === 1 ? 'Restored 1 unsent change' : `Restored ${n} unsent changes`, { kind: 'ok' });
  }
  async function discardRecovery() {
    const rec = recovery.value;
    recovery.value = null;
    if (rec) await sync.discardRecovery(rec);
  }

  onMounted(() => {
    window.addEventListener('focus', onFocus);
    window.addEventListener('blur', onBlur);
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);
    window.addEventListener('beforeunload', onUnload);
    window.addEventListener('pagehide', onUnload);
    window.addEventListener('keydown', onKeydown);
    document.addEventListener('copy', onCopy);
    document.addEventListener('cut', onCut);
    document.addEventListener('paste', onPaste);
    sync.setOnline(navigator.onLine !== false);
    sync.setFocused(focused);
    sync.startPolling();
    presenceTimer = window.setInterval(beat, 3000);
    beat();
    checkRecovery().then(() => {
      // A tab closed a few seconds ago still looks alive to the recovery check; look once more after that window.
      if (!recovery.value) recoverTimer = window.setTimeout(checkRecovery, 16000);
    });
  });
  onBeforeUnmount(() => {
    window.removeEventListener('focus', onFocus);
    window.removeEventListener('blur', onBlur);
    document.removeEventListener('visibilitychange', onVisibility);
    window.removeEventListener('online', onOnline);
    window.removeEventListener('offline', onOffline);
    window.removeEventListener('beforeunload', onUnload);
    window.removeEventListener('pagehide', onUnload);
    window.removeEventListener('keydown', onKeydown);
    document.removeEventListener('copy', onCopy);
    document.removeEventListener('cut', onCut);
    document.removeEventListener('paste', onPaste);
    window.clearInterval(presenceTimer);
    window.clearTimeout(nudgeTimer);
    window.clearTimeout(recoverTimer);
    sync.flush({ unloading: true });
    sync.stopPolling();
    offStore();
    offToasts();
    offSkipped();
    store.destroy();
    sync.destroy();
  });

  const editor = {
    store, state, sync, ui, assets, deck, slide, selected,
    me: props.me,
    can: props.can || {},
    aiMode: props.ai?.mode || 'local',
    api,
    presence,
    toast,
    openPanel,
    closePanel,
    toggleInspector,
    registerPanel,
    // beyond the contract
    deckId, clientId, mac, readOnly: !canEdit,
    saveInfo: save, retrySave, discardUnsent,
    recovery, restoreRecovery, discardRecovery,
    toastState, dismissToast: (id) => toasts.dismiss(id),
    live, announce,
    reviewIssues,
    cursor, setCursor,
    on, emit,
    hasPanel, panelList, activePanelId,
    renameDeck, present, copyAudienceLink,
    spineSelection, slideIdsForAction, removeSlides,
    settle,
  };
  provide(EDITOR_KEY, editor);
  return editor;
}
