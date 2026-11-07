// Radar chart: categories are the spokes, scale always starts at zero.
import { isNum, fx, wrapText, textWidth, clamp, tickFormatter } from './util.js';
import { niceTicks } from './ticks.js';
import { el, add, text, mark, shapeEl, makePlacer, textBox, planFrame, drawChrome, drawEmpty, legendForSeries, seriesFill } from './svg.js';

export function renderRadar(ctx) {
  const chart = ctx.chart;
  const { opt, data } = chart;
  const { fs, k } = ctx;
  const n = data.categories.length;
  const series = data.series;
  const legend = legendForSeries(ctx, series.map(() => 'line'));
  const hasData = n >= 3 && series.some((s) => s.values.some(isNum));
  const negs = series.reduce((a, s) => a + s.values.filter((v) => isNum(v) && v < 0).length, 0);
  if (negs && hasData) ctx.notes.push('Negative values are drawn at the centre.');
  const box = planFrame(ctx, legend, { noLegend: !hasData });
  if (!hasData) {
    drawEmpty(ctx, box, n < 3 ? 'A radar chart needs at least 3 categories.' : 'No values to chart yet.');
    drawChrome(ctx);
    return;
  }
  const size = fs * 0.95;
  let maxV = 0;
  series.forEach((s) => s.values.forEach((v) => {
    if (isNum(v) && v > maxV) maxV = v;
  }));
  const nt = niceTicks(0, maxV || 1, 5);
  const top = nt.max;
  const rings = nt.ticks.filter((t) => t > 0);
  const fmt = tickFormatter(opt, nt.step);

  const labelW = Math.min(box.w * 0.24, Math.max(...data.categories.map((c) => textWidth(c, size))) + 2);
  const R = Math.max(6, Math.min(box.w / 2 - labelW - fs * 0.8, box.h / 2 - fs * 2.0));
  const cx = box.x + box.w / 2;
  const cy = box.y + box.h / 2;
  const ang = (i) => -Math.PI / 2 + (i * 2 * Math.PI) / n;
  const pt = (i, v) => {
    const r = (clamp(v, 0, top) / top) * R;
    return [cx + r * Math.cos(ang(i)), cy + r * Math.sin(ang(i))];
  };

  const grid = [];
  rings.forEach((t) => {
    const pts = data.categories.map((_, i) => pt(i, t).map(fx).join(',')).join(' ');
    grid.push(el('polygon', { points: pts, fill: 'none', stroke: ctx.line, 'stroke-width': k }));
  });
  data.categories.forEach((_, i) => {
    const [x, y] = pt(i, top);
    grid.push(el('line', { x1: cx, y1: cy, x2: x, y2: y, stroke: ctx.line, 'stroke-width': k }));
  });
  add(ctx, el('g', { class: 'pc-grid' }, grid.join('')));
  // ring values along the first spoke, to the right of it
  const ringLabels = rings.map((t) => {
    const [x, y] = pt(0, t);
    return text(ctx, x + fs * 0.35, y - fs * 0.05, fmt(t), { size: fs * 0.75, fill: ctx.muted, anchor: 'start', halo: true });
  });
  add(ctx, el('g', { class: 'pc-axis' }, ringLabels.join('')));

  // series
  const els = [];
  const dots = [];
  const anchors = [];
  series.forEach((s, si) => {
    const st = ctx.styles[si];
    const pts = data.categories.map((_, i) => pt(i, isNum(s.values[i]) ? s.values[i] : 0));
    const d = 'M' + pts.map((p) => fx(p[0]) + ' ' + fx(p[1])).join('L') + 'z';
    els.push(mark(ctx, 'path', { d, fill: seriesFill(ctx, si), 'fill-opacity': series.length > 2 ? 0.1 : 0.16, stroke: st.color, 'stroke-width': Math.max(2, k * 2), 'stroke-linejoin': 'round', 'stroke-dasharray': st.dash || undefined }, { i: si, series: si, cls: 'pc-fade', title: `${s.name} (radar)` }));
    if (n <= 16) {
      pts.forEach((p, i) => {
        if (!isNum(s.values[i])) return;
        dots.push(shapeEl(st.marker, p[0], p[1], Math.max(3.5, fs * 0.24), { fill: st.color, stroke: ctx.surface, 'stroke-width': Math.max(1.5, k * 1.5) }, { ctx, mark: { i, series: si, index: i, value: s.values[i], cls: 'pc-dot pc-pop', title: `${s.name}, ${data.categories[i]}: ${ctx.fmt(s.values[i])}` } }));
        anchors.push({ si, i, x: p[0], y: p[1], v: s.values[i] });
      });
    }
  });
  add(ctx, el('g', { class: 'pc-marks' }, els.join('') + dots.join('')));

  // category labels around the rim
  const catEls = [];
  const blocked = [];
  data.categories.forEach((c, i) => {
    const a = ang(i);
    const x = cx + (R + fs * 0.8) * Math.cos(a);
    const y = cy + (R + fs * 0.8) * Math.sin(a);
    const cos = Math.cos(a);
    const anchor = cos > 0.3 ? 'start' : cos < -0.3 ? 'end' : 'middle';
    const room = anchor === 'middle' ? Math.min(box.w * 0.3, labelW * 1.6) : anchor === 'start' ? box.x + box.w - x : x - box.x;
    const lines = wrapText(c, size, Math.max(30, Math.min(room, labelW + fs)), 2);
    const sin = Math.sin(a);
    const baseY = y + (sin > 0.5 ? size * 0.5 : sin < -0.5 ? -size * 0.5 * (lines.length - 1) - size * 0.2 : 0) - ((lines.length - 1) * size * 1.15) / 2 * (Math.abs(sin) <= 0.5 ? 1 : 0);
    lines.forEach((ln, li) => {
      const ly = baseY + li * size * 1.15;
      catEls.push(text(ctx, x, ly, ln, { size, anchor }));
      blocked.push(textBox(x, ly, ln, size, anchor));
    });
  });
  add(ctx, el('g', { class: 'pc-cats' }, catEls.join('')));

  // value labels at vertices (few series only), avoiding category labels and each other
  if (opt.labels !== 'none' && series.length <= 2 && n <= 10) {
    const placer = makePlacer({ x: box.x, y: box.y, w: box.w, h: box.h }, blocked);
    const out = [];
    const ls = fs * 0.8;
    anchors.forEach((a) => {
      const str = opt.labels === 'percent' ? '' : ctx.fmt(a.v);
      if (!str) return;
      const dx = a.x - cx;
      const dy = a.y - cy;
      const len = Math.hypot(dx, dy) || 1;
      const x = a.x + (dx / len) * (fs * 0.9);
      const y = a.y + (dy / len) * (fs * 0.9);
      const anchor = dx > 4 ? 'start' : dx < -4 ? 'end' : 'middle';
      const tb = textBox(x, y, str, ls, anchor);
      if (placer.tryPlace(tb)) out.push(text(ctx, x, y, str, { size: ls, anchor, cls: 'pc-label' }));
    });
    add(ctx, el('g', { class: 'pc-labels' }, out.join('')));
  }
  drawChrome(ctx);
}
