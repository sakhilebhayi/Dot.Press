/**
 * Table panel maths on the document shape (spec section 6, `table`). Pure: element in, patch out.
 *
 *   MAX_ROWS, MAX_COLS
 *   targetCells(el, target, sel)      drawn cells [{r, c}] for target 'selection' (the stage's {r1,c1,r2,c2}), 'header', 'firstCol', 'all'.
 *                                     Covered (merged away) positions are never returned.
 *   readCells(el, cells, key)         the common value of a cell key over cells, 'mixed', or undefined for no cells
 *   cellsPatch(el, cells, props)      patch {rows}: props merged into each cell; false, '' and null remove the key
 *   structurePatch(el, op, sel)       patch for rowAbove, rowBelow, colLeft, colRight, delRow, delCol at the selection (or the end
 *                                     when there is none); null when it would remove the last row or column
 *   countPatch(el, 'rows'|'cols', n)  patch that grows or trims the table to n at its end (never below 1, never above the limits)
 *   moveEdge(colw, i, delta, min)     new fractions after dragging the edge between column i and i+1 by delta (a fraction of the width)
 *   setWidth(colw, i, frac, min)      column i set to frac, the others share the change in proportion
 *   evenColumns(n)
 *   fractionsText(colw)               '33% 33% 34%' for assistive text
 */
import {
  anchors, expandRange, gridSize, insertRow, insertCol, deleteRow, deleteCol,
} from '../../../stage/lib/table.js';

export const MAX_ROWS = 100;
export const MAX_COLS = 30;

const round4 = (n) => Math.round(n * 1e4) / 1e4;
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

export function targetCells(el, target, sel) {
  const list = anchors(el);
  if (target === 'header') return list.filter((a) => a.r === 0).map(({ r, c }) => ({ r, c }));
  if (target === 'firstCol') return list.filter((a) => a.c === 0).map(({ r, c }) => ({ r, c }));
  if (target === 'selection' && sel) {
    const x = expandRange(el, sel);
    return list.filter((a) => a.r >= x.r1 && a.r <= x.r2 && a.c >= x.c1 && a.c <= x.c2).map(({ r, c }) => ({ r, c }));
  }
  return list.map(({ r, c }) => ({ r, c }));
}

export function readCells(el, cells, key) {
  if (!cells.length) return undefined;
  const vals = cells.map(({ r, c }) => el.rows?.[r]?.[c]?.[key] ?? null);
  for (let i = 1; i < vals.length; i += 1) if (!same(vals[0], vals[i])) return 'mixed';
  return vals[0];
}

export function cellsPatch(el, cells, props) {
  if (!cells.length) return null;
  const rows = el.rows.map((row) => row.map((cell) => ({ ...cell, ...(cell.span ? { span: [...cell.span] } : {}) })));
  let changed = false;
  for (const { r, c } of cells) {
    const cell = rows[r]?.[c];
    if (!cell) continue;
    for (const [k, v] of Object.entries(props)) {
      const off = v === false || v === null || v === undefined || v === '';
      if (off) { if (k in cell) { delete cell[k]; changed = true; } } else if (!same(cell[k], v)) { cell[k] = v; changed = true; }
    }
  }
  return changed ? { rows } : null;
}

export function structurePatch(el, op, sel) {
  const size = gridSize(el);
  const r = sel ? expandRange(el, sel) : null;
  if (op === 'rowAbove') return size.rows >= MAX_ROWS ? null : insertRow(el, r ? r.r1 : 0);
  if (op === 'rowBelow') return size.rows >= MAX_ROWS ? null : insertRow(el, r ? r.r2 + 1 : size.rows);
  if (op === 'colLeft') return size.cols >= MAX_COLS ? null : insertCol(el, r ? r.c1 : 0);
  if (op === 'colRight') return size.cols >= MAX_COLS ? null : insertCol(el, r ? r.c2 + 1 : size.cols);
  if (op === 'delRow' || op === 'delCol') {
    const del = op === 'delRow' ? deleteRow : deleteCol;
    const from = op === 'delRow' ? (r ? r.r1 : size.rows - 1) : (r ? r.c1 : size.cols - 1);
    const to = op === 'delRow' ? (r ? r.r2 : size.rows - 1) : (r ? r.c2 : size.cols - 1);
    let tmp = el;
    let patch = null;
    for (let i = to; i >= from; i -= 1) {
      const p = del(tmp, i);
      if (!p) break;
      patch = { ...(patch || {}), ...p };
      tmp = { ...tmp, ...p };
    }
    return patch;
  }
  return null;
}

export function countPatch(el, which, n) {
  const max = which === 'rows' ? MAX_ROWS : MAX_COLS;
  const want = Math.min(max, Math.max(1, Math.floor(+n) || 1));
  let tmp = el;
  let patch = null;
  for (let guard = 0; guard < 200; guard += 1) {
    const size = gridSize(tmp);
    const have = which === 'rows' ? size.rows : size.cols;
    if (have === want) break;
    let p;
    if (have < want) p = which === 'rows' ? insertRow(tmp, size.rows) : insertCol(tmp, size.cols);
    else p = which === 'rows' ? deleteRow(tmp, size.rows - 1) : deleteCol(tmp, size.cols - 1);
    if (!p) break;
    patch = { ...(patch || {}), ...p };
    tmp = { ...tmp, ...p };
  }
  return patch;
}

export function evenColumns(n) {
  const k = Math.max(1, n | 0);
  const each = Math.floor(10000 / k) / 10000;
  const out = Array.from({ length: k }, () => each);
  out[k - 1] = round4(1 - each * (k - 1));
  return out;
}

function fix(list) {
  const sum = list.reduce((a, b) => a + b, 0) || 1;
  const out = list.map((v) => round4(v / sum));
  out[out.length - 1] = round4(out[out.length - 1] + (1 - out.reduce((a, b) => a + b, 0)));
  return out;
}

export function moveEdge(colw, i, delta, min = 0.04) {
  if (!Array.isArray(colw) || i < 0 || i >= colw.length - 1) return colw;
  const a = colw[i];
  const b = colw[i + 1];
  const total = a + b;
  const na = Math.min(total - min, Math.max(min, a + delta));
  const out = colw.slice();
  out[i] = na;
  out[i + 1] = total - na;
  return fix(out);
}

export function setWidth(colw, i, frac, min = 0.04) {
  const n = colw.length;
  if (n < 2 || i < 0 || i >= n) return colw;
  const f = Math.min(1 - min * (n - 1), Math.max(min, frac));
  const rest = colw.reduce((s, v, j) => (j === i ? s : s + v), 0) || 1;
  const out = colw.map((v, j) => (j === i ? f : (v / rest) * (1 - f)));
  return fix(out);
}

export function fractionsText(colw) {
  return colw.map((v) => `${Math.round(v * 100)}%`).join(' ');
}
