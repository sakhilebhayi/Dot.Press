// Spreadsheet paste: parseTable, tableToChartData and suggestChartType.
import { isNum, parseNumber, cleanText } from './util.js';
import { MAP_CODES, countryCodeFromName } from './map.js';
import { LIMITS } from './normalize.js';

const MAX_CHARS = 2_000_000;
const NA = /^(?:n\/?a|na|null|nil|none|-|--|\u2014|\u2013|#n\/a|#div\/0!?|#value!?|#ref!)$/i;

// ---------------------------------------------------------------- delimiter and quotes

/** Split text into records of raw cells honouring quotes ("" escapes a quote, newlines allowed inside). */
function splitRecords(text, delimIn) {
  let delim = delimIn;
  if (delim === 'spaces') {
    // columns separated by runs of two or more spaces (text pasted from a web page)
    text = text.replace(/ {2,}/g, '\t');
    delim = '\t';
  }
  const rows = [];
  let row = [];
  let cell = '';
  let q = false;
  let wasQuoted = false;
  const pushCell = () => {
    row.push({ v: cell, quoted: wasQuoted });
    cell = '';
    wasQuoted = false;
  };
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (q) {
      if (ch === '"') {
        if (text[i + 1] === '"') {
          cell += '"';
          i++;
        } else q = false;
      } else cell += ch;
      continue;
    }
    if (ch === '"' && cell.trim() === '') {
      q = true;
      wasQuoted = true;
      cell = '';
    } else if (ch === delim) {
      pushCell();
    } else if (ch === '\n') {
      pushCell();
      rows.push(row);
      row = [];
    } else if (ch === '\r') {
      // ignore; \r\n handled by \n
    } else cell += ch;
  }
  if (cell !== '' || row.length) {
    pushCell();
    rows.push(row);
  }
  return rows.filter((r) => r.some((c) => c.v.trim() !== '' || c.quoted));
}

function pickDelimiter(text) {
  const sample = text.split('\n').slice(0, 40).join('\n');
  let best = { d: ',', score: 0 };
  for (const d of ['\t', ';', ',', '|']) {
    const recs = splitRecords(sample, d);
    if (!recs.length) continue;
    const counts = recs.map((r) => r.length);
    const cols = counts[0];
    if (cols < 2) continue;
    const consistent = counts.filter((c) => c === cols).length;
    const score = consistent * 10 + cols + (d === '\t' ? 5 : 0);
    // all rows with the same width beats ragged guesses
    const bonus = consistent === counts.length ? 1000 : 0;
    if (score + bonus > best.score) best = { d, score: score + bonus };
  }
  if (best.score === 0) {
    const recs = splitRecords(sample, 'spaces');
    if (recs.length && recs[0].length >= 2 && recs.every((r) => r.length === recs[0].length)) return 'spaces';
    return ',';
  }
  return best.d;
}

// ---------------------------------------------------------------- numbers

const NUMBERISH = /^[\s\-+()R$€£¥\d.,%'  −A-Z]+$/;

function detectDecimal(cells, delim) {
  if (delim === ',') return '.';
  let dot = 0;
  let comma = 0;
  for (const raw of cells) {
    const c = raw.replace(/[^\d.,]/g, '');
    if (!/\d/.test(c)) continue;
    const dots = (c.match(/\./g) || []).length;
    const commas = (c.match(/,/g) || []).length;
    if (dots && commas) {
      if (c.lastIndexOf(',') > c.lastIndexOf('.')) comma++;
      else dot++;
    } else if (commas > 1) dot++;
    else if (dots > 1) comma++;
    else if (commas === 1) {
      if (c.length - c.indexOf(',') - 1 !== 3) comma++;
    } else if (dots === 1) {
      if (c.length - c.indexOf('.') - 1 !== 3) dot++;
    }
  }
  if (comma > dot) return ',';
  if (dot > comma) return '.';
  return delim === ';' ? ',' : '.';
}

/**
 * Parse pasted spreadsheet text (CSV, TSV, semicolon CSV, quoted fields, thousands separators,
 * decimal commas, percentages, currency symbols). Cells become numbers, strings or null.
 * Returns { rows, delimiter, decimal, hints: { percent, currency } }.
 */
export function parseTable(input) {
  let text = typeof input === 'string' ? input : input == null ? '' : String(input);
  if (text.length > MAX_CHARS) text = text.slice(0, MAX_CHARS);
  text = text.replace(/^﻿/, '').replace(/\r\n?/g, '\n');
  if (text.trim() === '') return { rows: [], delimiter: ',', decimal: '.', hints: { percent: false, currency: null } };
  const delimiter = pickDelimiter(text);
  const recs = splitRecords(text, delimiter).slice(0, 5000);
  const numericCandidates = [];
  for (const r of recs) for (const c of r) if (NUMBERISH.test(c.v) && /\d/.test(c.v) && !/[A-Za-z]{2,}/.test(c.v.replace(/ZAR|USD|EUR|GBP/gi, ''))) numericCandidates.push(c.v);
  const decimal = detectDecimal(numericCandidates, delimiter);
  let percent = false;
  let currency = null;
  const rows = recs.map((r) =>
    r.slice(0, 200).map((c) => {
      const v = c.v.trim();
      if (v === '' || NA.test(v)) return null;
      const n = parseNumber(v, decimal);
      if (n !== null) {
        if (/%$/.test(v)) percent = true;
        const m = /^[+-]?\s*(R|\$|€|£|¥)(?=\s*[\d.,])/.exec(v);
        if (m && !currency) currency = m[1];
        return n;
      }
      return cleanText(v, 200);
    }),
  );
  return { rows, delimiter, decimal, hints: { percent, currency } };
}

// ---------------------------------------------------------------- shaping

const isYear = (v) => (isNum(v) && Number.isInteger(v) && v >= 1900 && v <= 2100) || (typeof v === 'string' && /^(19|20)\d{2}$/.test(v));
const isText = (v) => typeof v === 'string';

function clean(rows) {
  let g = rows.map((r) => r.slice());
  const w = Math.max(0, ...g.map((r) => r.length));
  g = g.map((r) => {
    while (r.length < w) r.push(null);
    return r;
  });
  g = g.filter((r) => r.some((c) => c !== null));
  // drop empty columns
  const keep = [];
  for (let j = 0; j < w; j++) if (g.some((r) => r[j] !== null)) keep.push(j);
  return g.map((r) => keep.map((j) => r[j]));
}

function transpose(g) {
  if (!g.length) return g;
  return g[0].map((_, j) => g.map((r) => r[j]));
}

function columnIsNumeric(g, j, from) {
  let nums = 0;
  let strs = 0;
  for (let i = from; i < g.length; i++) {
    const v = g[i][j];
    if (isNum(v)) nums++;
    else if (isText(v)) strs++;
  }
  return nums > 0 && nums >= strs * 1.5;
}

function autoHeaderRow(g) {
  if (g.length < 2) return false;
  const first = g[0];
  const nonNull = first.filter((v) => v !== null);
  if (!nonNull.length) return false;
  const below = g.slice(1).some((r) => r.some(isNum));
  if (!below) return false;
  // a row of text above rows with numbers is a header; so is a blank corner above year headings
  if (nonNull.every(isText)) return true;
  if (first[0] === null && nonNull.every(isYear)) return true;
  return false;
}

function autoHeaderCol(g, from) {
  if (!g.length || !g[0].length) return false;
  if (g[0].length < 2) return false;
  const col = g.slice(from).map((r) => r[0]).filter((v) => v !== null);
  if (!col.length) return false;
  const texts = col.filter(isText).length;
  if (texts >= col.length * 0.6) return true;
  if (col.every(isYear)) return true;
  return false;
}

const TOTAL_NAME = /^(grand\s+)?(total|sub-?total|net|closing|opening|ending|start(ing)?|end|balance|profit|ebitda)\b/i;

/**
 * Turn parsed rows into chart data. Options: orientation ("columns": each column is a series, the default;
 * "rows": each row is a series), firstRowHeader and firstColHeader (true, false or "auto"), and type.
 * Returns { categories, series, totals?, kpis?, regions? } ready for the spec chart.data.
 */
export function tableToChartData(rows, o = {}) {
  const opts = o && typeof o === 'object' ? o : {};
  const type = typeof opts.type === 'string' ? opts.type : '';
  let g = clean(Array.isArray(rows) ? rows.filter(Array.isArray) : []);
  const out = { categories: [], series: [] };
  if (!g.length) return out;

  let frh = opts.firstRowHeader === undefined ? 'auto' : opts.firstRowHeader;
  let fch = opts.firstColHeader === undefined ? 'auto' : opts.firstColHeader;
  if (opts.orientation === 'rows') {
    g = transpose(g);
    [frh, fch] = [fch, frh];
  }

  // ----- map
  if (type === 'map') {
    const hasHeader = frh === 'auto' ? isText(g[0][0]) && !isNum(g[0][1]) && g.length > 1 && !(typeof g[0][0] === 'string' && MAP_CODES.has(g[0][0].toUpperCase())) : frh === true;
    const body = hasHeader ? g.slice(1) : g;
    const regions = [];
    for (const r of body) {
      const label = r[0];
      const value = r.find((v, j) => j > 0 && isNum(v));
      if (!isText(label) || !isNum(value)) continue;
      const up = label.trim().toUpperCase();
      const code = /^[A-Z]{2}$/.test(up) ? up : countryCodeFromName(label);
      if (code) regions.push({ code, value });
    }
    out.regions = regions.slice(0, LIMITS.regions);
    return out;
  }

  // ----- header detection
  const hasRowHeader = frh === 'auto' ? autoHeaderRow(g) : frh === true;
  const headerRow = hasRowHeader ? g[0] : null;
  const body = hasRowHeader ? g.slice(1) : g;
  if (!body.length) return out;
  const hasColHeader = fch === 'auto' ? autoHeaderCol(body.map((r) => r), 0) : fch === true;

  // ----- kpi
  if (type === 'kpi') {
    const head = headerRow ? headerRow.map((h) => String(h ?? '').toLowerCase()) : [];
    const find = (re, dflt) => {
      const i = head.findIndex((h) => re.test(h));
      return i >= 0 ? i : dflt;
    };
    const iVal = head.length ? find(/value|amount|total|number|figure/, 1) : 1;
    const iDelta = head.length ? find(/delta|change|vs|growth|%/, 2) : 2;
    const iNote = head.length ? find(/note|comment|period|detail/, 3) : 3;
    const kpis = [];
    for (const r of body) {
      const label = r[0] === null ? '' : String(r[0]);
      const v = r[iVal];
      if (v === null || v === undefined) continue;
      const k = { label, value: v };
      if (r[iDelta] !== null && r[iDelta] !== undefined && iDelta !== iVal) k.delta = r[iDelta];
      if (typeof r[iNote] === 'string' && iNote !== iDelta) k.note = r[iNote];
      kpis.push(k);
    }
    out.kpis = kpis.slice(0, LIMITS.kpis);
    return out;
  }

  const width = body[0].length;
  const nameOf = (j, k) => (headerRow && headerRow[j] !== null && headerRow[j] !== undefined ? String(headerRow[j]) : `Series ${k}`);

  // ----- scatter and bubble
  if (type === 'scatter' || type === 'bubble') {
    const numeric = [];
    for (let j = 0; j < width; j++) if (columnIsNumeric(body, j, 0)) numeric.push(j);
    const labelCol = hasColHeader || (!numeric.includes(0) && width > 0) ? 0 : -1;
    const nums = numeric.filter((j) => j !== labelCol);
    if (nums.length < 2) return out;
    const xj = nums[0];
    if (type === 'bubble') {
      const yj = nums[1];
      const rj = nums[2];
      const pts = [];
      for (const r of body) {
        if (!isNum(r[xj]) || !isNum(r[yj])) continue;
        const p = { x: r[xj], y: r[yj] };
        if (rj !== undefined && isNum(r[rj])) p.r = r[rj];
        if (labelCol >= 0 && isText(r[labelCol])) p.label = r[labelCol];
        pts.push(p);
      }
      out.series = [{ name: nameOf(yj, 1), values: [], points: pts.slice(0, LIMITS.points) }];
      return out;
    }
    out.series = nums.slice(1, 1 + LIMITS.series).map((yj, k) => {
      const pts = [];
      for (const r of body) {
        if (!isNum(r[xj]) || !isNum(r[yj])) continue;
        const p = { x: r[xj], y: r[yj] };
        if (labelCol >= 0 && isText(r[labelCol])) p.label = r[labelCol];
        pts.push(p);
      }
      return { name: nameOf(yj, k + 1), values: [], points: pts.slice(0, LIMITS.points) };
    });
    return out;
  }

  // ----- category charts
  const catCol = hasColHeader ? 0 : -1;
  const numericCols = [];
  for (let j = 0; j < width; j++) {
    if (j === catCol) continue;
    if (columnIsNumeric(body, j, 0)) numericCols.push(j);
  }
  out.categories = body.slice(0, LIMITS.categories).map((r, i) => (catCol >= 0 && r[catCol] !== null ? String(r[catCol]) : String(i + 1)));
  out.series = numericCols.slice(0, LIMITS.series).map((j, k) => ({
    name: nameOf(j, k + 1),
    values: body.slice(0, LIMITS.categories).map((r) => (isNum(r[j]) ? r[j] : null)),
  }));
  if (type === 'waterfall') {
    const totals = [];
    out.categories.forEach((c, i) => {
      if (TOTAL_NAME.test(c)) totals.push(i);
    });
    if (totals.length) out.totals = totals;
  }
  return out;
}

// ---------------------------------------------------------------- suggestions

const MONTH = /^(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?(\s+['’]?\d{2,4})?$/i;
const DAY = /^(mon|tue|wed|thu|fri|sat|sun)[a-z]*\.?$/i;

/** True when most categories read as dates, months, quarters, weeks or years. */
export function looksTemporal(categories) {
  const c = categories.map((x) => String(x).trim()).filter(Boolean);
  if (c.length < 3) return false;
  let hit = 0;
  for (const s of c) {
    if (/^(19|20)\d{2}$/.test(s) || /^(19|20)\d{2}[-/ .]?(0?[1-9]|1[0-2])$/.test(s) || /^(19|20)\d{2}[-/]\d{1,2}[-/]\d{1,2}/.test(s) || /^\d{1,2}[-/.]\d{1,2}[-/.]\d{2,4}$/.test(s) || /^q[1-4]\b/i.test(s) || /^[1-4]q\b/i.test(s) || /^h[12]\b/i.test(s) || /^(fy|cy)\s?'?\d{2,4}/i.test(s) || /^(w|wk|week)\s?\d{1,2}\b/i.test(s) || MONTH.test(s) || DAY.test(s) || /^\d{1,2}\s+(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/i.test(s)) hit++;
  }
  return hit / c.length >= 0.7;
}

/** A plain recommendation for the chart type: { type, why, stacked? }. Never throws. */
export function suggestChartType(data) {
  const d = data && typeof data === 'object' ? data : {};
  const cats = Array.isArray(d.categories) ? d.categories.map((c) => String(c ?? '')) : [];
  const series = Array.isArray(d.series) ? d.series.filter((s) => s && typeof s === 'object') : [];
  const vals = (s) => (Array.isArray(s.values) ? s.values.filter(isNum) : []);

  if (Array.isArray(d.kpis) && d.kpis.length) return { type: 'kpi', why: 'There are only a few headline figures, so show the numbers themselves.' };
  if (Array.isArray(d.regions) && d.regions.length) return { type: 'map', why: 'Each value belongs to a country, so shade a world map.' };
  const withPoints = series.filter((s) => Array.isArray(s.points) && s.points.length);
  if (withPoints.length) {
    const bubble = withPoints.some((s) => s.points.some((p) => p && isNum(p.r)));
    return bubble ? { type: 'bubble', why: 'Each point has an x, a y and a size, so draw bubbles.' } : { type: 'scatter', why: 'Each point has two numbers, so a scatter chart shows how they relate.' };
  }
  if (!series.length || !cats.length) return { type: 'column', why: 'Column charts are a safe start for categories and numbers.' };

  const codes = cats.filter((c) => /^[A-Za-z]{2}$/.test(c.trim()) && MAP_CODES.has(c.trim().toUpperCase())).length;
  if (series.length === 1 && cats.length >= 5 && codes / cats.length >= 0.8) return { type: 'map', why: 'The categories are country codes, so shade a world map.' };

  const first = vals(series[0]);
  const temporal = looksTemporal(cats);
  if (temporal && cats.length >= 3) {
    const nonNeg = series.every((s) => vals(s).every((v) => v >= 0));
    if (series.length > 1 && nonNeg && cats.length >= 6 && partsOfWhole(series)) return { type: 'area', stacked: true, why: 'The categories run over time and the series add up to a total, so a stacked area shows both the total and the mix.' };
    return { type: 'line', why: 'The categories are dates or periods in order, so a line shows the change over time.' };
  }
  // two numeric columns: numeric categories that are not periods
  if (series.length === 1 && cats.length >= 6 && cats.every((c) => /^-?[\d.,\s]+$/.test(c.trim()) && c.trim() !== '')) {
    return { type: 'scatter', why: 'Both columns are numbers, so a scatter chart shows how one relates to the other.' };
  }
  // shares of a whole
  if (series.length === 1 && first.length >= 2 && first.length <= 8 && first.every((v) => v >= 0)) {
    const sum = first.reduce((a, b) => a + b, 0);
    if ((sum >= 99 && sum <= 101) || (sum >= 0.99 && sum <= 1.01)) return { type: 'doughnut', why: 'The values add up to 100 percent, so a doughnut shows each share of the whole.' };
  }
  // waterfall: mixed signs with a total row
  if (series.length === 1 && first.length >= 4 && first.some((v) => v < 0) && first.some((v) => v > 0) && cats.some((c) => TOTAL_NAME.test(c))) {
    return { type: 'waterfall', why: 'The steps add and subtract and include a total, so a waterfall shows how you get from start to end.' };
  }
  // funnel: falling stage counts with stage-like names
  if (series.length === 1 && first.length >= 3 && first.length <= 8 && first.every((v, i) => i === 0 || v <= first[i - 1]) && cats.some((c) => /lead|visit|sign|trial|stage|appl|purchase|conver|click|impression|qualif|demo|won|enquir|inquir/i.test(c))) {
    return { type: 'funnel', why: 'The numbers fall step by step through named stages, so a funnel shows where people drop out.' };
  }
  const avgLen = cats.reduce((a, c) => a + c.length, 0) / Math.max(1, cats.length);
  const longest = Math.max(0, ...cats.map((c) => c.length));
  if (series.length === 1) {
    if (cats.length > 40) return { type: 'line', why: 'There are many points in one series, so a line reads more clearly than hundreds of bars.' };
    if (longest > 14 || avgLen > 11) return { type: 'bar', why: 'The category names are long, so horizontal bars leave room to read them.' };
    if (cats.length > 12) return { type: 'column', why: 'One series with many categories reads well as columns.' };
    return { type: 'column', why: 'Comparing one measure across categories reads well as columns.' };
  }
  if (series.length >= 2 && series.length <= 6 && partsOfWhole(series) && cats.length <= 6) {
    return { type: 'column', stacked: true, why: 'The series are parts of each category total, so stacked columns show the total and the mix.' };
  }
  if (longest > 14 && cats.length <= 12) return { type: 'bar', why: 'The category names are long, so horizontal bars leave room to read them.' };
  return { type: 'column', why: 'Comparing several measures across categories reads well as grouped columns.' };
}

function partsOfWhole(series) {
  // true when every series is non-negative and all the series share the same unit-like magnitude
  const all = series.map((s) => (Array.isArray(s.values) ? s.values.filter(isNum) : []));
  if (all.some((a) => a.length === 0)) return false;
  if (!all.every((a) => a.every((v) => v >= 0))) return false;
  const means = all.map((a) => a.reduce((x, y) => x + y, 0) / a.length);
  const mx = Math.max(...means);
  const mn = Math.min(...means);
  return mn > 0 && mx / mn <= 12;
}
