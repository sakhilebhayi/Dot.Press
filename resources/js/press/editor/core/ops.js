/**
 * applyOps: the pure, immutable implementation of every operation in spec section 8.
 * The server (PHP OpApplier) implements the same semantics; tests/fixtures/press-ops holds the
 * shared test vectors that both sides must pass.
 *
 *   applyOps(doc, ops, opts?) -> { doc, applied, inverse, guards, skipped }
 *
 *   doc       the new document (the SAME object when nothing changed; untouched slides and
 *             elements keep their identity: structural sharing)
 *   applied   the ops that were applied, in order
 *   inverse   an op batch that exactly undoes `applied` (apply it to the result to get `doc` back).
 *             The ops are valid server ops: undo is sent like any other edit
 *   guards    array parallel to `inverse`: null, or {expect: {key: value}} for inverse ops that
 *             patch (el.set, slide.set, deck.set). `expect` is the value each key had right after
 *             the forward op. history.js drops keys whose current value differs, so undo never
 *             overwrites a change somebody else made in the meantime
 *   skipped   [{index, op, reason}] ops that were not applied. Never thrown.
 *   opts      { makeId(taken: Set, prefix) } id source for slide.dup (tests inject a counter)
 *
 * Skip reasons: unknown_op, invalid, forbidden (deck.reload from a client, status approved), use_deck_resize, missing_slide,
 * missing_element, missing_section, duplicate_id, last_slide, limit.
 *
 * Semantics worth knowing (the PHP side must match):
 *  - Missing key `after` = at the end, `after: null` = first, an unknown id = at the end.
 *    slide.dup without `after` = directly after the source slide.
 *  - el.set `p`: a key set to null deletes the key; `id` and `type` can never be patched. Keys whose
 *    value would not change are not applied and not recorded in the inverse.
 *  - el.order: ids not in the slide are ignored; elements missing from the list stay on top in
 *    their old order.
 *  - slide.set only accepts title, notes, section_id, hidden, layout, bg, transition. deck.set accepts
 *    title, theme, status. A deck.set that carries w or h is skipped entirely (reason use_deck_resize:
 *    sizes change only through deck.resize), and so is one that sets status 'approved' (forbidden).
 *  - deck.resize needs w and h within 320..7680 and a mode in scale, anchor, fit (see resize.js), else
 *    it is skipped as invalid. The server logs a deck.reload after a resize; clients reload.
 *  - slide.add needs a `tid` that is not all digits (digits are real database ids). The server is strict;
 *    here the rule applies only with opts.strictTid (the shared vectors use it), because the client
 *    legitimately holds real ids (undoing a slide removal re-adds a slide under its old real id). sync.js
 *    swaps such a tid for a temporary id before anything is sent.
 *  - slide.dup gives the copy fresh element and group ids; an element without `morph` gets
 *    morph = the source element id so a morph transition between a slide and its copy still pairs.
 *  - Limits: 600 slides, 400 elements per slide.
 */
import { clone, deepEqual, isObj, ID_RE, uniqueId } from './util.js';
import { resizeDoc, validResize } from './resize.js';

export const MAX_SLIDES = 600;
export const MAX_ELS = 400;
const SLIDE_KEYS = ['title', 'notes', 'section_id', 'hidden', 'layout', 'bg', 'transition'];
const DECK_KEYS = ['title', 'theme', 'status'];
const STATUSES = ['draft', 'in_review', 'approved']; // 'approved' is valid but refused in deck.set
const EL_TYPES = ['text', 'shape', 'image', 'video', 'audio', 'icon', 'chart', 'table'];

const has = (o, k) => Object.prototype.hasOwnProperty.call(o, k);
const sid = (v) => (v === null || v === undefined ? null : String(v));
const validSlideKeyValue = {
  title: (v) => typeof v === 'string',
  notes: (v) => typeof v === 'string',
  section_id: (v) => v === null || typeof v === 'string',
  hidden: (v) => typeof v === 'boolean',
  layout: (v) => typeof v === 'string' && v !== '',
  bg: (v) => v === null || isObj(v) || typeof v === 'string',
  transition: (v) => v === null || isObj(v),
};
const validDeckKeyValue = {
  title: (v) => typeof v === 'string',
  theme: (v) => isObj(v),
  status: (v) => STATUSES.includes(v),
};

const skip = (reason) => ({ skip: reason });
const done = (doc, inv, guards) => ({ doc, inv, guards: guards || inv.map(() => null) });

function withSlides(doc, slides) {
  return { ...doc, slides, deck: { ...doc.deck, slide_order: slides.map((s) => s.id) } };
}
function replaceSlide(doc, idx, slide) {
  const slides = doc.slides.slice();
  slides[idx] = slide;
  return { ...doc, slides };
}
const slideIdx = (doc, id) => doc.slides.findIndex((s) => s.id === id);

/** Insert position for `after` semantics over a list of ids. */
function insertAt(ids, op, fallback) {
  if (!has(op, 'after')) return fallback === undefined ? ids.length : fallback;
  if (op.after === null) return 0;
  const i = ids.indexOf(sid(op.after));
  return i < 0 ? ids.length : i + 1;
}

function cleanEls(els) {
  const out = [];
  const seen = new Set();
  for (const el of Array.isArray(els) ? els : []) {
    if (!isObj(el) || typeof el.id !== 'string' || !ID_RE.test(el.id) || seen.has(el.id) || !EL_TYPES.includes(el.type)) continue;
    seen.add(el.id);
    out.push(clone(el));
  }
  return out;
}

// ---- handlers: (doc, op, ctx) -> {doc, inv, guards} | {skip} ----------------------------------

const H = {};

H['deck.set'] = (doc, op) => {
  if (has(op, 'w') || has(op, 'h')) return skip('use_deck_resize');
  if (op.status === 'approved') return skip('forbidden');
  const patch = {};
  const inv = {};
  let valid = 0;
  for (const k of DECK_KEYS) {
    if (!has(op, k) || !validDeckKeyValue[k](op[k])) continue;
    valid++;
    if (deepEqual(op[k], doc.deck[k])) continue;
    patch[k] = clone(op[k]);
    inv[k] = clone(doc.deck[k] ?? null);
  }
  if (!valid) return skip('invalid');
  if (!Object.keys(patch).length) return done(doc, []);
  return done({ ...doc, deck: { ...doc.deck, ...patch } }, [{ op: 'deck.set', ...inv }], [{ expect: clone(patch) }]);
};

H['deck.resize'] = (doc, op) => {
  const w = Number(op.w);
  const h = Number(op.h);
  if (!validResize(w, h, op.mode)) return skip('invalid');
  const oldW = doc.deck.w;
  const oldH = doc.deck.h;
  const next = resizeDoc(doc, w, h, op.mode);
  // Exact inverse: resize back, then restore every value the way-back geometry does not reproduce.
  const back = resizeDoc(next, oldW, oldH, 'scale');
  const inv = [{ op: 'deck.resize', w: oldW, h: oldH, mode: 'scale' }];
  const guards = [null];
  doc.slides.forEach((slide, i) => {
    slide.els.forEach((el, j) => {
      const mid = back.slides[i].els[j];
      const p = {};
      const expect = {};
      for (const k of new Set([...Object.keys(el), ...Object.keys(mid)])) {
        if (deepEqual(el[k], mid[k])) continue;
        p[k] = has(el, k) ? clone(el[k]) : null;
        expect[k] = has(mid, k) ? clone(mid[k]) : null;
      }
      if (Object.keys(p).length) {
        inv.push({ op: 'el.set', slide: slide.id, id: el.id, p });
        guards.push({ expect });
      }
    });
  });
  return done(next, inv, guards);
};

H['section.add'] = (doc, op) => {
  if (typeof op.id !== 'string' || !ID_RE.test(op.id)) return skip('invalid');
  if (doc.deck.sections.some((s) => s.id === op.id)) return skip('duplicate_id');
  const title = typeof op.title === 'string' ? op.title : 'Untitled section';
  const secs = doc.deck.sections.slice();
  secs.splice(insertAt(secs.map((s) => s.id), op, secs.length), 0, { id: op.id, title });
  return done({ ...doc, deck: { ...doc.deck, sections: secs } }, [{ op: 'section.remove', id: op.id }]);
};

H['section.set'] = (doc, op) => {
  const i = doc.deck.sections.findIndex((s) => s.id === op.id);
  if (i < 0) return skip('missing_section');
  if (typeof op.title !== 'string') return skip('invalid');
  const old = doc.deck.sections[i];
  if (old.title === op.title) return done(doc, []);
  const secs = doc.deck.sections.slice();
  secs[i] = { ...old, title: op.title };
  return done({ ...doc, deck: { ...doc.deck, sections: secs } }, [{ op: 'section.set', id: op.id, title: old.title }],
    [{ expect: { title: op.title } }]);
};

H['section.remove'] = (doc, op) => {
  const i = doc.deck.sections.findIndex((s) => s.id === op.id);
  if (i < 0) return skip('missing_section');
  const old = doc.deck.sections[i];
  const secs = doc.deck.sections.filter((_, k) => k !== i);
  const inv = [{ op: 'section.add', id: old.id, title: old.title, after: i === 0 ? null : doc.deck.sections[i - 1].id }];
  let slides = doc.slides;
  const members = [];
  slides = slides.map((s) => {
    if (s.section_id !== old.id) return s;
    members.push(s.id);
    return { ...s, section_id: null };
  });
  for (const id of members) inv.push({ op: 'slide.set', id, section_id: old.id });
  const next = { ...doc, deck: { ...doc.deck, sections: secs }, slides: members.length ? slides : doc.slides };
  return done(next, inv);
};

H['slide.add'] = (doc, op, ctx) => {
  const tid = sid(op.tid);
  if (!tid || (/^\d+$/.test(tid) && ctx.strictTid)) return skip('invalid');
  if (slideIdx(doc, tid) >= 0) return skip('duplicate_id');
  if (doc.slides.length >= MAX_SLIDES) return skip('limit');
  const src = isObj(op.slide) ? op.slide : {};
  const els = cleanEls(src.els);
  if (els.length > MAX_ELS) return skip('limit');
  const slide = {
    title: '', notes: '', section_id: null, hidden: false, layout: 'blank', bg: null, transition: null, rev: 0, els,
    id: tid,
  };
  for (const k of SLIDE_KEYS) if (has(src, k) && validSlideKeyValue[k](src[k])) slide[k] = clone(src[k]);
  const slides = doc.slides.slice();
  slides.splice(insertAt(slides.map((s) => s.id), op), 0, slide);
  return done(withSlides(doc, slides), [{ op: 'slide.remove', id: tid }]);
};

H['slide.set'] = (doc, op) => {
  const id = sid(op.id);
  const i = slideIdx(doc, id);
  if (i < 0) return skip('missing_slide');
  const slide = doc.slides[i];
  const patch = {};
  const inv = {};
  let valid = 0;
  for (const k of SLIDE_KEYS) {
    if (!has(op, k) || !validSlideKeyValue[k](op[k])) continue;
    valid++;
    if (deepEqual(op[k], slide[k])) continue;
    patch[k] = clone(op[k]);
    inv[k] = clone(slide[k] ?? null);
  }
  if (!valid) return skip('invalid');
  if (!Object.keys(patch).length) return done(doc, []);
  return done(replaceSlide(doc, i, { ...slide, ...patch }), [{ op: 'slide.set', id, ...inv }], [{ expect: clone(patch) }]);
};

H['slide.move'] = (doc, op) => {
  const id = sid(op.id);
  const i = slideIdx(doc, id);
  if (i < 0) return skip('missing_slide');
  const ids = doc.slides.map((s) => s.id);
  const rest = ids.filter((x) => x !== id);
  if (has(op, 'after') && sid(op.after) === id) return done(doc, []);
  const at = insertAt(rest, op);
  const order = rest.slice();
  order.splice(at, 0, id);
  if (order.every((x, k) => x === ids[k])) return done(doc, []);
  const slides = order.map((x) => doc.slides[ids.indexOf(x)]);
  return done(withSlides(doc, slides), [{ op: 'slide.move', id, after: i === 0 ? null : ids[i - 1] }]);
};

H['slide.remove'] = (doc, op) => {
  const id = sid(op.id);
  const i = slideIdx(doc, id);
  if (i < 0) return skip('missing_slide');
  if (doc.slides.length <= 1) return skip('last_slide');
  const slide = doc.slides[i];
  const slides = doc.slides.filter((_, k) => k !== i);
  const { id: _omit, rev: _rev, ...fields } = slide;
  return done(withSlides(doc, slides), [{
    op: 'slide.add', tid: id, after: i === 0 ? null : doc.slides[i - 1].id, slide: clone(fields),
  }]);
};

H['slide.dup'] = (doc, op, ctx) => {
  const id = sid(op.id);
  const tid = sid(op.tid);
  const i = slideIdx(doc, id);
  if (i < 0) return skip('missing_slide');
  if (!tid) return skip('invalid');
  if (slideIdx(doc, tid) >= 0) return skip('duplicate_id');
  if (doc.slides.length >= MAX_SLIDES) return skip('limit');
  const src = doc.slides[i];
  const taken = new Set();
  for (const el of src.els) { taken.add(el.id); if (el.group) taken.add(el.group); }
  const groupMap = new Map();
  const els = src.els.map((el) => {
    const copy = clone(el);
    const nid = ctx.makeId(taken, 'e');
    taken.add(nid);
    copy.id = nid;
    if (copy.morph === undefined) copy.morph = el.id;
    if (el.group) {
      if (!groupMap.has(el.group)) {
        const g = ctx.makeId(taken, 'g');
        taken.add(g);
        groupMap.set(el.group, g);
      }
      copy.group = groupMap.get(el.group);
    }
    return copy;
  });
  const slide = { ...clone(src), id: tid, rev: 0, els };
  const slides = doc.slides.slice();
  slides.splice(insertAt(slides.map((s) => s.id), op, i + 1), 0, slide);
  return done(withSlides(doc, slides), [{ op: 'slide.remove', id: tid }]);
};

const EL_ID = (v) => (typeof v === 'string' ? v : null);

H['el.add'] = (doc, op) => {
  const slideId = sid(op.slide);
  const i = slideIdx(doc, slideId);
  if (i < 0) return skip('missing_slide');
  const el = op.el;
  if (!isObj(el) || !EL_ID(el.id) || !ID_RE.test(el.id) || !EL_TYPES.includes(el.type)) return skip('invalid');
  const slide = doc.slides[i];
  if (slide.els.some((e) => e.id === el.id)) return skip('duplicate_id');
  if (slide.els.length >= MAX_ELS) return skip('limit');
  const els = slide.els.slice();
  const at = Number.isInteger(op.index) ? Math.min(Math.max(op.index, 0), els.length) : els.length;
  els.splice(at, 0, clone(el));
  return done(replaceSlide(doc, i, { ...slide, els }), [{ op: 'el.remove', slide: slideId, id: el.id }]);
};

H['el.set'] = (doc, op) => {
  const slideId = sid(op.slide);
  const i = slideIdx(doc, slideId);
  if (i < 0) return skip('missing_slide');
  const slide = doc.slides[i];
  const j = slide.els.findIndex((e) => e.id === op.id);
  if (j < 0) return skip('missing_element');
  if (!isObj(op.p)) return skip('invalid');
  const el = slide.els[j];
  const next = { ...el };
  const inv = {};
  const expect = {};
  for (const k of Object.keys(op.p)) {
    if (k === 'id' || k === 'type') continue;
    const v = op.p[k];
    if (v === null) {
      if (!has(el, k)) continue;
      delete next[k];
      inv[k] = clone(el[k]);
      expect[k] = null;
    } else {
      if (has(el, k) && deepEqual(el[k], v)) continue;
      next[k] = clone(v);
      inv[k] = has(el, k) ? clone(el[k]) : null;
      expect[k] = clone(v);
    }
  }
  if (!Object.keys(inv).length) return done(doc, []);
  const els = slide.els.slice();
  els[j] = next;
  return done(replaceSlide(doc, i, { ...slide, els }), [{ op: 'el.set', slide: slideId, id: op.id, p: inv }], [{ expect }]);
};

H['el.remove'] = (doc, op) => {
  const slideId = sid(op.slide);
  const i = slideIdx(doc, slideId);
  if (i < 0) return skip('missing_slide');
  const slide = doc.slides[i];
  const j = slide.els.findIndex((e) => e.id === op.id);
  if (j < 0) return skip('missing_element');
  const els = slide.els.filter((_, k) => k !== j);
  return done(replaceSlide(doc, i, { ...slide, els }), [{ op: 'el.add', slide: slideId, el: clone(slide.els[j]), index: j }]);
};

H['el.order'] = (doc, op) => {
  const slideId = sid(op.slide);
  const i = slideIdx(doc, slideId);
  if (i < 0) return skip('missing_slide');
  if (!Array.isArray(op.ids)) return skip('invalid');
  const slide = doc.slides[i];
  const byId = new Map(slide.els.map((e) => [e.id, e]));
  const seen = new Set();
  const order = [];
  for (const id of op.ids) {
    if (typeof id === 'string' && byId.has(id) && !seen.has(id)) { seen.add(id); order.push(byId.get(id)); }
  }
  for (const e of slide.els) if (!seen.has(e.id)) order.push(e);
  if (order.every((e, k) => e === slide.els[k])) return done(doc, []);
  return done(replaceSlide(doc, i, { ...slide, els: order }),
    [{ op: 'el.order', slide: slideId, ids: slide.els.map((e) => e.id) }]);
};

H['el.move'] = (doc, op) => {
  const from = sid(op.from);
  const to = sid(op.to);
  const fi = slideIdx(doc, from);
  const ti = slideIdx(doc, to);
  if (fi < 0 || ti < 0) return skip('missing_slide');
  const src = doc.slides[fi];
  const j = src.els.findIndex((e) => e.id === op.id);
  if (j < 0) return skip('missing_element');
  const el = src.els[j];
  const srcEls = src.els.filter((_, k) => k !== j);
  if (fi === ti) {
    const at = Number.isInteger(op.index) ? Math.min(Math.max(op.index, 0), srcEls.length) : srcEls.length;
    srcEls.splice(at, 0, el);
    if (at === j) return done(doc, []);
    return done(replaceSlide(doc, fi, { ...src, els: srcEls }), [{ op: 'el.move', from, id: el.id, to: from, index: j }]);
  }
  const dst = doc.slides[ti];
  if (dst.els.some((e) => e.id === el.id)) return skip('duplicate_id');
  if (dst.els.length >= MAX_ELS) return skip('limit');
  const dstEls = dst.els.slice();
  const at = Number.isInteger(op.index) ? Math.min(Math.max(op.index, 0), dstEls.length) : dstEls.length;
  dstEls.splice(at, 0, el);
  const slides = doc.slides.slice();
  slides[fi] = { ...src, els: srcEls };
  slides[ti] = { ...dst, els: dstEls };
  return done({ ...doc, slides }, [{ op: 'el.move', from: to, id: el.id, to: from, index: j }]);
};

H['deck.reload'] = () => skip('forbidden');

// ---- the entry point --------------------------------------------------------------------------

export function applyOps(doc, ops, opts = {}) {
  const ctx = { makeId: opts.makeId || ((taken, prefix) => uniqueId(taken, prefix)), strictTid: !!opts.strictTid };
  let cur = doc;
  const applied = [];
  const skipped = [];
  const batches = [];
  (Array.isArray(ops) ? ops : []).forEach((op, index) => {
    if (!isObj(op) || typeof op.op !== 'string') { skipped.push({ index, op, reason: 'invalid' }); return; }
    const handler = has(H, op.op) ? H[op.op] : null;
    if (!handler) { skipped.push({ index, op, reason: 'unknown_op' }); return; }
    const r = handler(cur, op, ctx);
    if (r.skip) { skipped.push({ index, op, reason: r.skip }); return; }
    cur = r.doc;
    applied.push(op);
    batches.push(r);
  });
  const inverse = [];
  const guards = [];
  for (let k = batches.length - 1; k >= 0; k--) {
    inverse.push(...batches[k].inv);
    guards.push(...batches[k].guards);
  }
  return { doc: cur, applied, inverse, guards, skipped };
}

/**
 * Turn a slide.dup op into the equivalent slide.add with explicit elements, using client generated
 * ids. The client must send THIS form, never slide.dup: the server would invent different element ids
 * and later edits of those elements would miss. Returns null when the source slide does not exist.
 */
export function dupToAdd(doc, op, opts = {}) {
  const makeId = opts.makeId || ((taken, prefix) => uniqueId(taken, prefix));
  const r = applyOps(doc, [op], { makeId });
  if (r.skipped.length) return null;
  const tid = sid(op.tid);
  const copy = r.doc.slides.find((s) => s.id === tid);
  const { id: _id, rev: _rev, ...fields } = copy;
  return { op: 'slide.add', tid, after: has(op, 'after') ? op.after : sid(op.id), slide: clone(fields) };
}

// ---- id remapping (temporary slide ids -> real ids) -------------------------------------------

const SLIDE_FIELDS = {
  'slide.add': ['tid', 'after'],
  'slide.set': ['id'],
  'slide.move': ['id', 'after'],
  'slide.remove': ['id'],
  'slide.dup': ['id', 'tid', 'after'],
  'el.add': ['slide'],
  'el.set': ['slide'],
  'el.remove': ['slide'],
  'el.order': ['slide'],
  'el.move': ['from', 'to'],
};

/** Rewrite slide ids in ops using `map` {oldId: newId}. Returns new ops, input untouched. */
export function remapOps(ops, map) {
  if (!map || !Object.keys(map).length) return ops;
  return ops.map((op) => {
    const fields = SLIDE_FIELDS[op?.op];
    if (!fields) return op;
    let out = op;
    for (const f of fields) {
      if (op[f] !== null && op[f] !== undefined && has(map, String(op[f]))) {
        if (out === op) out = { ...op };
        out[f] = map[String(op[f])];
      }
    }
    return out;
  });
}

/** Rewrite slide ids in a document. */
export function remapDoc(doc, map) {
  if (!map || !Object.keys(map).length) return doc;
  let changed = false;
  const slides = doc.slides.map((s) => {
    if (!has(map, s.id)) return s;
    changed = true;
    return { ...s, id: String(map[s.id]) };
  });
  return changed ? withSlides(doc, slides) : doc;
}
