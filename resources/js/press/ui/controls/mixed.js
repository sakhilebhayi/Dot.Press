/**
 * Reading and writing element properties for the inspector controls (pure, node tested).
 *   MIXED                         the value a control reads when the selected elements differ ('mixed')
 *   getPath(obj, 'shadow.blur')   nested read, undefined when absent
 *   resolveMixed(values, eq?)     the common value, MIXED when they differ, undefined for an empty list
 *   readProp(elements, path, {fallback})   resolveMixed over elements (a missing value counts as `fallback` when given)
 *   patchFor(el, path, value)     the shallow `p` of an el.set op: top level keys replace, dotted paths merge into a copy of the
 *                                 parent object, value null or undefined deletes (the last path segment)
 *   buildPatches(elements, path, value | fn)   [{id, p}] for store.patch; a function gets the element and returns the value;
 *                                 patches equal to the current value are dropped
 *   panelUndoKey(path, elements)  'panel:<path>:<ids>' so a drag of one control is one undo step
 */
export const MIXED = 'mixed';

export const getPath = (obj, path) => String(path).split('.').reduce((o, k) => (o == null ? undefined : o[k]), obj);

function same(a, b) {
  if (a === b) return true;
  if (a && b && typeof a === 'object' && typeof b === 'object') return JSON.stringify(a) === JSON.stringify(b);
  return false;
}

export function resolveMixed(values, eq = same) {
  if (!values.length) return undefined;
  for (let i = 1; i < values.length; i += 1) if (!eq(values[0], values[i])) return MIXED;
  return values[0];
}

export function readProp(elements, path, { fallback } = {}) {
  return resolveMixed(elements.map((e) => { const v = getPath(e, path); return v === undefined || v === null ? fallback : v; }));
}

export function patchFor(el, path, value) {
  const keys = String(path).split('.');
  const top = keys[0];
  if (keys.length === 1) return { [top]: value === undefined ? null : value };
  const root = el[top] && typeof el[top] === 'object' ? JSON.parse(JSON.stringify(el[top])) : {};
  let cur = root;
  for (let i = 1; i < keys.length - 1; i += 1) {
    if (cur[keys[i]] == null || typeof cur[keys[i]] !== 'object') cur[keys[i]] = {};
    cur = cur[keys[i]];
  }
  const last = keys[keys.length - 1];
  if (value === null || value === undefined) delete cur[last]; else cur[last] = value;
  return { [top]: root };
}

export function buildPatches(elements, path, value) {
  const out = [];
  for (const el of elements) {
    const v = typeof value === 'function' ? value(el) : value;
    const now = getPath(el, path);
    if (same(now, v) || (now === undefined && (v === null || v === undefined))) continue;
    out.push({ id: el.id, p: patchFor(el, path, v) });
  }
  return out;
}

export const panelUndoKey = (path, elements) => `panel:${path}:${elements.map((e) => e.id).join(',')}`;
