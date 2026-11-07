// Cartesian charts: column, bar (horizontal), line, area, combo and waterfall,
// with stacked variants, one shared value axis (never dual axis), direct labels and honest baselines.
import { isNum, fx, fitText, wrapText, textWidth, clamp, inkOn, mix, pctShare, tickFormatter } from './util.js';
import { niceTicks } from './ticks.js';
import { el, add, put, text, mark, roundedRect, shapeEl, makePlacer, textBox, planFrame, drawChrome, drawEmpty, seriesFill, legendForSeries, hatchFill, overlap } from './svg.js';

// ---------------------------------------------------------------- curve helpers

/** Monotone cubic (Fritsch-Carlson) control points: smooth without inventing overshoot. */
function monotonePath(pts, move = true) {
  const n = pts.length;
  if (n === 0) return '';
  let d = (move ? 'M' : 'L') + fx(pts[0][0]) + ' ' + fx(pts[0][1]);
  if (n === 1) return d;
  if (n === 2) return d + 'L' + fx(pts[1][0]) + ' ' + fx(pts[1][1]);
  const dx = [];
  const m = [];
  for (let i = 0; i < n - 1; i++) {
    dx.push(pts[i + 1][0] - pts[i][0] || 1e-6);
    m.push((pts[i + 1][1] - pts[i][1]) / dx[i]);
  }
  const t = [m[0]];
  for (let i = 1; i < n - 1; i++) t.push(m[i - 1] * m[i] <= 0 ? 0 : (m[i - 1] + m[i]) / 2);
  t.push(m[n - 2]);
  for (let i = 0; i < n - 1; i++) {
    if (m[i] === 0) {
      t[i] = 0;
      t[i + 1] = 0;
    } else {
      const a = t[i] / m[i];
      const b = t[i + 1] / m[i];
      const s = a * a + b * b;
      if (s > 9) {
        const k = 3 / Math.sqrt(s);
        t[i] = k * a * m[i];
        t[i + 1] = k * b * m[i];
      }
    }
  }
  for (let i = 0; i < n - 1; i++) {
    const h = dx[i];
    d += `C${fx(pts[i][0] + h / 3)} ${fx(pts[i][1] + (t[i] * h) / 3)} ${fx(pts[i + 1][0] - h / 3)} ${fx(pts[i + 1][1] - (t[i + 1] * h) / 3)} ${fx(pts[i + 1][0])} ${fx(pts[i + 1][1])}`;
  }
  return d;
}

function linearPath(pts, move = true) {
  return pts.map((p, i) => (i === 0 ? (move ? 'M' : 'L') : 'L') + fx(p[0]) + ' ' + fx(p[1])).join('');
}

function runs(arr) {
  // consecutive non-null runs of [x, y|null] points
  const out = [];
  let cur = [];
  for (const p of arr) {
    if (p[1] === null) {
      if (cur.length) out.push(cur);
      cur = [];
    } else cur.push(p);
  }
  if (cur.length) out.push(cur);
  return out;
}

// ---------------------------------------------------------------- category label planning

/**
 * Decide how horizontal category labels fit into `avail` pixels per label.
 * Returns { mode: 'flat'|'wrap'|'rotate'|'skip', lines, step, height, rot }.
 */
export function planCategoryLabels(labels, avail, fs, maxRotW) {
  const widths = labels.map((l) => textWidth(l, fs));
  const maxW = Math.max(0, ...widths);
  const lh = fs * 1.2;
  if (labels.length === 0) return { mode: 'flat', step: 1, height: 0, lines: [] };
  if (maxW <= avail * 0.9) return { mode: 'flat', step: 1, height: lh + fs * 0.4, lines: labels.map((l) => [l]) };
  const wrapped = labels.map((l) => wrapText(l, fs, avail * 0.96, 2));
  const wrapOk = wrapped.every((ls, i) => ls.every((ln) => !ln.endsWith('…') || ln === labels[i]) && ls.join(' ') === labels[i].replace(/\s+/g, ' '));
  if (wrapOk && avail >= fs * 2.2) return { mode: 'wrap', step: 1, height: Math.max(...wrapped.map((l) => l.length)) * lh + fs * 0.4, lines: wrapped };
  if (avail >= fs * 1.45) {
    const rw = Math.min(maxW, maxRotW);
    return { mode: 'rotate', step: 1, height: rw * Math.SQRT1_2 + fs * 0.9, rot: -45, rw, lines: labels.map((l) => [fitText(l, fs, rw)]) };
  }
  const cap = Math.max(fs * 4, avail * labels.length * 0.5);
  const step = Math.max(2, Math.ceil((Math.min(maxW, cap) + fs * 0.8) / Math.max(avail, 1)));
  return { mode: 'skip', step, height: lh + fs * 0.4, lines: labels.map((l) => [fitText(l, fs, Math.max(avail * step - fs * 0.4, fs * 2))]) };
}

// ---------------------------------------------------------------- domain

function rolesFor(chart) {
  const { type, data } = chart;
  return data.series.map((s) => (type === 'line' ? 'line' : type === 'area' ? 'area' : type === 'combo' ? (s.kind === 'line' ? 'line' : 'bar') : 'bar'));
}

/** Waterfall steps: running totals with stated or computed totals. */
export function waterfallSteps(chart) {
  const { categories, series, totals = [] } = chart.data;
  const vals = series[0] ? series[0].values : [];
  const steps = [];
  let run = 0;
  for (let i = 0; i < categories.length; i++) {
    const v = vals[i] ?? null;
    const isTotal = totals.includes(i);
    if (isTotal) {
      const end = v !== null ? v : run;
      steps.push({ i, total: true, from: 0, to: end, delta: end - run, value: end });
      run = end;
    } else if (v === null) {
      steps.push({ i, skip: true, from: run, to: run, delta: 0, value: null });
    } else {
      steps.push({ i, from: run, to: run + v, delta: v, value: v });
      run += v;
    }
  }
  return steps;
}

function dataRange(ctx, roles) {
  const { type, data, opt } = ctx.chart;
  let lo = Infinity;
  let hi = -Infinity;
  const see = (v) => {
    if (isNum(v)) {
      if (v < lo) lo = v;
      if (v > hi) hi = v;
    }
  };
  const n = data.categories.length;
  if (type === 'waterfall') {
    waterfallSteps(ctx.chart).forEach((s) => {
      if (!s.skip) {
        see(s.from);
        see(s.to);
      }
    });
    see(0);
    return { lo, hi, zero: true };
  }
  const stackBars = opt.stacked;
  const stackAreas = opt.stacked;
  let needZero = false;
  for (let c = 0; c < n; c++) {
    let pos = 0;
    let neg = 0;
    let acc = 0;
    data.series.forEach((s, si) => {
      const v = s.values[c];
      if (!isNum(v)) return;
      const role = roles[si];
      if (role === 'bar') {
        needZero = true;
        if (stackBars) {
          if (v >= 0) pos += v;
          else neg += v;
        } else see(v);
      } else if (role === 'area') {
        needZero = true;
        if (stackAreas) acc += v;
        else see(v);
      } else see(v);
    });
    if (stackBars && (pos !== 0 || neg !== 0)) {
      see(pos);
      see(neg);
    }
    if (stackAreas && acc !== 0) see(acc);
  }
  if (needZero) see(0);
  return { lo, hi, zero: needZero };
}

function pickDomain(ctx, roles, count) {
  const { opt } = ctx.chart;
  let { lo, hi, zero } = dataRange(ctx, roles);
  if (!Number.isFinite(lo) || !Number.isFinite(hi)) {
    lo = 0;
    hi = 1;
    zero = true;
  }
  // line-only charts may start above zero when the data sits far from it (and say so)
  if (!zero) {
    if (lo >= 0 && lo < 0.5 * hi) lo = 0;
    else if (hi <= 0 && hi > 0.5 * lo) hi = 0;
    else {
      const pad = (hi - lo) * 0.06 || Math.abs(hi) * 0.05 || 1;
      lo -= pad;
      hi += pad;
    }
  }
  const yMin = opt.ax.y.min;
  const yMax = opt.ax.y.max;
  const nt = niceTicks(lo, hi, count);
  let min = nt.min;
  let max = nt.max;
  let exact = false;
  if (isNum(yMin)) {
    min = yMin;
    exact = true;
  }
  if (isNum(yMax)) {
    max = yMax;
    exact = true;
  }
  if (max <= min) {
    max = min + (Math.abs(min) || 1);
    exact = true;
  }
  const res = exact ? niceTicks(min, max, count, { exact: true }) : nt;
  const ticks = res.ticks.length ? res.ticks : [min, max];
  return { min: exact ? min : res.min, max: exact ? max : res.max, ticks, step: res.step, truncated: (exact ? min : res.min) > 0 };
}

// ---------------------------------------------------------------- main

export function renderCartesian(ctx) {
  const chart = ctx.chart;
  const { type, data, opt } = chart;
  const { fs, k } = ctx;
  const horizontal = type === 'bar';
  const wf = type === 'waterfall';
  const n = data.categories.length;
  // a waterfall draws series[0] only; later series are ignored (the PHP exporter follows the same rule)
  const series = wf ? data.series.slice(0, 1) : data.series;
  const roles = wf ? ['bar'] : rolesFor(chart);
  const hasData = n > 0 && series.some((s) => s.values.some((v) => isNum(v)));

  // legend model
  let legend;
  if (wf) {
    const steps = waterfallSteps(chart);
    legend = [];
    if (steps.some((s) => !s.total && !s.skip && s.delta >= 0)) legend.push({ name: 'Increase', color: ctx.theme.c.good, kind: 'swatch' });
    if (steps.some((s) => !s.total && !s.skip && s.delta < 0)) legend.push({ name: 'Decrease', color: ctx.theme.c.bad, kind: 'swatch' });
    if (steps.some((s) => s.total)) legend.push({ name: 'Total', color: ctx.styles[0] ? ctx.styles[0].color : ctx.theme.c.chart[0], kind: 'swatch' });
  } else {
    legend = legendForSeries(ctx, roles.map((r) => (r === 'line' ? 'line' : 'swatch')));
  }

  // honest axes: say so when the value axis does not start at zero
  const provisional = hasData ? pickDomain(ctx, roles, 5) : { truncated: false };
  if (provisional.truncated) ctx.notes.push('Value axis does not start at zero.');
  if (hasData && !wf && series.every((s) => s.values.every((v) => v === null || v === 0))) ctx.notes.push('All values are zero.');
  if (wf && data.series.length > 1) ctx.notes.push('Only the first series is used for a waterfall.');
  const totalsSet = new Set(data.totals || []);
  const dropped = (wf ? series.slice(0, 1) : series).reduce((a, s) => a + s.values.filter((v, i) => v === null && !(wf && totalsSet.has(i))).length, 0);
  if (dropped > 0 && hasData) ctx.notes.push(`${dropped} missing value${dropped === 1 ? '' : 's'} not drawn.`);

  const box = planFrame(ctx, legend, { noLegend: !hasData });
  if (!hasData) {
    drawEmpty(ctx, box, n === 0 ? 'No data to chart yet.' : 'All values are empty.');
    drawChrome(ctx);
    return;
  }

  const showCat = opt.ax.x.show;
  const showVal = opt.ax.y.show;
  const yTitle = opt.ax.y.title;
  const xTitle = opt.ax.x.title;
  const valTitleSize = fs * 0.9;
  const lineSeries = series.map((s, i) => i).filter((i) => roles[i] !== 'bar');
  const direct = !wf && series.length >= 2 && series.length <= 4 && lineSeries.length > 0 && opt.legend !== 'right';
  let endLabelW = 0;
  if (direct) {
    const lw = Math.max(...lineSeries.map((i) => textWidth(series[i].name, fs * 0.9)));
    endLabelW = Math.min(lw + fs * 0.9, box.w * 0.24);
  }
  const labelsOn = opt.labels !== 'none';
  const pointCount = Math.max(1, n);
  const lineEdge = (type === 'line' || type === 'area') && n > 1;

  // value axis geometry (vertical charts: left axis; horizontal: bottom axis)
  const maxCatW = Math.max(0, ...data.categories.map((c) => textWidth(c, fs)));
  let plot = { x: box.x, y: box.y, w: box.w, h: box.h };
  let dom;
  let catPlan;
  let valFmt;
  let vLabelW = 0;

  for (let pass = 0; pass < 3; pass++) {
    const hAvail = plot.h;
    if (horizontal) {
      // value ticks along the bottom
      const probe = pickDomain(ctx, roles, 5);
      const probeFmt = tickFormatter(opt, probe.step);
      const tw = Math.max(...probe.ticks.map((t) => textWidth(probeFmt(t), fs * 0.95)));
      const count = clamp(Math.floor(plot.w / (tw + fs * 1.8)), 2, 8);
      dom = pickDomain(ctx, roles, count);
      valFmt = tickFormatter(opt, dom.step);
      vLabelW = Math.max(...dom.ticks.map((t) => textWidth(valFmt(t), fs * 0.95)));
      const catW = showCat ? Math.min(box.w * 0.36, maxCatW + fs * 0.4) : 0;
      const left = box.x + (xTitle ? fs * 1.6 : 0) + (showCat ? catW + fs * 0.7 : fs * 0.3);
      const right = box.x + box.w - endLabelW - Math.max(fs * 0.9, vLabelW / 2);
      const bottom = box.y + box.h - (showVal ? fs * 1.9 : fs * 0.3) - (yTitle ? fs * 1.5 : 0);
      plot = { x: left, y: box.y + fs * 0.3, w: Math.max(20, right - left), h: Math.max(20, bottom - (box.y + fs * 0.3)) };
      catPlan = { catW };
    } else {
      const count = clamp(Math.floor(hAvail / (fs * 3.1)), 2, 8);
      dom = pickDomain(ctx, roles, count);
      valFmt = tickFormatter(opt, dom.step);
      vLabelW = Math.max(...dom.ticks.map((t) => textWidth(valFmt(t), fs * 0.95)));
      const left = box.x + (yTitle ? fs * 1.6 : 0) + (showVal ? vLabelW + fs * 0.8 : fs * 0.3);
      const lastOverhang = Math.min(maxCatW / 2, fs * 3);
      const right = box.x + box.w - endLabelW - (lineEdge ? fs * 0.6 : Math.min(fs * 0.9, lastOverhang));
      const w = Math.max(20, right - left);
      const inset = lineEdge ? Math.max(fs * 0.8, 10) : 0;
      const avail = lineEdge ? (w - 2 * inset) / Math.max(1, n - 1) : w / pointCount;
      catPlan = showCat ? planCategoryLabels(data.categories, avail, fs, Math.max(fs * 4, box.h * 0.3)) : { mode: 'flat', step: 1, height: 0, lines: [] };
      const catH = showCat ? catPlan.height : fs * 0.3;
      const top = box.y + (labelsOn || type !== 'bar' ? fs * 1.1 : fs * 0.4);
      const bottom = box.y + box.h - catH - (xTitle ? fs * 1.5 : 0);
      plot = { x: left, y: top, w, h: Math.max(20, bottom - top) };
    }
  }

  // value and category transforms
  const axisLen = horizontal ? plot.w : plot.h;
  const V = (v) => (horizontal ? plot.x + ((v - dom.min) / (dom.max - dom.min)) * plot.w : plot.y + plot.h - ((v - dom.min) / (dom.max - dom.min)) * plot.h);
  const vClamp = (v) => V(clamp(v, dom.min, dom.max));
  const base = V(clamp(0, dom.min, dom.max));
  const inset = lineEdge ? Math.max(fs * 0.8, 10) : 0;
  const band = horizontal ? plot.h / pointCount : lineEdge ? (plot.w - 2 * inset) / Math.max(1, n - 1) : plot.w / pointCount;
  const C = (i) => (horizontal ? plot.y + (i + 0.5) * band : lineEdge ? plot.x + inset + i * band : n === 1 && !wf ? plot.x + plot.w / 2 : plot.x + (i + 0.5) * band);

  // ----- grid and axes
  const grid = [];
  const axis = [];
  const zeroInside = dom.min < 0 && dom.max > 0;
  dom.ticks.forEach((t) => {
    const p = V(t);
    if (horizontal) {
      if (opt.grid) grid.push(el('line', { x1: p, y1: plot.y, x2: p, y2: plot.y + plot.h, stroke: ctx.line, 'stroke-width': k }));
      if (showVal) axis.push(text(ctx, p, plot.y + plot.h + fs * 1.15, valFmt(t), { size: fs * 0.95, anchor: 'middle', fill: ctx.muted }));
    } else {
      if (opt.grid) grid.push(el('line', { x1: plot.x, y1: p, x2: plot.x + plot.w, y2: p, stroke: ctx.line, 'stroke-width': k }));
      if (showVal) axis.push(text(ctx, plot.x - fs * 0.6, p, valFmt(t), { size: fs * 0.95, anchor: 'end', fill: ctx.muted }));
    }
  });
  // baseline: zero line when inside, otherwise the plot edge
  if (horizontal) {
    axis.push(el('line', { x1: base, y1: plot.y, x2: base, y2: plot.y + plot.h, stroke: ctx.muted, 'stroke-width': k * (zeroInside ? 1.4 : 1) }));
  } else {
    axis.push(el('line', { x1: plot.x, y1: base, x2: plot.x + plot.w, y2: base, stroke: ctx.muted, 'stroke-width': k * (zeroInside ? 1.4 : 1) }));
    if (zeroInside) axis.push(el('line', { x1: plot.x, y1: plot.y + plot.h, x2: plot.x + plot.w, y2: plot.y + plot.h, stroke: ctx.line, 'stroke-width': k }));
  }
  // axis-break mark when the value axis does not include zero
  if (dom.truncated) {
    const bx = plot.x;
    const by = plot.y + plot.h;
    const sz = fs * 0.55;
    const strokeAttrs = { stroke: ctx.muted, 'stroke-width': k * 1.4, fill: 'none', class: 'pc-break', 'stroke-linecap': 'round' };
    if (horizontal) {
      axis.push(el('rect', { x: bx - sz * 0.8, y: by - sz * 1.1, width: sz * 1.6, height: sz * 0.9, fill: ctx.surface }));
      axis.push(el('path', { d: `M${fx(bx - sz * 0.8)} ${fx(by - sz * 0.75)}l${fx(sz * 1.6)} ${fx(-sz * 0.45)}M${fx(bx - sz * 0.8)} ${fx(by - sz * 0.15)}l${fx(sz * 1.6)} ${fx(-sz * 0.45)}`, ...strokeAttrs }));
    } else {
      axis.push(el('rect', { x: bx + sz * 0.1, y: by - sz * 0.8, width: sz * 0.9, height: sz * 1.6, fill: ctx.surface }));
      axis.push(el('path', { d: `M${fx(bx + sz * 0.05)} ${fx(by + sz * 0.8)}l${fx(sz * 0.45)} ${fx(-sz * 1.6)}M${fx(bx + sz * 0.65)} ${fx(by + sz * 0.8)}l${fx(sz * 0.45)} ${fx(-sz * 1.6)}`, ...strokeAttrs }));
    }
  }
  add(ctx, el('g', { class: 'pc-grid' }, grid.join('')));

  // ----- category labels
  if (showCat) {
    const cl = [];
    if (horizontal) {
      const lh = fs * 1.15;
      const stepSkip = band >= lh ? 1 : Math.ceil(lh / band);
      data.categories.forEach((c, i) => {
        if (i % stepSkip) return;
        const lines = band >= fs * 2.4 ? wrapText(c, fs, catPlan.catW, 2) : [fitText(c, fs, catPlan.catW)];
        lines.forEach((ln, li) => cl.push(text(ctx, plot.x - fs * 0.7, C(i) + (li - (lines.length - 1) / 2) * lh, ln, { size: fs, anchor: 'end' })));
      });
    } else {
      const y0 = plot.y + plot.h + fs * 0.9;
      data.categories.forEach((c, i) => {
        if (catPlan.mode === 'skip' && i % catPlan.step) return;
        const lines = catPlan.lines[i] || [c];
        if (catPlan.mode === 'rotate') {
          cl.push(text(ctx, C(i) + fs * 0.3, y0 - fs * 0.25, lines[0], { size: fs, anchor: 'end', rotate: catPlan.rot }));
        } else if (catPlan.mode === 'skip') {
          // left-aligned at the tick, kept inside the picture
          const w = textWidth(lines[0], fs);
          const x = clamp(C(i) - band * 0.4, box.x, Math.max(box.x, box.x + box.w - w - 2));
          cl.push(text(ctx, x, y0, lines[0], { size: fs, anchor: 'start' }));
        } else {
          lines.forEach((ln, li) => cl.push(text(ctx, C(i), y0 + li * fs * 1.2, ln, { size: fs, anchor: 'middle' })));
        }
      });
    }
    add(ctx, el('g', { class: 'pc-cats' }, cl.join('')));
  }
  add(ctx, el('g', { class: 'pc-axis' }, axis.join('')));
  if (yTitle) {
    const cx = horizontal ? plot.x + plot.w / 2 : box.x + fs * 0.6;
    const cy = horizontal ? plot.y + plot.h + (showVal ? fs * 2.9 : fs * 1.1) : plot.y + plot.h / 2;
    put(ctx, cx, cy, fitText(yTitle, valTitleSize, (horizontal ? plot.w : plot.h) - 10), { size: valTitleSize, anchor: 'middle', fill: ctx.muted, rotate: horizontal ? 0 : -90 });
  }
  if (xTitle) {
    const cx = horizontal ? box.x + fs * 0.6 : plot.x + plot.w / 2;
    const cy = horizontal ? plot.y + plot.h / 2 : box.y + box.h - fs * 0.6;
    put(ctx, cx, cy, fitText(xTitle, valTitleSize, (horizontal ? plot.h : plot.w) - 10), { size: valTitleSize, anchor: 'middle', fill: ctx.muted, rotate: horizontal ? -90 : 0 });
  }

  // ----- marks
  const fmtV = (v) => ctx.fmt(v);
  const labelSize = fs * 0.9;
  const labelQueue = []; // {text, x, y, anchor, fill, prio, alt}
  const gap = Math.max(2, k * 2);
  const radius = Math.max(3, Math.min(fs * 0.3, 6));
  const barIdx = series.map((s, i) => i).filter((i) => roles[i] === 'bar');
  const nBars = wf ? 1 : barIdx.length;
  const cap = fs * 3.2;
  const groupFrac = 0.74;
  const stacked = opt.stacked && !wf;
  const slots = stacked ? 1 : nBars;
  const thickness = Math.max(2, Math.min(cap, (band * groupFrac - gap * (slots - 1)) / Math.max(1, slots)));
  const groupW = slots * thickness + (slots - 1) * gap;

  const share = (si, c, v) => {
    if (stacked) {
      let tot = 0;
      barIdx.forEach((j) => {
        const x = series[j].values[c];
        if (isNum(x) && x > 0) tot += x;
      });
      return tot ? v / tot : 0;
    }
    let tot = 0;
    series[si].values.forEach((x) => {
      if (isNum(x) && x > 0) tot += x;
    });
    return tot ? v / tot : 0;
  };
  const labelText = (si, c, v) => (opt.labels === 'percent' ? pctShare(share(si, c, v)) : fmtV(v));

  const rectEl = (cat, off, thick, a, b, edge, fill, o) => {
    // a: value-pixel at base side, b: value-pixel at data end side
    const lo = Math.min(a, b);
    const len = Math.abs(b - a);
    const c0 = cat - groupW / 2 + off;
    const geom = horizontal ? { x: lo, y: c0, w: len, h: thick } : { x: c0, y: lo, w: thick, h: len };
    const d = roundedRect(geom.x, geom.y, geom.w, geom.h, radius, edge);
    barBoxes.push({ x: geom.x, y: geom.y, w: geom.w, h: geom.h, color: o.color || ctx.theme.c.chart[0] });
    return mark(ctx, 'path', { d, fill }, o);
  };

  const marks = [];
  const bars = [];
  const barBoxes = [];

  if (wf) {
    const steps = waterfallSteps(chart);
    const good = ctx.theme.c.good;
    const bad = ctx.theme.c.bad;
    const total = ctx.styles[0].color;
    let prevEnd = null;
    const conns = [];
    steps.forEach((s) => {
      if (s.skip) return;
      const fill = s.total ? total : s.delta >= 0 ? good : bad;
      const a = V(clamp(s.from, dom.min, dom.max));
      const b = V(clamp(s.to, dom.min, dom.max));
      const up = s.to >= s.from;
      const edge = horizontal ? (up ? 'right' : 'left') : up ? 'top' : 'bottom';
      const len = Math.abs(b - a);
      const name = data.categories[s.i];
      const tl = s.total ? `${name}: total ${fmtV(s.value)}` : `${name}: ${s.delta >= 0 ? '+' : ''}${fmtV(s.delta)}`;
      const o = { i: s.i, series: 0, index: s.i, value: s.value, title: tl };
      if (len < 1) bars.push(mark(ctx, 'rect', horizontal ? { x: a - 1, y: C(s.i) - thickness / 2, width: 2, height: thickness, fill } : { x: C(s.i) - thickness / 2, y: a - 1, width: thickness, height: 2, fill }, { ...o, cls: horizontal ? 'pc-g-x' : 'pc-g-y' }));
      else bars.push(rectEl(C(s.i), 0, thickness, a, b, edge, fill, { ...o, color: fill, cls: horizontal ? 'pc-g-x' : 'pc-g-y' }));
      if (prevEnd !== null) {
        const cp = V(clamp(s.from, dom.min, dom.max));
        const cPrev = prevEnd.c;
        if (horizontal) conns.push(el('line', { x1: cp, y1: cPrev + thickness / 2, x2: cp, y2: C(s.i) - thickness / 2, stroke: ctx.muted, 'stroke-width': k, 'stroke-opacity': 0.7 }));
        else conns.push(el('line', { x1: cPrev + thickness / 2, y1: cp, x2: C(s.i) - thickness / 2, y2: cp, stroke: ctx.muted, 'stroke-width': k, 'stroke-opacity': 0.7 }));
      }
      prevEnd = { c: C(s.i) };
      if (labelsOn) {
        const lab = s.total ? fmtV(s.value) : (s.delta >= 0 ? '+' : '') + fmtV(s.delta);
        labelQueue.push({ text: lab, cat: C(s.i), end: horizontal ? (up ? Math.max(a, b) : Math.min(a, b)) : (up ? Math.min(a, b) : Math.max(a, b)), up, a, b, fill, si: 0, idx: s.i });
      }
    });
    marks.push(el('g', { class: 'pc-connectors' }, conns.join('')));
  } else {
    // bars (clustered or stacked)
    barIdx.forEach((si, bi) => {
      const s = series[si];
      const fill = seriesFill(ctx, si);
      const solid = ctx.styles[si].color;
      s.values.forEach((v, c) => {
        if (!isNum(v)) return;
        let a;
        let b;
        let edge = 'none';
        let off = stacked ? 0 : bi * (thickness + gap);
        if (stacked) {
          // cumulative position for this category
          let pos = 0;
          let neg = 0;
          for (const j of barIdx) {
            if (j === si) break;
            const x = series[j].values[c];
            if (!isNum(x)) continue;
            if (x >= 0) pos += x;
            else neg += x;
          }
          const from = v >= 0 ? pos : neg;
          const to = from + v;
          a = vClamp(from);
          b = vClamp(to);
          // surface gap on the inside edge of non-first segments
          if (Math.abs(b - a) > gap * 2.5 && from !== 0) {
            const dir = Math.sign(b - a);
            a += dir * (gap / 2);
          }
          // round only the outermost segment
          let outer = true;
          for (let j = si + 1; j < series.length; j++) {
            if (roles[j] !== 'bar') continue;
            const x = series[j].values[c];
            if (isNum(x) && (x >= 0) === (v >= 0) && x !== 0) {
              outer = false;
              break;
            }
          }
          if (outer && v !== 0) edge = horizontal ? (v >= 0 ? 'right' : 'left') : v >= 0 ? 'top' : 'bottom';
        } else {
          a = base;
          b = vClamp(v);
          if (v !== 0) edge = horizontal ? (v >= 0 ? 'right' : 'left') : v >= 0 ? 'top' : 'bottom';
        }
        if (Math.abs(b - a) < 0.6) {
          if (v === 0) return;
          b = a + (v > 0 ? (horizontal ? 1 : -1) : horizontal ? -1 : 1);
        }
        const title = (series.length > 1 ? s.name + ', ' : '') + `${data.categories[c]}: ${fmtV(v)}`;
        bars.push(rectEl(C(c), off, thickness, a, b, edge, fill, { i: c, series: si, index: c, value: v, title, color: solid, cls: horizontal ? 'pc-g-x' : 'pc-g-y' }));
        if (labelsOn) {
          labelQueue.push({ text: labelText(si, c, v), cat: C(c) - groupW / 2 + off + thickness / 2, end: b, a, b, up: v >= 0, fill: solid, si, idx: c, stacked, v });
        }
      });
    });
    // stack totals above stacked columns
    if (stacked && labelsOn && opt.labels === 'value' && barIdx.length > 1) {
      for (let c = 0; c < n; c++) {
        let pos = 0;
        let neg = 0;
        barIdx.forEach((j) => {
          const x = series[j].values[c];
          if (!isNum(x)) return;
          if (x >= 0) pos += x;
          else neg += x;
        });
        if (pos === 0 && neg === 0) continue;
        const useNeg = pos === 0;
        const t = useNeg ? neg : pos;
        labelQueue.push({ text: fmtV(t), cat: C(c), end: vClamp(t), up: !useNeg, total: true, idx: c, si: -1 });
      }
    }
  }
  marks.push(el('g', { class: 'pc-bars' }, bars.join('')));

  // ----- lines and areas
  const lineEls = [];
  const areaEls = [];
  const dotEls = [];
  const dotBoxes = [];
  const endLabels = [];
  const markerR = Math.max(3.5, fs * 0.26);
  const showDots = (si) => {
    const cnt = series[si].values.filter(isNum).length;
    return cnt <= 30 && band >= markerR * 1.8;
  };
  // cumulative baseline for stacked areas
  const areaStack = (c, si) => {
    let lo = 0;
    for (let j = 0; j < si; j++) {
      if (roles[j] !== 'area') continue;
      const x = series[j].values[c];
      if (isNum(x)) lo += x;
    }
    return lo;
  };
  const areaOrder = series.map((s, i) => i).filter((i) => roles[i] === 'area');
  lineSeries.forEach((si) => {
    const s = series[si];
    const st = ctx.styles[si];
    const role = roles[si];
    const stackedArea = role === 'area' && opt.stacked;
    const pts = s.values.map((v, c) => {
      if (!isNum(v)) return [C(c), null, c, v];
      const val = stackedArea ? areaStack(c, si) + v : v;
      return [C(c), vClamp(val), c, v];
    });
    const rs = runs(pts);
    const pathOf = (seg) => (opt.smooth ? monotonePath(seg.map((p) => [p[0], p[1]])) : linearPath(seg.map((p) => [p[0], p[1]])));
    if (role === 'area') {
      const fillCol = st.pattern ? hatchFill(ctx, st.color) : st.color;
      rs.forEach((seg, ri) => {
        if (seg.length < 2) return;
        let d;
        if (stackedArea) {
          const lower = seg.map((p) => [p[0], vClamp(areaStack(p[2], si))]).reverse();
          d = pathOf(seg) + (opt.smooth ? monotonePath(lower, false) : linearPath(lower, false)) + 'z';
        } else {
          d = pathOf(seg) + `L${fx(seg[seg.length - 1][0])} ${fx(base)}L${fx(seg[0][0])} ${fx(base)}z`;
        }
        const op = stackedArea ? 0.82 : series.filter((_, j) => roles[j] === 'area').length > 1 ? 0.2 : 0.16;
        areaEls.push(mark(ctx, 'path', { d, fill: fillCol, 'fill-opacity': op }, { i: si, series: si, index: ri, cls: 'pc-fade', title: `${s.name} (area)` }));
      });
    }
    const strokeW = Math.max(2, k * 2);
    const dpath = rs.filter((seg) => seg.length > 1).map(pathOf).join('');
    if (dpath) {
      lineEls.push(
        mark(
          ctx,
          'path',
          { d: dpath, fill: 'none', stroke: st.color, 'stroke-width': stackedArea ? Math.max(1.5, k * 1.5) : strokeW, 'stroke-linejoin': 'round', 'stroke-linecap': 'round', 'stroke-dasharray': st.dash || undefined, pathLength: st.dash ? undefined : 100 },
          { i: si, series: si, cls: st.dash ? 'pc-fade' : 'pc-draw', title: s.name },
        ),
      );
    }
    // markers
    const dots = showDots(si);
    const lastIdx = (() => {
      for (let c = pts.length - 1; c >= 0; c--) if (pts[c][1] !== null) return c;
      return -1;
    })();
    pts.forEach((p, c) => {
      if (p[1] === null) return;
      const isolated = (c === 0 || pts[c - 1][1] === null) && (c === pts.length - 1 || pts[c + 1][1] === null);
      if (!(dots || isolated || c === lastIdx)) return;
      if (stackedArea) return;
      dotBoxes.push({ x: p[0] - markerR - 1, y: p[1] - markerR - 1, w: markerR * 2 + 2, h: markerR * 2 + 2 });
      dotEls.push(
        shapeEl(
          st.marker,
          p[0],
          p[1],
          markerR,
          { fill: st.color, stroke: ctx.surface, 'stroke-width': Math.max(1.5, k * 1.5) },
          { ctx, mark: { i: c, series: si, index: c, value: p[3], cls: 'pc-dot pc-pop', title: (series.length > 1 ? s.name + ', ' : '') + `${data.categories[c]}: ${fmtV(p[3])}` } },
        ),
      );
    });
    // end label anchor
    if (direct && lastIdx >= 0 && lastIdx >= pts.length - 1) {
      const lp = pts[lastIdx];
      let yy = lp[1];
      if (stackedArea) yy = (vClamp(areaStack(lp[2], si) + lp[3]) + vClamp(areaStack(lp[2], si))) / 2;
      endLabels.push({ si, x: lp[0], y: yy, text: s.name });
    }
    // stacked areas: one label inside the band where it is thickest (value or share of that stack)
    if (labelsOn && stackedArea) {
      let bestC = -1;
      let bestT = 0;
      pts.forEach((p, c) => {
        if (p[1] === null) return;
        const thick = Math.abs(vClamp(areaStack(c, si)) - p[1]);
        if (thick > bestT) {
          bestT = thick;
          bestC = c;
        }
      });
      if (bestC >= 0) {
        const p = pts[bestC];
        let stackTot = 0;
        series.forEach((s2, j) => {
          if (roles[j] === 'area' && isNum(s2.values[bestC])) stackTot += s2.values[bestC];
        });
        const txt = opt.labels === 'percent' ? pctShare(stackTot ? p[3] / stackTot : 0) : fmtV(p[3]);
        labelQueue.push({ text: txt, cat: p[0], area: true, y: (p[1] + vClamp(areaStack(bestC, si))) / 2, thick: bestT, fill: inkOn(mix(st.color, ctx.surface, 0.18)), si, idx: bestC });
      }
    }
    // value labels on lines
    if (labelsOn && !stackedArea) {
      const idxs = [];
      const valid = pts.map((p, c) => c).filter((c) => pts[c][1] !== null);
      if (valid.length <= 12) idxs.push(...valid);
      else {
        let mx = valid[0];
        let mn = valid[0];
        valid.forEach((c) => {
          if (pts[c][3] > pts[mx][3]) mx = c;
          if (pts[c][3] < pts[mn][3]) mn = c;
        });
        idxs.push(...new Set([valid[valid.length - 1], mx, mn]));
      }
      idxs.forEach((c) => {
        const p = pts[c];
        const share = (() => {
          let tot = 0;
          s.values.forEach((x) => {
            if (isNum(x) && x > 0) tot += x;
          });
          return tot ? p[3] / tot : 0;
        })();
        labelQueue.push({ text: opt.labels === 'percent' ? pctShare(share) : fmtV(p[3]), cat: p[0], end: p[1], up: true, line: true, fill: st.color, si, idx: c, r: markerR });
      });
    }
  });
  marks.push(el('g', { class: 'pc-areas' }, areaEls.join('')));
  marks.push(el('g', { class: 'pc-lines' }, lineEls.join('')));
  marks.push(el('g', { class: 'pc-dots' }, dotEls.join('')));
  add(ctx, el('g', { class: 'pc-marks' }, marks.join('')));

  // ----- data labels (collision-aware, two attempts at decreasing size)
  const labelEls = [];
  if (labelQueue.length) {
    const bounds = { x: box.x, y: box.y - fs * 0.2, w: box.w, h: box.h + fs * 0.2 };
    const attempt = (size) => {
      const placer = makePlacer(bounds, dotBoxes);
      const out = [];
      let missed = 0;
      for (const q of labelQueue) {
        const tw = textWidth(q.text, size);
        const th = size * 1.15;
        const cands = [];
        const m = size * 0.35;
        if (q.area) {
          if (q.thick >= th + 2) cands.push({ x: q.cat, y: q.y, anchor: 'middle', fill: q.fill });
        } else
        // candidate positions: outside the data end first, then inside
        if (horizontal) {
          const dirRight = q.up;
          const room = Math.abs((q.b ?? 0) - (q.a ?? 0));
          if (q.line) cands.push({ x: q.end + q.r + m, y: q.cat, anchor: 'start', fill: ctx.ink });
          else if (q.stacked && !q.total) {
            if (room >= tw + m * 2) cands.push({ x: (q.a + q.b) / 2, y: q.cat, anchor: 'middle', fill: inkOn(ctx.styles[q.si].color) });
          } else {
            cands.push({ x: dirRight ? q.end + m : q.end - m, y: q.cat, anchor: dirRight ? 'start' : 'end', fill: ctx.ink });
            if (!q.total && room >= tw + m * 2) cands.push({ x: dirRight ? q.end - m : q.end + m, y: q.cat, anchor: dirRight ? 'end' : 'start', fill: inkOn(q.fill) });
          }
        } else if (q.stacked && !q.total) {
          const room = Math.abs(q.b - q.a);
          if (room >= th + m && thickness >= tw + m) cands.push({ x: q.cat, y: (q.a + q.b) / 2, anchor: 'middle', fill: inkOn(ctx.styles[q.si].color) });
        } else if (q.line) {
          cands.push({ x: q.cat, y: q.end - q.r - th / 2 - 2, anchor: 'middle', fill: ctx.ink }, { x: q.cat, y: q.end + q.r + th / 2 + 2, anchor: 'middle', fill: ctx.ink });
        } else {
          const above = q.up;
          cands.push({ x: q.cat, y: above ? q.end - th / 2 - m * 0.6 : q.end + th / 2 + m * 0.6, anchor: 'middle', fill: ctx.ink });
          if (!q.total) {
            const room = Math.abs(q.b - q.a);
            if (room >= th * 1.5 && thickness >= tw * 0.9) cands.push({ x: q.cat, y: above ? q.end + th / 2 + m : q.end - th / 2 - m, anchor: 'middle', fill: inkOn(ctx.styles[q.si] ? ctx.styles[q.si].color : q.fill) });
          }
        }
        let ok = false;
        for (const cnd of cands) {
          const box2 = textBox(cnd.x, cnd.y, q.text, size, cnd.anchor);
          let fillCol = cnd.fill;
          if (q.line) {
            // a line label over a bar must sit wholly inside it (and then wears the bar's contrast ink)
            const hits = barBoxes.filter((b) => overlap(b, box2, 0));
            if (hits.length) {
              const inside = hits.find((b) => box2.x >= b.x && box2.x + box2.w <= b.x + b.w && box2.y >= b.y && box2.y + box2.h <= b.y + b.h);
              if (!inside) continue;
              fillCol = inkOn(inside.color);
            }
          }
          if (placer.tryPlace(box2)) {
            out.push(text(ctx, cnd.x, cnd.y, q.text, { size, anchor: cnd.anchor, fill: fillCol, weight: q.total ? 600 : 400, cls: 'pc-label' }));
            ok = true;
            break;
          }
        }
        if (!ok) missed++;
      }
      return { out, missed };
    };
    let res = attempt(labelSize);
    if (res.missed) {
      const r2 = attempt(labelSize * 0.8);
      if (r2.missed < res.missed) res = r2;
    }
    labelEls.push(...res.out);
  }
  add(ctx, el('g', { class: 'pc-labels' }, labelEls.join('')));

  // ----- direct end labels with leader lines when nudged
  if (endLabels.length) {
    const size = fs * 0.9;
    const lhh = size * 1.25;
    endLabels.sort((a, b) => a.y - b.y);
    const ys = endLabels.map((e) => e.y);
    for (let it = 0; it < 12; it++) {
      for (let i = 1; i < ys.length; i++) if (ys[i] - ys[i - 1] < lhh) ys[i] = ys[i - 1] + lhh;
      const over = ys[ys.length - 1] - (plot.y + plot.h);
      if (over > 0) for (let i = ys.length - 1; i >= 0; i--) ys[i] -= over * (i === ys.length - 1 ? 1 : 0.5);
    }
    const ex = plot.x + plot.w + (lineEdge ? fs * 0.6 : 0);
    const els = [];
    endLabels.forEach((e, i) => {
      const nudged = Math.abs(ys[i] - e.y) > fs * 0.3;
      const lx = e.x + markerR + fs * 0.3;
      const tx = Math.max(ex, lx) + fs * 0.35;
      if (nudged) els.push(el('polyline', { points: `${fx(e.x + markerR + 1)},${fx(e.y)} ${fx(tx - fs * 0.3)},${fx(ys[i])}`, fill: 'none', stroke: ctx.muted, 'stroke-width': k, 'stroke-opacity': 0.7 }));
      const room = box.x + box.w - tx;
      els.push(text(ctx, tx, ys[i], fitText(e.text, size, room), { size, fill: ctx.ink, weight: 600, cls: 'pc-direct' }));
    });
    add(ctx, el('g', { class: 'pc-direct-labels' }, els.join('')));
  }
  drawChrome(ctx);
}
