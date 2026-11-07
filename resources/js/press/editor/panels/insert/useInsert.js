// Vue glue for the Insert panel: builds with insertModel.js, places with placement.js, adds through the store (one undo step each) and
// selects the new element. Also starts and ends drags onto the stage and receives the stage's 'insert:drop' event.
//
//   const ins = useInsert(editor)
//   ins.canEdit                       false for viewers
//   ins.insertText(preset) insertShape(kind) insertIcon(name) insertChart(type) insertTable(rows, cols) insertVideo(parsed)
//   ins.insertAsset(asset, kind?)     asset from GET /api/assets (or a brand kit logo); registers it in editor.assets first
//   ins.registerAsset(asset)
//   ins.dragStart(event, payload, label) / ins.dragEnd()      payload: see buildFromDrag in insertModel.js
//   ins.listenForDrops()              call once in a panel's setup; returns nothing, cleans up on unmount
import { nextTick, onBeforeUnmount, unref } from 'vue';
import { toAssetEntry } from '../../stage/lib/drop.js';
import { kindOf } from '../assets/assetsModel.js';
import {
  buildText, buildShape, buildIcon, buildChart, buildTable, buildVideoFromUrl, buildMediaElement, buildFromDrag, SHAPE_LABELS, textPreset,
} from './insertModel.js';
import { placeNew, insertionPointOf } from './placement.js';
import { CHART_TYPES } from '../../../charts/index.js';

export function useInsert(editor) {
  const { store, ui } = editor;
  const canEdit = () => !editor.readOnly && editor.can?.update !== false;
  const deckNow = () => unref(editor.deck);

  function insertBuilt(build, { label, say, point = null, afterAdd = null } = {}) {
    if (!canEdit()) {
      editor.toast('You can view this deck but not change it.', { kind: 'warn' });
      return null;
    }
    const deck = deckNow();
    const slide = store.slide();
    if (!deck || !slide) return null;
    const el = build(deck, slide.els.map((e) => e.id));
    if (!el) return null;
    const placed = placeNew(deck, el, { point: point || insertionPointOf(editor), existing: slide.els });
    store.dispatch([{ op: 'el.add', slide: slide.id, el: placed }], { label });
    store.select([placed.id]);
    editor.announce?.(say || label);
    if (afterAdd) nextTick(() => afterAdd(placed));
    return placed;
  }

  const insertText = (preset, point) => insertBuilt((d, t) => buildText(d, preset, t), {
    label: `Add ${(textPreset(preset)?.label || 'text').toLowerCase()}`,
    point,
    afterAdd: (el) => { try { editor.stage?.beginTextEdit?.(el.id); } catch { /* the stage may not be mounted */ } },
  });
  const insertShape = (kind, point) => insertBuilt((d, t) => buildShape(d, kind, t), { label: `Add ${(SHAPE_LABELS[kind] || 'shape').toLowerCase()}`, point });
  const insertIcon = (name, point) => insertBuilt((d, t) => buildIcon(d, name, t), { label: 'Add icon', say: `Added icon ${name}`, point });
  const insertChart = (type, point) => insertBuilt((d, t) => buildChart(d, type, t), {
    label: `Add ${(CHART_TYPES.find((c) => c.id === type)?.label || 'chart').toLowerCase()} chart`,
    point,
    afterAdd: () => { if (editor.hasPanel?.('chart')) editor.openPanel('chart'); },
  });
  const insertTable = (rows, cols, point) => insertBuilt((d, t) => buildTable(d, rows, cols, t), { label: 'Add table', say: `Added a table with ${rows} rows and ${cols} columns`, point });
  const insertVideo = (parsed, point) => insertBuilt((d, t) => buildVideoFromUrl(d, parsed, t), { label: 'Add video', point });

  function registerAsset(asset) {
    const entry = toAssetEntry(asset, typeof location !== 'undefined' ? location.origin : null);
    if (asset.alt) entry.alt = asset.alt;
    if (typeof editor.registerAsset === 'function') editor.registerAsset(asset.id, entry);
    else if (editor.assets) editor.assets[asset.id] = { ...(editor.assets[asset.id] || {}), ...entry };
  }

  function insertAsset(asset, kind = null, point = null) {
    const k = kind || kindOf(asset);
    registerAsset(asset);
    return insertBuilt((d, t) => buildMediaElement(d, k, asset, t), { label: k === 'image' ? 'Add picture' : `Add ${k}`, say: `Added ${k}`, point });
  }

  function dragStart(e, payload, label = '') {
    if (!canEdit()) { e.preventDefault(); return; }
    ui.dragging = payload;
    if (e.dataTransfer) {
      e.dataTransfer.effectAllowed = 'copy';
      try { e.dataTransfer.setData('text/plain', label || 'Dot.Press item'); } catch { /* some browsers refuse */ }
    }
  }
  function dragEnd() {
    if (ui.dragging && (ui.dragging.kind === 'insert' || ui.dragging.kind === 'asset')) ui.dragging = null;
  }

  function listenForDrops() {
    const off = editor.on?.('insert:drop', ({ drag, point }) => {
      if (!canEdit()) return;
      const deck = deckNow();
      const slide = store.slide();
      if (!deck || !slide) return;
      const el = buildFromDrag(deck, drag, slide.els.map((e) => e.id));
      if (!el) return;
      const placed = placeNew(deck, el, { point, existing: slide.els });
      store.dispatch([{ op: 'el.add', slide: slide.id, el: placed }], { label: `Add ${placed.type}` });
      store.select([placed.id]);
      editor.announce?.(`Added ${placed.type}`);
      if (placed.type === 'chart' && editor.hasPanel?.('chart')) editor.openPanel('chart');
    });
    onBeforeUnmount(() => off?.());
  }

  return {
    get canEdit() { return canEdit(); },
    insertText, insertShape, insertIcon, insertChart, insertTable, insertVideo, insertAsset, registerAsset, dragStart, dragEnd, listenForDrops,
  };
}
