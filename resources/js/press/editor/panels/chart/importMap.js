/**
 * Importing pasted text or an uploaded file into a chart (pure, node tested).
 *
 *   cellsFromApi(rows)                 rows of strings from POST /api/data/parse -> rows of number | string | null
 *   cellsFromText(text)                the same for pasted text (the chart engine's parseTable)
 *   mapImport(rows, opts)              { ok, why, data, guess, roles, stats, warnings, preview }
 *       opts: { type, orientation: 'columns' | 'rows', firstRowHeader: 'auto' | bool, firstColHeader: 'auto' | bool }
 *       guess    what 'auto' decided: { row: bool, col: bool }
 *       roles    one per column of the oriented table: { index, type: 'number'|'text'|'mixed'|'empty', role: 'category'|'series'|'skipped'|'header', name }
 *       stats    { rows, series, emptyCells, textCells, skipped: [names] }
 *       preview  { header: [string], rows: [[string]], more: number } the first rows of the oriented table
 *   fileSrc(name, now)                the `src` record of a file import: { kind: 'file', name, at }
 *   applyImport(chart, data, src)     the chart with its data replaced (opt untouched) and `src` recorded
 *   importedLabel(src)                "Imported from sales.xlsx on 7 Nov 2026" or ''
 *
 * The import is a snapshot: the numbers are copied into the chart, there are no live links.
 */
import { LIMITS, parseTable, tableToChartData } from '../../../charts/index.js';
import { MAP_CODES } from '../../../charts/map.js';
import { cleanText, isNum } from '../../../charts/util.js';

const MAX_PREVIEW_ROWS = 6;
const MAX_PREVIEW_COLS = 8;

function tsvCell(v) {
  const s = v === null || v === undefined ? '' : String(v);
  return /[\t\n\r"]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

/** Cells from the server are strings; read them the way a pasted spreadsheet is read (numbers, percents, decimal commas). */
export function cellsFromApi(rows) {
  if (!Array.isArray(rows)) return [];
  const tsv = rows.map((r) => (Array.isArray(r) ? r : []).map(tsvCell).join('\t')).join('\n');
  return parseTable(tsv).rows;
}

export function cellsFromText(text) {
  return parseTable(String(text ?? '')).rows;
}

function transpose(g) {
  return g.length ? g[0].map((_, j) => g.map((r) => r[j])) : g;
}

function squareUp(rows) {
  const w = Math.max(0, ...rows.map((r) => r.length));
  const g = rows.map((r) => { const c = r.slice(); while (c.length < w) c.push(null); return c; });
  return g.filter((r) => r.some((c) => c !== null));
}

function columnType(g, j, from) {
  let nums = 0;
  let strs = 0;
  for (let i = from; i < g.length; i += 1) {
    const v = g[i][j];
    if (isNum(v)) nums += 1; else if (typeof v === 'string') strs += 1;
  }
  if (!nums && !strs) return 'empty';
  if (!strs) return 'number';
  if (!nums) return 'text';
  return nums >= strs * 1.5 ? 'number' : 'mixed';
}

const eq = (a, b) => JSON.stringify(a) === JSON.stringify(b);

export function mapImport(rows, opts = {}) {
  const type = opts.type || 'column';
  const orientation = opts.orientation === 'rows' ? 'rows' : 'columns';
  const frh = opts.firstRowHeader === undefined ? 'auto' : opts.firstRowHeader;
  const fch = opts.firstColHeader === undefined ? 'auto' : opts.firstColHeader;
  const grid = squareUp(Array.isArray(rows) ? rows.filter(Array.isArray) : []);
  if (!grid.length) return { ok: false, why: 'There is nothing to import. The text has no rows.', data: null, guess: { row: false, col: false }, roles: [], stats: null, warnings: [], preview: null };

  // The engine reads firstRowHeader and firstColHeader against the ORIGINAL table; the panel speaks about the table as
  // it is laid out after the swap ("the first row names the series"), so the two are exchanged for 'rows'.
  const make = (r, c) => (orientation === 'rows'
    ? tableToChartData(rows, { type, orientation, firstRowHeader: c, firstColHeader: r })
    : tableToChartData(rows, { type, orientation, firstRowHeader: r, firstColHeader: c }));
  const data = make(frh, fch);
  let unknownCodes = 0;
  if (type === 'map' && data.regions) {
    // the engine accepts any two letters; the map only draws codes it knows
    const known = data.regions.filter((g) => MAP_CODES.has(g.code));
    unknownCodes = data.regions.length - known.length;
    data.regions = known;
  }

  // what "auto" decided: the variant with the header forced on that gives the same data
  const guess = { row: frh === true, col: fch === true };
  if (frh === 'auto') guess.row = eq(data, make(true, fch)) && !eq(make(true, fch), make(false, fch));
  if (fch === 'auto') guess.col = eq(data, make(guess.row, true)) && !eq(make(guess.row, true), make(guess.row, false));
  if (frh === 'auto' && !guess.row && eq(make(true, fch), make(false, fch))) guess.row = false;

  const oriented = orientation === 'rows' ? transpose(grid) : grid;
  const bodyFrom = guess.row ? 1 : 0;
  const headerCells = guess.row ? oriented[0] : null;
  const width = oriented[0].length;
  const nameOf = (j) => (headerCells && headerCells[j] !== null && headerCells[j] !== undefined ? String(headerCells[j]) : `Column ${j + 1}`);

  const roles = [];
  let textCells = 0;
  let emptyCells = 0;
  const skipped = [];
  const categoryLike = !['scatter', 'bubble', 'kpi', 'map'].includes(type);
  for (let j = 0; j < width; j += 1) {
    const t = columnType(oriented, j, bodyFrom);
    let role = 'skipped';
    if (categoryLike) {
      if (guess.col && j === 0) role = 'category';
      else if (t === 'number') role = 'series';
    } else if (type === 'map') role = j === 0 ? 'category' : t === 'number' ? 'series' : 'skipped';
    else if (type === 'kpi') role = j === 0 ? 'category' : 'series';
    else role = t === 'number' ? 'series' : j === 0 && t === 'text' ? 'category' : 'skipped';
    if (role === 'series') {
      for (let i = bodyFrom; i < oriented.length; i += 1) {
        const v = oriented[i][j];
        if (typeof v === 'string') textCells += 1; else if (v === null) emptyCells += 1;
      }
    }
    if (role === 'skipped' && t !== 'empty') skipped.push(nameOf(j));
    roles.push({ index: j, type: t, role, name: nameOf(j) });
  }

  const warnings = [];
  const bodyRows = oriented.length - bodyFrom;
  const seriesCount = data.series.length;
  const empty = type === 'kpi' ? !(data.kpis || []).length : type === 'map' ? !(data.regions || []).length : !seriesCount || (categoryLike ? !(data.categories || []).length : !data.series.some((x) => (x.points || []).length));
  if (empty) {
    const why = type === 'map' ? 'No row has a country code or name this map knows, together with a number.'
      : type === 'scatter' || type === 'bubble' ? 'A scatter chart needs at least two columns of numbers.'
        : type === 'kpi' ? 'Each figure needs a label and a number.'
          : 'No column of numbers was found. Check which row and column hold the headings.';
    return { ok: false, why, data, guess, roles, stats: { rows: bodyRows, series: 0, emptyCells, textCells, skipped }, warnings, preview: previewOf(oriented, guess, roles) };
  }
  if (bodyRows > LIMITS.categories && !['scatter', 'bubble', 'kpi', 'map'].includes(type)) warnings.push(`The data has ${bodyRows} rows and a chart holds ${LIMITS.categories}, so the last ${bodyRows - LIMITS.categories} are left out.`);
  if (categoryLike && roles.filter((r) => r.role === 'series').length > LIMITS.series) warnings.push(`The data has more than ${LIMITS.series} columns of numbers and a chart holds ${LIMITS.series}, so the rest are left out.`);
  if (unknownCodes) warnings.push(`${unknownCodes} ${unknownCodes === 1 ? 'row has' : 'rows have'} a two letter code this map does not know and ${unknownCodes === 1 ? 'is' : 'are'} left out.`);
  if (textCells) warnings.push(`${textCells} ${textCells === 1 ? 'cell in a number column is text' : 'cells in number columns are text'} and will be left empty.`);
  if (skipped.length) warnings.push(`${skipped.length === 1 ? 'The column' : 'The columns'} ${skipped.slice(0, 4).map((s) => `"${cleanText(s, 24)}"`).join(', ')}${skipped.length > 4 ? ' and more' : ''} ${skipped.length === 1 ? 'has' : 'have'} no numbers and will not be used.`);
  return { ok: true, why: '', data, guess, roles, stats: { rows: bodyRows, series: seriesCount, emptyCells, textCells, skipped }, warnings, preview: previewOf(oriented, guess, roles) };
}

function previewOf(oriented, guess, roles) {
  const show = (v) => (v === null || v === undefined ? '' : String(v));
  const cols = Math.min(oriented[0]?.length || 0, MAX_PREVIEW_COLS);
  const header = guess.row ? oriented[0].slice(0, cols).map(show) : oriented[0].slice(0, cols).map((_, j) => `Column ${j + 1}`);
  const from = guess.row ? 1 : 0;
  const body = oriented.slice(from, from + MAX_PREVIEW_ROWS).map((r) => r.slice(0, cols).map(show));
  return { header, rows: body, more: Math.max(0, oriented.length - from - MAX_PREVIEW_ROWS), moreCols: Math.max(0, (oriented[0]?.length || 0) - cols), roles: roles.slice(0, cols) };
}

export function fileSrc(name, now = new Date()) {
  return { kind: 'file', name: cleanText(name, 120), at: (now instanceof Date ? now : new Date(now)).toISOString() };
}

export function applyImport(chart, data, src) {
  const next = { ...chart, data };
  next.src = src && src.kind === 'file' ? { kind: 'file', name: src.name, at: src.at } : { kind: 'manual' };
  return next;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export function importedLabel(src) {
  if (!src || src.kind !== 'file' || !src.name) return '';
  const d = src.at ? new Date(src.at) : null;
  if (!d || Number.isNaN(d.getTime())) return `Imported from ${src.name}`;
  return `Imported from ${src.name} on ${d.getUTCDate()} ${MONTHS[d.getUTCMonth()]} ${d.getUTCFullYear()}`;
}
