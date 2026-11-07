/**
 * Table editing on the document shape (spec section 6, `table`). Pure: element in, patch out.
 *
 * `rows` is a full grid of cells `{t, b?, i?, al?, fill?, color?, span?: [rows, cols]}`. A cell with a span covers the cells to its
 * right and below it; those covered positions stay in the grid and are skipped when drawing (render/table.js).
 *
 *   gridSize(el)                         {rows, cols}
 *   anchors(el)                          cells that are drawn, in DOM order: [{r, c, rs, cs}]
 *   anchorAt(el, r, c)                   the drawn cell that covers the grid position
 *   expandRange(el, range)               grow a {r1, c1, r2, c2} range until it holds only whole merged cells
 *   canMerge(el, range)                  the expanded range covers more than one drawn cell
 *   mergeRange(el, range)                patch {rows}: the top left cell spans the range and takes the text of the others
 *   unmerge(el, r, c)                    patch {rows}
 *   setCell(el, r, c, props)             patch {rows}
 *   insertRow(el, at) / insertCol(el, at)   patches; `at` is where the new row (column) goes, 0 to the count
 *   deleteRow(el, r) / deleteCol(el, c)  patches, or null when it would remove the last one
 *   nextCell(el, r, c, dir)              the drawn cell after (dir 1) or before (dir -1) in reading order, or null
 */
const clone = (rows) => rows.map((r) => r.map((c) => ({ ...c, ...(c.span ? { span: [...c.span] } : {}) })));
const round4 = (n) => Math.round(n * 1e4) / 1e4;
const round2 = (n) => Math.round(n * 100) / 100;

export function gridSize(el) {
  const rows = Array.isArray(el.rows) ? el.rows.length : 0;
  const cols = Math.max(1, ...(el.rows || []).map((r) => (Array.isArray(r) ? r.length : 0)));
  return { rows, cols };
}

const spanOf = (c) => {
  const s = Array.isArray(c?.span) ? c.span : [1, 1];
  return [Math.max(1, Math.floor(+s[0] || 1)), Math.max(1, Math.floor(+s[1] || 1))];
};

export function anchors(el) {
  const { rows, cols } = gridSize(el);
  const covered = new Set();
  const out = [];
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      if (covered.has(`${r}:${c}`)) continue;
      const [sr, sc] = spanOf(el.rows[r][c]);
      const rs = Math.min(sr, rows - r);
      const cs = Math.min(sc, cols - c);
      for (let i = 0; i < rs; i++) for (let j = 0; j < cs; j++) if (i || j) covered.add(`${r + i}:${c + j}`);
      out.push({ r, c, rs, cs });
    }
  }
  return out;
}

export function anchorAt(el, r, c) {
  return anchors(el).find((a) => r >= a.r && r < a.r + a.rs && c >= a.c && c < a.c + a.cs) || null;
}

export function expandRange(el, range) {
  let { r1, c1, r2, c2 } = { r1: Math.min(range.r1, range.r2), c1: Math.min(range.c1, range.c2), r2: Math.max(range.r1, range.r2), c2: Math.max(range.c1, range.c2) };
  const list = anchors(el);
  let changed = true;
  while (changed) {
    changed = false;
    for (const a of list) {
      const touches = a.r <= r2 && a.r + a.rs - 1 >= r1 && a.c <= c2 && a.c + a.cs - 1 >= c1;
      if (!touches) continue;
      if (a.r < r1) { r1 = a.r; changed = true; }
      if (a.c < c1) { c1 = a.c; changed = true; }
      if (a.r + a.rs - 1 > r2) { r2 = a.r + a.rs - 1; changed = true; }
      if (a.c + a.cs - 1 > c2) { c2 = a.c + a.cs - 1; changed = true; }
    }
  }
  return { r1, c1, r2, c2 };
}

export function canMerge(el, range) {
  const x = expandRange(el, range);
  return anchors(el).filter((a) => a.r >= x.r1 && a.r <= x.r2 && a.c >= x.c1 && a.c <= x.c2).length > 1;
}

export function mergeRange(el, range) {
  const x = expandRange(el, range);
  const rows = clone(el.rows);
  const texts = [];
  for (const a of anchors(el)) {
    if (a.r < x.r1 || a.r > x.r2 || a.c < x.c1 || a.c > x.c2) continue;
    const t = rows[a.r][a.c].t;
    if (t != null && String(t).trim() !== '') texts.push(String(t));
  }
  for (let r = x.r1; r <= x.r2; r++) for (let c = x.c1; c <= x.c2; c++) rows[r][c] = { t: '' };
  const head = { ...el.rows[x.r1][x.c1], t: texts.join('\n') };
  const rs = x.r2 - x.r1 + 1;
  const cs = x.c2 - x.c1 + 1;
  if (rs > 1 || cs > 1) head.span = [rs, cs]; else delete head.span;
  rows[x.r1][x.c1] = head;
  return { rows };
}

export function unmerge(el, r, c) {
  const a = anchorAt(el, r, c);
  if (!a || (a.rs === 1 && a.cs === 1)) return null;
  const rows = clone(el.rows);
  const { span: _s, ...rest } = rows[a.r][a.c];
  rows[a.r][a.c] = rest;
  return { rows };
}

export function setCell(el, r, c, props) {
  const rows = clone(el.rows);
  if (!rows[r] || !rows[r][c]) return null;
  rows[r][c] = { ...rows[r][c], ...props };
  return { rows };
}

function normalizeFractions(list) {
  const sum = list.reduce((a, b) => a + b, 0) || 1;
  const out = list.map((v) => round4(v / sum));
  const diff = round4(1 - out.reduce((a, b) => a + b, 0));
  out[out.length - 1] = round4(out[out.length - 1] + diff);
  return out;
}

export function insertRow(el, at) {
  const { rows: n, cols } = gridSize(el);
  const i = Math.max(0, Math.min(n, at));
  const rows = clone(el.rows);
  const list = anchors(el);
  for (const a of list) if (a.r < i && a.r + a.rs > i) rows[a.r][a.c].span = [a.rs + 1, a.cs];
  const rh = (el.rowh?.[i - 1] ?? el.rowh?.[i] ?? 60);
  rows.splice(i, 0, Array.from({ length: cols }, () => ({ t: '' })));
  const rowh = [...(el.rowh || [])];
  while (rowh.length < n) rowh.push(60);
  rowh.splice(i, 0, rh);
  return { rows, rowh, h: round2((el.h || 0) + rh) };
}

export function insertCol(el, at) {
  const { cols: n } = gridSize(el);
  const i = Math.max(0, Math.min(n, at));
  const rows = clone(el.rows);
  const list = anchors(el);
  for (const a of list) if (a.c < i && a.c + a.cs > i) rows[a.r][a.c].span = [a.rs, a.cs + 1];
  const base = Array.isArray(el.colw) && el.colw.length === n ? el.colw : Array.from({ length: n }, () => 1 / n);
  const share = 1 / (n + 1);
  const colw = base.map((w) => (w * n) / (n + 1));
  colw.splice(i, 0, share);
  rows.forEach((r) => r.splice(i, 0, { t: '' }));
  return { rows, colw: normalizeFractions(colw) };
}

export function deleteRow(el, r) {
  const { rows: n } = gridSize(el);
  if (n <= 1 || r < 0 || r >= n) return null;
  const rows = clone(el.rows);
  for (const a of anchors(el)) {
    if (a.r === r && a.rs > 1) {
      // the merged cell loses its first row: it moves down one row and keeps its text
      rows[r + 1][a.c] = { ...rows[r][a.c], span: [a.rs - 1, a.cs] };
      if (a.rs - 1 === 1 && a.cs === 1) delete rows[r + 1][a.c].span;
    } else if (a.r < r && a.r + a.rs > r) {
      const s = [a.rs - 1, a.cs];
      if (s[0] === 1 && s[1] === 1) delete rows[a.r][a.c].span; else rows[a.r][a.c].span = s;
    }
  }
  rows.splice(r, 1);
  const rowh = [...(el.rowh || [])];
  const gone = rowh[r] ?? 60;
  rowh.splice(r, 1);
  return { rows, rowh, h: Math.max(1, round2((el.h || 0) - gone)) };
}

export function deleteCol(el, c) {
  const { cols: n } = gridSize(el);
  if (n <= 1 || c < 0 || c >= n) return null;
  const rows = clone(el.rows);
  for (const a of anchors(el)) {
    if (a.c === c && a.cs > 1) {
      rows[a.r][c + 1] = { ...rows[a.r][c], span: [a.rs, a.cs - 1] };
      if (a.rs === 1 && a.cs - 1 === 1) delete rows[a.r][c + 1].span;
    } else if (a.c < c && a.c + a.cs > c) {
      const s = [a.rs, a.cs - 1];
      if (s[0] === 1 && s[1] === 1) delete rows[a.r][a.c].span; else rows[a.r][a.c].span = s;
    }
  }
  rows.forEach((r) => r.splice(c, 1));
  const base = Array.isArray(el.colw) && el.colw.length === n ? el.colw : Array.from({ length: n }, () => 1 / n);
  const colw = base.filter((_, i) => i !== c);
  return { rows, colw: normalizeFractions(colw) };
}

export function nextCell(el, r, c, dir = 1) {
  const list = anchors(el);
  const i = list.findIndex((a) => r >= a.r && r < a.r + a.rs && c >= a.c && c < a.c + a.cs);
  if (i < 0) return null;
  return list[i + dir] || null;
}
