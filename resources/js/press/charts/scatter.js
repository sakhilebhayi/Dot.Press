// Scatter and bubble charts: two numeric axes, marker shapes carry series identity beside colour.
import { isNum, fitText, textWidth, clamp, tickFormatter, makeFormatter } from './util.js';
import { niceTicks } from './ticks.js';
import { el, add, put, text, mark, shapeEl, makePlacer, textBox, planFrame, drawChrome, drawEmpty } from './svg.js';

function range(vals) {
  let lo = Infinity;
  let hi = -Infinity;
  for (const v of vals) {
    if (v < lo) lo = v;
    if (v > hi) hi = v;
  }
  return [lo, hi];
}

export function renderScatter(ctx) {
  const chart = ctx.chart;
  const bubble = chart.type === 'bubble';
  const { opt, data } = chart;
  const { fs, k } = ctx;
  const series = data.series;
  const all = [];
  series.forEach((s, si) => (s.points || []).forEach((p, i) => all.push({ ...p, si, i })));
  const legend = series.map((s, i) => ({ name: s.name, color: ctx.styles[i].color, kind: 'marker', marker: ctx.styles[i].marker, opacity: bubble ? 0.6 : 1 }));
  if (bubble) ctx.notes.push('Bubble area is proportional to size.');
  const box = planFrame(ctx, series.length > 1 ? legend : legend, { noLegend: !all.length });
  if (!all.length) {
    drawEmpty(ctx, box, 'No points to chart yet.');
    drawChrome(ctx);
    return;
  }

  const xs = range(all.map((p) => p.x));
  const ys = range(all.map((p) => p.y));
  const pad = (a, b) => {
    const d = (b - a) * 0.06;
    return d > 0 ? [a - d, b + d] : [a, b];
  };
  const [xl, xh] = pad(xs[0], xs[1]);
  let [yl, yh] = pad(ys[0], ys[1]);
  if (ys[0] >= 0 && ys[0] < 0.4 * ys[1]) yl = 0;
  const xfmtOpt = { fmt: opt.fmt === 'pct' || opt.fmt === 'cur' ? 'num' : opt.fmt, dec: opt.dec };

  let plot = { x: box.x, y: box.y, w: box.w, h: box.h };
  let xt;
  let yt;
  let yFmt;
  let xFmt;
  for (let pass = 0; pass < 3; pass++) {
    yt = niceTicks(opt.ax.y.min ?? yl, opt.ax.y.max ?? yh, clamp(Math.floor(plot.h / (fs * 3.1)), 2, 8), isNum(opt.ax.y.min) || isNum(opt.ax.y.max) ? { exact: true } : {});
    yFmt = tickFormatter(opt, yt.step);
    xt = niceTicks(xl, xh, clamp(Math.floor(plot.w / (fs * 6)), 2, 9));
    xFmt = tickFormatter(xfmtOpt, xt.step);
    const yw = Math.max(...yt.ticks.map((t) => textWidth(yFmt(t), fs * 0.95)));
    const left = box.x + (opt.ax.y.title ? fs * 1.6 : 0) + (opt.ax.y.show ? yw + fs * 0.8 : fs * 0.3);
    const right = box.x + box.w - Math.max(fs * 1.0, textWidth(xFmt(xt.ticks[xt.ticks.length - 1]), fs * 0.95) / 2);
    const bottom = box.y + box.h - (opt.ax.x.show ? fs * 1.9 : fs * 0.3) - (opt.ax.x.title ? fs * 1.5 : 0);
    const top = box.y + fs * 0.9;
    plot = { x: left, y: top, w: Math.max(20, right - left), h: Math.max(20, bottom - top) };
  }
  const xmin = xt.min;
  const xmax = xt.max > xt.min ? xt.max : xt.min + 1;
  const ymin = isNum(opt.ax.y.min) ? opt.ax.y.min : yt.min;
  const ymax = isNum(opt.ax.y.max) ? opt.ax.y.max : yt.max > yt.min ? yt.max : yt.min + 1;
  const rMax = Math.max(fs * 0.8, Math.min(plot.w, plot.h) / 9);
  const ins = bubble ? rMax * 0.9 : Math.max(4, fs * 0.3) + 1;
  const X = (v) => plot.x + ins + ((v - xmin) / (xmax - xmin)) * (plot.w - 2 * ins);
  const Y = (v) => plot.y + plot.h - ins - ((clamp(v, ymin, ymax) - ymin) / (ymax - ymin)) * (plot.h - 2 * ins);

  const grid = [];
  const axis = [];
  yt.ticks.forEach((t) => {
    if (t < ymin || t > ymax) return;
    const y = Y(t);
    if (opt.grid) grid.push(el('line', { x1: plot.x, y1: y, x2: plot.x + plot.w, y2: y, stroke: ctx.line, 'stroke-width': k }));
    if (opt.ax.y.show) axis.push(text(ctx, plot.x - fs * 0.6, y, yFmt(t), { size: fs * 0.95, anchor: 'end', fill: ctx.muted }));
  });
  xt.ticks.forEach((t) => {
    const x = X(t);
    if (opt.grid) grid.push(el('line', { x1: x, y1: plot.y, x2: x, y2: plot.y + plot.h, stroke: ctx.line, 'stroke-width': k }));
    if (opt.ax.x.show) axis.push(text(ctx, x, plot.y + plot.h + fs * 1.15, xFmt(t), { size: fs * 0.95, anchor: 'middle', fill: ctx.muted }));
  });
  axis.push(el('line', { x1: plot.x, y1: plot.y + plot.h, x2: plot.x + plot.w, y2: plot.y + plot.h, stroke: ctx.muted, 'stroke-width': k }));
  axis.push(el('line', { x1: plot.x, y1: plot.y, x2: plot.x, y2: plot.y + plot.h, stroke: ctx.muted, 'stroke-width': k }));
  add(ctx, el('g', { class: 'pc-grid' }, grid.join('')));
  add(ctx, el('g', { class: 'pc-axis' }, axis.join('')));
  if (opt.ax.y.title) put(ctx, box.x + fs * 0.6, plot.y + plot.h / 2, fitText(opt.ax.y.title, fs * 0.9, plot.h - 10), { size: fs * 0.9, anchor: 'middle', fill: ctx.muted, rotate: -90 });
  if (opt.ax.x.title) put(ctx, plot.x + plot.w / 2, box.y + box.h - fs * 0.6, fitText(opt.ax.x.title, fs * 0.9, plot.w - 10), { size: fs * 0.9, anchor: 'middle', fill: ctx.muted });

  // marks (bubbles largest first so small ones stay visible)
  const rVals = all.map((p) => (isNum(p.r) ? p.r : 0));
  const rMaxVal = Math.max(0, ...rVals);
  const radius = (p) => {
    if (!bubble) return Math.max(4, fs * 0.3);
    if (!rMaxVal) return Math.max(5, rMax * 0.4);
    return Math.max(3, rMax * Math.sqrt((isNum(p.r) ? p.r : 0) / rMaxVal));
  };
  const order = all.map((p, n) => ({ p, n })).sort((a, b) => (bubble ? radius(b.p) - radius(a.p) : a.n - b.n));
  const els = [];
  const boxes = [];
  order.forEach(({ p, n }, idx) => {
    const st = ctx.styles[p.si];
    const r = radius(p);
    const cx = X(p.x);
    const cy = Y(p.y);
    boxes.push({ p, cx, cy, r });
    const title = `${series[p.si].name}${p.label ? ', ' + p.label : ''}: x ${makeFormatter(xfmtOpt)(p.x)}, y ${ctx.fmt(p.y)}${bubble && isNum(p.r) ? ', size ' + makeFormatter({ fmt: 'num' })(p.r) : ''}`;
    els.push(
      shapeEl(
        bubble ? 'circle' : st.marker,
        cx,
        cy,
        r,
        bubble ? { fill: st.color, 'fill-opacity': 0.6, stroke: st.color, 'stroke-width': Math.max(1.5, k * 1.5) } : { fill: st.color, 'fill-opacity': 0.85, stroke: ctx.surface, 'stroke-width': Math.max(1, k) },
        { ctx, mark: { i: p.i, series: p.si, index: p.i, value: p.y, cls: 'pc-dot pc-pop', title } },
      ),
    );
  });
  add(ctx, el('g', { class: 'pc-marks' }, els.join('')));

  // labels: point labels, or y values, placed without overlapping each other or other marks
  if (opt.labels !== 'none' && all.length <= 40) {
    const size = fs * 0.85;
    const placer = makePlacer({ x: plot.x - 2, y: plot.y - fs, w: plot.w + fs * 2, h: plot.h + fs * 1.2 }, boxes.map((b) => ({ x: b.cx - b.r, y: b.cy - b.r, w: b.r * 2, h: b.r * 2 })));
    const out = [];
    for (const b of boxes) {
      const str = b.p.label || (opt.labels === 'percent' ? '' : ctx.fmt(b.p.y));
      if (!str) continue;
      const cands = [
        { x: b.cx + b.r + 3, y: b.cy, anchor: 'start' },
        { x: b.cx, y: b.cy - b.r - size * 0.7, anchor: 'middle' },
        { x: b.cx - b.r - 3, y: b.cy, anchor: 'end' },
        { x: b.cx, y: b.cy + b.r + size * 0.7, anchor: 'middle' },
      ];
      for (const c of cands) {
        const tb = textBox(c.x, c.y, str, size, c.anchor);
        // own marker is in the blocked list; shrink the test box slightly so its own edge does not block it
        const fitsBox = { x: tb.x, y: tb.y, w: tb.w, h: tb.h };
        const own = { x: b.cx - b.r, y: b.cy - b.r, w: b.r * 2, h: b.r * 2 };
        const saved = placer.blocked.indexOf(placer.blocked.find((q) => q.x === own.x && q.y === own.y && q.w === own.w));
        const removed = saved >= 0 ? placer.blocked.splice(saved, 1)[0] : null;
        const ok = placer.tryPlace(fitsBox);
        if (removed) placer.blocked.push(removed);
        if (ok) {
          out.push(text(ctx, c.x, c.y, str, { size, anchor: c.anchor, cls: 'pc-label' }));
          break;
        }
      }
    }
    add(ctx, el('g', { class: 'pc-labels' }, out.join('')));
  }
  drawChrome(ctx);
}
