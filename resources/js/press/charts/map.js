// World choropleth by ISO 3166 alpha-2 code with a sequential single-hue scale and a gradient legend.
import { isNum, fx, textWidth, clamp, mix, inkOn, tickFormatter } from './util.js';
import { niceTicks } from './ticks.js';
import { WORLD, WORLD_W, WORLD_H } from './world.js';
import { el, add, text, mark, id, planFrame, drawChrome, drawEmpty } from './svg.js';

const BY_CODE = new Map(WORLD.map((c) => [c.c, c]));
const BY_NAME = new Map(WORLD.map((c) => [c.n.toLowerCase(), c.c]));
const bboxCache = new Map();

/** Bounding box of a country path (the largest ring is used for label placement). */
function bbox(code) {
  if (bboxCache.has(code)) return bboxCache.get(code);
  const c = BY_CODE.get(code);
  let best = null;
  if (c) {
    const rings = c.d.split('z').filter(Boolean);
    for (const ring of rings) {
      const m = /^M([-\d.]+) ([-\d.]+)l(.*)$/.exec(ring);
      if (!m) continue;
      let x = Number(m[1]);
      let y = Number(m[2]);
      let x0 = x;
      let x1 = x;
      let y0 = y;
      let y1 = y;
      const nums = (m[3].match(/-?\d*\.?\d+/g) || []).map(Number);
      for (let i = 0; i + 1 < nums.length; i += 2) {
        x += nums[i];
        y += nums[i + 1];
        if (x < x0) x0 = x;
        if (x > x1) x1 = x;
        if (y < y0) y0 = y;
        if (y > y1) y1 = y;
      }
      const area = (x1 - x0) * (y1 - y0);
      if (!best || area > best.area) best = { x0, y0, x1, y1, area };
    }
  }
  bboxCache.set(code, best);
  return best;
}

export const MAP_CODES = new Set(WORLD.map((c) => c.c));
export const countryName = (code) => (BY_CODE.get(code) ? BY_CODE.get(code).n : code);
export const countryCodeFromName = (name) => BY_NAME.get(String(name || '').trim().toLowerCase()) || null;

/** Regions from data.regions, or from categories (codes or country names) and the first series. */
export function mapRegions(chart) {
  const d = chart.data;
  if (d.regions && d.regions.length) return d.regions;
  const s = d.series[0];
  if (!s) return [];
  const out = [];
  d.categories.forEach((c, i) => {
    const v = s.values[i];
    if (!isNum(v)) return;
    const up = String(c).trim().toUpperCase();
    const code = /^[A-Z]{2}$/.test(up) ? up : countryCodeFromName(c);
    if (code) out.push({ code, value: v });
  });
  return out;
}

export function renderMap(ctx) {
  const chart = ctx.chart;
  const { opt } = chart;
  const { fs, k } = ctx;
  const regions = mapRegions(chart);
  const drawn = regions.filter((r) => BY_CODE.has(r.code));
  const missing = regions.filter((r) => !BY_CODE.has(r.code)).map((r) => r.code);
  if (missing.length) ctx.notes.push(`Not drawn at this map detail: ${missing.slice(0, 8).join(', ')}${missing.length > 8 ? ` and ${missing.length - 8} more` : ''}. They are in the table.`);
  const showScale = opt.legend !== 'none' && drawn.length > 0;
  const box = planFrame(ctx, [], { noLegend: true });
  if (!drawn.length) {
    drawEmpty(ctx, box, regions.length ? 'None of these regions can be drawn on this map.' : 'No regions to chart yet.');
    drawChrome(ctx);
    return;
  }
  const scaleH = showScale ? fs * 2.7 : 0;
  const mapBox = { x: box.x, y: box.y, w: box.w, h: Math.max(30, box.h - scaleH) };
  const sc = Math.min(mapBox.w / WORLD_W, mapBox.h / WORLD_H);
  const mw = WORLD_W * sc;
  const mh = WORLD_H * sc;
  const ox = mapBox.x + (mapBox.w - mw) / 2;
  const oy = mapBox.y + (mapBox.h - mh) / 2;
  const T = (px, py) => [ox + px * sc, oy + py * sc];

  const vals = drawn.map((r) => r.value);
  const lo = Math.min(...vals);
  const hi = Math.max(...vals);
  const accent = ctx.styles[0] ? ctx.styles[0].color : ctx.theme.c.chart[0];
  // one hue from a clear tint (30 percent) to the full colour; no-data land stays a neutral grey
  const colorAt = (t) => mix(ctx.surface, accent, 0.3 + 0.7 * clamp(t, 0, 1));
  const t01 = (v) => (hi > lo ? (v - lo) / (hi - lo) : 1);
  const byCode = new Map(drawn.map((r) => [r.code, r]));
  const noData = mix(ctx.line, ctx.surface, 0.45);

  const base = WORLD.filter((c) => !byCode.has(c.c)).map((c) => c.d).join('');
  const sw = Math.max(0.35, k * 0.5) / sc;
  const g = [];
  g.push(el('path', { d: base, fill: noData, stroke: ctx.surface, 'stroke-width': sw, 'stroke-linejoin': 'round', class: 'pc-land' }));
  const paths = [];
  drawn.forEach((r, i) => {
    const c = BY_CODE.get(r.code);
    paths.push(mark(ctx, 'path', { d: c.d, fill: colorAt(t01(r.value)), stroke: ctx.surface, 'stroke-width': sw, 'stroke-linejoin': 'round' }, { i, series: 0, index: i, value: r.value, cls: 'pc-fade', title: `${c.n} (${r.code}): ${ctx.fmt(r.value)}` }));
  });
  add(ctx, el('g', { transform: `translate(${fx(ox)} ${fx(oy)}) scale(${sc.toFixed(5)})`, class: 'pc-map' }, g.join('') + el('g', { class: 'pc-marks' }, paths.join(''))));

  // labels for the biggest values that have room
  if (opt.labels !== 'none') {
    const size = fs * 0.8;
    const out = [];
    [...drawn]
      .sort((a, b) => b.value - a.value)
      .slice(0, 10)
      .forEach((r) => {
        const bb = bbox(r.code);
        if (!bb) return;
        const str = ctx.fmt(r.value);
        const w = (bb.x1 - bb.x0) * sc;
        const h = (bb.y1 - bb.y0) * sc;
        if (w < textWidth(str, size) + 4 || h < size * 1.3) return;
        const [x, y] = T((bb.x0 + bb.x1) / 2, (bb.y0 + bb.y1) / 2);
        out.push(text(ctx, x, y, str, { size, anchor: 'middle', fill: inkOn(colorAt(t01(r.value))), weight: 600, cls: 'pc-label' }));
      });
    add(ctx, el('g', { class: 'pc-labels' }, out.join('')));
  }

  // gradient scale with a "No data" swatch
  if (showScale) {
    const size = fs * 0.85;
    const bw = Math.min(box.w * 0.5, 300);
    const bh = Math.max(6, fs * 0.5);
    const nd = 'No data';
    const swatch = bh * 1.4;
    const extra = fs * 1.6 + swatch + fs * 0.4 + textWidth(nd, size);
    const total = Math.min(box.w, bw + extra);
    const bx = box.x + Math.max(0, (box.w - total) / 2);
    const by = box.y + box.h - scaleH + fs * 0.5;
    const gid = id(ctx, 'scale');
    ctx.defs.push(el('linearGradient', { id: gid, x1: 0, y1: 0, x2: 1, y2: 0 }, el('stop', { offset: 0, 'stop-color': colorAt(0) }) + el('stop', { offset: 1, 'stop-color': colorAt(1) })));
    const parts = [el('rect', { x: bx, y: by, width: bw, height: bh, rx: bh / 2, fill: `url(#${gid})` })];
    const ticks = hi > lo ? niceTicks(lo, hi, 4, { exact: true }).ticks : [lo];
    const step = ticks.length > 1 ? ticks[1] - ticks[0] : 1;
    const f = tickFormatter(opt, step);
    const labs = hi > lo ? [{ v: lo, a: 'start', x: bx }, ...ticks.filter((t) => t > lo + step * 0.25 && t < hi - step * 0.25).map((t) => ({ v: t, a: 'middle', x: bx + t01(t) * bw })), { v: hi, a: 'end', x: bx + bw }] : [{ v: lo, a: 'middle', x: bx + bw / 2 }];
    labs.forEach((l) => parts.push(text(ctx, l.x, by + bh + fs * 0.95, f(l.v), { size, anchor: l.a, fill: ctx.muted })));
    const sx = bx + bw + fs * 1.6;
    parts.push(el('rect', { x: sx, y: by + bh / 2 - swatch / 2, width: swatch, height: swatch, rx: swatch * 0.2, fill: noData }));
    parts.push(text(ctx, sx + swatch + fs * 0.4, by + bh / 2, nd, { size, fill: ctx.muted }));
    add(ctx, el('g', { class: 'pc-legend pc-scale' }, parts.join('')));
  }
  drawChrome(ctx);
}
