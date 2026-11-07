/**
 * The sample slide every theme card is drawn with: a title, a line of text, a shape, a second shape and a chart. Built
 * from the core element factories with theme tokens, so SlideView shows the theme's colours, fonts, radius and stroke.
 * Pure (node tested).
 *
 *   sampleSlide(theme, {w, h}) -> {deck: {w, h, theme}, slide: {id, bg: null, els: [...]}}
 */
import { newChart, newShape, newText } from '../../core/doc.js';
import { cleanTheme } from './themeModel.js';

export const SAMPLE_W = 1600;
export const SAMPLE_H = 900;

export function sampleSlide(theme, { w = SAMPLE_W, h = SAMPLE_H } = {}) {
  const deck = { w, h, theme: cleanTheme(theme) };
  const hw = deck.theme.t.hw;
  const els = [
    newText(deck, {
      id: 'st', role: 'title', text: 'Claim in one line', x: w * 0.07, y: h * 0.09, w: w * 0.86, h: h * 0.18, size: h * 0.115, weight: hw,
    }),
    newText(deck, {
      id: 'sb', role: 'body', text: 'Evidence follows in plain words.', x: w * 0.07, y: h * 0.3, w: w * 0.5, h: h * 0.14, size: h * 0.06, color: '@muted',
    }),
    newShape(deck, {
      id: 'sr', kind: 'rect', x: w * 0.07, y: h * 0.56, w: w * 0.26, h: h * 0.3, fill: '@accent', stroke: '@line', sw: 0,
    }),
    newShape(deck, {
      id: 'se', kind: 'ellipse', x: w * 0.36, y: h * 0.62, w: h * 0.2, h: h * 0.2, fill: '@accent2', sw: 0,
    }),
    newChart(deck, {
      id: 'sc', chartType: 'column', x: w * 0.6, y: h * 0.42, w: w * 0.34, h: h * 0.46,
      data: { categories: ['A', 'B', 'C', 'D'], series: [{ name: 'Series', values: [4, 7, 5, 9] }] },
    }),
  ];
  // The chart is drawn with the theme chart colours; keep its legend out of a card this small.
  const chart = els[4];
  els[4] = { ...chart, chart: { ...chart.chart, opt: { ...chart.chart.opt, legend: 'none', anim: false } } };
  return { deck, slide: { id: 'sample', title: 'Sample', bg: null, els } };
}
