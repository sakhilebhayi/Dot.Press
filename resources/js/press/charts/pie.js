// Pie and doughnut charts.
import { isNum, fx, fitText, textWidth, inkOn, pctShare } from './util.js';
import { seriesStyle } from './theme.js';
import { el, add, put, text, mark, planFrame, drawChrome, drawEmpty, hatchFill } from './svg.js';

/** Slices from the first series: negatives and gaps count as zero. Optionally folds the tail into "Other". */
export function pieSlices(chart) {
  const { categories, series } = chart.data;
  const vals = series[0] ? series[0].values : [];
  let slices = categories.map((name, i) => ({ name: name || String(i + 1), value: isNum(vals[i]) && vals[i] > 0 ? vals[i] : 0, index: i, raw: vals[i] }));
  const folded = [];
  if (chart.opt.other && slices.length > 8) {
    const keep = slices
      .map((s) => s)
      .sort((a, b) => b.value - a.value)
      .slice(0, 7)
      .map((s) => s.index);
    const kept = slices.filter((s) => keep.includes(s.index));
    const rest = slices.filter((s) => !keep.includes(s.index));
    folded.push(...rest.map((r) => r.name));
    slices = kept.concat([{ name: 'Other', value: rest.reduce((a, r) => a + r.value, 0), index: -1, other: true }]);
  }
  return { slices, folded };
}

const rad = (deg) => (deg * Math.PI) / 180;

function arcPath(cx, cy, R, r, a0, a1) {
  const sweep = a1 - a0;
  if (sweep >= 359.99) {
    // full circle (or ring)
    const full = (rr) => `M${fx(cx + rr)} ${fx(cy)}A${fx(rr)} ${fx(rr)} 0 1 1 ${fx(cx - rr)} ${fx(cy)}A${fx(rr)} ${fx(rr)} 0 1 1 ${fx(cx + rr)} ${fx(cy)}z`;
    return r > 0 ? full(R) + full(r) : full(R);
  }
  const large = sweep > 180 ? 1 : 0;
  const x0 = cx + R * Math.cos(rad(a0));
  const y0 = cy + R * Math.sin(rad(a0));
  const x1 = cx + R * Math.cos(rad(a1));
  const y1 = cy + R * Math.sin(rad(a1));
  if (r <= 0) return `M${fx(cx)} ${fx(cy)}L${fx(x0)} ${fx(y0)}A${fx(R)} ${fx(R)} 0 ${large} 1 ${fx(x1)} ${fx(y1)}z`;
  const xi1 = cx + r * Math.cos(rad(a1));
  const yi1 = cy + r * Math.sin(rad(a1));
  const xi0 = cx + r * Math.cos(rad(a0));
  const yi0 = cy + r * Math.sin(rad(a0));
  return `M${fx(x0)} ${fx(y0)}A${fx(R)} ${fx(R)} 0 ${large} 1 ${fx(x1)} ${fx(y1)}L${fx(xi1)} ${fx(yi1)}A${fx(r)} ${fx(r)} 0 ${large} 0 ${fx(xi0)} ${fx(yi0)}z`;
}

export function renderPie(ctx) {
  const chart = ctx.chart;
  const { opt } = chart;
  const { fs, k } = ctx;
  const donut = chart.type === 'doughnut';
  const { slices, folded } = pieSlices(chart);
  const total = slices.reduce((a, s) => a + s.value, 0);
  const neg = (chart.data.series[0] ? chart.data.series[0].values : []).filter((v) => isNum(v) && v < 0).length;
  if (neg) ctx.notes.push(`${neg} negative value${neg === 1 ? ' is' : 's are'} not shown in a pie.`);
  if (folded.length) ctx.notes.push(`Other: ${folded.slice(0, 6).join(', ')}${folded.length > 6 ? ` and ${folded.length - 6} more` : ''}.`);

  const styles = slices.map((s, i) => seriesStyle(i, ctx.theme, s.other ? ctx.theme.c.muted : opt.colors && opt.colors[i]));
  const legend = slices.map((s, i) => ({ name: s.name, color: styles[i].color, kind: 'swatch', fill: styles[i].pattern ? hatchFill(ctx, styles[i].color) : styles[i].color }));
  const box = planFrame(ctx, legend, { noLegend: total <= 0 });

  if (slices.length === 0 || total <= 0) {
    drawEmpty(ctx, box, slices.length === 0 ? 'No data to chart yet.' : 'No values above zero to show.');
    drawChrome(ctx);
    return;
  }

  const named = slices.length <= 4 && ctx.w >= 420;
  const labelsOn = opt.labels !== 'none';
  const size = fs * 0.9;
  const lines = slices.map((s) => {
    const share = s.value / total;
    const val = opt.labels === 'percent' ? pctShare(share) : ctx.fmt(s.value);
    return { share, val };
  });

  // decide inside / outside placement
  const place = slices.map(() => 'none');
  let outsideW = 0;
  const outsideCount = [0, 0];
  const R0 = Math.min(box.w, box.h) / 2 - fs * 0.4;
  const innerFrac = donut ? 0.6 : 0;
  slices.forEach((s, i) => {
    const share = lines[i].share;
    if (share <= 0) return;
    const wantName = named;
    if (!labelsOn && !wantName) return;
    const thetaRad = rad(share * 360);
    const rMid = R0 * (donut ? (1 + innerFrac) / 2 : 0.62);
    const chord = 2 * rMid * Math.sin(Math.min(thetaRad, Math.PI) / 2);
    const thick = donut ? R0 * (1 - innerFrac) : R0 * 0.55;
    const fits = labelsOn && !wantName && share >= 0.02 && textWidth(lines[i].val, size) * 1.3 + size * 0.6 <= chord && size * 1.15 <= thick && (share > 0.06 || chord > textWidth(lines[i].val, size) * 1.4);
    if (fits) place[i] = 'inside';
    else {
      place[i] = 'outside';
      const tiny = !wantName && share < 0.02;
      const w = tiny ? textWidth(`${s.name} ${lines[i].val}`, size) : Math.max(textWidth(lines[i].val, size), wantName ? textWidth(s.name, size, 600) : 0);
      outsideW = Math.max(outsideW, w);
    }
  });
  const anyOutside = place.includes('outside');
  const maxOut = Math.min(outsideW, box.w * 0.28);
  const R = Math.max(20, Math.min(R0, anyOutside ? Math.min(R0 - fs * 1.4, box.w / 2 - maxOut - fs * 2.2) : R0, anyOutside ? box.h / 2 - fs * 1.4 : R0));
  const cx = box.x + box.w / 2;
  const cy = box.y + box.h / 2;
  const r = donut ? R * innerFrac : 0;
  const gap = Math.max(2, k * 2);

  // slices
  const paths = [];
  const angles = [];
  let a = -90;
  slices.forEach((s, i) => {
    const sweep = (s.value / total) * 360;
    angles.push([a, a + sweep]);
    if (sweep > 0) {
      const fill = styles[i].pattern ? hatchFill(ctx, styles[i].color) : styles[i].color;
      const title = `${s.name}: ${ctx.fmt(s.value)} (${pctShare(s.value / total)})`;
      paths.push(mark(ctx, 'path', { d: arcPath(cx, cy, R, r, a, a + sweep), fill, stroke: ctx.surface, 'stroke-width': gap, 'stroke-linejoin': 'round', 'fill-rule': 'evenodd' }, { i, series: 0, index: s.index, value: s.value, cls: 'pc-g-c', title }));
    }
    a += sweep;
  });
  add(ctx, el('g', { class: 'pc-marks pc-slices' }, paths.join('')));

  // labels
  const labelEls = [];
  const sides = { l: [], r: [] };
  slices.forEach((s, i) => {
    if (place[i] === 'none') return;
    const [a0, a1] = angles[i];
    const mid = rad((a0 + a1) / 2);
    if (place[i] === 'inside') {
      const rr = donut ? (R + r) / 2 : R * 0.64;
      labelEls.push(text(ctx, cx + rr * Math.cos(mid), cy + rr * Math.sin(mid), lines[i].val, { size, anchor: 'middle', fill: inkOn(styles[i].color), weight: 600, cls: 'pc-label' }));
    } else {
      const right = Math.cos(mid) >= 0;
      sides[right ? 'r' : 'l'].push({ i, mid, y: cy + (R + fs * 0.5) * Math.sin(mid) });
    }
  });
  const lineH = size * (named && labelsOn ? 2.3 : named ? 1.3 : 1.35);
  for (const side of ['l', 'r']) {
    const arr = sides[side].sort((p, q) => p.y - q.y);
    const ys = arr.map((e) => e.y);
    for (let it = 0; it < 10; it++) {
      for (let j = 1; j < ys.length; j++) if (ys[j] - ys[j - 1] < lineH) ys[j] = ys[j - 1] + lineH;
      const over = ys.length ? ys[ys.length - 1] - (box.y + box.h - lineH / 2) : 0;
      if (over > 0) for (let j = ys.length - 1; j >= 0; j--) ys[j] -= over;
      const under = ys.length ? box.y + lineH / 2 - ys[0] : 0;
      if (under > 0) for (let j = 0; j < ys.length; j++) ys[j] += under;
    }
    arr.forEach((e, j) => {
      const dir = side === 'r' ? 1 : -1;
      const x0 = cx + R * Math.cos(e.mid) * 1.0;
      const y0 = cy + R * Math.sin(e.mid);
      const x1 = cx + (R + fs * 0.45) * Math.cos(e.mid);
      const y1 = cy + (R + fs * 0.45) * Math.sin(e.mid);
      const x2 = cx + dir * (R + fs * 0.9);
      const ty = ys[j];
      labelEls.push(el('polyline', { points: `${fx(x0)},${fx(y0)} ${fx(x1)},${fx(y1)} ${fx(x2)},${fx(ty)} ${fx(x2 + dir * fs * 0.3)},${fx(ty)}`, fill: 'none', stroke: ctx.muted, 'stroke-width': k, 'stroke-opacity': 0.8 }));
      const tx = x2 + dir * fs * 0.45;
      const room = side === 'r' ? box.x + box.w - tx : tx - box.x;
      const nm = slices[e.i].name;
      if (named && labelsOn) {
        labelEls.push(text(ctx, tx, ty - size * 0.55, fitText(nm, size, room, 600), { size, anchor: dir > 0 ? 'start' : 'end', weight: 600, cls: 'pc-label' }));
        labelEls.push(text(ctx, tx, ty + size * 0.6, lines[e.i].val, { size, anchor: dir > 0 ? 'start' : 'end', fill: ctx.muted, cls: 'pc-label' }));
      } else if (named) {
        labelEls.push(text(ctx, tx, ty, fitText(nm, size, room, 600), { size, anchor: dir > 0 ? 'start' : 'end', weight: 600, cls: 'pc-label' }));
      } else {
        const tiny = lines[e.i].share < 0.02;
        const str = tiny ? `${nm} ${lines[e.i].val}` : lines[e.i].val;
        labelEls.push(text(ctx, tx, ty, fitText(str, size, room), { size, anchor: dir > 0 ? 'start' : 'end', cls: 'pc-label' }));
      }
    });
  }
  add(ctx, el('g', { class: 'pc-labels' }, labelEls.join('')));

  // doughnut centre label
  if (donut) {
    const innerW = r * 1.7;
    const big = opt.center || ctx.fmt(total);
    let bs = Math.min(fs * 2.2, r * 0.7);
    while (bs > 8 && textWidth(big, bs, 700) > innerW) bs -= 1;
    const hasSub = !opt.center && r > fs * 2.2;
    put(ctx, cx, cy - (hasSub ? bs * 0.18 : 0), fitText(big, bs, innerW, 700), { size: bs, anchor: 'middle', weight: 700, cls: 'pc-center' });
    if (hasSub) put(ctx, cx, cy + bs * 0.75, 'Total', { size: Math.min(fs * 0.9, bs * 0.6), anchor: 'middle', fill: ctx.muted, cls: 'pc-center' });
  }
  drawChrome(ctx);
}
