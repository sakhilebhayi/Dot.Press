/**
 * Pure planners that turn an intent on a slide into ops: z-order, grouping, lock and hide,
 * deleting, moving. The store calls these; they are tested on their own.
 *
 *   reorderIds(els, ids, how)        how: 'forward' | 'backward' | 'front' | 'back'; new id list or null
 *   layerOps(slide, ids, how)        [el.order] or []
 *   groupOps(slide, ids, groupId)    el.set group on each member and an el.order that makes the group
 *                                    contiguous in z-order (at the position of its topmost member)
 *   ungroupOps(slide, ids)           removes the group key from each member
 *   toggleOps(slide, ids, key)       key 'lock' | 'hide': all on -> off, otherwise all on
 *   removeOps(slide, ids)            el.remove for each id that exists
 *   moveOps(slide, ids, dx, dy)      el.set x y
 *   patchOps(slide, patches)         [{id, p}] -> el.set ops for ids that exist
 */
import { round2 } from './util.js';

export function reorderIds(els, ids, how) {
  const sel = new Set(ids);
  const list = els.map((e) => e.id);
  let out = list.slice();
  if (how === 'front') out = [...list.filter((i) => !sel.has(i)), ...list.filter((i) => sel.has(i))];
  else if (how === 'back') out = [...list.filter((i) => sel.has(i)), ...list.filter((i) => !sel.has(i))];
  else if (how === 'forward') {
    for (let i = out.length - 2; i >= 0; i--) {
      if (sel.has(out[i]) && !sel.has(out[i + 1])) [out[i], out[i + 1]] = [out[i + 1], out[i]];
    }
  } else if (how === 'backward') {
    for (let i = 1; i < out.length; i++) {
      if (sel.has(out[i]) && !sel.has(out[i - 1])) [out[i], out[i - 1]] = [out[i - 1], out[i]];
    }
  }
  return out.every((x, i) => x === list[i]) ? null : out;
}

export function layerOps(slide, ids, how) {
  const order = reorderIds(slide.els, ids, how);
  return order ? [{ op: 'el.order', slide: slide.id, ids: order }] : [];
}

export function groupOps(slide, ids, groupId) {
  const sel = new Set(ids);
  const members = slide.els.filter((e) => sel.has(e.id));
  if (members.length < 2) return [];
  const ops = members.filter((e) => e.group !== groupId).map((e) => ({
    op: 'el.set', slide: slide.id, id: e.id, p: { group: groupId },
  }));
  const topIndex = Math.max(...members.map((m) => slide.els.indexOf(m)));
  const order = [];
  slide.els.forEach((e, i) => {
    if (i === topIndex) order.push(...members.map((m) => m.id));
    else if (!sel.has(e.id)) order.push(e.id);
  });
  if (order.some((x, i) => x !== slide.els[i].id)) ops.push({ op: 'el.order', slide: slide.id, ids: order });
  return ops;
}

export function ungroupOps(slide, ids) {
  const sel = new Set(ids);
  return slide.els.filter((e) => sel.has(e.id) && e.group).map((e) => ({
    op: 'el.set', slide: slide.id, id: e.id, p: { group: null },
  }));
}

export function toggleOps(slide, ids, key) {
  const sel = new Set(ids);
  const els = slide.els.filter((e) => sel.has(e.id));
  if (!els.length) return [];
  const value = !els.every((e) => !!e[key]);
  return els.filter((e) => !!e[key] !== value).map((e) => ({
    op: 'el.set', slide: slide.id, id: e.id, p: { [key]: value },
  }));
}

export function removeOps(slide, ids) {
  const have = new Set(slide.els.map((e) => e.id));
  return ids.filter((id) => have.has(id)).map((id) => ({ op: 'el.remove', slide: slide.id, id }));
}

export function moveOps(slide, ids, dx, dy) {
  const sel = new Set(ids);
  return slide.els.filter((e) => sel.has(e.id)).map((e) => ({
    op: 'el.set', slide: slide.id, id: e.id, p: { x: round2(e.x + dx), y: round2(e.y + dy) },
  }));
}

export function patchOps(slide, patches) {
  const have = new Set(slide.els.map((e) => e.id));
  return patches.filter((p) => have.has(p.id) && Object.keys(p.p).length)
    .map((p) => ({ op: 'el.set', slide: slide.id, id: p.id, p: p.p }));
}
