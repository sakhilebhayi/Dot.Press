/**
 * Getting spreadsheet data into a table, and out into a chart. Pure.
 *
 *   MAX_IMPORT_ROWS, MAX_IMPORT_COLS
 *   textToGrid(text)               {grid, delimiter, clipped} cells as the text shows them (the delimiter comes from parseTable, the
 *                                  cells keep their own text: "12%" stays "12%", "R1 200" stays "R1 200")
 *   fileRowsToGrid(rows)           the same for rows returned by POST /api/data/parse
 *   importPatch(el, grid, {header})   patch {rows, colw, rowh, h, header} that replaces the table's cells with the grid. The table keeps
 *                                  its position, width, font and lines; column widths are kept when the count matches, else made even.
 *   tableChart(el)                 {ok: true, data, type, why} for a new chart from the table, or {ok: false, message}
 *   gridText(el)                   the table as tab separated text (what a copy would give)
 */
import { parseTable, tableToChartData, suggestChartType } from '../../../../charts/index.js';
import { evenColumns } from './cells.js';

export const MAX_IMPORT_ROWS = 100;
export const MAX_IMPORT_COLS = 30;

function split(text, delimiter) {
  let t = text;
  let d = delimiter;
  if (d === 'spaces') { t = t.replace(/ {2,}/g, '\t'); d = '\t'; }
  const rows = [];
  let row = [];
  let cell = '';
  let q = false;
  const push = () => { row.push(cell); cell = ''; };
  for (let i = 0; i < t.length; i += 1) {
    const ch = t[i];
    if (q) {
      if (ch === '"') { if (t[i + 1] === '"') { cell += '"'; i += 1; } else q = false; } else cell += ch;
    } else if (ch === '"' && cell.trim() === '') { q = true; cell = ''; }
    else if (ch === d) push();
    else if (ch === '\n') { push(); rows.push(row); row = []; }
    else if (ch !== '\r') cell += ch;
  }
  if (cell !== '' || row.length) { push(); rows.push(row); }
  return rows;
}

function tidy(rows) {
  let grid = rows.map((r) => r.map((c) => String(c ?? '').replace(/ /g, ' ').trim().slice(0, 500)));
  grid = grid.filter((r) => r.some((c) => c !== ''));
  const w = Math.max(0, ...grid.map((r) => r.length));
  grid = grid.map((r) => { const o = r.slice(); while (o.length < w) o.push(''); return o; });
  const clipped = grid.length > MAX_IMPORT_ROWS || w > MAX_IMPORT_COLS;
  grid = grid.slice(0, MAX_IMPORT_ROWS).map((r) => r.slice(0, MAX_IMPORT_COLS));
  // a column with nothing in it at the end (a trailing delimiter) is not a column
  while (grid.length && grid[0].length > 1 && grid.every((r) => r[r.length - 1] === '')) grid = grid.map((r) => r.slice(0, -1));
  return { grid, clipped };
}

export function textToGrid(text) {
  const t = String(text ?? '').replace(/^﻿/, '').replace(/\r\n?/g, '\n');
  if (!t.trim()) return { grid: [], delimiter: ',', clipped: false };
  const { delimiter } = parseTable(t);
  const { grid, clipped } = tidy(split(t, delimiter));
  return { grid, delimiter, clipped };
}

export function fileRowsToGrid(rows) {
  const list = Array.isArray(rows) ? rows.filter(Array.isArray) : [];
  const { grid, clipped } = tidy(list);
  return { grid, clipped };
}

export function importPatch(el, grid, { header = true } = {}) {
  if (!grid?.length || !grid[0]?.length) return null;
  const cols = grid[0].length;
  const old = Array.isArray(el.rowh) ? el.rowh.filter((v) => +v > 0) : [];
  const unit = old.length ? old.reduce((a, b) => a + b, 0) / old.length : (el.h && el.rows?.length ? el.h / el.rows.length : 60);
  const rowh = grid.map((_, i) => Math.round((el.rowh?.[i] > 0 ? el.rowh[i] : unit) * 100) / 100);
  const keep = Array.isArray(el.colw) && el.colw.length === cols ? el.colw : evenColumns(cols);
  return {
    rows: grid.map((r) => r.map((t) => ({ t }))),
    colw: keep,
    rowh,
    h: Math.round(rowh.reduce((a, b) => a + b, 0) * 100) / 100,
    header: !!header,
  };
}

export function gridText(el) {
  return (el.rows || []).map((r) => r.map((c) => String(c?.t ?? '').replace(/[\t\n\r]+/g, ' ')).join('\t')).join('\n');
}

export function tableChart(el) {
  const text = gridText(el);
  if (!text.replace(/[\t\n]/g, '').trim()) return { ok: false, message: 'The table is empty.' };
  const parsed = parseTable(text);
  const data = tableToChartData(parsed.rows, { firstRowHeader: el.header ? true : 'auto', firstColHeader: 'auto' });
  const hasNumbers = data.series?.some((s) => s.values?.some((v) => typeof v === 'number'));
  if (!hasNumbers) return { ok: false, message: 'A chart needs at least one column of numbers. This table has none.' };
  const s = suggestChartType(data);
  return { ok: true, data, type: s.type, why: s.why };
}
