/**
 * Undo and redo of op batches.
 *
 * Every stack item is `{ ops, guards, label, key, t, meta }`: the ops that perform the step (for the
 * undo stack: the inverse of what was done), the guards that go with them (see ops.js) and
 * bookkeeping. Undo applies the item and pushes the inverse of that application on the redo stack,
 * so redo is exactly symmetric.
 *
 * Rules
 *  - Coalescing: record() with the same `key` as the top item, within `coalesceMs` of the previous
 *    record, and not sealed, merges into the top item. Dragging, typing and slider scrubbing give
 *    one undo step. When both steps are el.set only, they compact into one op per element.
 *  - Transactions: begin(label) ... commit() make everything recorded in between one step.
 *    begin/commit nest. cancel() drops the open transaction and returns what to apply to revert it.
 *  - Cap: 200 steps, the oldest go first.
 *  - Remote changes are never undone. Before an op is applied it is filtered by its guard: a key of
 *    an el.set / slide.set / deck.set whose current value differs from what this client wrote (so
 *    somebody else changed it) is dropped. An op on an element or slide that somebody deleted is
 *    skipped by applyOps. An undo step in which nothing could be applied is discarded and the next
 *    step is tried, so Cmd+Z never looks dead because of other people's work.
 *
 * API
 *   createHistory({cap?, coalesceMs?, now?}) -> history
 *   history.record({inverse, guards, label?, key?, meta?})
 *   history.breakCoalescing()                  seal the top step (pointer up, blur, selection change)
 *   history.begin(label?) / commit() / cancel()
 *   history.undo(doc, opts?) / history.redo(doc, opts?)
 *        -> null | { doc, ops, skipped, label, meta }   `ops` are the ops to send to the server
 *   history.canUndo() / canRedo() / undoLabel() / redoLabel() / size() -> {undo, redo}
 *   history.remapSlideIds(map)                 after the server replaced temporary slide ids
 *   history.clear()
 *   applyGuarded(doc, ops, guards, opts?)      the guard aware apply used by undo and redo
 */
import { applyOps, remapOps } from './ops.js';
import { deepEqual } from './util.js';

const has = (o, k) => Object.prototype.hasOwnProperty.call(o, k);

function guardedOp(doc, op, guard) {
  if (!guard || !guard.expect) return op;
  const target = (() => {
    if (op.op === 'el.set') return doc.slides.find((s) => s.id === String(op.slide))?.els.find((e) => e.id === op.id);
    if (op.op === 'slide.set') return doc.slides.find((s) => s.id === String(op.id));
    if (op.op === 'deck.set') return doc.deck;
    if (op.op === 'section.set') return doc.deck.sections.find((s) => s.id === op.id);
    return null;
  })();
  if (!target) return op; // applyOps reports the missing element or slide
  const cur = (k) => (has(target, k) && target[k] !== undefined ? target[k] : null);
  if (op.op === 'el.set') {
    const p = {};
    for (const k of Object.keys(op.p)) {
      if (!has(guard.expect, k) || deepEqual(cur(k), guard.expect[k])) p[k] = op.p[k];
    }
    return Object.keys(p).length ? { ...op, p } : null;
  }
  const out = { ...op };
  let kept = 0;
  for (const k of Object.keys(guard.expect)) {
    if (!has(op, k)) continue;
    if (deepEqual(cur(k), guard.expect[k])) kept++;
    else delete out[k];
  }
  return kept ? out : null;
}

export function applyGuarded(doc, ops, guards = [], opts = {}) {
  let cur = doc;
  const applied = [];
  const skipped = [];
  const batches = [];
  ops.forEach((op, i) => {
    const g = guardedOp(cur, op, guards[i]);
    if (!g) { skipped.push({ index: i, op, reason: 'remote_changed' }); return; }
    const r = applyOps(cur, [g], opts);
    if (r.skipped.length) { skipped.push({ index: i, op, reason: r.skipped[0].reason }); return; }
    cur = r.doc;
    applied.push(g);
    batches.push(r);
  });
  const inverse = [];
  const outGuards = [];
  for (let k = batches.length - 1; k >= 0; k--) {
    inverse.push(...batches[k].inverse);
    outGuards.push(...batches[k].guards);
  }
  return { doc: cur, applied, inverse, guards: outGuards, skipped };
}

const elKey = (op) => `${op.slide}\u0000${op.id}`;

/** Merge two undo items (`older` was recorded first). Result applies newer ops first. */
function mergeItems(older, newer) {
  const ops = [...newer.ops, ...older.ops];
  const guards = [...newer.guards, ...older.guards];
  if (ops.every((o) => o.op === 'el.set')) {
    const map = new Map();
    ops.forEach((o, i) => {
      const k = elKey(o);
      const g = guards[i]?.expect || {};
      const m = map.get(k);
      if (!m) { map.set(k, { op: { ...o, p: { ...o.p } }, expect: { ...g } }); return; }
      for (const key of Object.keys(o.p)) m.op.p[key] = o.p[key]; // older state wins
      for (const key of Object.keys(g)) if (!has(m.expect, key)) m.expect[key] = g[key]; // newer expectation wins
    });
    return { ...older, ops: [...map.values()].map((m) => m.op), guards: [...map.values()].map((m) => ({ expect: m.expect })), t: newer.t };
  }
  return { ...older, ops, guards, t: newer.t };
}

export function createHistory({ cap = 200, coalesceMs = 1000, now = Date.now } = {}) {
  let undoStack = [];
  let redoStack = [];
  let tx = null; // {depth, label, item}
  let sealed = true;
  let lastT = 0;

  const trim = () => { while (undoStack.length > cap) undoStack.shift(); };

  function pushUndo(item, { coalesce }) {
    const top = undoStack[undoStack.length - 1];
    if (coalesce && item.key && top && !sealed && top.key === item.key && item.t - lastT <= coalesceMs) {
      undoStack[undoStack.length - 1] = mergeItems(top, item);
    } else {
      undoStack.push(item);
      trim();
    }
    sealed = false;
    lastT = item.t;
  }

  const h = {
    record({ inverse, guards, label = '', key = null, meta = null }) {
      if (!inverse || !inverse.length) return;
      const item = { ops: inverse, guards: guards || inverse.map(() => null), label, key, t: now(), meta };
      redoStack = [];
      if (tx) {
        tx.item = tx.item ? mergeTx(tx.item, item) : item;
        return;
      }
      pushUndo(item, { coalesce: true });
    },
    breakCoalescing() { sealed = true; },
    begin(label = '') {
      if (tx) { tx.depth++; return; }
      tx = { depth: 1, label, item: null };
    },
    commit() {
      if (!tx) return;
      tx.depth--;
      if (tx.depth > 0) return;
      const { item, label } = tx;
      tx = null;
      if (item) {
        pushUndo({ ...item, label: label || item.label, key: null }, { coalesce: false });
        sealed = true;
      }
    },
    /** Drop the open transaction. Returns {ops, guards} that revert what it recorded, or null. */
    cancel() {
      if (!tx) return null;
      const item = tx.item;
      tx = null;
      return item ? { ops: item.ops, guards: item.guards } : null;
    },
    inTransaction: () => !!tx,
    canUndo: () => undoStack.length > 0,
    canRedo: () => redoStack.length > 0,
    undoLabel: () => undoStack[undoStack.length - 1]?.label || '',
    redoLabel: () => redoStack[redoStack.length - 1]?.label || '',
    size: () => ({ undo: undoStack.length, redo: redoStack.length }),
    clear() { undoStack = []; redoStack = []; tx = null; sealed = true; },
    remapSlideIds(map) {
      const fix = (s) => ({ ...s, ops: remapOps(s.ops, map) });
      undoStack = undoStack.map(fix);
      redoStack = redoStack.map(fix);
    },
    undo(doc, opts) { return step(doc, undoStack, redoStack, opts); },
    redo(doc, opts) { return step(doc, redoStack, undoStack, opts); },
  };

  // Newest ops of a transaction go first when undone.
  function mergeTx(a, b) {
    return { ...a, ops: [...b.ops, ...a.ops], guards: [...b.guards, ...a.guards], t: b.t };
  }

  function step(doc, from, to, opts) {
    sealed = true;
    while (from.length) {
      const item = from.pop();
      const r = applyGuarded(doc, item.ops, item.guards, opts);
      if (!r.applied.length || r.doc === doc) continue; // nothing left to do: try the next step
      if (r.inverse.length) to.push({ ...item, ops: r.inverse, guards: r.guards, key: null, t: now() });
      trim();
      return { doc: r.doc, ops: r.applied, skipped: r.skipped, label: item.label, meta: item.meta };
    }
    return null;
  }

  return h;
}
