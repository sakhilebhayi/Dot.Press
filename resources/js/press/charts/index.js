// Dot.Press chart engine (R-40). Pure JavaScript: no DOM, no dependencies, no network.
// It runs in the browser editor, in the player and under `node --test`.
//
// Public API
//   chartToSvg(chart, opts)       -> standalone <svg> string (see opts below)
//   normalizeChart(chart)         -> complete, bounded chart object; never throws
//   chartToTable(chart, {format}) -> { header: [], rows: [[]] }, the accessible table twin
//   describeChart(chart)          -> plain-sentence description (also used as the SVG <desc>)
//   parseTable(text)              -> { rows, delimiter, decimal, hints: { percent, currency } }
//   tableToChartData(rows, o)     -> chart.data   (o: orientation, firstRowHeader, firstColHeader, type)
//   suggestChartType(data)        -> { type, why, stacked? }
//   sampleChart(type)             -> a realistic example chart for "insert chart"
//   CHART_TYPES                   -> [{ id, label, desc, needs }]
//   niceTicks(min, max, count)    -> { ticks, step, min, max }
//   CHART_CSS                     -> entrance-animation CSS the player includes once
//   DEFAULT_THEME, DEFAULT_DARK_THEME -> theme tokens (spec section 3) for light and dark surfaces
//
// chartToSvg opts: { w, h, theme, font, reduced, animate, idPrefix, surface, fontSize, debug }
//   w, h       viewBox size in slide units (clamped to 120..4000 by 90..4000); text scales with the size
//   theme      spec theme object ({ c: { bg, ink, muted, line, surface, good, bad, chart: [...] }, t });
//              missing tokens fall back to DEFAULT_THEME
//   font       CSS font-family for all text (default: theme.t.body)
//   animate    adds class pc-anim; entrance animation is done by CHART_CSS, staggered with the --i variable
//   reduced    adds class pc-reduced and never animates
//   idPrefix   prefix for ids inside the SVG (title, desc, gradients, patterns); use a unique one per chart
//   surface    colour of the slide behind the chart, used for marker rings and pie gaps (default theme.c.bg)
//   debug      rethrow render errors instead of drawing a plain message
// Every mark is its own element with class "pc-mark", a CSS variable --i (stagger index), data-series,
// data-index and data-value, and carries a <title> so it has a native tooltip and an accessible name.
// Text never uses a series colour; it uses the ink and muted tokens (or black or white on a coloured mark).
//
// Spec reading and decisions (R40_DOCUMENT_SPEC.md section 6, chart)
//   * Percent format: fmt "pct" treats values as percentage points (12.5 prints "12.5%"). opt.labels
//     "percent" is the share of a total instead: of the pie or stack, otherwise of the series.
//   * Additive opt keys (unknown keys are ignored by every other consumer): opt.other (fold pie slices past 7
//     into "Other"), opt.center (doughnut centre text; default is the total), data.kpis[].good ("down" when a
//     fall is good news). Without opt.cur, fmt "cur" uses "R".
//   * ax.x is always the category axis and ax.y always the value axis, also for horizontal bars.
//   * One shared value axis only: there is no dual axis. In a combo, series[].kind picks bar or line.
//   * Waterfall: series[0] values are signed changes; indexes in data.totals are total bars. A total bar draws its
//     stated value (and restarts the running total there), or the running total when the value is null.
//   * KPI: a numeric delta is a percent change (+4.2%); a string delta is shown as written and its sign gives the
//     direction. An arrow shape and sign carry direction so colour is never the only cue.
//   * Map: regions[] ({ code, value }) by ISO 3166 alpha-2 code on a Natural Earth 110m world (Robinson
//     projection). Microstates too small for that detail are not drawn; a note names them and the table lists them.
//
// Honesty rules enforced here
//   * Bars, columns, areas and waterfalls start at zero. opt.ax.y.min can override; the chart then draws a break mark
//     on the axis and the note "Value axis does not start at zero." Line charts include zero unless the data sits
//     far above it, and are labelled the same way when they do not.
//   * Values are drawn exactly (no smoothing of bars; smoothing of lines is monotone, so it never overshoots).
//   * Colour order is fixed by series index, never by value. More than 8 series reuse the palette with a lightness
//     step (darker on light surfaces, lighter on dark, then the opposite), a hatch pattern on filled marks and a dash
//     on lines; at most 40 series are drawn. Slices past 8 in a pie follow the same rule.
//   * Identity never rests on colour alone: legend (2+ series), marker shapes on lines and scatter, direct labels
//     at line ends for 2 to 4 series, direct names on pie slices for 4 or fewer slices, and a table twin.
//
// Palette validation (dataviz validate_palette.js, 2026-11-07)
//   Light, surface #ffffff, DEFAULT_THEME.c.chart #2563eb #f59e0b #15803d #7c3aed #db2777 #0891b2 #65a30d #475569
//     Lightness band PASS; normal-vision floor PASS (worst adjacent #65a30d/#0891b2 dE 21.1);
//     adjacent colour-blind separation PASS (worst #0891b2/#db2777 dE 10.3 deutan, tritan 6.0);
//     chroma floor: slots 1 to 7 PASS, slot 8 #475569 is a deliberate neutral (reads grey, 0.037);
//     contrast WARN: amber #f59e0b is 2.15:1 on white, so every chart carries visible labels or a table view
//     and text is never set in the series colour.
//   Dark, surface #111827, DEFAULT_DARK_THEME.c.chart #3b82f6 #d86f06 #16a25a #8b5cf6 #ec4899 #0d9cbc #65a30d #64748b
//     (the same hues stepped for the dark surface; found with the validator so adjacent pairs separate):
//     lightness band PASS; normal-vision floor PASS (dE 20.6); colour-blind separation PASS (worst #0d9cbc/#ec4899
//     dE 8.5 deutan, tritan 6.1); contrast vs surface PASS (all >= 3:1); slot 8 neutral as above.
//   Tritan 6.0 sits at the validator's floor, which is legal only with secondary encoding; the marker shapes,
//   legend, direct labels and dashes above are that encoding.

export { chartToSvg, CHART_CSS } from './render.js';
export { normalizeChart, CHART_IDS, LIMITS } from './normalize.js';
export { chartToTable, describeChart } from './describe.js';
export { niceTicks } from './ticks.js';
export { sampleChart, CHART_TYPES } from './samples.js';
export { parseTable, tableToChartData, suggestChartType, looksTemporal } from './table.js';
export { DEFAULT_THEME, DEFAULT_DARK_THEME } from './theme.js';
export { textWidth, fitText, wrapText, makeFormatter, parseNumber } from './util.js';
