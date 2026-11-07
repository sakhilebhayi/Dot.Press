/**
 * Selection model. A selection is the immutable value `{ slide: slideId|null, ids: [elementId] }`
 * (ids in z-order, bottom first). Every function takes the document so that groups expand to all
 * their members and ids that no longer exist are dropped.
 *
 * Canvas picking (click, marquee, select all, Tab) only sees PICKABLE elements: not hidden and not
 * locked. The layer list uses `layers()` which keeps locked and hidden elements, and may select them
 * with `select()`.
 *
 * API
 *   emptySelection(slideId?)
 *   isPickable(el)
 *   expandGroups(slide, ids) -> ids   members of every group touched, z-order
 *   select(doc, sel, slideId, ids, mode = 'replace', {members})   mode: replace | add | remove | toggle;
 *        members: true selects exactly the ids given, without expanding to their groups (double click into a group)
 *   clickSelect(doc, sel, slideId, elId, {shift, meta})   canvas / layer click semantics
 *   pickAt(doc, slideId, point, {pad}) -> elementId|null   topmost pickable element under the point
 *   marquee(doc, slideId, rect, {contain}) -> ids          pickable elements touched (or contained)
 *   selectAll(doc, slideId)
 *   selectStep(doc, sel, dir)    dir +1 next in z-order (up), -1 previous; wraps; from empty selects top/bottom
 *   prune(doc, sel)              drop ids that no longer exist
 *   selected(doc, sel) -> elements
 *   selectionBounds(doc, sel) -> {x, y, w, h, rot, single} | null
 *        one element: its own box with its rotation (handles follow the rotation).
 *        several: the axis aligned union of the rotated members (rot 0).
 *   layers(slide) -> [{id, name, type, lock, hide, group, role}] top first
 */
import { pointInBox, boxIntersectsRect, boxInsideRect, rotatedBounds, unionBounds } from './geometry.js';

export const emptySelection = (slide = null) => ({ slide, ids: [] });
export const isPickable = (el) => !el.hide && !el.lock;

const slideById = (doc, id) => doc.slides.find((s) => s.id === String(id));

export function expandGroups(slide, ids) {
  const want = new Set(ids);
  const groups = new Set();
  for (const el of slide.els) if (want.has(el.id) && el.group) groups.add(el.group);
  return slide.els.filter((el) => want.has(el.id) || (el.group && groups.has(el.group))).map((el) => el.id);
}

function same(a, b) {
  return a.slide === b.slide && a.ids.length === b.ids.length && a.ids.every((x, i) => x === b.ids[i]);
}

export function select(doc, sel, slideId, ids, mode = 'replace', { members = false } = {}) {
  const slide = slideById(doc, slideId);
  if (!slide) return emptySelection(sel.slide);
  const sid = slide.id;
  const base = sel.slide === sid ? sel.ids : [];
  const present = ids.filter((id) => slide.els.some((e) => e.id === id));
  // members: true selects exactly these elements and does not expand to their groups (entering a group)
  const incoming = members ? slide.els.filter((e) => present.includes(e.id)).map((e) => e.id) : expandGroups(slide, present);
  let next;
  if (mode === 'replace') next = incoming;
  else if (mode === 'add') next = [...new Set([...base, ...incoming])];
  else if (mode === 'remove') next = base.filter((id) => !incoming.includes(id));
  else {
    // toggle: if every incoming id is already selected, remove them, otherwise add them
    const all = incoming.length > 0 && incoming.every((id) => base.includes(id));
    next = all ? base.filter((id) => !incoming.includes(id)) : [...new Set([...base, ...incoming])];
  }
  const order = slide.els.map((e) => e.id);
  next = order.filter((id) => next.includes(id));
  const out = { slide: sid, ids: next };
  return same(out, sel) ? sel : out;
}

export function clickSelect(doc, sel, slideId, elId, { shift = false, meta = false } = {}) {
  if (elId == null) return shift || meta ? sel : select(doc, sel, slideId, []);
  return select(doc, sel, slideId, [elId], shift || meta ? 'toggle' : 'replace');
}

export function pickAt(doc, slideId, point, { pad = 4 } = {}) {
  const slide = slideById(doc, slideId);
  if (!slide) return null;
  for (let i = slide.els.length - 1; i >= 0; i--) {
    const el = slide.els[i];
    if (!isPickable(el)) continue;
    // thin shapes (lines) get a larger target
    const thin = Math.min(el.w, el.h) < pad * 2;
    if (pointInBox(el, point, thin ? pad * 1.5 : 0)) return el.id;
  }
  return null;
}

export function marquee(doc, slideId, rect, { contain = false } = {}) {
  const slide = slideById(doc, slideId);
  if (!slide) return [];
  const hits = slide.els.filter((el) => isPickable(el)
    && (contain ? boxInsideRect(el, rect) : boxIntersectsRect(el, rect))).map((e) => e.id);
  return expandGroups(slide, hits);
}

export function selectAll(doc, slideId) {
  const slide = slideById(doc, slideId);
  if (!slide) return emptySelection();
  return { slide: slide.id, ids: slide.els.filter(isPickable).map((e) => e.id) };
}

export function selectStep(doc, sel, dir = 1) {
  const slide = slideById(doc, sel.slide);
  if (!slide) return sel;
  const list = slide.els.filter(isPickable);
  if (!list.length) return emptySelection(slide.id);
  let i;
  if (!sel.ids.length) i = dir > 0 ? 0 : list.length - 1;
  else {
    const cur = dir > 0 ? Math.max(...sel.ids.map((id) => list.findIndex((e) => e.id === id)))
      : Math.min(...sel.ids.map((id) => list.findIndex((e) => e.id === id)));
    i = (cur + dir + list.length) % list.length;
  }
  return select(doc, sel, slide.id, [list[i].id]);
}

export function prune(doc, sel) {
  const slide = slideById(doc, sel.slide);
  if (!slide) return emptySelection(null);
  const ids = sel.ids.filter((id) => slide.els.some((e) => e.id === id));
  return ids.length === sel.ids.length ? sel : { slide: slide.id, ids };
}

export function selected(doc, sel) {
  const slide = slideById(doc, sel.slide);
  if (!slide) return [];
  const want = new Set(sel.ids);
  return slide.els.filter((e) => want.has(e.id));
}

export function selectionBounds(doc, sel) {
  const els = selected(doc, sel);
  if (!els.length) return null;
  if (els.length === 1) {
    const e = els[0];
    return { x: e.x, y: e.y, w: e.w, h: e.h, rot: e.rot || 0, single: true };
  }
  const u = unionBounds(els.map((e) => rotatedBounds(e)));
  return { ...u, rot: 0, single: false };
}

export function layers(slide) {
  return slide.els.slice().reverse().map((e) => ({
    id: e.id, name: e.name || e.type, type: e.type, lock: !!e.lock, hide: !!e.hide, group: e.group || null, role: e.role || null,
  }));
}
