// Fakes for the sync tests: a deterministic clock, an in-memory storage and a small server that
// really applies ops (with the server rules: strict tids, deck.reload after a resize).
import { applyOps, remapOps, remapDoc } from '../ops.js';
import { clone } from '../util.js';

export function fakeClock(start = 1_000_000) {
  let t = start;
  let nextId = 1;
  const timers = new Map();
  const flush = async () => { for (let i = 0; i < 30; i++) await Promise.resolve(); };
  return {
    now: () => t,
    random: () => 0.5, // jitter factor 0.75
    setTimeout(fn, ms) { const id = nextId++; timers.set(id, { at: t + ms, fn, id }); return id; },
    clearTimeout(id) { timers.delete(id); },
    pending: () => timers.size,
    /** Move time forward, firing timers in order and letting promises settle after each. */
    async advance(ms) {
      const end = t + ms;
      await flush();
      for (;;) {
        const due = [...timers.values()].filter((x) => x.at <= end).sort((a, b) => a.at - b.at || a.id - b.id)[0];
        if (!due) break;
        timers.delete(due.id);
        t = Math.max(t, due.at);
        due.fn();
        await flush();
      }
      t = end;
      await flush();
    },
    flush,
  };
}

export function fakeStorage() {
  const m = new Map();
  return {
    m,
    get: (k) => (m.has(k) ? m.get(k) : null),
    set: (k, v) => { m.set(k, v); },
    remove: (k) => { m.delete(k); },
  };
}

export function netError(msg = 'offline') { const e = new Error(msg); e.network = true; return e; }
export function httpError(status, extra = {}) { const e = new Error(`http ${status}`); e.status = status; Object.assign(e, extra); return e; }

export function fakeServer(initial) {
  const s = {
    doc: clone(initial), seq: 0, log: [], keys: new Map(), nextSlide: 100, calls: [], pollCalls: 0,
    failures: [], // queued {when:'before'|'after', error}
    hold: null, // when set, postOps waits for hold.promise before answering
    minSeq: 0, // changes at or below this are "no longer in the log"
  };
  const slim = (d) => ({ deck: { ...d.deck, slide_order: d.slides.map((x) => x.id) }, slides: d.slides, seq: s.seq });
  function process({ client, key, ops }) {
    if (s.keys.has(key)) return s.keys.get(key);
    const ids = {};
    let work = ops;
    for (const op of ops) {
      if ((op.op === 'slide.add' || op.op === 'slide.dup') && !/^\d+$/.test(String(op.tid))) {
        const real = String(++s.nextSlide);
        ids[op.tid] = real;
      }
    }
    const r = applyOps(s.doc, work, { strictTid: true });
    r.doc = remapDoc(r.doc, ids);
    r.applied = remapOps(r.applied, ids);
    // The real server logs a slide.add with the stored id next to the author's temporary tid (DeckBatch::slideAdd).
    const tidOf = Object.fromEntries(Object.entries(ids).map(([temp, real]) => [real, temp]));
    r.applied = r.applied.map((o) => (o.op === 'slide.add' && tidOf[String(o.tid)] ? { ...o, id: String(o.tid), tid: tidOf[String(o.tid)] } : o));
    if (!r.applied.length) {
      const res = { seq: s.seq, deck_rev: 1, slides: {}, ids: {}, skipped: r.skipped.map((x) => ({ op: x.op, reason: x.reason })) };
      s.keys.set(key, res);
      return res;
    }
    s.doc = r.doc;
    s.seq += 1;
    const entry = { seq: s.seq, client, user: 1, ops: clone(r.applied.flatMap((o) => (o.op === 'deck.resize' ? [o, { op: 'deck.reload' }] : [o]))) };
    s.log.push(entry);
    const res = {
      seq: s.seq, deck_rev: s.seq, slides: Object.fromEntries(r.doc.slides.map((x) => [x.id, 1])), ids,
      skipped: r.skipped.map((x) => ({ op: x.op, reason: x.reason })),
    };
    s.keys.set(key, res);
    return res;
  }
  s.transport = {
    async postOps(req) {
      s.calls.push(clone(req));
      const f = s.failures[0];
      if (f && f.when === 'before') { s.failures.shift(); throw f.error; }
      if (s.hold) await s.hold.promise;
      const res = process(req);
      const g = s.failures[0];
      if (g && g.when === 'after') { s.failures.shift(); throw g.error; }
      return clone(res);
    },
    async getChanges(after) {
      s.pollCalls++;
      if (s.pollFail) throw s.pollFail;
      if (after < s.minSeq) return { stale: true };
      return { seq: s.seq, changes: clone(s.log.filter((c) => c.seq > after)) };
    },
    async getDocument() { return clone(slim(s.doc)); },
  };
  /** Another client's batch. */
  s.remote = (ops, client = 'other') => {
    const key = `remote-${s.keys.size}-${Math.random()}`;
    return process({ client, key, ops });
  };
  s.failNext = (error, when = 'before', n = 1) => { for (let i = 0; i < n; i++) s.failures.push({ error, when }); };
  s.holdResponses = () => { let release; const promise = new Promise((r) => { release = r; }); s.hold = { promise, release }; return () => { s.hold = null; release(); }; };
  s.snapshot = () => slim(s.doc);
  return s;
}
