#!/usr/bin/env node
// Dev tool: writes /tmp/press-chart-gallery/index.html with every chart type at three sizes,
// in light and dark themes, so a person can look at them. Not used at runtime.
//   node scripts/chart-gallery.mjs [outDir]
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { STRESS } from '../resources/js/press/charts/__tests__/fixtures.mjs';
import { chartToSvg, sampleChart, CHART_TYPES, CHART_CSS, DEFAULT_THEME, DEFAULT_DARK_THEME, chartToTable } from '../resources/js/press/charts/index.js';

const out = process.argv[2] || '/tmp/press-chart-gallery';
mkdirSync(out, { recursive: true });

const SIZES = [
  [640, 360],
  [960, 540],
  [320, 240],
];

function card(type, theme, name, w, h) {
  const chart = sampleChart(type);
  const svg = chartToSvg(chart, { w, h, theme, idPrefix: `${type}-${name}-${w}`, animate: false });
  return `<figure class="card" data-type="${type}" data-theme="${name}"><figcaption>${type} ${w}x${h}</figcaption><div class="frame" style="background:${theme.c.bg};color:${theme.c.ink}">${svg}</div></figure>`;
}

const sections = [];
const perType = {};
for (const t of CHART_TYPES) {
  const rows = [];
  for (const [w, h] of SIZES) {
    for (const [name, theme] of [['light', DEFAULT_THEME], ['dark', DEFAULT_DARK_THEME]]) rows.push(card(t.id, theme, name, w, h));
  }
  const sec = `<section id="${t.id}"><h2>${t.label} <small>${t.id}</small></h2><p>${t.desc}</p><div class="grid">${rows.join('')}</div></section>`;
  sections.push(sec);
  perType[t.id] = sec;
}

const nav = CHART_TYPES.map((t) => `<a href="#${t.id}">${t.id}</a>`).join(' ');
const page = (body, navHtml) => `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Press charts gallery</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{font:14px/1.4 system-ui,sans-serif;margin:12px;background:#e8eaee;color:#111}
nav{position:sticky;top:0;background:#e8eaeeee;padding:8px 0;z-index:2}
nav a{margin-right:10px}
h2{margin:32px 0 4px}h2 small{color:#667;font-weight:400}
.grid{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start}
figure{margin:0}figcaption{font-size:12px;color:#556;margin-bottom:4px}
.frame{display:inline-block;line-height:0;border-radius:6px;box-shadow:0 0 0 1px #0002}
svg{display:block}
${CHART_CSS}
</style></head><body><h1>Press charts</h1><nav>${navHtml}</nav>${body}</body></html>`;
const html = page(sections.join('\n'), nav);
writeFileSync(join(out, 'index.html'), html);
// one page per chart type, handy for looking at a single type
for (const t of CHART_TYPES) writeFileSync(join(out, `${t.id}.html`), page(perType[t.id], CHART_TYPES.map((x) => `<a href="${x.id}.html">${x.id}</a>`).join(' ')));
// one page per size and theme with every type, for reviewing a size across all charts
for (const [w, h] of SIZES) {
  for (const [name, theme] of [['light', DEFAULT_THEME], ['dark', DEFAULT_DARK_THEME]]) {
    const cards = CHART_TYPES.map((t) => card(t.id, theme, name, w, h)).join('');
    writeFileSync(join(out, `review-${w}-${name}.html`), page(`<div class="grid">${cards}</div>`, nav));
  }
}
// edge cases: stress-<w>-<theme>.html
for (const [w, h] of SIZES.slice(0, 2)) {
  for (const [name, theme] of [['light', DEFAULT_THEME], ['dark', DEFAULT_DARK_THEME]]) {
    const cards = STRESS.map((c, i) => `<figure class="card" data-type="${c.chart.type}"><figcaption>${i}. ${c.name} ${w}x${h}</figcaption><div class="frame" style="background:${theme.c.bg};color:${theme.c.ink}">${chartToSvg(c.chart, { w, h, theme, idPrefix: `s${i}-${name}-${w}` })}</div></figure>`).join('');
    writeFileSync(join(out, `stress-${w}-${name}.html`), page(`<div class="grid">${cards}</div>`, nav));
  }
}
writeFileSync(join(out, 'tables.json'), JSON.stringify(Object.fromEntries(CHART_TYPES.map((t) => [t.id, chartToTable(sampleChart(t.id))])), null, 1));
console.log('wrote', join(out, 'index.html'), `(${html.length} bytes)`);
