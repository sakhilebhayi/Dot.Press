/**
 * The data grid model of the chart panel (pure, no DOM, no Vue; node tested).
 *
 * The panel edits `chart.data` as a table. Which table depends on the chart type (modeOf):
 *   cat     rows are categories, columns are series          bar column line area pie doughnut funnel radar combo waterfall
 *   points  rows are points of ONE series (ctx.series)       scatter bubble     columns x, y, size (bubble), label
 *   kpi     rows are key figures                              columns label, value, delta, unit, note, good direction
 *   map     rows are countries                                columns country code, value
 *
 * Every operation takes a chart and returns a NEW chart (the input is never mutated) so the panel can hand the
 * result to the store as one undoable step. Only `chart.data` changes; `opt`, `src` and `type` are carried over.
 *
 *   gridView(chart, ctx)                  { mode, columns, rows, rowCount, limits, ... } text for every cell
 *   setCell(chart, ctx, r, c, text)       { chart, error }  error is a plain sentence; a cell that cannot hold the text is
 *                                         refused (points, map) or stored as empty (category values) and reported
 *   setHeader(chart, ctx, c, text)        rename a series (cat mode)
 *   addRow / removeRow / addColumn / removeColumn / addSeries / removeSeries / renameSeries
 *   setSeriesKind(chart, i, kind)         combo: 'bar' | 'line' | null
 *   toggleTotal(chart, r)                 waterfall: mark or unmark row r as a total
 *   pasteGrid(chart, ctx, r, c, text)     spreadsheet paste (TSV or CSV) at a cell; extends rows and series up to the limits
 *   swapOrientation(chart)                categories become series and series become categories; reports what is lost
 *   parseCell(text)                       { value, error } for a numeric cell
 *
 * Limits are the engine's (charts LIMITS): 500 categories, 40 series, 2000 points per series, 24 figures, 300 countries.
 */
import { LIMITS, normalizeChart, parseTable } from '../../../charts/index.js';
import { countryCodeFromName, countryName, MAP_CODES } from '../../../charts/map.js';
import { cleanText, isNum, parseNumber } from '../../../charts/util.js';

export const GRID_LIMITS = Object.freeze({ rows: LIMITS.categories, series: LIMITS.series, points: LIMITS.points, kpis: LIMITS.kpis, regions: LIMITS.regions });

const NA = /^(?:n\/?a|na|null|nil|none|-|--|—|–|#n\/a|#div\/0!?|#value!?|#ref!)$/i;

export function modeOf(type) {
  if (type === 'scatter' || type === 'bubble') return 'points';
  if (type === 'kpi') return 'kpi';
  if (type === 'map') return 'map';
  return 'cat';
}

const clone = (v) => JSON.parse(JSON.stringify(v));

/** The chart's data, completed and bounded by the engine (values padded to the category count, points built). */
export function normData(chart) {
  const c = chart && typeof chart === 'object' ? chart : {};
  return clone(normalizeChart({ type: c.type, data: c.data, opt: {}, src: {} }).data);
}

function withData(chart, data) {
  return { ...chart, data };
}

// ---------------------------------------------------------------- cells

/** Text shown for a stored number: full precision, never grouped, so it round trips. */
export function cellText(v) {
  if (isNum(v)) return String(v);
  if (v === null || v === undefined) return '';
  return String(v);
}

/**
 * Read a typed or pasted cell as a number. Empty and "n/a" style words are a deliberate gap (value null, no error).
 * Anything else that is not a number is a gap too, and reported.
 */
export function parseCell(text) {
  const t = String(text ?? '').trim();
  if (t === '' || NA.test(t)) return { value: null, error: null };
  // "12,5" is twelve and a half; "1,234" is one thousand two hundred and thirty four
  const decimal = /^[+\-−]?\d+,\d{1,2}$/.test(t) ? ',' : '.';
  const n = parseNumber(t, decimal);
  if (n === null) return { value: null, error: `"${cleanText(t, 24)}" is not a number, so the cell is empty` };
  return { value: n, error: null };
}

function uniqueName(series, base = 'Series') {
  const used = new Set(series.map((s) => s.name));
  let n = series.length + 1;
  while (used.has(`${base} ${n}`)) n += 1;
  return `${base} ${n}`;
}

// ---------------------------------------------------------------- view

export function gridView(chart, ctx = {}) {
  const type = chart?.type;
  const mode = modeOf(type);
  const data = normData(chart);
  if (mode === 'points') {
    const si = clampIndex(ctx.series, data.series.length);
    const s = data.series[si];
    const bubble = type === 'bubble';
    const pts = s ? s.points || [] : [];
    const columns = [
      { key: 'x', label: 'x', kind: 'number' },
      { key: 'y', label: 'y', kind: 'number' },
      ...(bubble ? [{ key: 'r', label: 'Size', kind: 'number' }] : []),
      { key: 'label', label: 'Label', kind: 'text' },
    ];
    return {
      mode, columns, series: data.series.map((x) => ({ name: x.name })), seriesIndex: si, rowCount: pts.length,
      rows: pts.map((p) => columns.map((c) => cellText(p[c.key]))), rowLimit: GRID_LIMITS.points, canAddColumn: false, canRemoveColumn: false,
    };
  }
  if (mode === 'kpi') {
    const columns = [
      { key: 'label', label: 'Label', kind: 'text' },
      { key: 'value', label: 'Value', kind: 'number-or-text' },
      { key: 'delta', label: 'Change', kind: 'number-or-text' },
      { key: 'unit', label: 'Unit', kind: 'text' },
      { key: 'note', label: 'Note', kind: 'text' },
      { key: 'good', label: 'Good when', kind: 'choice', options: [{ value: 'up', label: 'Up' }, { value: 'down', label: 'Down' }] },
    ];
    const k = data.kpis || [];
    return {
      mode, columns, rowCount: k.length, rowLimit: GRID_LIMITS.kpis, canAddColumn: false, canRemoveColumn: false,
      rows: k.map((x) => [cellText(x.label), cellText(x.value), cellText(x.delta), cellText(x.unit), cellText(x.note), x.good === 'down' ? 'down' : 'up']),
    };
  }
  if (mode === 'map') {
    const columns = [
      { key: 'code', label: 'Country code', kind: 'country' },
      { key: 'name', label: 'Country', kind: 'readonly' },
      { key: 'value', label: 'Value', kind: 'number' },
    ];
    const g = data.regions || [];
    return {
      mode, columns, rowCount: g.length, rowLimit: GRID_LIMITS.regions, canAddColumn: false, canRemoveColumn: false,
      rows: g.map((x) => [x.code, countryName(x.code), cellText(x.value)]),
    };
  }
  const columns = [
    { key: 'cat', label: 'Category', kind: 'text', fixed: true },
    ...data.series.map((s, i) => ({ key: `s${i}`, label: s.name, kind: 'number', series: i, seriesKind: s.kind || null })),
  ];
  const totals = new Set(data.totals || []);
  return {
    mode, columns, rowCount: data.categories.length, rowLimit: GRID_LIMITS.rows, colLimit: GRID_LIMITS.series,
    canAddColumn: data.series.length < GRID_LIMITS.series, canRemoveColumn: data.series.length > 1,
    totals: data.categories.map((_, r) => totals.has(r)),
    rows: data.categories.map((c, r) => [c, ...data.series.map((s) => cellText(s.values[r]))]),
  };
}

function clampIndex(i, n) {
  const k = Number.isInteger(i) ? i : 0;
  return Math.max(0, Math.min(k, Math.max(0, n - 1)));
}

// ---------------------------------------------------------------- one cell, on a data copy

/** Writes text into data (mutates `data`, which the caller owns). Returns { error, changed }. */
function writeCell(data, type, ctx, r, c, text) {
  const mode = modeOf(type);
  const raw = String(text ?? '');
  if (mode === 'cat') {
    if (r < 0 || r >= GRID_LIMITS.rows) return { error: `A chart holds ${GRID_LIMITS.rows} rows at most`, changed: false };
    while (data.categories.length <= r) {
      data.categories.push(String(data.categories.length + 1));
      for (const s of data.series) s.values.push(null);
    }
    if (c === 0) {
      data.categories[r] = cleanText(raw, 80);
      return { error: null, changed: true };
    }
    const s = data.series[c - 1];
    if (!s) return { error: 'There is no such series', changed: false };
    const { value, error } = parseCell(raw);
    s.values[r] = value;
    return { error, changed: true };
  }
  if (mode === 'points') {
    const si = clampIndex(ctx.series, data.series.length);
    const s = data.series[si];
    if (!s) return { error: 'Add a series first', changed: false };
    s.points = s.points || [];
    if (r < 0 || r >= GRID_LIMITS.points) return { error: `A series holds ${GRID_LIMITS.points} points at most`, changed: false };
    while (s.points.length <= r) {
      const last = s.points[s.points.length - 1];
      s.points.push({ x: last ? last.x + 1 : 1, y: last ? last.y : 0 });
    }
    const p = s.points[r];
    const key = type === 'bubble' ? ['x', 'y', 'r', 'label'][c] : ['x', 'y', 'label'][c];
    if (!key) return { error: null, changed: false };
    if (key === 'label') {
      const t = cleanText(raw, 60);
      if (t) p.label = t; else delete p.label;
      return { error: null, changed: true };
    }
    const { value, error } = parseCell(raw);
    if (key === 'r') {
      if (value !== null && value < 0) return { error: 'A size cannot be negative, so the cell was not changed', changed: false };
      if (value === null) delete p.r; else p.r = value;
      return { error: error ? `${error.replace(', so the cell is empty', '')}, so the size was cleared` : null, changed: true };
    }
    if (value === null) return { error: `${key} needs a number, so the cell was not changed`, changed: false };
    p[key] = value;
    return { error: null, changed: true };
  }
  if (mode === 'kpi') {
    data.kpis = data.kpis || [];
    if (r < 0 || r >= GRID_LIMITS.kpis) return { error: `A chart holds ${GRID_LIMITS.kpis} key figures at most`, changed: false };
    while (data.kpis.length <= r) data.kpis.push({ label: '', value: null });
    const k = data.kpis[r];
    const key = ['label', 'value', 'delta', 'unit', 'note', 'good'][c];
    if (!key) return { error: null, changed: false };
    const t = raw.trim();
    if (key === 'label') k.label = cleanText(raw, 60);
    else if (key === 'unit' || key === 'note') {
      const v = cleanText(raw, key === 'unit' ? 12 : 80);
      if (v) k[key] = v; else delete k[key];
    } else if (key === 'good') {
      if (t.toLowerCase() === 'down') k.good = 'down'; else delete k.good;
    } else {
      const { value } = parseCell(t);
      const out = t === '' || NA.test(t) ? null : value !== null ? value : cleanText(t, key === 'value' ? 40 : 24);
      if (key === 'value') k.value = out;
      else if (out === null) delete k.delta; else k.delta = out;
    }
    return { error: null, changed: true };
  }
  // map
  data.regions = data.regions || [];
  if (r < 0 || r >= GRID_LIMITS.regions) return { error: `A map holds ${GRID_LIMITS.regions} countries at most`, changed: false };
  if (c === 1) return { error: null, changed: false };
  if (c === 0) {
    const t = raw.trim();
    const up = t.toUpperCase();
    const code = /^[A-Z]{2}$/.test(up) && MAP_CODES.has(up) ? up : countryCodeFromName(t);
    if (!code) return { error: `"${cleanText(t, 24)}" is not a country code or name this map knows, so the cell was not changed`, changed: false };
    if (data.regions.some((x, i) => i !== r && x.code === code)) return { error: `${code} is already in the list, so the cell was not changed`, changed: false };
    if (r >= data.regions.length) data.regions.push({ code, value: 0 }); else data.regions[r].code = code;
    return { error: null, changed: true };
  }
  const reg = data.regions[r];
  if (!reg) return { error: 'Choose the country first', changed: false };
  const { value, error } = parseCell(raw);
  if (value === null) return { error: error || 'A country needs a number, so the cell was not changed', changed: false };
  reg.value = value;
  return { error: null, changed: true };
}

export function setCell(chart, ctx, r, c, text) {
  const data = normData(chart);
  const res = writeCell(data, chart.type, ctx || {}, r, c, text);
  return { chart: res.changed ? withData(chart, data) : chart, error: res.error, changed: res.changed };
}

export function setHeader(chart, ctx, c, text) {
  if (modeOf(chart.type) !== 'cat' || c < 1) return chart;
  const data = normData(chart);
  const s = data.series[c - 1];
  if (!s) return chart;
  s.name = cleanText(text, 80) || uniqueName(data.series);
  return withData(chart, data);
}

// ---------------------------------------------------------------- structure

/** Add a row after row `at` (default: at the end). For a map pass { code } to add that country. */
export function addRow(chart, ctx = {}, at = null, extra = {}) {
  const mode = modeOf(chart.type);
  const data = normData(chart);
  if (mode === 'cat') {
    const n = data.categories.length;
    if (n >= GRID_LIMITS.rows) return { chart, ok: false, reason: `A chart holds ${GRID_LIMITS.rows} rows at most` };
    const idx = at === null || at === undefined ? n : Math.max(0, Math.min(at + 1, n));
    data.categories.splice(idx, 0, `Item ${n + 1}`);
    for (const s of data.series) s.values.splice(idx, 0, null);
    if (data.totals) data.totals = data.totals.map((t) => (t >= idx ? t + 1 : t));
    return { chart: withData(chart, data), ok: true, index: idx };
  }
  if (mode === 'points') {
    const si = clampIndex(ctx.series, data.series.length);
    const s = data.series[si];
    if (!s) return { chart, ok: false, reason: 'Add a series first' };
    s.points = s.points || [];
    if (s.points.length >= GRID_LIMITS.points) return { chart, ok: false, reason: `A series holds ${GRID_LIMITS.points} points at most` };
    const idx = at === null || at === undefined ? s.points.length : Math.max(0, Math.min(at + 1, s.points.length));
    const prev = s.points[idx - 1];
    const p = { x: prev ? prev.x + 1 : 1, y: prev ? prev.y : 0 };
    if (chart.type === 'bubble' && prev && isNum(prev.r)) p.r = prev.r;
    s.points.splice(idx, 0, p);
    return { chart: withData(chart, data), ok: true, index: idx };
  }
  if (mode === 'kpi') {
    data.kpis = data.kpis || [];
    if (data.kpis.length >= GRID_LIMITS.kpis) return { chart, ok: false, reason: `A chart holds ${GRID_LIMITS.kpis} key figures at most` };
    const idx = at === null || at === undefined ? data.kpis.length : Math.max(0, Math.min(at + 1, data.kpis.length));
    data.kpis.splice(idx, 0, { label: `Figure ${data.kpis.length + 1}`, value: null });
    return { chart: withData(chart, data), ok: true, index: idx };
  }
  data.regions = data.regions || [];
  if (data.regions.length >= GRID_LIMITS.regions) return { chart, ok: false, reason: `A map holds ${GRID_LIMITS.regions} countries at most` };
  const code = String(extra.code || '').toUpperCase();
  if (!MAP_CODES.has(code)) return { chart, ok: false, reason: 'Choose a country first' };
  if (data.regions.some((x) => x.code === code)) return { chart, ok: false, reason: `${countryName(code)} is already in the list` };
  data.regions.push({ code, value: 0 });
  return { chart: withData(chart, data), ok: true, index: data.regions.length - 1 };
}

export function removeRow(chart, ctx = {}, r) {
  const mode = modeOf(chart.type);
  const data = normData(chart);
  if (mode === 'cat') {
    if (data.categories.length <= 1) return { chart, ok: false, reason: 'A chart needs at least one row' };
    if (r < 0 || r >= data.categories.length) return { chart, ok: false, reason: 'There is no such row' };
    data.categories.splice(r, 1);
    for (const s of data.series) s.values.splice(r, 1);
    if (data.totals) {
      data.totals = data.totals.filter((t) => t !== r).map((t) => (t > r ? t - 1 : t));
      if (!data.totals.length) delete data.totals;
    }
    return { chart: withData(chart, data), ok: true };
  }
  if (mode === 'points') {
    const s = data.series[clampIndex(ctx.series, data.series.length)];
    if (!s || !s.points || r < 0 || r >= s.points.length) return { chart, ok: false, reason: 'There is no such row' };
    s.points.splice(r, 1);
    return { chart: withData(chart, data), ok: true };
  }
  const key = mode === 'kpi' ? 'kpis' : 'regions';
  if (!data[key] || r < 0 || r >= data[key].length) return { chart, ok: false, reason: 'There is no such row' };
  data[key].splice(r, 1);
  if (!data[key].length) delete data[key];
  return { chart: withData(chart, data), ok: true };
}

/** Move key figure `from` to position `to`. */
export function moveKpi(chart, from, to) {
  const data = normData(chart);
  const k = data.kpis || [];
  if (from === to || from < 0 || to < 0 || from >= k.length || to >= k.length) return chart;
  const [it] = k.splice(from, 1);
  k.splice(to, 0, it);
  return withData(chart, data);
}

export function addSeries(chart, name) {
  const data = normData(chart);
  if (data.series.length >= GRID_LIMITS.series) return { chart, ok: false, reason: `A chart holds ${GRID_LIMITS.series} series at most` };
  const s = { name: cleanText(name, 80) || uniqueName(data.series), values: data.categories.map(() => null) };
  if (modeOf(chart.type) === 'points') s.points = [];
  data.series.push(s);
  return { chart: withData(chart, data), ok: true, index: data.series.length - 1 };
}

export function removeSeries(chart, i) {
  const data = normData(chart);
  if (data.series.length <= 1) return { chart, ok: false, reason: 'A chart needs at least one series' };
  if (i < 0 || i >= data.series.length) return { chart, ok: false, reason: 'There is no such series' };
  data.series.splice(i, 1);
  return { chart: withData(chart, data), ok: true };
}

export function renameSeries(chart, i, name) {
  const data = normData(chart);
  if (!data.series[i]) return chart;
  data.series[i].name = cleanText(name, 80) || uniqueName(data.series);
  return withData(chart, data);
}

export function addColumn(chart) {
  return modeOf(chart.type) === 'cat' ? addSeries(chart) : { chart, ok: false, reason: 'This chart has a fixed set of columns' };
}

export function removeColumn(chart, c) {
  if (modeOf(chart.type) !== 'cat' || c < 1) return { chart, ok: false, reason: 'This column cannot be removed' };
  return removeSeries(chart, c - 1);
}

export function setSeriesKind(chart, i, kind) {
  const data = normData(chart);
  if (!data.series[i]) return chart;
  if (kind === 'bar' || kind === 'line') data.series[i].kind = kind; else delete data.series[i].kind;
  return withData(chart, data);
}

export function toggleTotal(chart, r) {
  const data = normData(chart);
  if (r < 0 || r >= data.categories.length) return chart;
  const t = new Set(data.totals || []);
  if (t.has(r)) t.delete(r); else t.add(r);
  if (t.size) data.totals = [...t].sort((a, b) => a - b); else delete data.totals;
  return withData(chart, data);
}

// ---------------------------------------------------------------- paste

/**
 * Paste spreadsheet text with its top left corner at (r, c). Returns
 * { chart, cells, errors: [{r, c, text, message}], clipped: { rows, cols } } where `cells` counts the cells written.
 * A cell the model refuses (a non number in a point, an unknown country) is listed in `errors` and left as it was.
 */
export function pasteGrid(chart, ctx, r, c, text) {
  const parsed = parseTable(String(text ?? ''));
  const rows = parsed.rows;
  if (!rows.length) return { chart, cells: 0, errors: [], clipped: { rows: 0, cols: 0 } };
  const data = normData(chart);
  const mode = modeOf(chart.type);
  const errors = [];
  const clipped = { rows: 0, cols: 0 };
  let cells = 0;
  const view = gridView(chart, ctx);
  if (mode === 'map') return pasteMap(chart, data, r, c, rows, view.rowLimit);
  // a map's name column is read only: pasted columns skip it (country, value land in columns 0 and 2)
  const colMap = mode === 'map' ? [0, 2] : null;
  const start = colMap ? Math.max(0, colMap.indexOf(c)) : c;
  const maxCols = colMap ? colMap.length : mode === 'cat' ? 1 + GRID_LIMITS.series : view.columns.length;
  const rowLimit = view.rowLimit;
  for (let i = 0; i < rows.length; i += 1) {
    const rr = r + i;
    if (rr >= rowLimit) { clipped.rows = rows.length - i; break; }
    for (let j = 0; j < rows[i].length; j += 1) {
      const slot = start + j;
      if (slot >= maxCols) { clipped.cols = Math.max(clipped.cols, rows[i].length - j); break; }
      const cc = colMap ? colMap[slot] : slot;
      if (mode === 'cat' && cc > data.series.length) {
        while (data.series.length < cc) data.series.push({ name: uniqueName(data.series), values: data.categories.map(() => null) });
      }
      const v = rows[i][j];
      const t = v === null || v === undefined ? '' : isNum(v) ? String(v) : String(v);
      if (mode === 'map' && cc === 1 && (data.regions || []).length <= rr) continue;
      const res = writeCell(data, chart.type, ctx || {}, rr, cc, t);
      if (res.changed) cells += 1;
      if (res.error) errors.push({ r: rr, c: cc, text: t, message: res.error });
    }
  }
  return { chart: cells ? withData(chart, data) : chart, cells, errors, clipped };
}

// ---------------------------------------------------------------- orientation

export function canSwap(chart) {
  if (modeOf(chart.type) !== 'cat') return { ok: false, reason: 'Only charts with categories and series can swap rows and columns' };
  const data = normData(chart);
  if (data.categories.length > GRID_LIMITS.series) return { ok: false, reason: `Swapping would make ${data.categories.length} series, and a chart holds ${GRID_LIMITS.series} at most` };
  if (!data.series.length) return { ok: false, reason: 'There is nothing to swap' };
  return { ok: true, reason: '' };
}

/** Categories become series and series become categories. What cannot travel is listed in `dropped`. */
export function swapOrientation(chart) {
  const can = canSwap(chart);
  if (!can.ok) return { chart, ok: false, reason: can.reason, dropped: [] };
  const data = normData(chart);
  const dropped = [];
  const colours = data.series.filter((s) => s.color).length;
  const kinds = data.series.filter((s) => s.kind).length;
  if (colours) dropped.push({ what: 'Series colours', count: colours });
  if (kinds) dropped.push({ what: 'Bar or line choices', count: kinds });
  if (data.totals?.length) dropped.push({ what: 'Total marks', count: data.totals.length });
  const next = {
    categories: data.series.map((s) => s.name),
    series: data.categories.map((cat, r) => ({ name: cleanText(cat, 80) || `Series ${r + 1}`, values: data.series.map((s) => s.values[r] ?? null) })),
  };
  return { chart: withData(chart, next), ok: true, dropped };
}

// ---------------------------------------------------------------- summary

export function dataSummary(chart, ctx = {}) {
  const v = gridView(chart, ctx);
  if (v.mode === 'cat') {
    const n = v.columns.length - 1;
    return `${v.rowCount} ${v.rowCount === 1 ? 'row' : 'rows'}, ${n} series`;
  }
  const word = { points: ['point', 'points'], kpi: ['figure', 'figures'], map: ['country', 'countries'] }[v.mode];
  return `${v.rowCount} ${v.rowCount === 1 ? word[0] : word[1]}`;
}

/** Map paste: each pasted row is a country and a value; a country that is refused takes its value with it. */
function pasteMap(chart, data, r, c, rows, rowLimit) {
  data.regions = data.regions || [];
  const errors = [];
  const clipped = { rows: 0, cols: 0 };
  let cells = 0;
  const valueOnly = c === 2;
  for (let i = 0; i < rows.length; i += 1) {
    const cell = (j) => { const v = rows[i][j]; return v === null || v === undefined ? '' : String(v); };
    if (r + i >= rowLimit) { clipped.rows = rows.length - i; break; }
    if (rows[i].length > (valueOnly ? 1 : 2)) clipped.cols = Math.max(clipped.cols, rows[i].length - (valueOnly ? 1 : 2));
    let idx = r + i;
    if (!valueOnly) {
      const before = data.regions.length;
      const res = writeCell(data, 'map', {}, idx, 0, cell(0));
      if (res.error) errors.push({ r: idx, c: 0, text: cell(0), message: res.error });
      if (!res.changed) continue;
      cells += 1;
      if (idx >= before) idx = data.regions.length - 1;
      if (rows[i].length < 2) continue;
      const v = writeCell(data, 'map', {}, idx, 2, cell(1));
      if (v.changed) cells += 1;
      if (v.error) errors.push({ r: idx, c: 2, text: cell(1), message: v.error });
    } else {
      const v = writeCell(data, 'map', {}, idx, 2, cell(0));
      if (v.changed) cells += 1;
      if (v.error) errors.push({ r: idx, c: 2, text: cell(0), message: v.error });
    }
  }
  return { chart: cells ? withData(chart, data) : chart, cells, errors, clipped };
}
