// chartToSvg: dispatch to the renderer for the chart type and wrap the result.
import { normalizeChart } from './normalize.js';
import { makeContext, finishSvg } from './svg.js';
import { renderCartesian } from './cartesian.js';
import { renderPie } from './pie.js';
import { renderScatter } from './scatter.js';
import { renderRadar } from './radar.js';
import { renderFunnel } from './funnel.js';
import { renderKpi } from './kpi.js';
import { renderMap } from './map.js';
import { describeChart } from './describe.js';

const RENDERERS = {
  bar: renderCartesian,
  column: renderCartesian,
  line: renderCartesian,
  area: renderCartesian,
  combo: renderCartesian,
  waterfall: renderCartesian,
  pie: renderPie,
  doughnut: renderPie,
  scatter: renderScatter,
  bubble: renderScatter,
  radar: renderRadar,
  funnel: renderFunnel,
  kpi: renderKpi,
  map: renderMap,
};

/**
 * Render a spec chart as a standalone SVG string.
 * opts: { w, h, theme, font, reduced, animate, idPrefix, surface, fontSize }
 */
export function chartToSvg(chart, opts = {}) {
  const norm = normalizeChart(chart);
  const ctx = makeContext(norm, opts);
  try {
    RENDERERS[norm.type](ctx);
  } catch (err) {
    if (opts && opts.debug) throw err;
    // never throw out of a render: show a plain message instead of half a chart
    ctx.body = [];
    ctx.defs = [];
    ctx.notes = [];
    ctx.body.push(
      `<text x="${ctx.pad}" y="${ctx.h / 2}" font-size="${ctx.fs}" font-family="sans-serif" fill="${ctx.muted}">This chart could not be drawn.</text>`,
    );
  }
  const title = norm.opt.title || chartTitleFallback(norm);
  return finishSvg(ctx, title, describeChart(norm));
}

function chartTitleFallback(chart) {
  const map = { bar: 'Bar chart', column: 'Column chart', line: 'Line chart', area: 'Area chart', pie: 'Pie chart', doughnut: 'Doughnut chart', scatter: 'Scatter chart', bubble: 'Bubble chart', waterfall: 'Waterfall chart', funnel: 'Funnel chart', radar: 'Radar chart', combo: 'Combination chart', kpi: 'Key figures', map: 'World map' };
  return map[chart.type] || 'Chart';
}

/** CSS the player can include once to animate chart entrances (staggered by --i). */
export const CHART_CSS = `
.pc-anim .pc-mark{animation:pc-fade .5s ease both;animation-delay:calc(var(--i,0)*45ms)}
.pc-anim .pc-g-y{transform-box:fill-box;transform-origin:50% 100%;animation-name:pc-grow-y}
.pc-anim .pc-g-x{transform-box:fill-box;transform-origin:0 50%;animation-name:pc-grow-x}
.pc-anim .pc-g-c,.pc-anim .pc-pop{transform-box:fill-box;transform-origin:50% 50%;animation-name:pc-pop}
.pc-anim .pc-draw{stroke-dasharray:100;animation:pc-draw .9s ease both;animation-delay:calc(var(--i,0)*120ms)}
@keyframes pc-fade{from{opacity:0}to{opacity:1}}
@keyframes pc-grow-y{from{opacity:0;transform:scaleY(.02)}to{opacity:1;transform:none}}
@keyframes pc-grow-x{from{opacity:0;transform:scaleX(.02)}to{opacity:1;transform:none}}
@keyframes pc-pop{from{opacity:0;transform:scale(.6)}to{opacity:1;transform:none}}
@keyframes pc-draw{from{stroke-dashoffset:100}to{stroke-dashoffset:0}}
.pc-reduced .pc-mark,.pc-reduced .pc-draw{animation:none!important}
@media (prefers-reduced-motion:reduce){.pc-anim .pc-mark,.pc-anim .pc-draw{animation:none!important}}
`.trim();
