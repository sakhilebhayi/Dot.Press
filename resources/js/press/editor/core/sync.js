/**
 * The autosave engine. Framework free and deterministic under test: the network, storage and time
 * are injected.
 *
 *   const sync = createSync({ transport, storage, clock, clientId, deckId, uuid? })
 *   sync.init(serverDocument)                 sets `base`; `view` = base
 *   const r = sync.dispatch(ops)              applies locally at once; r = applyOps result (doc, inverse,
 *                                             guards, skipped). slide.dup is turned into slide.add
 *
 * Concepts
 *   base      the last server confirmed document (spec section 9), `base.seq` = last log entry applied
 *   pending   ops not yet acknowledged = inflight batch (being sent, has an idempotency key) + queue
 *   view      applyOps(base, pending).doc, what the editor shows. Remote changes go into base and the
 *             pending ops are re-applied on top, so concurrent edits merge
 *
 * Injected collaborators
 *   transport.postOps({client, key, ops}) -> Promise<{seq, deck_rev, slides, ids, skipped}>
 *   transport.getChanges(after)           -> Promise<{seq, changes: [{seq, client, user, ops}]} | {stale: true}>
 *   transport.getDocument()               -> Promise<{deck, slides, seq, assets}>
 *   transport.beacon?({client, key, ops}) -> boolean   optional, used by flush({unloading: true})
 *       A rejected promise carries `status` (HTTP), `retryAfter` (seconds) or `network: true`.
 *   storage  {get(key), set(key, string), remove(key)}  sync or async
 *   clock    {now(), setTimeout(fn, ms), clearTimeout(h), random()}   see realClock
 *
 * Behaviour
 *   - flush 600 ms after the last edit, at most 2.5 s after the first unsent edit, on blur(), on
 *     flush(), and flush({unloading: true}). One request in flight at a time, up to 200 ops each.
 *   - every request has a key; a retry reuses it, so a delivered-but-unanswered request is harmless
 *   - network errors, 5xx, 408, 429: retry with exponential backoff (1 s doubling to 30 s, jittered;
 *     429 honours retryAfter). Pending ops are never dropped. 413/422: the batch is split to find the
 *     bad op; a single op the server refuses stops saving (status 'error', fatal) until retry() or
 *     discardInflight(). 401/403/419 pause saving (needsAuth) until retry().
 *   - a slide.add whose tid is a real id (undo of a slide removal) is re-sent under a temporary id
 *   - after a batch with deck.resize the feed is polled at once: the server logs deck.reload and we reload
 *   - ops the server reports as skipped are not applied to base (they were not applied there)
 *   - temporary slide ids are replaced by the real ids from the response (onIdMap)
 *   - pending ops are persisted after every change; recover() / restore() bring them back after a crash
 *   - polling getChanges(after) every `pollMs.focused` (3 s) or `pollMs.hidden` (15 s). Entries are applied
 *     in seq order; our own echoes are never applied twice. {stale: true}, a deck.reload op or a remote
 *     slide.dup fetches the whole document; pending ops are re-applied on top.
 *   - state: {status: 'saved'|'saving'|'offline'|'error', pending, inflight, queued, lastError, lastSavedAt,
 *     retryAt, fatal, needsAuth, seq}
 */
import { applyOps, remapOps, dupToAdd } from './ops.js';
import { createDoc } from './doc.js';
import { uniqueId } from './util.js';
import { createPersistence } from './sync-storage.js';

export const realClock = {
  now: () => Date.now(),
  setTimeout: (fn, ms) => setTimeout(fn, ms),
  clearTimeout: (h) => clearTimeout(h),
  random: () => Math.random(),
};

const DEFAULTS = {
  debounceMs: 600, maxWaitMs: 2500, maxBatch: 200, maxBytes: 1_500_000,
  backoffBaseMs: 1000, backoffMaxMs: 30_000,
  pollMs: { focused: 3000, hidden: 15_000 }, heartbeatMs: 5000, aliveMs: 15_000,
};

const defaultUuid = () => (globalThis.crypto?.randomUUID ? globalThis.crypto.randomUUID()
  : `k${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`);

function classify(err) {
  const status = err?.status;
  if (err?.network || status === undefined || status === 0) return { kind: 'network' };
  if (status === 429) return { kind: 'throttle', retryAfter: err.retryAfter };
  if (status >= 500 || status === 408 || status === 425) return { kind: 'server' };
  if (status === 401 || status === 403 || status === 419) return { kind: 'auth' };
  return { kind: 'rejected' };
}

export function createSync(opts) {
  const { transport, storage, clock = realClock, clientId } = opts;
  const cfg = { ...DEFAULTS, ...(opts.config || {}), pollMs: { ...DEFAULTS.pollMs, ...(opts.config?.pollMs || {}) } };
  const uuid = opts.uuid || defaultUuid;
  const store = createPersistence({ storage, clientId, deckId: opts.deckId });

  let base = null;
  let view = null;
  let inflight = null; // {key, ops, covered, uncertain}
  let queue = [];
  let settling = []; // acknowledged batches the feed has not reached yet: {seq, ops}
  let maxBatch = cfg.maxBatch;
  let sending = false;
  let polling = false;
  let pollTimer = null;
  let debounceTimer = null;
  let maxTimer = null;
  let retryTimer = null;
  let hbTimer = null;
  let attempts = 0;
  let focused = true;
  let online = true;
  let paused = false;
  let reloading = false;
  let pollFailing = false;
  let destroyed = false;
  let sendDone = [];
  const st = { lastError: null, lastSavedAt: null, retryAt: null, fatal: false, needsAuth: false, failKind: null };
  const subs = { change: new Set(), state: new Set(), idmap: new Set(), skipped: new Set() };
  let lastState = null;

  const emit = (set, ...a) => { for (const f of [...subs[set]]) { try { f(...a); } catch { /* subscriber errors never break saving */ } } };
  const sub = (set) => (f) => { subs[set].add(f); return () => subs[set].delete(f); };

  // ---- derived state ----------------------------------------------------------------------------
  const overlay = () => [
    ...settling.filter((s) => s.seq > base.seq).flatMap((s) => s.ops),
    ...(inflight && !inflight.covered && !inflight.uncertain ? inflight.ops : []),
    ...queue,
  ];

  function stateNow() {
    const inflightN = inflight ? inflight.ops.length : 0;
    const pending = inflightN + queue.length;
    let status = 'saved';
    if (st.fatal || st.needsAuth) status = 'error';
    else if (!online || st.failKind === 'network') status = 'offline';
    else if (st.failKind === 'server') status = 'error';
    else if (pending || sending) status = 'saving';
    return {
      status, pending, inflight: inflightN, queued: queue.length, lastError: st.lastError, lastSavedAt: st.lastSavedAt,
      retryAt: st.retryAt, fatal: st.fatal, needsAuth: st.needsAuth, seq: base ? base.seq : 0,
    };
  }
  function pushState() {
    const s = stateNow();
    const sig = JSON.stringify(s);
    if (sig === lastState) return;
    lastState = sig;
    emit('state', s);
  }
  function recompute(source) {
    view = applyOps(base, overlay()).doc;
    emit('change', view, { source });
    pushState();
  }

  // ---- persistence ------------------------------------------------------------------------------
  function persist() {
    const has = !!(inflight || queue.length);
    store.write(has ? {
      v: 1, client: clientId, deckId: opts.deckId, baseSeq: base.seq, savedAt: clock.now(),
      inflight: inflight ? { key: inflight.key, ops: inflight.ops } : null, queue,
    } : null);
    if (has && !hbTimer) hbTimer = clock.setTimeout(() => { hbTimer = null; if (!destroyed) persist(); }, cfg.heartbeatMs);
    if (!has && hbTimer) { clock.clearTimeout(hbTimer); hbTimer = null; }
  }

  // ---- sending ----------------------------------------------------------------------------------
  function scheduleFlush() {
    if (destroyed) return;
    if (debounceTimer) clock.clearTimeout(debounceTimer);
    debounceTimer = clock.setTimeout(() => { debounceTimer = null; api.flush(); }, cfg.debounceMs);
    if (!maxTimer) maxTimer = clock.setTimeout(() => { maxTimer = null; api.flush(); }, cfg.maxWaitMs);
  }
  function clearFlushTimers() {
    if (debounceTimer) { clock.clearTimeout(debounceTimer); debounceTimer = null; }
    if (maxTimer) { clock.clearTimeout(maxTimer); maxTimer = null; }
  }
  function takeBatch() {
    const out = [];
    let bytes = 0;
    while (queue.length && out.length < maxBatch) {
      const size = JSON.stringify(queue[0]).length;
      if (out.length && bytes + size > cfg.maxBytes) break;
      bytes += size;
      out.push(queue.shift());
    }
    return out;
  }

  async function sendNext() {
    if (sending) return; // the answer will resolve waiting flushes
    if (destroyed || paused || !online || retryTimer) { resolveSend(); return; }
    if (!inflight) {
      if (!queue.length) { resolveSend(); return; }
      inflight = { key: uuid(), ops: takeBatch(), covered: false, uncertain: false };
      persist();
    }
    sending = true;
    pushState();
    const sent = inflight;
    let res;
    try {
      res = await transport.postOps({ client: clientId, key: sent.key, ops: sent.ops });
    } catch (e) {
      sending = false;
      if (inflight === sent) failed(e);
      return;
    }
    sending = false;
    if (inflight !== sent) { resolveSend(); return; } // late duplicate of an answer we already handled
    acknowledged(sent, res);
    resolveSend();
    if (queue.length && !destroyed) sendNext();
  }
  function resolveSend() { const d = sendDone; sendDone = []; d.forEach((f) => f()); }

  function failed(err) {
    const c = classify(err);
    st.lastError = { message: String(err?.message || err), status: err?.status ?? null, at: clock.now() };
    if (c.kind === 'rejected') {
      st.failKind = null;
      if (inflight.ops.length > 1) {
        // find the bad op: halve the batch and try again with fresh keys
        maxBatch = Math.max(1, Math.floor(inflight.ops.length / 2));
        queue = [...inflight.ops, ...queue];
        inflight = null;
        persist();
        pushState();
        sendNext();
        return;
      }
      st.fatal = true;
      paused = true;
      pushState();
      resolveSend();
      return;
    }
    if (c.kind === 'auth') { st.needsAuth = true; paused = true; pushState(); resolveSend(); return; }
    st.failKind = c.kind === 'network' ? 'network' : 'server';
    attempts += 1;
    let delay = Math.min(cfg.backoffMaxMs, cfg.backoffBaseMs * 2 ** (attempts - 1));
    delay = Math.round(delay * (0.5 + clock.random() * 0.5));
    if (c.kind === 'throttle' && Number(c.retryAfter) > 0) delay = Math.max(delay, Math.round(Number(c.retryAfter) * 1000));
    st.retryAt = clock.now() + delay;
    retryTimer = clock.setTimeout(() => { retryTimer = null; st.retryAt = null; sendNext(); }, delay);
    pushState();
    resolveSend();
  }

  function acknowledged(sent, res) {
    attempts = 0;
    st.failKind = null; st.lastError = null; st.fatal = false; st.needsAuth = false; st.retryAt = null;
    maxBatch = cfg.maxBatch;
    const map = {};
    for (const [k, v] of Object.entries(res?.ids || {})) if (String(k) !== String(v)) map[String(k)] = String(v);
    const skippedIdx = new Set();
    const list = Array.isArray(res?.skipped) ? res.skipped : [];
    const used = new Set();
    for (const s of list) {
      let i = Number.isInteger(s.index) ? s.index : -1;
      if (i < 0) i = sent.ops.findIndex((o, k) => !used.has(k) && JSON.stringify(o) === JSON.stringify(s.op));
      if (i >= 0) { used.add(i); skippedIdx.add(i); }
    }
    const ops = remapOps(sent.ops, map).filter((_, i) => !skippedIdx.has(i));
    inflight = null;
    queue = remapOps(queue, map);
    settling = settling.map((s) => ({ ...s, ops: remapOps(s.ops, map) }));
    const seq = Number(res?.seq) || 0;
    let nextBase = base;
    if (!ops.length) {
      // every op was skipped: nothing was logged, `seq` is just the current end of the log
      queueMicrotaskSafe(() => pollNow());
    } else if (sent.covered || seq <= base.seq) {
      // the feed or a reload already delivered this batch
    } else if (seq === base.seq + 1) {
      nextBase = { ...applyOps(base, ops).doc, seq };
      nextBase = withRevs(nextBase, res);
    } else {
      settling.push({ seq, ops });
      queueMicrotaskSafe(() => pollNow());
    }
    base = nextBase;
    st.lastSavedAt = clock.now();
    if (Object.keys(map).length) emit('idmap', map);
    if (list.length) emit('skipped', list);
    persist();
    recompute('ack');
    // the server follows a resize with a deck.reload entry: fetch it
    if (sent.ops.some((o) => o?.op === 'deck.resize')) queueMicrotaskSafe(() => pollNow());
  }
  function withRevs(doc, res) {
    if (!res?.slides && res?.deck_rev === undefined) return doc;
    const slides = doc.slides.map((s) => (res.slides && res.slides[s.id] !== undefined ? { ...s, rev: res.slides[s.id] } : s));
    return { ...doc, slides, deck: res.deck_rev !== undefined ? { ...doc.deck, rev: res.deck_rev } : doc.deck };
  }
  const queueMicrotaskSafe = (f) => Promise.resolve().then(() => { if (!destroyed) f(); });

  // ---- remote changes ---------------------------------------------------------------------------
  function applyFeed(changes) {
    let needReload = false;
    let applied = 0;
    for (const ch of [...changes].sort((a, b) => a.seq - b.seq)) {
      if (ch.seq <= base.seq) continue;
      const ops = Array.isArray(ch.ops) ? ch.ops : [];
      if (ops.some((o) => o?.op === 'deck.reload' || o?.op === 'slide.dup')) { needReload = true; break; }
      const own = ch.client === clientId;
      let use = ops;
      if (own) {
        const i = settling.findIndex((s) => s.seq === ch.seq);
        if (i >= 0) { use = settling[i].ops; settling.splice(i, 1); } else if (inflight && !inflight.covered) inflight.covered = true;
      }
      base = { ...applyOps(base, use).doc, seq: ch.seq };
      applied++;
    }
    settling = settling.filter((s) => s.seq > base.seq);
    if (needReload) return reload();
    if (applied) { recompute('remote'); persist(); }
    return Promise.resolve();
  }

  async function reload() {
    if (reloading) return;
    reloading = true;
    try {
      const doc = await transport.getDocument();
      base = createDoc(doc);
      settling = settling.filter((s) => s.seq > base.seq);
      if (inflight) inflight.uncertain = true; // it may or may not be inside the document we just got
      pollFailing = false;
      recompute('reload');
      persist();
    } catch (e) {
      st.lastError = { message: String(e?.message || e), status: e?.status ?? null, at: clock.now() };
      st.failKind = classify(e).kind === 'network' ? 'network' : 'server';
      pushState();
      retryReloadSoon();
    } finally {
      reloading = false;
    }
  }
  function retryReloadSoon() {
    attempts += 1;
    const delay = Math.min(cfg.backoffMaxMs, cfg.backoffBaseMs * 2 ** (attempts - 1));
    clock.setTimeout(() => { if (!destroyed) reload(); }, Math.round(delay * (0.5 + clock.random() * 0.5)));
  }

  async function pollNow() {
    if (destroyed || polling || !base || !online) return;
    polling = true;
    try {
      const r = await transport.getChanges(base.seq);
      if (pollFailing) { pollFailing = false; if (!inflight && !queue.length) { st.failKind = null; st.lastError = null; } }
      if (r?.stale) await reload();
      else await applyFeed(r?.changes || []);
      pushState();
    } catch (e) {
      pollFailing = true;
      if (classify(e).kind === 'network' && !inflight && !queue.length) { st.failKind = 'network'; st.lastError = { message: String(e?.message || e), status: null, at: clock.now() }; }
      pushState();
    } finally {
      polling = false;
    }
  }
  function schedulePoll() {
    if (destroyed || !pollActive || pollTimer) return;
    const ms = focused ? cfg.pollMs.focused : cfg.pollMs.hidden;
    const delay = pollFailing ? Math.min(cfg.backoffMaxMs, ms * 2) : ms;
    pollTimer = clock.setTimeout(async () => {
      pollTimer = null;
      await pollNow();
      schedulePoll();
    }, delay);
  }
  let pollActive = false;

  // ---- public API -------------------------------------------------------------------------------
  const api = {
    init(serverDocument) {
      base = createDoc(serverDocument);
      view = base;
      inflight = null; queue = []; settling = [];
      emit('change', view, { source: 'init' });
      pushState();
      return view;
    },

    dispatch(ops) {
      const list = [];
      let cur = view;
      for (const op of ops) {
        if (op?.op === 'slide.dup') {
          const add = dupToAdd(cur, op);
          if (!add) continue;
          list.push(add);
          cur = applyOps(cur, [add]).doc;
        } else {
          list.push(op);
          cur = applyOps(cur, [op]).doc;
        }
      }
      // The server refuses a slide.add whose tid is a real (all digit) id, which is what undoing a slide
      // removal produces. Re-add under a temporary id and tell the store, which remaps history and selection.
      const alias = {};
      const taken = new Set(view.slides.map((x) => x.id));
      for (const op of list) {
        if (op.op === 'slide.add' && /^\d+$/.test(String(op.tid))) {
          const t = uniqueId(taken, 's');
          taken.add(t);
          alias[String(op.tid)] = t;
        }
      }
      if (Object.keys(alias).length) {
        const rewritten = remapOps(list, alias);
        list.length = 0;
        list.push(...rewritten);
        emit('idmap', alias);
      }
      const result = applyOps(view, list);
      view = result.doc;
      queue.push(...result.applied);
      persist();
      emit('change', view, { source: 'local' });
      pushState();
      scheduleFlush();
      return result;
    },

    flush(o = {}) {
      clearFlushTimers();
      if (o.unloading) {
        const all = [...(inflight ? inflight.ops : []), ...queue];
        if (all.length && typeof transport.beacon === 'function') {
          if (!inflight) { inflight = { key: uuid(), ops: takeBatch(), covered: false, uncertain: false }; persist(); }
          try { transport.beacon({ client: clientId, key: inflight.key, ops: inflight.ops }); } catch { /* the persisted copy is the fallback */ }
        }
        return Promise.resolve();
      }
      const done = new Promise((resolve) => sendDone.push(resolve));
      sendNext();
      return done;
    },
    blur() { return api.flush(); },
    /** Resolves when nothing is pending, or saving is paused by an error. */
    async settled() {
      for (let i = 0; i < 1000; i++) {
        if ((!inflight && !queue.length) || paused) return;
        if (retryTimer) return; // waiting for a retry: the caller advances the clock
        await api.flush();
      }
    },
    retry() {
      paused = false; st.fatal = false; st.needsAuth = false; attempts = 0; maxBatch = cfg.maxBatch;
      if (retryTimer) { clock.clearTimeout(retryTimer); retryTimer = null; st.retryAt = null; }
      pushState();
      return api.flush();
    },
    /** Drop the batch the server keeps refusing. Returns the dropped ops so the shell can say so. */
    discardInflight() {
      const dropped = inflight ? inflight.ops : [];
      inflight = null; paused = false; st.fatal = false; st.lastError = null; st.failKind = null; maxBatch = cfg.maxBatch;
      persist();
      recompute('discard');
      if (queue.length) sendNext();
      return dropped;
    },
    setFocused(f) {
      focused = !!f;
      if (pollActive && pollTimer) { clock.clearTimeout(pollTimer); pollTimer = null; schedulePoll(); }
      if (focused) pollNow();
    },
    setOnline(o) {
      online = !!o;
      pushState();
      if (online) { if (retryTimer) { clock.clearTimeout(retryTimer); retryTimer = null; } attempts = 0; sendNext(); pollNow(); }
    },
    startPolling() { pollActive = true; if (!pollTimer) schedulePoll(); },
    stopPolling() { pollActive = false; if (pollTimer) { clock.clearTimeout(pollTimer); pollTimer = null; } },
    pollNow,
    reload,

    /** Unsent changes left by a closed or crashed tab, or null. This tab's own queue is not an earlier session (self: true reads it, for tests). */
    async recover({ self = false } = {}) {
      const best = await store.read(clock.now(), cfg.aliveMs, { self });
      if (!best) return null;
      const ops = [...(best.inflight ? best.inflight.ops : []), ...best.queue];
      if (!ops.length) return null;
      return { ops, baseSeq: best.baseSeq, inflight: best.inflight, queue: best.queue, clientId: best.client, savedAt: best.savedAt };
    },
    /** Put recovered changes back into the pending queue (call after init). */
    async restore(rec) {
      if (!rec) return 0;
      if (rec.inflight && !inflight) inflight = { key: rec.inflight.key, ops: rec.inflight.ops, covered: false, uncertain: false };
      else if (rec.inflight) queue = [...rec.inflight.ops, ...queue];
      queue = [...queue, ...rec.queue];
      if (rec.clientId && rec.clientId !== clientId) await store.forget(rec.clientId);
      persist();
      recompute('restore');
      sendNext();
      return rec.ops.length;
    },
    async discardRecovery(rec) { if (rec) await store.forget(rec.clientId); },
    persisted: () => store.settled(),

    getView: () => view,
    getBase: () => base,
    get state() { return stateNow(); },
    pendingOps: () => [...(inflight ? inflight.ops : []), ...queue],
    onChange: sub('change'),
    onState: sub('state'),
    onIdMap: sub('idmap'),
    onSkipped: sub('skipped'),
    destroy() {
      destroyed = true;
      for (const t of [debounceTimer, maxTimer, retryTimer, pollTimer, hbTimer]) if (t) clock.clearTimeout(t);
      resolveSend();
    },
  };
  return api;
}
