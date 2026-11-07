<script setup>
// EditorStage: the canvas area of the editor. The shell mounts it with no props; everything comes from useEditorContext().
//
// What it draws: the current slide (SlideView, mode edit) on a pale stage; a selection layer in screen pixels (outlines, handles,
// smart guides, readouts); the in place text editor (Tiptap), crop, table cell and media preview overlays; the contextual
// bar; the context menu; other people's cursors and selections; comment pins; one focusable proxy per element for keyboards
// and screen readers. All geometry, snapping and history logic is the editor core's, this file only wires events to it.
//
// What it needs from the shell (all optional unless marked, see context.js for the rest)
//   editor.hasPanel(id)               true when the inspector panel is registered; controls that open a missing panel are hidden
//   editor.on(name, fn)               the stage listens for 'stage:focus' (focus the slide area) and 'paste:files' (File[] to add)
//   ui.contextMenu = {x, y, items}    the stage fills the shell's context menu host with its own items (items carry `run`)
//   editor.commentPins                ref of [{id, slide_id, x, y, count?, resolved?}] drawn as pins; click opens panel 'comments'
//   editor.clientId                   this tab's presence client id, so the stage does not draw its own cursor
//   editor.uploadAsset(file, {projectId, onProgress}) -> asset payload   when absent the stage POSTs multipart to /api/assets
//   editor.registerAsset(id, entry)   when absent the stage writes editor.assets[id] directly
//   editor.runCommand(cmd, args)      for keymap commands the store does not run (present.*, view.help, edit.find, edit.rename)
//   ui.dragging                       {kind: 'asset', id, asset?} from the asset panel (native drag and drop onto the stage);
//                                     {kind: 'insert', what, ...} from the Insert panel: the stage emits 'insert:drop' {drag, point} on the bus
// What it gives the shell
//   editor.stage                      {fit, zoomToSelection, zoomToRect, focus, toScreen, toSlide, beginTextEdit, beginCrop,
//                                      replacePicture, pickFiles, addFiles, announce}; also the exposed API of the component
//   ui.zoomValue                      the effective zoom as a number (ui.zoom may be 'fit'); ui.panX/panY follow the store
//   editor.setCursor(x, y)            the pointer in slide units (null when it leaves), for the presence heartbeat
//   ui.dragging                       {kind: 'elements', ids, x, y, copy, overSlide} while elements are dragged. A Spine row is any
//                                     element with data-spine-slide="<slide id>" or the shell's own [data-row][data-id]; it gets the
//                                     class es-drop-row (amber outline) while an element hovers it. Releasing over one dispatches a
//                                     cancelable DOM event `press:drop-elements` {detail: {ids, slideId, copy, from}} on the stage;
//                                     when nobody calls preventDefault the stage moves (el.move) or copies (el.add) itself
//   ui.userGuides                     [{orient: 'v'|'h', pos}] guides pulled from the rulers (kept per deck in localStorage)
//
// Decisions: a drag writes `transform` on the dragged wrappers and the store only once, on release, with a unique undo key (one
// undo step, one op batch); resize, line ends and drawing preview through a patched copy of the slide so content reflows live;
// text never receives store updates while it is edited (IME safe) and is written back as one el.set on Escape or blur.
// Zoom: 'fit' or a number, mirrored both ways with the store's own zoom, zoomMode and pan so the keymap's view.* commands work.
// Keyboard map: arrows nudge 1, Shift+arrows 10, Alt+arrows resize 1, Alt+Shift+arrows resize 10 (the brief's Shift+arrows resize
// collides with the core keymap's nudge by 10), Enter or typing edits, Tab and Shift+Tab walk the element proxies, Shift+1 fit,
// Shift+2 zoom to selection, Alt+F10 focus the contextual bar, Space+drag pans.
import {
    computed, defineAsyncComponent, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, unref, watch,
} from 'vue';
import {
    SlideView, ensureFonts, reducedMotion, resolveSrc, themeVars,
} from '../../render/index.js';
import { useEditorContext } from '../context.js';
import { matchShortcut, shortcutsFor } from '../core/keys.js';
import { contextItems } from './lib/menu.js';
import { marginLines } from '../core/margins.js';
import { classifyText } from '../core/clipboard.js';
import { rotatedBounds, unionBounds } from '../core/geometry.js';
import { useViewport } from './useViewport.js';
import { useGestures } from './useGestures.js';
import { useUploads } from './useUploads.js';
import { createFormat } from './format.js';
import SelectionLayer from './SelectionLayer.vue';
import CropLayer from './CropLayer.vue';
import TableLayer from './TableLayer.vue';
import ContextBar from './ContextBar.vue';
import StageRulers from './StageRulers.vue';
import { barKind } from './lib/bar.js';
import { gridLines } from './lib/guides.js';
import { hitOrder, isLine } from './lib/hit.js';
import { classifyFile } from './lib/drop.js';
import { shapeTextDefaults } from './lib/textstyle.js';
import { docIsBlank } from './lib/textdoc.js';
import {
    elementLabel, stageLabel, selectionAnnouncement, moveAnnouncement, resizeAnnouncement, resizeByKey, slideAnnouncement,
} from './lib/a11y.js';
import { pasteStylePatch, copyStyle } from './lib/style.js';
import {
    insertRow, insertCol, deleteRow, deleteCol, mergeRange, unmerge, canMerge, anchorAt, gridSize,
} from './lib/table.js';
import '../../../../css/press/stage.css';

// Tiptap is the heaviest part of the editor and only needed once text is edited: it loads on first use.
const TextEditor = defineAsyncComponent(() => import('./TextEditor.vue'));

const editor = useEditorContext();
const { store, ui } = editor;
const U = unref;

const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

// ---- document state ------------------------------------------------------------------------------
const state = computed(() => U(editor.state));
const deck = computed(() => state.value.doc.deck);
const slides = computed(() => state.value.doc.slides);
const slide = computed(() => slides.value.find((s) => s.id === state.value.slideId) || slides.value[0]);
const slideIndex = computed(() => slides.value.findIndex((s) => s.id === slide.value.id));
const assets = computed(() => U(editor.assets) || state.value.doc.assets || {});
const can = computed(() => U(editor.can) || { update: true, comment: true });
const canEdit = computed(() => can.value.update !== false);
const selIds = computed(() => state.value.selection.ids);
const selected = computed(() => {
    const want = new Set(selIds.value);
    return slide.value.els.filter((e) => want.has(e.id));
});
const readOnly = computed(() => !canEdit.value);
const presence = computed(() => (U(editor.presence) || []).filter((p) => p.slide_id != null && String(p.slide_id) === slide.value.id && p.client !== U(editor.clientId)));
const pins = computed(() => (U(editor.commentPins) || []).filter((p) => String(p.slide_id ?? p.slide) === slide.value.id));

// ---- viewport ----------------------------------------------------------------------------------------
const root = ref(null);
const kbd = ref(false); // the last input was the keyboard: only then draw the focus ring on the stage
const viewport = ref(null);
const viewRef = ref(null);
const vp = useViewport(editor, viewport, deck);
const zoom = vp.zoom;
const origin = vp.origin;

const preview = shallowRef(null);
const draft = ref(null); // a text element that is being typed but is not in the document yet
const textRef = ref(null);
const cropRef = ref(null);

const hasPanel = (id) => {
    if (typeof editor.hasPanel === 'function') return !!editor.hasPanel(id);
    if (editor.panels && typeof editor.panels.has === 'function') return !!editor.panels.has(id);
    return true;
};

function announce(message) {
    liveText.value = '';
    setTimeout(() => { liveText.value = message; }, 30);
}
const liveText = ref('');

const hooks = {
    announce,
    toast: (m, kind = 'info', action) => editor.toast?.(m, { kind, action }),
    finishText: () => finishText(),
    commitCrop: () => cropRef.value?.commit?.(),
    cropWheel: (e) => cropRef.value?.wheel?.(e) || false,
    openContext: (x, y) => openMenu(x, y),
    beginTextEdit: (id) => beginTextEdit(id),
    startDraftText: (el) => startDraftText(el),
    pickFiles: (kind, pt) => pickFiles(kind, pt),
    closePopovers: () => { barRef.value?.close?.(); tableEdit.value = null; },
    clicked: () => {},
    moved: (ids) => {
        const els = store.selectedElements();
        if (els.length === 1) announce(moveAnnouncement(els[0]));
        else announce(`Moved ${ids.length} elements`);
    },
};

const gestures = useGestures({
    editor, store, ui, vp, rootRef: viewport, viewRef, hooks, canEdit: () => canEdit.value,
});
const { live } = gestures;
watch(gestures.preview, (v) => { preview.value = v; });

const viewSlide = computed(() => {
    const p = preview.value;
    if (!p || !p.size) return slide.value;
    return { ...slide.value, els: slide.value.els.map((e) => (p.has(e.id) ? { ...e, ...p.get(e.id) } : e)) };
});

const liveSelected = computed(() => {
    const patches = live.value.patches;
    if (!patches) return selected.value;
    return selected.value.map((e) => (patches.has(e.id) ? { ...e, ...patches.get(e.id) } : e));
});

const spaceStyle = computed(() => ({
    transform: `translate(${origin.value.x}px, ${origin.value.y}px) scale(${zoom.value})`,
    '--z': zoom.value,
    ...themeVars(deck.value.theme),
}));

// ---- the sheet and travelling between slides -------------------------------------------------------
const sheetStyle = computed(() => ({
    width: `${deck.value.w * zoom.value}px`,
    transform: `translate(${origin.value.x}px, ${origin.value.y}px)`,
}));
const outgoing = ref(null);
const inBody = ref(null);
const outBody = ref(null);
let lastSlide = slide.value;
let inAnim = null;
let outAnim = null;
watch(slide, (s) => { lastSlide = s; }, { flush: 'post' });
const cssTime = (name, fallback) => {
    const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    if (!v) return fallback;
    return v.endsWith('ms') ? parseFloat(v) : parseFloat(v) * 1000;
};
// The next slide travels in along the rail (vertical), the old one eases away underneath: short travel, one small overshoot, still.
watch(() => state.value.slideId, async (next, prev) => {
    const a = slides.value.findIndex((s) => s.id === prev);
    const b = slides.value.findIndex((s) => s.id === next);
    inAnim?.cancel();
    outAnim?.cancel();
    if (a < 0 || b < 0 || a === b || reducedMotion.value || !vp.size.value.w || typeof Element.prototype.animate !== 'function') { outgoing.value = null; return; }
    const dir = b > a ? 1 : -1;
    outgoing.value = { slide: lastSlide, dir };
    announce(slideAnnouncement(slides.value[b], b, slides.value.length));
    await nextTick();
    if (!inBody.value || !outBody.value) { outgoing.value = null; return; }
    const dist = Math.min(deck.value.h * zoom.value * 0.55, 320) * dir;
    const dur = cssTime('--d-3', 380);
    const spring = getComputedStyle(document.documentElement).getPropertyValue('--spring').trim() || 'cubic-bezier(0.2, 0.9, 0.25, 1)';
    inAnim = inBody.value.animate([{ transform: `translateY(${dist}px)` }, { transform: 'translateY(0)' }], { duration: dur, easing: spring });
    outAnim = outBody.value.animate([{ transform: 'translateY(0)' }, { transform: `translateY(${-dist * 0.35}px)` }], { duration: dur, easing: 'cubic-bezier(0.2, 0.9, 0.25, 1)', fill: 'forwards' });
    const mine = outgoing.value;
    inAnim.finished.then(() => { if (outgoing.value === mine) outgoing.value = null; }).catch(() => {});
});

// ---- grid, margins, centre lines ---------------------------------------------------------------------
const furniture = computed(() => {
    const z = zoom.value;
    const o = origin.value;
    const d = deck.value;
    const out = { grid: null, margins: null, centre: null };
    if (ui.grid) {
        const size = Math.max(4, Math.round(d.w / 96));
        if (size * z >= 6) {
            const g = gridLines(d, size);
            out.grid = {
                x: g.x.map((p) => o.x + p * z), y: g.y.map((p) => o.y + p * z), x0: o.x, y0: o.y, x1: o.x + d.w * z, y1: o.y + d.h * z,
            };
        }
    }
    if (ui.margins) {
        const m = marginLines(d);
        out.margins = {
            x: m.x.map((p) => o.x + p * z), y: m.y.map((p) => o.y + p * z), x0: o.x, y0: o.y, x1: o.x + d.w * z, y1: o.y + d.h * z,
        };
        out.centre = { x: o.x + (d.w / 2) * z, y: o.y + (d.h / 2) * z, x0: o.x, y0: o.y, x1: o.x + d.w * z, y1: o.y + d.h * z };
    }
    return out;
});

// ---- cursor and classes --------------------------------------------------------------------------------
const cursorStyle = computed(() => ({ '--es-cursor': gestures.cursor.value }));
const dragging = computed(() => ['move', 'resize', 'rotate', 'lineEnd', 'draw'].includes(live.value.mode));
const coarse = ref(typeof matchMedia === 'function' && matchMedia('(pointer: coarse)').matches);

// ---- text editing ----------------------------------------------------------------------------------------
const editTarget = computed(() => {
    if (draft.value && ui.editingText === draft.value.id) return draft.value;
    if (!ui.editingText) return null;
    return slide.value.els.find((e) => e.id === ui.editingText) || null;
});

function textEditable(el) {
    return el && (el.type === 'text' || (el.type === 'shape' && !isLine(el)));
}

const insertText = ref('');
const editAt = ref(null);
function beginTextEdit(id, { insert = '', at = null } = {}) {
    if (!canEdit.value) return;
    const el = slide.value.els.find((e) => e.id === id);
    if (!textEditable(el) || el.lock) return;
    if (ui.cropping) cropRef.value?.commit?.();
    store.select([id]);
    insertText.value = insert;
    editAt.value = at;
    ui.editingText = id;
}

function startDraftText(el) {
    if (!canEdit.value) return;
    store.clearSelection();
    insertText.value = '';
    editAt.value = null;
    draft.value = el;
    ui.editingText = el.id;
}

function finishText() {
    const t = textRef.value;
    if (t) t.commit();
    else { ui.editingText = null; draft.value = null; }
}

function onTextCommit({ id, patch, doc, isDraft, cancelled }) {
    const wasDraft = isDraft && draft.value && draft.value.id === id;
    if (wasDraft) {
        const el = draft.value;
        draft.value = null;
        ui.editingText = null;
        if (cancelled || docIsBlank(doc)) {
            announce('Empty text box removed');
        } else {
            store.addElement({ ...el, ...patch, doc });
            announce('Text added');
        }
    } else {
        ui.editingText = null;
        const el = slide.value.els.find((e) => e.id === id);
        if (el && !cancelled && Object.keys(patch).length) {
            const p = { ...patch };
            if (el.type === 'shape') Object.assign(p, shapeTextDefaults(deck.value, el));
            for (const k of Object.keys(p)) if (k !== 'doc' && el[k] === p[k]) delete p[k];
            store.patch([{ id, p }], { undoKey: `text:${id}`, label: 'Edit text' });
        }
    }
    nextTick(() => viewport.value?.focus({ preventScroll: true }));
}

// Hide the slide's own rendering of the box while the overlay edits it, so nothing shows twice.
const editorReady = ref(false);
watch(() => ui.editingText, () => { editorReady.value = false; });
watch([editTarget, () => ui.cropping, editorReady], ([t, c, ready]) => {
    const nodes = viewRef.value?.nodes?.();
    if (!nodes) return;
    for (const n of nodes.values()) {
        n.querySelector(':scope > .p-fx > .pt, :scope > .p-fx > .psh > .pt')?.style.removeProperty('visibility');
        n.style.removeProperty('visibility');
    }
    if (t && ready && !(draft.value && t.id === draft.value.id)) {
        const n = nodes.get(t.id);
        n?.querySelector(':scope > .p-fx > .pt, :scope > .p-fx > .psh > .pt')?.style.setProperty('visibility', 'hidden');
    }
    if (c) nodes.get(c)?.style.setProperty('visibility', 'hidden');
}, { flush: 'post' });

// ---- crop ------------------------------------------------------------------------------------------------
const cropTarget = computed(() => (ui.cropping ? slide.value.els.find((e) => e.id === ui.cropping && e.type === 'image') || null : null));
watch(() => ui.cropping, (id) => { if (id && !cropTarget.value) ui.cropping = null; });
function beginCrop(id) {
    if (!canEdit.value) return;
    const el = slide.value.els.find((e) => e.id === id);
    if (!el || el.type !== 'image' || el.lock) return;
    if (ui.editingText) finishText();
    store.select([id]);
    ui.cropping = id;
}
function onCropDone({ id, patch }) {
    ui.cropping = null;
    if (patch && Object.keys(patch).length) store.patch([{ id, p: patch }], { undoKey: `crop:${id}:${Date.now()}`, label: 'Crop' });
    nextTick(() => viewport.value?.focus({ preventScroll: true }));
}

// ---- uploads, drops, pastes ---------------------------------------------------------------------------------
const uploads = useUploads({
    editor, store, slide: () => slide.value, deck: () => deck.value, toast: hooks.toast, announce, canEdit: () => canEdit.value,
});
const picker = ref(null);
let pickerAt = null;
function pickFiles(kind, pt) {
    pickerAt = pt;
    const el = picker.value;
    if (!el) return;
    el.accept = kind === 'video' ? 'video/*' : kind === 'audio' ? 'audio/*' : 'image/*';
    el.multiple = true;
    el.value = '';
    el.click();
}
function onPicked(e) {
    const files = [...(e.target.files || [])];
    if (files.length) uploads.addFiles(files, { point: pickerAt, replaceId: pickerReplace });
    pickerAt = null;
    pickerReplace = null;
}
let pickerReplace = null;
function replacePicture(id) {
    const el = slide.value.els.find((x) => x.id === id);
    if (!el) return;
    pickerReplace = id;
    pickFiles(el.type === 'video' ? 'video' : 'image', null);
}

const dropOn = ref(false);
function hasFiles(dt) {
    return !!dt && [...(dt.types || [])].includes('Files');
}
function dragKind() {
    return ui.dragging && (ui.dragging.kind === 'asset' || ui.dragging.kind === 'file' || ui.dragging.kind === 'insert') ? ui.dragging : null;
}
function onDragEnter(e) {
    if (!canEdit.value) return;
    if (hasFiles(e.dataTransfer) || dragKind()) {
        dropOn.value = true;
        if (!ui.dragging) ui.dragging = { kind: 'file' };
    }
}
function dropPoint(e) {
    const r = viewport.value.getBoundingClientRect();
    return vp.toSlide({ x: e.clientX - r.left, y: e.clientY - r.top });
}
function onDragOver(e) {
    if (!canEdit.value) return;
    if (!(hasFiles(e.dataTransfer) || dragKind())) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    const pt = dropPoint(e);
    const target = hitOrder(slide.value, pt, { pad: 0 }).map((id) => slide.value.els.find((x) => x.id === id)).find((x) => x);
    const id = target && (target.type === 'image' || target.type === 'video') && ui.dragging?.kind !== 'insert' ? target.id : null;
    if (gestures.hover.value.id !== id) gestures.hover.value = { id, handle: null, lockedId: null };
}
function onDragLeave(e) {
    if (e.relatedTarget && viewport.value.contains(e.relatedTarget)) return;
    dropOn.value = false;
    if (ui.dragging && (ui.dragging.kind === 'file')) ui.dragging = null;
}
function onDrop(e) {
    dropOn.value = false;
    if (!canEdit.value) return;
    const dt = e.dataTransfer;
    const asset = dragKind();
    const pt = dropPoint(e);
    if (hasFiles(dt)) {
        e.preventDefault();
        if (ui.dragging?.kind === 'file') ui.dragging = null;
        uploads.addFiles([...dt.files], { point: pt });
        return;
    }
    if (asset && asset.kind === 'asset') {
        e.preventDefault();
        uploads.addExisting(asset, { point: pt });
        ui.dragging = null;
    } else if (asset && asset.kind === 'insert') {
        // an item dragged from the Insert panel: the panel builds the element and adds it at the drop point
        e.preventDefault();
        editor.emit?.('insert:drop', { drag: asset, point: pt });
        ui.dragging = null;
    }
}

function onCopy(e) {
    if (isEditable(e.target)) return;
    if (!selIds.value.length) return;
    const nativeSel = window.getSelection?.()?.toString();
    if (readOnly.value && nativeSel) return; // text selected inside a read only slide: let the browser copy it
    const c = store.copy();
    if (!c) return;
    lastCopied = c.text;
    e.clipboardData.setData('text/plain', c.text);
    e.preventDefault();
    e.stopPropagation(); // the shell has a document level copy handler too: this one has done it
    announce('Copied');
}
function onCut(e) {
    if (isEditable(e.target) || !canEdit.value) return;
    if (!selIds.value.length) return;
    const c = store.cut();
    if (!c) return;
    lastCopied = c.text;
    e.clipboardData.setData('text/plain', c.text);
    e.preventDefault();
    e.stopPropagation();
    announce('Cut');
}
let lastCopied = '';
function onPaste(e) {
    if (isEditable(e.target) || !canEdit.value) return;
    e.preventDefault();
    e.stopPropagation();
    const cd = e.clipboardData;
    const files = [...(cd?.files || [])].filter((f) => classifyFile(f));
    if (files.length) { uploads.addFiles(files, { point: null }); return; }
    const text = cd?.getData('text/plain') || '';
    pasteText(text);
}
function pasteText(text) {
    let r = null;
    if (!text || text === lastCopied) r = store.paste({});
    else r = store.paste({ text, mode: 'conform' });
    if (!r) return;
    reportPaste(r);
}
function reportPaste(r) {
    announce(r.ids?.length > 1 ? `Pasted ${r.ids.length} elements` : 'Pasted');
    const changes = r.report?.changes || [];
    if (changes.length && r.report && !r.report.sameTheme) {
        hooks.toast("Matched to this deck's theme. Undo to keep the original look.", 'info', { label: 'Undo', run: () => store.undo() });
    }
}

// ---- keyboard ---------------------------------------------------------------------------------------------
function isEditable(t) {
    if (!t || !t.closest) return false;
    if (t.closest('[data-es-ui]')) return true;
    const tag = t.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || t.isContentEditable;
}

const READ_ONLY_OK = new Set(['edit.copy', 'edit.selectAll', 'edit.deselect', 'view.zoomIn', 'view.zoomOut', 'view.zoomFit', 'view.zoom100', 'view.grid', 'view.guides', 'slide.next', 'slide.prev']);
const format = createFormat({ editor, store, text: () => textRef.value, deck: () => deck.value });

function runCommand(cmd, args, e) {
    if (cmd === 'select.next' || cmd === 'select.prev') { e?.stopPropagation(); return false; } // Tab and Shift+Tab move focus between the element proxies: the browser does that
    if (cmd === 'edit.copy' || cmd === 'edit.cut' || cmd === 'edit.paste') return false; // the clipboard events do these
    if (cmd === 'edit.pastePlain') return false;
    if (readOnly.value && !READ_ONLY_OK.has(cmd) && !cmd.startsWith('view.')) return false;
    if (cmd.startsWith('text.')) return format.command(cmd, e);
    if (store.runCommand(cmd, args)) return true;
    return !!(typeof editor.runCommand === 'function' && editor.runCommand(cmd, args));
}

let nudgeTimer = 0;
function onKeyDown(e) {
    if (e.key === 'Tab') kbd.value = true;
    if (isEditable(e.target)) {
        if (e.key === 'Escape' && e.target.closest?.('[data-es-ui]') && !e.target.closest('.ProseMirror')) {
            barRef.value?.close?.();
            viewport.value.focus({ preventScroll: true });
        }
        return;
    }
    const mod = isMac ? e.metaKey : e.ctrlKey;
    if (ui.cropping && cropRef.value) {
        const c = cropRef.value;
        if (e.key === 'Enter') { e.preventDefault(); c.commit(); return; }
        if (e.key === 'Escape') { e.preventDefault(); c.cancel(); return; }
        if (e.key.startsWith('Arrow')) {
            const s = e.shiftKey ? 10 : 1;
            c.nudge(e.key === 'ArrowLeft' ? -s : e.key === 'ArrowRight' ? s : 0, e.key === 'ArrowUp' ? -s : e.key === 'ArrowDown' ? s : 0);
            e.preventDefault();
            return;
        }
        if (e.key === '+' || e.key === '=') { c.zoomBy(1.1); e.preventDefault(); return; }
        if (e.key === '-') { c.zoomBy(1 / 1.1); e.preventDefault(); return; }
    }
    if (e.code === 'Space' && !e.repeat) {
        if (e.target === viewport.value || e.target.closest?.('.es-proxy')) { gestures.setSpace(true); e.preventDefault(); return; }
    }
    if (e.key === 'F10' && e.altKey) { e.preventDefault(); barRef.value?.focusFirst?.(); return; }
    if (e.key === 'Escape') {
        if (gestures.cancel()) { e.preventDefault(); return; }
        if (ui.contextMenu) { ui.contextMenu = null; e.preventDefault(); return; }
        if (ui.tool !== 'select') { ui.tool = 'select'; e.preventDefault(); return; }
    }
    if (e.key === 'Tab') {
        e.stopPropagation(); // the shell maps Tab to select.next: here Tab is real focus movement
        const onProxy = !!e.target.closest?.('.es-proxy');
        if (!onProxy && selIds.value.length && !e.shiftKey) {
            const p = viewport.value.querySelector(`.es-proxy[data-id="${CSS.escape(selIds.value[0])}"]`);
            if (p) { e.preventDefault(); p.focus(); }
        }
        return;
    }
    const proxy = e.target.closest?.('.es-proxy');
    if (e.altKey && !mod && e.key.startsWith('Arrow') && canEdit.value && selected.value.length === 1) {
        const el = selected.value[0];
        const patch = resizeByKey(el, e.key, { shift: e.shiftKey });
        e.preventDefault();
        if (patch) {
            store.patch([{ id: el.id, p: patch }], { undoKey: `kresize:${el.id}`, label: 'Resize' });
            clearTimeout(nudgeTimer);
            nudgeTimer = setTimeout(() => { const n = store.selectedElements()[0]; if (n) announce(resizeAnnouncement(n)); }, 350);
        }
        return;
    }
    if (!mod && !e.altKey && canEdit.value) {
        if (e.key === 'Enter' || e.key === 'F2') {
            if (selected.value.length === 1) {
                const el = selected.value[0];
                if (textEditable(el)) { e.preventDefault(); beginTextEdit(el.id); return; }
                if (el.type === 'image') { e.preventDefault(); beginCrop(el.id); return; }
                if (el.type === 'chart' && hasPanel('chart')) { e.preventDefault(); editor.openPanel('chart'); return; }
            }
        }
        if (e.key.length === 1 && e.key !== ' ' && selected.value.length === 1 && textEditable(selected.value[0]) && !proxy?.dataset.nokeys) {
            e.preventDefault();
            beginTextEdit(selected.value[0].id, { insert: e.key });
            return;
        }
        if ((e.key === ' ' || e.key === 'Enter') && proxy) {
            e.preventDefault();
            const id = proxy.dataset.id;
            store.clickElement(id, { shift: e.shiftKey });
            return;
        }
    }
    if (e.shiftKey && !mod && !e.altKey && (e.code === 'Digit1' || e.code === 'Digit2')) {
        e.preventDefault();
        if (e.code === 'Digit1') vp.fit(); else zoomToSelection();
        return;
    }
    const m = matchShortcut(e, 'canvas', { mac: isMac });
    if (!m) return;
    const done = runCommand(m.cmd, m.args, e);
    if (done) {
        e.preventDefault();
        if (m.cmd.startsWith('nudge.')) {
            clearTimeout(nudgeTimer);
            nudgeTimer = setTimeout(() => { const n = store.selectedElements(); if (n.length === 1) announce(moveAnnouncement(n[0])); else if (n.length) announce(`Moved ${n.length} elements`); }, 350);
        }
        if (m.cmd === 'edit.delete') nextTick(() => { if (!document.activeElement || document.activeElement === document.body) viewport.value?.focus({ preventScroll: true }); });
    }
}
function onKeyUp(e) {
    if (e.code === 'Space') gestures.setSpace(false);
}

// ---- context menu ----------------------------------------------------------------------------------------
function onContextMenu(e) {
    if (e.target.closest?.('[data-es-ui]')) return;
    e.preventDefault();
    openMenu(e.clientX, e.clientY);
}

// ---- double click ------------------------------------------------------------------------------------------
function onDblClick(e) {
    if (e.target.closest?.('[data-es-ui]') || !canEdit.value || ui.tool !== 'select') return;
    const r = viewport.value.getBoundingClientRect();
    const pt = vp.toSlide({ x: e.clientX - r.left, y: e.clientY - r.top });
    const id = hitOrder(slide.value, pt, { pad: 6 / zoom.value })[0];
    if (!id) return;
    const el = slide.value.els.find((x) => x.id === id);
    // a grouped element: the first double click enters the group and selects that member alone
    if (el.group && !(selIds.value.length === 1 && selIds.value[0] === id)) {
        store.select([id], 'replace', { members: true });
        announce(`Entered group. ${elementLabel(el, assets.value)} selected`);
        return;
    }
    if (textEditable(el)) { beginTextEdit(id, { at: { x: e.clientX, y: e.clientY } }); return; }
    if (el.type === 'image') { beginCrop(id); return; }
    if (el.type === 'chart' && hasPanel('chart')) { store.select([id]); editor.openPanel('chart'); return; }
    if (el.type === 'table') { store.select([id]); tableEdit.value = id; }
}
const tableEdit = ref(null);
const tableRef = ref(null);
const tableRange = ref(null);
const tableTarget = computed(() => (tableEdit.value ? slide.value.els.find((x) => x.id === tableEdit.value && x.type === 'table') || null : null));
watch(tableTarget, (t) => { if (!t) { tableEdit.value = null; tableRange.value = null; } });
const tableState = computed(() => {
    const el = selected.value.length === 1 && selected.value[0].type === 'table' ? selected.value[0] : null;
    if (!el) return {};
    const size = gridSize(el);
    const r = tableEdit.value === el.id ? tableRange.value : null;
    return {
        active: !!r, rows: size.rows, cols: size.cols,
        canMerge: !!r && canMerge(el, r),
        canUnmerge: !!r && (anchorAt(el, r.r1, r.c1)?.rs > 1 || anchorAt(el, r.r1, r.c1)?.cs > 1),
    };
});
function applyTable(op) {
    const el = selected.value[0];
    if (!el || el.type !== 'table') return;
    const r = tableEdit.value === el.id ? tableRange.value : null;
    const size = gridSize(el);
    let patch = null;
    let label = 'Edit table';
    if (op === 'rowBelow') { patch = insertRow(el, r ? r.r2 + 1 : size.rows); label = 'Add row'; }
    else if (op === 'rowAbove') { patch = insertRow(el, r ? r.r1 : 0); label = 'Add row'; }
    else if (op === 'colRight') { patch = insertCol(el, r ? r.c2 + 1 : size.cols); label = 'Add column'; }
    else if (op === 'colLeft') { patch = insertCol(el, r ? r.c1 : 0); label = 'Add column'; }
    else if (op === 'delRow') {
        let tmp = el;
        for (let i = r ? r.r2 : size.rows - 1; i >= (r ? r.r1 : size.rows - 1); i--) { const p = deleteRow(tmp, i); if (p) { patch = { ...(patch || {}), ...p }; tmp = { ...tmp, ...p }; } }
        label = 'Delete row';
    } else if (op === 'delCol') {
        let tmp = el;
        for (let i = r ? r.c2 : size.cols - 1; i >= (r ? r.c1 : size.cols - 1); i--) { const p = deleteCol(tmp, i); if (p) { patch = { ...(patch || {}), ...p }; tmp = { ...tmp, ...p }; } }
        label = 'Delete column';
    } else if (op === 'delTable') { tableEdit.value = null; store.deleteSelection(); return; }
    else if (op === 'merge' && r) { patch = mergeRange(el, r); label = 'Merge cells'; }
    else if (op === 'unmerge' && r) { patch = unmerge(el, r.r1, r.c1); label = 'Split cell'; }
    if (patch) store.patch([{ id: el.id, p: patch }], { label });
}

// ---- video and audio preview ---------------------------------------------------------------------------
const previewId = ref(null);
const previewEl = computed(() => (previewId.value ? slide.value.els.find((x) => x.id === previewId.value) || null : null));
const previewSrc = computed(() => (previewEl.value ? resolveSrc(previewEl.value.src, assets.value) : null));
function togglePreview(id) {
    if (previewId.value === id) { previewId.value = null; return; }
    const el = slide.value.els.find((x) => x.id === id);
    if (!el || !resolveSrc(el.src, assets.value)) { hooks.toast('Preview plays uploaded files. Embedded videos play in the player.', 'info'); return; }
    previewId.value = id;
}
watch([selIds, () => state.value.slideId], () => { previewId.value = null; });

// ---- selection driven things -----------------------------------------------------------------------------
let proxyFocusing = false;
let selTimer = 0;
watch(selIds, (ids, old) => {
    if (ids.length === old.length && ids.every((x, i) => x === old[i])) return;
    clearTimeout(selTimer);
    selTimer = setTimeout(() => {
        if (proxyFocusing) { proxyFocusing = false; return; }
        const els = selected.value;
        announce(selectionAnnouncement(els, els.length === 1 ? slide.value.els.findIndex((x) => x.id === els[0].id) : null, slide.value.els.length));
    }, 160);
    if (ui.editingText && !ids.includes(ui.editingText) && !(draft.value && draft.value.id === ui.editingText)) finishText();
    if (ui.cropping && !ids.includes(ui.cropping)) cropRef.value?.commit?.();
    if (tableEdit.value && !ids.includes(tableEdit.value)) tableEdit.value = null;
    // the proxy that had focus may be gone
    nextTick(() => {
        const a = document.activeElement;
        if (a === document.body && root.value && root.value.contains(a) === false && lastFocusInside) viewport.value?.focus({ preventScroll: true });
    });
});
let lastFocusInside = false;
function onFocusIn(e) {
    lastFocusInside = true;
    const p = e.target.closest?.('.es-proxy');
    if (p) {
        const id = p.dataset.id;
        if (!(selIds.value.length === 1 && selIds.value[0] === id)) { proxyFocusing = true; store.select([id], 'replace', { members: true }); }
        else announce(elementLabel(slide.value.els.find((x) => x.id === id) || {}, assets.value));
    }
}
function onFocusOut(e) {
    if (!root.value?.contains(e.relatedTarget)) lastFocusInside = false;
}

// ---- the contextual bar ------------------------------------------------------------------------------------
const barRef = ref(null);
const barAnchor = computed(() => {
    const els = editTarget.value ? [editTarget.value] : liveSelected.value;
    if (!els.length) return null;
    const u = unionBounds(els.map((e) => rotatedBounds(e)));
    const o = origin.value;
    const z = zoom.value;
    // the rotation handle sticks out above a single box: keep the bar above it
    const knob = !editTarget.value && els.length === 1 && !isLine(els[0]) && !els[0].lock ? 34 : 0;
    return { x: o.x + u.x * z, y: o.y + u.y * z, w: u.w * z, h: Math.max(u.h * z, 2), inset: knob };
});
const barOn = computed(() => canEdit.value
    && !!barAnchor.value
    && ui.tool === 'select'
    && !ui.cropping
    && !['pinch', 'pan', 'marquee', 'draw'].includes(live.value.mode)
    && !!barKind(editTarget.value ? [editTarget.value] : selected.value));
const barHidden = computed(() => dragging.value);

// ---- other bits ------------------------------------------------------------------------------------------
function zoomToSelection() {
    const els = selected.value;
    if (!els.length) { vp.fit(); return; }
    const u = unionBounds(els.map((e) => rotatedBounds(e)));
    vp.zoomToRect(u, { pad: 160, maxZoom: 2 });
    announce('Zoomed to selection');
}
function pointerKind(e) { coarse.value = e.pointerType === 'touch'; }

// ---- the context menu actions need access to everything above -----------------------------------------------
const styleClip = ref(null);
function menuAction(id) {
    ui.contextMenu = null;
    const sel = selected.value;
    switch (id) {
        case 'cut': { const c = store.cut(); if (c) { lastCopied = c.text; navigator.clipboard?.writeText(c.text).catch(() => {}); } break; }
        case 'copy': { const c = store.copy(); if (c) { lastCopied = c.text; navigator.clipboard?.writeText(c.text).catch(() => {}); } break; }
        case 'paste': readClipboard(false); break;
        case 'pastePlain': readClipboard(true); break;
        case 'duplicate': store.duplicate(); break;
        case 'delete': store.deleteSelection(); break;
        case 'group': store.group(); break;
        case 'ungroup': store.ungroup(); break;
        case 'front': store.front(); break;
        case 'forward': store.forward(); break;
        case 'backward': store.backward(); break;
        case 'back': store.back(); break;
        case 'lock': store.toggleLock(); break;
        case 'hide': store.toggleHide(); break;
        case 'flipH': store.flip('h'); break;
        case 'flipV': store.flip('v'); break;
        case 'selectAll': store.selectAll(); break;
        case 'align.left': case 'align.center': case 'align.right': case 'align.top': case 'align.middle': case 'align.bottom':
            store.align(id.slice(6)); break;
        case 'distribute.h': store.distribute('h'); break;
        case 'distribute.v': store.distribute('v'); break;
        case 'copyStyle': if (sel.length === 1) { styleClip.value = copyStyle(sel[0]); hooks.toast('Style copied', 'ok'); } break;
        case 'pasteStyle': {
            if (!styleClip.value) break;
            const patches = sel.map((el) => ({ id: el.id, p: pasteStylePatch(styleClip.value, el) })).filter((p) => p.p);
            if (patches.length) store.patch(patches, { label: 'Paste style' });
            break;
        }
        case 'comment': {
            const anchor = menuAnchor();
            editor.openPanel?.('comments', { anchor });
            break;
        }
        default: break;
    }
    nextTick(() => viewport.value?.focus({ preventScroll: true }));
}
function menuAnchor() {
    const m = menuAt;
    const r = viewport.value.getBoundingClientRect();
    const pt = m ? vp.toSlide({ x: m.x - r.left, y: m.y - r.top }) : { x: deck.value.w / 2, y: deck.value.h / 2 };
    const el = selected.value[0];
    return {
        slide: slide.value.id, x: Math.round(pt.x), y: Math.round(pt.y), element: el ? el.id : null,
    };
}
let menuAt = null;
// The shell's context menu host renders ui.contextMenu = {x, y, items}; the stage supplies the items.
function openMenu(x, y) {
    menuAt = { x, y };
    const items = contextItems(menuCtx.value).map((i) => (i.id ? { ...i, run: () => menuAction(i.id) } : i));
    ui.contextMenu = { x, y, items };
}
async function readClipboard(plain) {
    try {
        if (navigator.clipboard?.read) {
            const items = await navigator.clipboard.read();
            for (const it of items) {
                const imageType = it.types.find((t) => t.startsWith('image/'));
                if (imageType && !it.types.includes('text/plain')) {
                    const blob = await it.getType(imageType);
                    uploads.addFiles([new File([blob], `pasted.${imageType.split('/')[1] || 'png'}`, { type: imageType })], { point: null });
                    return;
                }
            }
        }
        const text = await navigator.clipboard.readText();
        if (plain && classifyText(text)?.kind !== 'payload') {
            const plainText = text;
            const r = store.paste({ text: plainText });
            if (r) reportPaste(r);
        } else pasteText(text);
    } catch {
        hooks.toast(`Your browser asked not to share the clipboard. Press ${isMac ? 'Cmd' : 'Ctrl'}+V instead.`, 'warn');
    }
}
const menuCtx = computed(() => ({
    count: selected.value.length,
    hasGroup: selected.value.length > 0 && selected.value.every((e) => e.group),
    locked: selected.value.length > 0 && selected.value.every((e) => e.lock),
    hidden: selected.value.length > 0 && selected.value.every((e) => e.hide),
    canEdit: canEdit.value,
    canComment: can.value.comment !== false,
    hasPanel,
    hasStyle: !!styleClip.value,
    shortcuts: (cmd) => (shortcutsFor(cmd, isMac)[0] || ''),
}));

// ---- lifecycle ---------------------------------------------------------------------------------------------
const api = {
    fit: () => vp.fit(),
    zoomToSelection,
    zoomToRect: (r) => vp.zoomToRect(r),
    focus: () => viewport.value?.focus({ preventScroll: true }),
    toScreen: (p) => vp.toScreen(p),
    toSlide: (p) => vp.toSlide(p),
    beginTextEdit,
    beginCrop,
    replacePicture,
    pickFiles,
    addFiles: (files, opts) => uploads.addFiles(files, opts),
    announce,
    openLink: () => barRef.value?.openLink?.(),
};
defineExpose(api);

onMounted(() => {
    ensureFonts();
    // warm the text editor chunk while the browser is idle, so the first double click does not wait for it
    (typeof requestIdleCallback === 'function' ? requestIdleCallback : (f) => setTimeout(f, 800))(() => { import('./TextEditor.vue'); });
    try { editor.stage = api; } catch { /* a frozen editor object: the shell can use the template ref instead */ }
    try { ui.userGuides = JSON.parse(localStorage.getItem(`press:guides:${deck.value.id}`) || '[]'); } catch { ui.userGuides = []; }
    window.addEventListener('keyup', onKeyUp);
    window.addEventListener('blur', onWindowBlur);
    offBus = [
        editor.on?.('stage:focus', () => viewport.value?.focus({ preventScroll: true })),
        editor.on?.('paste:files', (files) => uploads.addFiles(files, { point: null })),
    ].filter(Boolean);
    viewport.value?.focus({ preventScroll: true });
});
watch(() => ui.userGuides, (g) => {
    try { localStorage.setItem(`press:guides:${deck.value.id}`, JSON.stringify(g || [])); } catch { /* storage unavailable */ }
}, { deep: true });
let offBus = [];
const onWindowBlur = () => gestures.setSpace(false);
onBeforeUnmount(() => {
    window.removeEventListener('keyup', onKeyUp);
    window.removeEventListener('blur', onWindowBlur);
    offBus.forEach((off) => off());
    gestures.destroy();
    clearTimeout(selTimer);
    clearTimeout(nudgeTimer);
    try { if (editor.stage === api) editor.stage = null; } catch { /* nothing to clear */ }
});

</script>

<template>
    <div ref="root" class="es-root" :class="{ 'es-readonly': readOnly, 'es-moving': dragging, 'es-kbd': kbd }" @focusin="onFocusIn" @focusout="onFocusOut">
        <div
            ref="viewport"
            class="es-viewport"
            :class="{ 'es-drop-on': dropOn }"
            :style="cursorStyle"
            role="application"
            data-keyscope="stage"
            aria-roledescription="slide editor"
            :aria-label="stageLabel(slide, slideIndex, slides.length)"
            tabindex="0"
            @pointerdown="(kbd = false, pointerKind($event), gestures.onPointerDown($event))"
            @pointermove="gestures.onPointerMove"
            @pointerup="gestures.onPointerUp"
            @pointercancel="gestures.onPointerCancel"
            @pointerleave="gestures.onPointerLeave"
            @wheel="gestures.onWheel"
            @keydown="onKeyDown"
            @contextmenu="onContextMenu"
            @dblclick="onDblClick"
            @copy="onCopy"
            @cut="onCut"
            @paste="onPaste"
            @dragenter="onDragEnter"
            @dragover="onDragOver"
            @dragleave="onDragLeave"
            @drop="onDrop"
        >
            <!-- the slide being left, while the next one travels in along the rail -->
            <div v-if="outgoing" class="es-sheet es-sheet-out" :style="sheetStyle" aria-hidden="true">
                <div ref="outBody" class="es-sheet-body">
                    <SlideView :slide="outgoing.slide" :deck="deck" :assets="assets" mode="thumb" />
                </div>
            </div>
            <div class="es-sheet es-sheet-in" :style="sheetStyle">
                <div ref="inBody" class="es-sheet-body">
                    <SlideView ref="viewRef" :slide="viewSlide" :deck="deck" :assets="assets" mode="edit" />
                </div>
            </div>

            <!-- grid, margins, centre lines -->
            <svg v-if="furniture.grid || furniture.margins" class="es-furniture" :width="vp.size.value.w" :height="vp.size.value.h" aria-hidden="true">
                <template v-if="furniture.grid">
                    <line v-for="x in furniture.grid.x" :key="'gx' + x" class="es-grid-line" :x1="x" :y1="furniture.grid.y0" :x2="x" :y2="furniture.grid.y1" />
                    <line v-for="y in furniture.grid.y" :key="'gy' + y" class="es-grid-line" :x1="furniture.grid.x0" :y1="y" :x2="furniture.grid.x1" :y2="y" />
                </template>
                <template v-if="furniture.margins">
                    <line v-for="x in furniture.margins.x" :key="'mx' + x" class="es-margin-line" :x1="x" :y1="furniture.margins.y0" :x2="x" :y2="furniture.margins.y1" />
                    <line v-for="y in furniture.margins.y" :key="'my' + y" class="es-margin-line" :x1="furniture.margins.x0" :y1="y" :x2="furniture.margins.x1" :y2="y" />
                    <line class="es-centre-line" :x1="furniture.centre.x" :y1="furniture.centre.y0" :x2="furniture.centre.x" :y2="furniture.centre.y1" />
                    <line class="es-centre-line" :x1="furniture.centre.x0" :y1="furniture.centre.y" :x2="furniture.centre.x1" :y2="furniture.centre.y" />
                </template>
            </svg>

            <!-- upload placeholders, text editor, crop layer: all drawn in slide space -->
            <div class="es-slidespace" :style="spaceStyle">
                <div
                    v-for="u in uploads.items.value"
                    :key="u.id"
                    class="es-upload"
                    :style="{ transform: `translate(${u.box.x}px, ${u.box.y}px)`, width: u.box.w + 'px', height: u.box.h + 'px' }"
                    role="status"
                    :aria-label="`Uploading ${u.name}, ${Math.round(u.progress * 100)} percent`"
                >
                    <span class="es-upload-name">{{ u.name }}</span>
                    <span class="es-upload-bar"><i :style="{ transform: `scaleX(${Math.max(0.03, u.progress)})` }"></i></span>
                </div>
                <TextEditor
                    v-if="editTarget"
                    :key="editTarget.id"
                    ref="textRef"
                    :el="editTarget"
                    :deck="deck"
                    :is-draft="!!draft && draft.id === editTarget.id"
                    :insert="insertText" :at="editAt"
                    :zoom="zoom"
                    @commit="onTextCommit"
                    @ready="editorReady = true"
                    @link="barRef?.openLink?.()"
                />
                <div v-if="previewEl && previewSrc" class="es-media" data-es-ui :style="{ width: previewEl.w + 'px', height: previewEl.h + 'px', transform: `translate(${previewEl.x}px, ${previewEl.y}px)${previewEl.rot ? ` rotate(${previewEl.rot}deg)` : ''}` }">
                    <video v-if="previewEl.type === 'video'" :src="previewSrc.url" controls autoplay playsinline :loop="!!previewEl.loop" :muted="!!previewEl.muted"></video>
                    <audio v-else :src="previewSrc.url" controls autoplay :loop="!!previewEl.loop"></audio>
                </div>
                <TableLayer
                    v-if="tableTarget"
                    ref="tableRef"
                    :key="tableTarget.id"
                    :el="tableTarget"
                    :get-node="(id) => viewRef?.nodes?.().get(id)"
                    :vp="vp"
                    @range="(r) => (tableRange = r)"
                    @exit="tableEdit = null"
                />
                <CropLayer
                    v-if="cropTarget"
                    ref="cropRef"
                    :key="cropTarget.id"
                    :el="cropTarget"
                    :assets="assets"
                    :deck="deck"
                    :zoom="zoom"
                    :origin="origin"
                    :vp="vp"
                    @done="onCropDone"
                />
            </div>

            <StageRulers v-if="ui.rulers" :vp="vp" :ui="ui" :deck="deck" />

            <SelectionLayer
                :origin="origin"
                :zoom="zoom"
                :size="vp.size.value"
                :elements="liveSelected"
                :slide-els="slide.els"
                :live="live"
                :hover="gestures.hover.value"
                :show-handles="canEdit && !editTarget && !cropTarget && !tableTarget && ui.tool === 'select' && live.mode !== 'move' && live.mode !== 'rotate'"
                :dragging="false"
                :coarse="coarse"
                :presence="presence"
                :user-guides="ui.userGuides || []"
                :hidden-ids="editTarget ? [editTarget.id] : cropTarget ? [cropTarget.id] : []"
            />

            <!-- comment pins -->
            <div v-if="pins.length" class="es-pins">
                <button
                    v-for="p in pins"
                    :key="p.id"
                    type="button"
                    class="es-pin"
                    data-es-ui
                    :data-resolved="!!p.resolved"
                    :style="{ transform: `translate(${origin.x + p.x * zoom}px, ${origin.y + p.y * zoom}px)` }"
                    :aria-label="`Comment${p.count ? `, ${p.count} replies` : ''}${p.resolved ? ', resolved' : ''}`"
                    @click.stop="editor.openPanel?.('comments', { pin: p.id })"
                >{{ p.count || '' }}</button>
            </div>
            <slot name="overlay" :to-screen="vp.toScreen" :to-slide="vp.toSlide" :zoom="zoom" :origin="origin" :slide="slide" />

            <!-- one real, focusable proxy per element, in z order, for the keyboard and screen readers -->
            <ul class="es-proxies" :style="{ transform: `translate(${origin.x}px, ${origin.y}px) scale(${zoom})`, '--z': zoom }" aria-label="Elements on this slide">
                <li v-for="el in slide.els" :key="el.id">
                    <button
                        type="button"
                        class="es-proxy"
                        :data-id="el.id"
                        :aria-label="elementLabel(el, assets)"
                        :aria-pressed="selIds.includes(el.id)"
                        :aria-keyshortcuts="'Enter Delete ArrowUp ArrowDown ArrowLeft ArrowRight Alt+ArrowRight'"
                        :aria-describedby="'es-help-' + slide.id"
                        :disabled="el.hide ? true : undefined"
                        tabindex="0"
                        :style="{ width: el.w + 'px', height: Math.max(el.h, 4) + 'px', transform: `translate(${el.x}px, ${el.y}px)${el.rot ? ` rotate(${el.rot}deg)` : ''}` }"
                    ></button>
                </li>
            </ul>

            <ContextBar
                v-if="barOn"
                v-show="!barHidden"
                ref="barRef"
                :anchor="barAnchor"
                :size="vp.size.value"
                :elements="editTarget ? [editTarget] : selected"
                :format="format"
                :editing="!!editTarget"
                :has-panel="hasPanel"
                :hidden="barHidden"
                :previewing="previewId"
                @preview="togglePreview"
                :table="tableState"
                @table="applyTable"
                @crop="beginCrop"
                @replace="replacePicture"
                @refocus="() => (editTarget ? textRef?.focus() : viewport?.focus({ preventScroll: true }))"
            />

            <div v-if="cropTarget" class="es-crop-hint" role="status">Drag to move, scroll to scale. Enter applies, Esc cancels.</div>
            <div v-if="readOnly" class="es-badge" role="note"><i aria-hidden="true"></i>Read only</div>
            <p :id="'es-help-' + slide.id" class="es-sr">Arrow keys move the element, Shift with arrows moves by ten, Alt with arrows resizes, Enter edits, Delete removes, Alt F10 moves to the formatting bar.</p>
            <div class="es-sr" role="status" aria-live="polite" aria-atomic="true">{{ liveText }}</div>
        </div>

        <input ref="picker" type="file" class="es-sr" tabindex="-1" aria-hidden="true" @change="onPicked" />
    </div>
</template>
