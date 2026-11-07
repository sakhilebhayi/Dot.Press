// Accessible alternatives: a plain-sentence description and a table twin of any chart.
import { isNum, makeFormatter, pctShare, cleanText } from './util.js';
import { normalizeChart } from './normalize.js';
import { waterfallSteps } from './cartesian.js';
import { pieSlices } from './pie.js';
import { kpiItems, deltaText } from './kpi.js';
import { mapRegions, countryName } from './map.js';

const LABEL = {
  bar: 'Bar chart',
  column: 'Column chart',
  line: 'Line chart',
  area: 'Area chart',
  pie: 'Pie chart',
  doughnut: 'Doughnut chart',
  scatter: 'Scatter chart',
  bubble: 'Bubble chart',
  waterfall: 'Waterfall chart',
  funnel: 'Funnel chart',
  radar: 'Radar chart',
  combo: 'Combination chart of bars and lines',
  kpi: 'Key figures',
  map: 'World map shaded by value',
};

function list(items, max = 4) {
  const a = items.slice(0, max);
  const more = items.length - a.length;
  if (a.length === 0) return '';
  if (a.length === 1) return a[0] + (more ? ` and ${more} more` : '');
  const head = a.slice(0, -1).join(', ');
  return more ? `${head}, ${a[a.length - 1]} and ${more} more` : `${head} and ${a[a.length - 1]}`;
}

function extremes(values, cats) {
  let hi = -1;
  let lo = -1;
  values.forEach((v, i) => {
    if (!isNum(v)) return;
    if (hi < 0 || v > values[hi]) hi = i;
    if (lo < 0 || v < values[lo]) lo = i;
  });
  return hi < 0 ? null : { hi, lo, hiName: cats[hi], loName: cats[lo] };
}

/** One or two plain sentences that describe the chart for people who cannot see it. */
export function describeChart(input) {
  const chart = normalizeChart(input);
  const { type, data, opt } = chart;
  const f = makeFormatter(opt);
  const cats = data.categories;
  const series = data.series;
  const lead = LABEL[type] || 'Chart';
  const title = opt.title ? ` titled ${opt.title}` : '';
  const parts = [];

  if (type === 'kpi') {
    const items = kpiItems(chart);
    if (!items.length) return `${lead}${title}. No figures yet.`;
    const bits = items.slice(0, 6).map((k) => `${k.label || 'Figure'} ${isNum(k.value) ? f(k.value) : k.value ?? 'n/a'}${k.unit ? ' ' + k.unit : ''}${k.delta !== undefined ? ` (${deltaText(k.delta)})` : ''}`);
    return `${lead}${title}: ${list(bits, 6)}.`;
  }
  if (type === 'map') {
    const regions = mapRegions(chart);
    if (!regions.length) return `${lead}${title}. No regions yet.`;
    const sorted = [...regions].sort((a, b) => b.value - a.value);
    const top = sorted.slice(0, 3).map((r) => `${countryName(r.code)} ${f(r.value)}`);
    return `${lead}${title}. ${regions.length} ${regions.length === 1 ? 'country has' : 'countries have'} values. Highest: ${list(top, 3)}. Lowest: ${countryName(sorted[sorted.length - 1].code)} ${f(sorted[sorted.length - 1].value)}.`;
  }
  if (type === 'scatter' || type === 'bubble') {
    const pts = series.reduce((a, s) => a + (s.points ? s.points.length : 0), 0);
    if (!pts) return `${lead}${title}. No points yet.`;
    const xs = series.flatMap((s) => (s.points || []).map((p) => p.x));
    const ys = series.flatMap((s) => (s.points || []).map((p) => p.y));
    const n = (a) => [Math.min(...a), Math.max(...a)];
    const [x0, x1] = n(xs);
    const [y0, y1] = n(ys);
    return `${lead}${title}. ${pts} points in ${series.length} ${series.length === 1 ? 'series' : 'series'}${series.length > 1 ? ' (' + list(series.map((s) => s.name), 4) + ')' : ''}. X runs from ${f(x0)} to ${f(x1)} and Y from ${f(y0)} to ${f(y1)}.`;
  }
  if (type === 'pie' || type === 'doughnut') {
    const { slices } = pieSlices(chart);
    const total = slices.reduce((a, s) => a + s.value, 0);
    if (!total) return `${lead}${title}. No values above zero.`;
    const sorted = [...slices].sort((a, b) => b.value - a.value);
    const top = sorted.slice(0, 3).map((s) => `${s.name} ${pctShare(s.value / total)}`);
    return `${lead}${title} with ${slices.length} slices of a total of ${f(total)}. Largest: ${list(top, 3)}.`;
  }
  if (type === 'waterfall') {
    const steps = waterfallSteps(chart).filter((s) => !s.skip);
    if (!steps.length) return `${lead}${title}. No steps yet.`;
    const end = steps[steps.length - 1].to;
    const ups = steps.filter((s) => !s.total && s.delta > 0).length;
    const downs = steps.filter((s) => !s.total && s.delta < 0).length;
    return `${lead}${title} with ${steps.length} steps: ${ups} increase${ups === 1 ? '' : 's'} and ${downs} decrease${downs === 1 ? '' : 's'}, ending at ${f(end)}.`;
  }
  if (type === 'funnel') {
    const s = series[0];
    const vals = s ? s.values.filter(isNum) : [];
    if (!vals.length) return `${lead}${title}. No stages yet.`;
    const first = s.values.findIndex(isNum);
    let lastI = first;
    s.values.forEach((v, i) => {
      if (isNum(v)) lastI = i;
    });
    const a = s.values[first];
    const b = s.values[lastI];
    return `${lead}${title} with ${cats.length} stages, from ${cats[first]} at ${f(a)} to ${cats[lastI]} at ${f(b)}${a > 0 ? ` (${pctShare(b / a)} of the first stage)` : ''}.`;
  }

  // category charts
  if (!cats.length || !series.some((s) => s.values.some(isNum))) return `${lead}${title}. No data yet.`;
  const stackWord = opt.stacked && ['bar', 'column', 'area', 'combo'].includes(type) ? 'Stacked ' : '';
  const head = `${stackWord ? 'Stacked ' + lead.charAt(0).toLowerCase() + lead.slice(1) : lead}${title} with ${cats.length} ${cats.length === 1 ? 'category' : 'categories'} (${list(cats, 3)})`;
  if (series.length === 1) {
    const s = series[0];
    const ex = extremes(s.values, cats);
    parts.push(`${head}. ${s.name}`);
    if (ex) {
      let txt = ` is highest for ${ex.hiName} at ${f(s.values[ex.hi])}`;
      if (ex.lo !== ex.hi) txt += ` and lowest for ${ex.loName} at ${f(s.values[ex.lo])}`;
      parts.push(txt + '.');
      const first = s.values.findIndex(isNum);
      let last = first;
      s.values.forEach((v, i) => {
        if (isNum(v)) last = i;
      });
      if ((type === 'line' || type === 'area') && last > first) parts.push(` It goes from ${f(s.values[first])} in ${cats[first]} to ${f(s.values[last])} in ${cats[last]}.`);
    }
    return parts.join('');
  }
  const bits = series.slice(0, 4).map((s) => {
    const ex = extremes(s.values, cats);
    return ex ? `${s.name} peaks at ${f(s.values[ex.hi])} in ${ex.hiName}` : `${s.name} has no values`;
  });
  return `${head} and ${series.length} series: ${list(series.map((s) => s.name), 5)}. ${bits.join('; ')}.`;
}

/**
 * The table twin of a chart: { header, rows }. Numbers are raw by default (missing values are empty
 * strings); pass { format: true } for display strings.
 */
export function chartToTable(input, o = {}) {
  const chart = normalizeChart(input);
  const { type, data, opt } = chart;
  const f = makeFormatter(opt);
  const cell = (v) => (isNum(v) ? (o.format ? f(v) : Number(v.toPrecision(12))) : '');
  const cats = data.categories;
  const series = data.series;

  if (type === 'kpi') {
    const items = kpiItems(chart);
    return { header: ['Figure', 'Value', 'Change', 'Note'], rows: items.map((k) => [k.label, isNum(k.value) ? (o.format ? f(k.value) : k.value) : k.value ?? '', k.delta !== undefined ? (isNum(k.delta) ? deltaText(k.delta) : k.delta) : '', k.note || '']) };
  }
  if (type === 'map') {
    return { header: ['Code', 'Country', 'Value'], rows: mapRegions(chart).map((r) => [r.code, countryName(r.code), cell(r.value)]) };
  }
  if (type === 'scatter' || type === 'bubble') {
    const rows = [];
    series.forEach((s) => (s.points || []).forEach((p) => rows.push(type === 'bubble' ? [s.name, p.label || '', p.x, p.y, p.r ?? ''] : [s.name, p.label || '', p.x, p.y])));
    return { header: type === 'bubble' ? ['Series', 'Label', 'X', 'Y', 'Size'] : ['Series', 'Label', 'X', 'Y'], rows };
  }
  if (type === 'waterfall') {
    const steps = waterfallSteps(chart);
    return { header: ['Step', 'Change', 'Running total'], rows: steps.map((s) => [cats[s.i], s.skip ? '' : s.total ? '' : cell(s.delta), s.skip ? '' : cell(s.to)]) };
  }
  if (type === 'funnel') {
    const s = series[0];
    const first = s ? s.values.find(isNum) : undefined;
    return { header: ['Stage', s ? s.name : 'Value', '% of first'], rows: cats.map((c, i) => [c, s ? cell(s.values[i]) : '', s && isNum(s.values[i]) && first ? pctShare(s.values[i] / first) : '']) };
  }
  if (type === 'pie' || type === 'doughnut') {
    const { slices } = pieSlices(chart);
    const total = slices.reduce((a, s) => a + s.value, 0);
    return { header: ['Slice', series[0] ? series[0].name : 'Value', 'Share'], rows: slices.map((s) => [s.name, cell(s.value), total ? pctShare(s.value / total) : '']) };
  }
  return { header: ['Category', ...series.map((s) => cleanText(s.name, 80))], rows: cats.map((c, i) => [c, ...series.map((s) => cell(s.values[i]))]) };
}
