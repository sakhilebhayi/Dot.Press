/**
 * Changing a chart's type without losing the user's numbers (pure, node tested).
 *
 *   typeChoices(chart)        every type in CHART_TYPES with { ok, why } for the user's current data; `why` is the plain reason
 *                             a type is disabled
 *   convertType(chart, to)    { ok, why, chart, dropped: [{what, count, detail?}], notes: [string] }
 *   typeNotes(chart)          one line per limit that applies to the current type (a pie draws one series, a radar wants 3 rows)
 *
 * Data comes in four shapes (grid.js modeOf): cat (categories x series), points (x, y, size), kpi (figures), map (countries).
 *   * Between category types nothing is lost: waterfall total marks and combo bar/line choices stay in the data, and a pie
 *     still holds every series even though it draws the first. Switching back restores the chart.
 *   * Between shapes the numbers are converted. Whatever cannot travel is listed in `dropped`, with counts, and the panel
 *     shows it together with an Undo. Nothing is dropped silently.
 *   * A conversion that would invent data (x values for text categories, countries for names that are not countries) is
 *     refused with a reason instead.
 */
import { CHART_TYPES } from '../../../charts/index.js';
import { countryCodeFromName, countryName } from '../../../charts/map.js';
import { cleanText, isNum, toNum } from '../../../charts/util.js';
import { GRID_LIMITS, modeOf, normData } from './grid.js';

const TOTAL_NAME = /^(grand\s+)?(total|sub-?total|net|closing|opening|ending|start(ing)?|end|balance|profit|ebitda)\b/i;
const clone = (v) => JSON.parse(JSON.stringify(v));
const ok = (chart, extra = {}) => ({ ok: true, why: '', chart, dropped: [], notes: [], ...extra });
const no = (chart, why) => ({ ok: false, why, chart, dropped: [], notes: [] });

function firstSeriesValues(data) {
  return data.series[0] ? data.series[0].values : [];
}

/** { ok, why } for the type `to` on this chart; same as convertType without the result chart. */
export function convertType(chart, to) {
  const from = chart.type;
  const known = CHART_TYPES.find((t) => t.id === to);
  if (!known) return no(chart, 'Unknown chart type');
  if (from === to) return ok(chart);
  const a = modeOf(from);
  const b = modeOf(to);
  const data = normData(chart);
  const out = clone(chart);
  out.type = to;
  out.data = data;
  const dropped = [];
  const notes = [];

  // ----- category to category: lossless
  if (a === 'cat' && b === 'cat') {
    const n = data.categories.length;
    const vals = firstSeriesValues(data).filter(isNum);
    if (to === 'radar' && n < 3) return no(chart, 'Needs at least 3 categories.');
    if ((to === 'pie' || to === 'doughnut') && !vals.some((v) => v > 0)) return no(chart, 'Slices need positive numbers.');
    if ((to === 'pie' || to === 'doughnut') && vals.some((v) => v < 0)) notes.push('Negative values cannot be a slice, so they are left out of the chart.');
    if ((to === 'pie' || to === 'doughnut') && n > 8) notes.push('Only 8 slices fit; turn on Fold into Other in Format or trim the rows.');
    if ((to === 'pie' || to === 'doughnut' || to === 'funnel' || to === 'waterfall') && data.series.length > 1) notes.push(`This type draws the first series only; the other ${data.series.length - 1} stay in the data.`);
    if (to === 'combo') {
      let changed = 0;
      data.series.forEach((s, i) => { if (!s.kind) { s.kind = i === 0 ? 'bar' : 'line'; changed += 1; } });
      if (changed) notes.push('The first series is drawn as bars and the others as lines. Change this per series in Data.');
    }
    if (to === 'waterfall' && !(data.totals && data.totals.length)) {
      const t = data.categories.map((c, i) => (TOTAL_NAME.test(c) ? i : -1)).filter((i) => i >= 0);
      if (t.length) {
        data.totals = t;
        notes.push(`Marked ${t.length} ${t.length === 1 ? 'row' : 'rows'} as totals because of their names. Check them in Data.`);
      } else notes.push('No row is marked as a total yet. Mark the starting and ending rows in Data.');
    }
    return { ok: true, why: '', chart: out, dropped, notes };
  }

  // ----- from points
  if (a === 'points') {
    if (b === 'points') {
      if (to === 'bubble' && !data.series.some((s) => (s.points || []).some((p) => isNum(p.r)))) notes.push('No point has a size yet, so every bubble is small. Fill in the Size column in Data.');
      return { ok: true, why: '', chart: out, dropped, notes };
    }
    if (b !== 'cat') return no(chart, `Points have no ${b === 'kpi' ? 'figure labels' : 'countries'}. Switch to a column chart first.`);
    const s0 = data.series[0];
    const pts = (s0 && s0.points) || [];
    if (!pts.length) return no(chart, 'There are no points to turn into rows.');
    const useLabels = pts.every((p) => p.label);
    const cats = pts.map((p) => (useLabels ? p.label : String(p.x)));
    const series = [{ name: s0.name, values: pts.map((p) => p.y) }];
    if (useLabels) dropped.push({ what: 'x values', count: pts.length, detail: 'the labels became the categories' });
    const sizes = pts.filter((p) => isNum(p.r)).length;
    if (sizes) dropped.push({ what: 'Bubble sizes', count: sizes });
    let lostSeries = 0;
    for (let i = 1; i < data.series.length; i += 1) {
      const o = data.series[i];
      const op = o.points || [];
      const aligned = op.length === pts.length && op.every((p, k) => p.x === pts[k].x);
      if (aligned) series.push({ name: o.name, values: op.map((p) => p.y) });
      else lostSeries += op.length;
    }
    if (lostSeries) dropped.push({ what: 'Points of other series', count: lostSeries, detail: 'their x values differ from the first series' });
    out.data = { categories: cats, series };
    return { ok: true, why: '', chart: out, dropped, notes };
  }

  // ----- from kpi
  if (a === 'kpi') {
    if (b !== 'cat') return no(chart, b === 'points' ? 'Key figures have no x and y.' : 'Key figures are not countries.');
    const k = data.kpis || [];
    if (!k.length) return no(chart, 'There are no figures to turn into rows.');
    const numeric = k.filter((x) => isNum(x.value)).length;
    if (k.length - numeric) dropped.push({ what: 'Figures whose value is text', count: k.length - numeric, detail: 'they became empty cells' });
    const deltas = k.filter((x) => x.delta !== undefined).length;
    if (deltas) dropped.push({ what: 'Changes', count: deltas });
    const extras = k.filter((x) => x.unit || x.note || x.good).length;
    if (extras) dropped.push({ what: 'Units, notes and good-direction choices', count: extras });
    out.data = { categories: k.map((x, i) => x.label || `Figure ${i + 1}`), series: [{ name: 'Value', values: k.map((x) => (isNum(x.value) ? x.value : null)) }] };
    return { ok: true, why: '', chart: out, dropped, notes };
  }

  // ----- from map
  if (a === 'map') {
    if (b !== 'cat') return no(chart, b === 'points' ? 'A map has no x and y.' : 'A map holds countries. Switch to a column chart first.');
    const g = data.regions || [];
    if (!g.length) return no(chart, 'There are no countries to turn into rows.');
    out.data = { categories: g.map((x) => countryName(x.code)), series: [{ name: 'Value', values: g.map((x) => x.value) }] };
    return { ok: true, why: '', chart: out, dropped, notes };
  }

  // ----- from cat to another shape
  if (a === 'cat') {
    const n = data.categories.length;
    if (b === 'points') {
      const xs = data.categories.map((c) => toNum(c));
      if (n < 2) return no(chart, 'Needs at least 2 rows.');
      if (xs.some((x) => x === null)) {
        const example = data.categories.find((c, i) => xs[i] === null);
        return no(chart, `Each point needs a number for x, but ${example ? `"${cleanText(example, 14)}"` : 'a category'} is text.`);
      }
      if (to === 'bubble') {
        if (data.series.length < 2) return no(chart, 'Needs a second series for the bubble sizes.');
        const y = data.series[0];
        const r = data.series[1];
        const pts = [];
        let empty = 0;
        xs.forEach((x, i) => {
          const yy = y.values[i];
          if (yy === null) { empty += 1; return; }
          const p = { x, y: yy };
          const rr = r.values[i];
          if (isNum(rr) && rr >= 0) p.r = rr;
          pts.push(p);
        });
        if (empty) dropped.push({ what: 'Rows with an empty y value', count: empty });
        if (data.series.length > 2) dropped.push({ what: 'Series beyond the sizes', count: data.series.length - 2, detail: data.series.slice(2).map((s) => s.name).join(', ') });
        out.data = { categories: [], series: [{ name: y.name, values: [], points: pts }] };
        notes.push(`${y.name} is y and ${r.name} sets the bubble size.`);
        return { ok: true, why: '', chart: out, dropped, notes };
      }
      let empty = 0;
      const series = data.series.map((s) => {
        const pts = [];
        xs.forEach((x, i) => { if (s.values[i] === null) empty += 1; else pts.push({ x, y: s.values[i] }); });
        return { name: s.name, values: [], points: pts };
      });
      if (empty) dropped.push({ what: 'Empty cells', count: empty, detail: 'a point needs both numbers' });
      out.data = { categories: [], series };
      return { ok: true, why: '', chart: out, dropped, notes };
    }
    if (b === 'kpi') {
      const v = firstSeriesValues(data);
      const k = [];
      let empty = 0;
      data.categories.forEach((c, i) => { if (v[i] === null || v[i] === undefined) empty += 1; else k.push({ label: c || `Figure ${i + 1}`, value: v[i] }); });
      if (!k.length) return no(chart, 'The first series has no numbers.');
      if (empty) dropped.push({ what: 'Rows with no value', count: empty });
      if (k.length > GRID_LIMITS.kpis) { dropped.push({ what: 'Rows beyond the 24 figure limit', count: k.length - GRID_LIMITS.kpis }); k.length = GRID_LIMITS.kpis; }
      if (data.series.length > 1) dropped.push({ what: 'Series', count: data.series.length - 1, detail: data.series.slice(1).map((s) => s.name).join(', ') });
      out.data = { categories: [], series: [], kpis: k };
      return { ok: true, why: '', chart: out, dropped, notes };
    }
    if (b === 'map') {
      const v = firstSeriesValues(data);
      const regions = [];
      const unmatched = [];
      const seen = new Set();
      data.categories.forEach((c, i) => {
        const up = String(c).trim().toUpperCase();
        const code = /^[A-Z]{2}$/.test(up) && countryName(up) !== up ? up : countryCodeFromName(c);
        if (!code || !isNum(v[i]) || seen.has(code)) { unmatched.push(c || `Row ${i + 1}`); return; }
        seen.add(code);
        regions.push({ code, value: v[i] });
      });
      if (!regions.length) return no(chart, 'No category is a country code (ZA) or name.');
      if (unmatched.length) dropped.push({ what: 'Rows that are not a country or have no value', count: unmatched.length, detail: unmatched.slice(0, 4).join(', ') + (unmatched.length > 4 ? ' and more' : '') });
      if (data.series.length > 1) dropped.push({ what: 'Series', count: data.series.length - 1, detail: data.series.slice(1).map((s) => s.name).join(', ') });
      out.data = { categories: [], series: [], regions: regions.slice(0, GRID_LIMITS.regions) };
      return { ok: true, why: '', chart: out, dropped, notes };
    }
  }
  return no(chart, 'This change is not available.');
}

/** Every type with whether it can be used on this data, and why not. */
export function typeChoices(chart) {
  return CHART_TYPES.map((t) => {
    const r = convertType(chart, t.id);
    return { id: t.id, label: t.label, desc: t.desc, needs: t.needs, ok: r.ok, why: r.why, current: t.id === chart.type };
  });
}

/** Plain notes about limits that apply to the current type and data (shown in the Data section). */
export function typeNotes(chart) {
  const data = normData(chart);
  const notes = [];
  const t = chart.type;
  if ((t === 'pie' || t === 'doughnut' || t === 'funnel' || t === 'waterfall') && data.series.length > 1) notes.push(`A ${t} chart draws the first series only (${data.series[0].name}). The other ${data.series.length - 1} are kept.`);
  if (t === 'radar' && data.series.length > 3) notes.push('A radar chart reads best with up to 3 series.');
  if (t === 'radar' && data.categories.length < 3) notes.push('Needs at least 3 categories.');
  if ((t === 'pie' || t === 'doughnut') && data.categories.length > 8) notes.push('A pie shows 8 slices. Fold the rest into Other in Format.');
  if (t === 'waterfall') notes.push('Mark the starting and ending rows as totals. Other rows are changes: positive up, negative down.');
  if (t === 'combo') notes.push('Choose bar or line for each series. All series share one axis, so they need the same unit.');
  return notes;
}
