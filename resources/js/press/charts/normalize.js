// normalizeChart: fills defaults, validates and bounds untrusted chart specs. Never throws.
import { cleanText, isNum, toNum, clamp } from './util.js';

export const CHART_IDS = ['bar', 'column', 'line', 'area', 'pie', 'doughnut', 'scatter', 'bubble', 'waterfall', 'funnel', 'radar', 'combo', 'kpi', 'map'];

const PLAIN_NUMBER = /^[+-]?\d[\d\s\u00a0\u202f,.'\u2019]*$/;
export const LIMITS = Object.freeze({ categories: 500, series: 40, points: 2000, kpis: 24, regions: 300, pieSlices: 8 });

const LEGENDS = ['none', 'bottom', 'right', 'top'];
const LABELS = ['none', 'value', 'percent'];
const FMTS = ['num', 'pct', 'cur', 'compact'];

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

function normSeries(s, i, nCat) {
  const o = isObj(s) ? s : {};
  const rawVals = Array.isArray(o.values) ? o.values : [];
  const values = [];
  const len = Math.max(nCat, 0);
  for (let k = 0; k < len; k++) values.push(toNum(rawVals[k]));
  const out = {
    name: cleanText(o.name, 80) || 'Series ' + (i + 1),
    values,
  };
  if (typeof o.color === 'string' && o.color.length <= 24) out.color = o.color;
  if (o.kind === 'bar' || o.kind === 'line') out.kind = o.kind;
  if (Array.isArray(o.points)) {
    const pts = [];
    for (const p of o.points.slice(0, LIMITS.points)) {
      if (!isObj(p)) continue;
      const x = toNum(p.x);
      const y = toNum(p.y);
      if (x === null || y === null) continue;
      const pt = { x, y };
      const r = toNum(p.r);
      if (r !== null && r >= 0) pt.r = r;
      if (typeof p.label === 'string') pt.label = cleanText(p.label, 60);
      pts.push(pt);
    }
    out.points = pts;
  }
  return out;
}

/** Returns a complete, bounded chart object. Unknown values fall back to defaults. */
export function normalizeChart(chart) {
  const src = isObj(chart) ? chart : {};
  const type = CHART_IDS.includes(src.type) ? src.type : 'column';
  const d = isObj(src.data) ? src.data : {};

  let categories = (Array.isArray(d.categories) ? d.categories : []).slice(0, LIMITS.categories).map((c) => cleanText(c, 80));
  const rawSeries = (Array.isArray(d.series) ? d.series : []).slice(0, LIMITS.series);
  let longest = 0;
  for (const s of rawSeries) if (isObj(s) && Array.isArray(s.values)) longest = Math.max(longest, Math.min(s.values.length, LIMITS.categories));
  if (longest > categories.length) {
    for (let i = categories.length; i < longest; i++) categories.push(String(i + 1));
  }
  const n = categories.length;
  const series = rawSeries.map((s, i) => normSeries(s, i, n));

  // scatter and bubble need points: build them from categories (x) and values (y) if absent
  if (type === 'scatter' || type === 'bubble') {
    for (const s of series) {
      if (!s.points || s.points.length === 0) {
        const pts = [];
        s.values.forEach((v, k) => {
          if (v === null) return;
          const x = toNum(categories[k]);
          pts.push({ x: x === null ? k + 1 : x, y: v });
        });
        s.points = pts;
      }
    }
  }

  const totals = [];
  if (Array.isArray(d.totals)) {
    for (const t of d.totals) {
      const k = Number(t);
      if (Number.isInteger(k) && k >= 0 && k < n && !totals.includes(k)) totals.push(k);
    }
  }

  const kpis = [];
  if (Array.isArray(d.kpis)) {
    for (const k of d.kpis.slice(0, LIMITS.kpis)) {
      if (!isObj(k)) continue;
      // A figure typed with a currency sign, a percent sign or a unit letter ("R412", "91%", "1.2m") is text: turning it into
      // a number would drop the sign. Only a plain number, with thousands separators, becomes one.
      const value = isNum(k.value) ? k.value : typeof k.value === 'string' ? (PLAIN_NUMBER.test(k.value.trim()) ? (toNum(k.value) ?? cleanText(k.value, 40)) : cleanText(k.value, 40)) : null;
      const item = { label: cleanText(k.label, 60), value };
      if (k.delta !== undefined && k.delta !== null) {
        const dv = isNum(k.delta) ? k.delta : typeof k.delta === 'string' ? (toNum(k.delta) ?? cleanText(k.delta, 24)) : null;
        if (dv !== null && dv !== '') item.delta = dv;
      }
      if (typeof k.unit === 'string') item.unit = cleanText(k.unit, 12);
      if (typeof k.note === 'string') item.note = cleanText(k.note, 80);
      if (k.good === 'down') item.good = 'down';
      kpis.push(item);
    }
  }

  const regions = [];
  if (Array.isArray(d.regions)) {
    const seen = new Map();
    for (const r of d.regions.slice(0, LIMITS.regions)) {
      if (!isObj(r) || typeof r.code !== 'string') continue;
      const code = r.code.trim().toUpperCase();
      if (!/^[A-Z]{2}$/.test(code)) continue;
      const value = toNum(r.value);
      if (value === null) continue;
      if (seen.has(code)) regions[seen.get(code)].value = value;
      else {
        seen.set(code, regions.length);
        regions.push({ code, value });
      }
    }
  }

  const o = isObj(src.opt) ? src.opt : {};
  const ax = isObj(o.ax) ? o.ax : {};
  const axis = (a, withRange) => {
    const x = isObj(a) ? a : {};
    const r = { show: x.show !== false };
    const t = cleanText(x.title, 80);
    if (t) r.title = t;
    if (withRange) {
      const mn = toNum(x.min);
      const mx = toNum(x.max);
      if (mn !== null) r.min = mn;
      if (mx !== null) r.max = mx;
    }
    return r;
  };
  const multi = series.length > 1;
  const pieLike = type === 'pie' || type === 'doughnut';
  const opt = {
    legend: LEGENDS.includes(o.legend) ? o.legend : pieLike ? 'right' : type === 'map' ? 'bottom' : multi || type === 'waterfall' ? 'bottom' : 'none',
    labels: LABELS.includes(o.labels) ? o.labels : 'none',
    stacked: o.stacked === true,
    smooth: o.smooth === true,
    grid: o.grid !== false,
    fmt: FMTS.includes(o.fmt) ? o.fmt : 'num',
    cur: typeof o.cur === 'string' && o.cur.trim() ? cleanText(o.cur, 4) : 'R',
    ax: { x: axis(ax.x, false), y: axis(ax.y, true) },
    anim: o.anim !== false,
    other: o.other === true,
  };
  const title = cleanText(o.title, 140);
  const sub = cleanText(o.sub, 220);
  const center = cleanText(o.center, 40);
  if (title) opt.title = title;
  if (sub) opt.sub = sub;
  if (center) opt.center = center;
  if (Number.isInteger(o.dec) && o.dec >= 0 && o.dec <= 3) opt.dec = o.dec;
  if (Array.isArray(o.colors)) opt.colors = o.colors.filter((c) => typeof c === 'string' && c.length <= 24).slice(0, 16);

  const s = isObj(src.src) ? src.src : {};
  const srcOut = { kind: s.kind === 'file' ? 'file' : 'manual' };
  if (typeof s.name === 'string') srcOut.name = cleanText(s.name, 120);
  if (typeof s.at === 'string') srcOut.at = cleanText(s.at, 40);

  const data = { categories, series };
  if (totals.length) data.totals = totals.sort((a, b) => a - b);
  if (kpis.length) data.kpis = kpis;
  if (regions.length) data.regions = regions;
  return { type, data, opt, src: srcOut };
}

/** Clamp requested output size to something sane. */
export function clampSize(w, h) {
  const W = isNum(w) ? clamp(Math.round(w), 120, 4000) : 960;
  const H = isNum(h) ? clamp(Math.round(h), 90, 4000) : 540;
  return [W, H];
}
