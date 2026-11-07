// Funnel: ordered stages as centred bars whose width is proportional to the value.
import { isNum, fitText, textWidth, inkOn, pctShare } from './util.js';
import { el, add, text, mark, roundedRect, planFrame, drawChrome, drawEmpty, seriesFill } from './svg.js';

export function renderFunnel(ctx) {
  const chart = ctx.chart;
  const { opt, data } = chart;
  const { fs } = ctx;
  const n = data.categories.length;
  const vals = data.series[0] ? data.series[0].values.map((v) => (isNum(v) && v > 0 ? v : 0)) : [];
  const maxV = Math.max(0, ...vals);
  const box = planFrame(ctx, [], { noLegend: true });
  if (!n || maxV <= 0) {
    drawEmpty(ctx, box, n ? 'No values above zero to show.' : 'No data to chart yet.');
    drawChrome(ctx);
    return;
  }
  const size = fs * 0.95;
  const nameW = Math.min(box.w * 0.3, Math.max(...data.categories.map((c) => textWidth(c, size))) + fs * 0.5);
  const x0 = box.x + nameW + fs * 0.8;
  const maxBar = Math.max(30, box.x + box.w - x0);
  const gapY = Math.max(3, fs * 0.35);
  const rowH = (box.h - gapY * (n - 1)) / n;
  const barH = Math.max(4, Math.min(rowH, fs * 3.4));
  const fill = seriesFill(ctx, 0);
  const solid = ctx.styles[0].color;
  const bars = [];
  const labels = [];
  const names = [];
  const first = vals[0] || maxV;
  const totalH = n * barH + (n - 1) * gapY;
  const y0 = box.y + (box.h - totalH) / 2;
  data.categories.forEach((c, i) => {
    const w = Math.max(2, (vals[i] / maxV) * maxBar);
    const cx = x0 + maxBar / 2;
    const y = y0 + i * (barH + gapY);
    const title = `${c}: ${ctx.fmt(vals[i])}${first ? ` (${pctShare(vals[i] / first)} of first stage)` : ''}`;
    bars.push(mark(ctx, 'path', { d: roundedRect(cx - w / 2, y, w, barH, 4, 'none'), fill }, { i, series: 0, index: i, value: vals[i], cls: 'pc-g-c', title }));
    names.push(text(ctx, box.x, y + barH / 2, fitText(c, size, nameW), { size }));
    if (opt.labels !== 'none') {
      const str = opt.labels === 'percent' ? pctShare(first ? vals[i] / first : 0) : ctx.fmt(vals[i]);
      const tw = textWidth(str, size, 600);
      if (tw + fs * 0.8 <= w) labels.push(text(ctx, cx, y + barH / 2, str, { size, anchor: 'middle', weight: 600, fill: inkOn(solid), cls: 'pc-label' }));
      else labels.push(text(ctx, cx + w / 2 + fs * 0.4, y + barH / 2, str, { size, weight: 600, cls: 'pc-label' }));
    }
  });
  add(ctx, el('g', { class: 'pc-cats' }, names.join('')));
  add(ctx, el('g', { class: 'pc-marks' }, bars.join('')));
  add(ctx, el('g', { class: 'pc-labels' }, labels.join('')));
  drawChrome(ctx);
}
