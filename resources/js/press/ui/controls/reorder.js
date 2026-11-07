/**
 * List reordering maths (pure, node tested), shared by ReorderList.
 *   moveItem(list, from, to)        new array with the item moved (to is the final index)
 *   dropIndex(mids, y, from)        the final index an item dragged from `from` takes when released at y (mids = row midpoints)
 *   stepIndex(from, dir, length)    keyboard: the index after one step, clamped
 */
export function moveItem(list, from, to) {
  if (from === to || from < 0 || from >= list.length) return list;
  const t = Math.min(list.length - 1, Math.max(0, to));
  const next = list.slice();
  const [it] = next.splice(from, 1);
  next.splice(t, 0, it);
  return next;
}
export function dropIndex(mids, y, from) {
  let gap = 0;
  while (gap < mids.length && y > mids[gap]) gap += 1;
  return gap > from ? gap - 1 : gap;
}
export const stepIndex = (from, dir, length) => Math.min(length - 1, Math.max(0, from + dir));
