/**
 * Format rules of the chart panel (pure, node tested).
 *
 *   controlsFor(type)           which Format controls apply to a type (a pie has no axes, a map no stacking ...)
 *   FORMATS, CURRENCIES         the choices for number format and currency symbol
 *   numberSample(opt, v)        the engine's own formatting of a sample value, for the live example under the controls
 *   axisWarnings(chart)         [{ kind: 'warn'|'error', text }] about the value axis range
 *   resetFormatting(chart)      { chart, changed: [word] } formatting back to the defaults; title, subtitle, data and
 *                               alt text are content and stay
 *   setOpt(chart, path, value)  immutable write into chart.opt ('ax.y.min'); null or '' removes the key
 *   seriesColorState(chart)     { colors: [string|null per series], overrides: n } the stored per-series colours
 *   setSeriesColor(chart, i, c) set or clear one series colour; slice colours of pie-like charts live in opt.colors
 */
import { makeFormatter } from '../../../charts/index.js';
import { resolveTheme, seriesStyle } from '../../../charts/theme.js';
import { isNum, parseColor } from '../../../charts/util.js';
import { normData } from './grid.js';

export const FORMATS = [
  { value: 'num', label: 'Number', hint: '1,234' },
  { value: 'pct', label: 'Percent', hint: '12.5%' },
  { value: 'cur', label: 'Currency', hint: 'R1,234' },
  { value: 'compact', label: 'Compact', hint: '1.2K' },
];
export const CURRENCIES = ['R', '$', '€', '£', '¥'];

const CARTESIAN = ['bar', 'column', 'line', 'area', 'waterfall', 'combo'];
const ZERO_BASED = ['bar', 'column', 'area', 'waterfall', 'combo'];

/** Which Format controls make sense for a type. */
export function controlsFor(type) {
  const cartesian = CARTESIAN.includes(type);
  const xy = type === 'scatter' || type === 'bubble';
  const sliceLike = type === 'pie' || type === 'doughnut';
  return {
    title: true,
    legend: type !== 'kpi',
    legendNote: type === 'map' ? 'On a map the legend is the colour scale.' : '',
    labels: type !== 'kpi',
    labelsPercent: !['scatter', 'bubble', 'radar', 'map'].includes(type),
    stacked: ['bar', 'column', 'area', 'combo'].includes(type),
    smooth: ['line', 'area', 'combo'].includes(type),
    grid: cartesian || xy,
    axes: cartesian || xy,
    yRange: cartesian || xy,
    fmt: true,
    center: type === 'doughnut',
    other: sliceLike,
    seriesColors: !['kpi', 'map', 'pie', 'doughnut'].includes(type),
    singleColor: type === 'funnel',
    sliceColors: sliceLike,
    animate: true,
  };
}

export function numberSample(opt, v = 1234.5) {
  try {
    return makeFormatter(opt || {})(v);
  } catch {
    return String(v);
  }
}

/** Plain warnings about the value axis. A non-zero start on a bar-like chart is the one that misleads. */
export function axisWarnings(chart) {
  const out = [];
  const type = chart?.type;
  const y = chart?.opt?.ax?.y || {};
  const min = isNum(y.min) ? y.min : null;
  const max = isNum(y.max) ? y.max : null;
  if (min !== null && max !== null && min >= max) out.push({ kind: 'error', text: 'The minimum must be below the maximum. The chart ignores this range until it is fixed.' });
  if (min !== null && min > 0 && ZERO_BASED.includes(type)) {
    out.push({ kind: 'warn', text: `${label(type)} lengths are read as sizes, so an axis that starts at ${min} instead of 0 makes small differences look large. The chart will say it does not start at zero.` });
  } else if (min !== null && min > 0 && type === 'line') {
    out.push({ kind: 'info', text: 'The line axis starts above zero. The chart will say so on the axis.' });
  }
  if (max !== null && CARTESIAN.concat(['scatter', 'bubble']).includes(type)) {
    const data = normData(chart);
    let top = -Infinity;
    for (const s of data.series) {
      for (const v of s.values) if (isNum(v)) top = Math.max(top, v);
      for (const p of s.points || []) if (isNum(p.y)) top = Math.max(top, p.y);
    }
    if (top > max) out.push({ kind: 'warn', text: `The maximum ${max} is below the largest value (${top}), so part of the data is cut off.` });
  }
  return out;
}

function label(type) {
  return { bar: 'Bar', column: 'Column', area: 'Area', waterfall: 'Waterfall', combo: 'Bar' }[type] || 'Bar';
}

/** Defaults the engine and newChart() use. */
const DEFAULT_OPT = { labels: 'none', stacked: false, smooth: false, grid: true, fmt: 'num', anim: true };

/** Formatting only: legend, labels, stacking, smoothing, grid, number format, axis titles and range, colours, animation. */
export function resetFormatting(chart) {
  const opt = { ...(chart.opt || {}) };
  const changed = [];
  const note = (w) => { if (!changed.includes(w)) changed.push(w); };
  for (const [k, d] of Object.entries(DEFAULT_OPT)) {
    if (opt[k] !== undefined && opt[k] !== d) note(k === 'fmt' ? 'number format' : k === 'anim' ? 'animation' : k === 'grid' ? 'gridlines' : k);
    opt[k] = d;
  }
  if (opt.legend !== undefined) { delete opt.legend; note('legend'); }
  for (const k of ['cur', 'dec', 'colors', 'other']) {
    if (opt[k] !== undefined) { delete opt[k]; note(k === 'cur' ? 'currency symbol' : k === 'dec' ? 'decimals' : k === 'other' ? 'Other slice' : 'colours'); }
  }
  if (opt.ax) {
    const keepTitle = (a) => (a && a.title ? { title: a.title, show: true } : { show: true });
    if (opt.ax.y && (opt.ax.y.min !== undefined || opt.ax.y.max !== undefined)) note('axis range');
    if (opt.ax.x?.show === false || opt.ax.y?.show === false) note('axis visibility');
    opt.ax = { x: keepTitle(opt.ax.x), y: keepTitle(opt.ax.y) };
  }
  const data = chart.data ? { ...chart.data, series: (chart.data.series || []).map((s) => { if (!s.color) return s; const { color, ...rest } = s; note('colours'); return rest; }) } : chart.data;
  return { chart: { ...chart, data, opt }, changed };
}

/** Immutable write of a dotted path inside chart.opt. null, undefined and '' remove the key. */
export function setOpt(chart, path, value) {
  const keys = String(path).split('.');
  const opt = { ...(chart.opt || {}) };
  let cur = opt;
  for (let i = 0; i < keys.length - 1; i += 1) {
    cur[keys[i]] = { ...(cur[keys[i]] || {}) };
    cur = cur[keys[i]];
  }
  const last = keys[keys.length - 1];
  if (value === null || value === undefined || value === '') delete cur[last]; else cur[last] = value;
  return { ...chart, opt };
}

export function seriesColorState(chart) {
  const series = chart?.data?.series || [];
  const colors = series.map((s) => (s && typeof s.color === 'string' && s.color ? s.color : null));
  return { colors, overrides: colors.filter(Boolean).length };
}

export function setSeriesColor(chart, i, color) {
  const series = (chart.data?.series || []).map((s, k) => {
    if (k !== i) return s;
    if (color === null || color === undefined || color === '') { const { color: _c, ...rest } = s; return rest; }
    return { ...s, color };
  });
  return { ...chart, data: { ...chart.data, series } };
}

/** Slice colours for pie, doughnut and funnel live in opt.colors by index. */
export function setSliceColor(chart, i, color, count) {
  const colors = Array.from({ length: Math.max(count, i + 1) }, (_, k) => (chart.opt?.colors || [])[k] || '');
  colors[i] = color || '';
  while (colors.length && !colors[colors.length - 1]) colors.pop();
  return setOpt(chart, 'colors', colors.length ? colors : null);
}

/**
 * Series whose colours are close enough to be confused: [{ a, b, distance }] over the colours the chart will really use (theme
 * colours unless overridden). This is a plain distance in sRGB (0 to 441, under 60 is flagged), not a colour-blindness
 * simulation; the engine exports no palette check, and the legend, markers and dashes still separate the series.
 */
export function colorCollisions(chart, theme, threshold = 60) {
  const th = resolveTheme(theme);
  const series = (chart?.data?.series || []).slice(0, 8);
  const rgb = series.map((s, i) => parseColor(seriesStyle(i, th, s.color || (chart.opt?.colors || [])[i]).color));
  const out = [];
  for (let i = 0; i < series.length; i += 1) {
    for (let j = i + 1; j < series.length; j += 1) {
      if (!rgb[i] || !rgb[j]) continue;
      const d = Math.hypot(rgb[i].r - rgb[j].r, rgb[i].g - rgb[j].g, rgb[i].b - rgb[j].b);
      if (d < threshold) out.push({ a: series[i].name, b: series[j].name, distance: Math.round(d) });
    }
  }
  return out;
}
