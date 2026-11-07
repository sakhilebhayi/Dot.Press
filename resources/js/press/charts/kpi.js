// KPI tiles: a row or grid of big numbers with signed deltas.
import { isNum, fx, fitText, wrapText, textWidth, clamp, compactNum, makeFormatter } from './util.js';
import { el, add, text, mark, planFrame, drawChrome, drawEmpty } from './svg.js';

/** KPIs from data.kpis, or from the first series when none are given. */
export function kpiItems(chart) {
  const { kpis, categories, series } = chart.data;
  if (kpis && kpis.length) return kpis;
  const s = series[0];
  if (!s) return [];
  return categories.map((c, i) => ({ label: c, value: isNum(s.values[i]) ? s.values[i] : null })).filter((k) => k.value !== null);
}

/** Direction of a delta: 1 up, -1 down, 0 flat. */
export function deltaDirection(d) {
  if (isNum(d)) return d > 0 ? 1 : d < 0 ? -1 : 0;
  if (typeof d === 'string') {
    const t = d.trim();
    if (t.startsWith('+')) return 1;
    if (t.startsWith('-') || t.startsWith('−')) return -1;
  }
  return 0;
}

export function deltaText(d) {
  if (isNum(d)) {
    const a = Math.abs(d);
    const body = (Number.isInteger(a) ? String(a) : a.toFixed(a < 10 ? 1 : 0)).replace(/\.0$/, '');
    return (d > 0 ? '+' : d < 0 ? '-' : '') + body + '%';
  }
  return String(d);
}

export function renderKpi(ctx) {
  const chart = ctx.chart;
  const { opt } = chart;
  const { fs } = ctx;
  const items = kpiItems(chart).slice(0, 24);
  const box = planFrame(ctx, [], { noLegend: true });
  if (!items.length) {
    drawEmpty(ctx, box, 'No figures to show yet.');
    drawChrome(ctx);
    return;
  }
  const n = items.length;
  const aspect = box.w / Math.max(1, box.h);
  let cols = n <= 3 ? n : n === 4 ? (aspect > 2.4 ? 4 : 2) : Math.ceil(Math.sqrt(n * aspect));
  cols = clamp(cols, 1, 6);
  // do not make tiles too narrow
  while (cols > 1 && (box.w - (cols - 1) * fs) / cols < fs * 7) cols--;
  const rows = Math.ceil(n / cols);
  const gap = fs * 0.9;
  const tw = (box.w - gap * (cols - 1)) / cols;
  const th = Math.min((box.h - gap * (rows - 1)) / rows, fs * 11);
  const gridH = rows * th + (rows - 1) * gap;
  const gy = box.y + Math.max(0, (box.h - gridH) / 2);
  const pad = Math.min(fs * 1.1, tw * 0.1);
  const els = [];
  const fmt = makeFormatter(opt);
  items.forEach((it, i) => {
    const r = Math.floor(i / cols);
    const c = i % cols;
    const x = box.x + c * (tw + gap);
    const y = gy + r * (th + gap);
    const inner = tw - 2 * pad;
    const parts = [];
    parts.push(el('rect', { x, y, width: tw, height: th, rx: Math.min(fs * 0.7, 14), fill: ctx.theme.c.surface }));
    const labelSize = fs * 0.95;
    let hasDelta = it.delta !== undefined;
    let noteLines = it.note ? wrapText(it.note, fs * 0.8, inner, th > fs * 6 ? 2 : 1) : [];
    let vs = clamp(th * 0.3, fs * 1.3, fs * 3.4);
    const unit = it.unit || '';
    let vstr = isNum(it.value) ? fmt(it.value) : it.value === null ? '' : String(it.value);
    const fitVal = (str) => {
      let s = vs;
      while (s > fs * 0.9 && textWidth(str + (unit ? ' ' + unit : ''), s, 700) > inner) s -= 1;
      return s;
    };
    let size = fitVal(vstr);
    if (isNum(it.value) && textWidth(vstr, size, 700) > inner) {
      vstr = compactNum(it.value);
      size = fitVal(vstr);
    }
    const labelH = labelSize * 1.3;
    const heights = () => labelH + size * 1.15 + (hasDelta ? fs * 1.45 : 0) + (noteLines.length ? noteLines.length * fs * 0.8 * 1.25 + fs * 0.2 : 0);
    // too little room: drop the note, then the change, then shrink the figure
    if (heights() > th - pad * 0.6) noteLines = [];
    if (heights() > th - pad * 0.6) hasDelta = false;
    while (size > fs * 0.5 && heights() > th - pad * 0.4) size -= 1;
    const deltaH = hasDelta ? fs * 1.45 : 0;
    const noteH = noteLines.length * fs * 0.8 * 1.25;
    const contentH = heights();
    let cy = y + Math.max(pad * 0.6, (th - contentH) / 2);
    parts.push(text(ctx, x + pad, cy + labelH / 2, fitText(it.label || '', labelSize, inner), { size: labelSize, fill: ctx.muted, cls: 'pc-kpi-label' }));
    cy += labelH;
    parts.push(text(ctx, x + pad, cy + size * 0.58, fitText(vstr, size, inner, 700), { size, weight: 700, cls: 'pc-kpi-value' }));
    if (unit) {
      const vw = textWidth(vstr, size, 700);
      if (vw + textWidth(unit, size * 0.5) + 6 <= inner) parts.push(text(ctx, x + pad + vw + size * 0.15, cy + size * 0.58 + size * 0.1, unit, { size: size * 0.5, fill: ctx.muted }));
    }
    cy += size * 1.15;
    if (hasDelta) {
      const dir = deltaDirection(it.delta);
      const upGood = it.good !== 'down';
      const good = dir === 0 ? null : (dir > 0) === upGood;
      const col = dir === 0 ? ctx.muted : good ? ctx.theme.c.good : ctx.theme.c.bad;
      const ds = fs * 0.95;
      const ty = cy + deltaH / 2;
      const tri = ds * 0.34;
      if (dir !== 0) {
        const d = dir > 0 ? `M${fx(x + pad)} ${fx(ty + tri * 0.8)}L${fx(x + pad + tri * 2)} ${fx(ty + tri * 0.8)}L${fx(x + pad + tri)} ${fx(ty - tri * 0.9)}z` : `M${fx(x + pad)} ${fx(ty - tri * 0.8)}L${fx(x + pad + tri * 2)} ${fx(ty - tri * 0.8)}L${fx(x + pad + tri)} ${fx(ty + tri * 0.9)}z`;
        parts.push(el('path', { d, fill: col }));
      } else parts.push(el('rect', { x: x + pad, y: ty - 1, width: tri * 2, height: 2, fill: col }));
      parts.push(text(ctx, x + pad + tri * 2 + ds * 0.4, ty, fitText(deltaText(it.delta), ds, inner - tri * 2 - ds * 0.4, 600), { size: ds, fill: col, weight: 600, cls: 'pc-kpi-delta' }));
      cy += deltaH;
    }
    noteLines.forEach((ln, li) => parts.push(text(ctx, x + pad, cy + fs * 0.2 + li * fs * 0.8 * 1.25 + fs * 0.4, ln, { size: fs * 0.8, fill: ctx.muted })));
    els.push(mark(ctx, 'g', {}, { i, series: 0, index: i, value: isNum(it.value) ? it.value : undefined, cls: 'pc-g-c', inner: parts.join(''), title: `${it.label}: ${vstr}${unit ? ' ' + unit : ''}${hasDelta ? ', change ' + deltaText(it.delta) : ''}` }));
  });
  add(ctx, el('g', { class: 'pc-marks pc-tiles' }, els.join('')));
  drawChrome(ctx);
}
