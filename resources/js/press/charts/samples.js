// Sample charts for "insert chart" and the type catalogue shown in the editor.
import { normalizeChart, CHART_IDS } from './normalize.js';

export const CHART_TYPES = Object.freeze([
  { id: 'column', label: 'Column', desc: 'Compare values across categories with vertical bars. Bars start at zero.', needs: 'Categories and one or more series of numbers.' },
  { id: 'bar', label: 'Bar', desc: 'Compare values with horizontal bars. Best for long category names and rankings.', needs: 'Categories and one or more series of numbers.' },
  { id: 'line', label: 'Line', desc: 'Show change over time or order. Optional smoothing.', needs: 'Ordered categories (often dates) and one or more series.' },
  { id: 'area', label: 'Area', desc: 'A line with the area under it filled. Use stacked to show parts of a total over time.', needs: 'Ordered categories and one or more series.' },
  { id: 'pie', label: 'Pie', desc: 'Show parts of a whole when there are a few slices.', needs: 'Up to 8 categories with one series of positive numbers.' },
  { id: 'doughnut', label: 'Doughnut', desc: 'A pie with a centre label, for a total or a headline.', needs: 'Up to 8 categories with one series of positive numbers.' },
  { id: 'scatter', label: 'Scatter', desc: 'Show how two measures relate. Each point is one observation.', needs: 'Points with an x and a y number, in one or more series.' },
  { id: 'bubble', label: 'Bubble', desc: 'A scatter chart where a third number sets the bubble area.', needs: 'Points with x, y and size.' },
  { id: 'waterfall', label: 'Waterfall', desc: 'Show how a start value changes through increases and decreases to a total.', needs: 'Steps with signed changes; mark which steps are totals.' },
  { id: 'funnel', label: 'Funnel', desc: 'Show how a count shrinks through ordered stages.', needs: 'Ordered stages with one series of positive numbers.' },
  { id: 'radar', label: 'Radar', desc: 'Compare a few items across the same measures. Scale starts at zero.', needs: 'At least 3 categories and one to three series.' },
  { id: 'combo', label: 'Combo', desc: 'Bars and lines on one shared axis, such as results against a target in the same unit.', needs: 'Categories and series, each marked as bar or line, in one unit.' },
  { id: 'kpi', label: 'Key figures', desc: 'Big numbers with an optional change. Use when the number is the story.', needs: 'A label and a value for each figure, with an optional change.' },
  { id: 'map', label: 'World map', desc: 'Shade countries by value using ISO two-letter codes.', needs: 'Country codes (ZA, US, BR) with a number each.' },
]);

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const S = {
  column: () => ({
    type: 'column',
    data: { categories: ['Q1', 'Q2', 'Q3', 'Q4'], series: [{ name: 'Revenue', values: [1.84, 2.31, 2.67, 3.12] }, { name: 'Costs', values: [1.52, 1.78, 1.9, 2.15] }] },
    opt: { title: 'Revenue and costs by quarter', sub: 'Rand millions, 2026', legend: 'bottom', labels: 'value', fmt: 'num', dec: 2 },
  }),
  bar: () => ({
    type: 'bar',
    data: { categories: ['Gauteng', 'Western Cape', 'KwaZulu-Natal', 'Eastern Cape', 'Free State', 'Limpopo'], series: [{ name: 'Customers', values: [4120, 2980, 2350, 1210, 780, 640] }] },
    opt: { title: 'Customers by province', legend: 'none', labels: 'value' },
  }),
  line: () => ({
    type: 'line',
    data: { categories: MONTHS, series: [{ name: 'Website', values: [820, 910, 1040, 980, 1150, 1290, 1210, 1380, 1460, 1420, 1590, 1720] }, { name: 'App', values: [310, 360, 420, 510, 590, 700, 780, 910, 1010, 1130, 1260, 1410] }] },
    opt: { title: 'Monthly active users', legend: 'bottom', labels: 'none', fmt: 'num', smooth: false },
  }),
  area: () => ({
    type: 'area',
    data: { categories: ['2021', '2022', '2023', '2024', '2025', '2026'], series: [{ name: 'Solar', values: [12, 18, 27, 41, 58, 79] }, { name: 'Wind', values: [30, 34, 39, 46, 52, 61] }, { name: 'Hydro', values: [22, 22, 23, 23, 24, 24] }] },
    opt: { title: 'Generation by source', sub: 'GWh', legend: 'bottom', stacked: true, smooth: true },
  }),
  pie: () => ({
    type: 'pie',
    data: { categories: ['Salaries', 'Rent', 'Marketing', 'Software', 'Other'], series: [{ name: 'Share of spend', values: [54, 18, 12, 9, 7] }] },
    opt: { title: 'Where the money goes', legend: 'right', labels: 'percent', fmt: 'pct' },
  }),
  doughnut: () => ({
    type: 'doughnut',
    data: { categories: ['Retail', 'Wholesale', 'Online'], series: [{ name: 'Sales', values: [48, 27, 25] }] },
    opt: { title: 'Sales by channel', legend: 'right', labels: 'percent', center: 'R 8.4m' },
  }),
  scatter: () => ({
    type: 'scatter',
    data: { categories: [], series: [{ name: 'Stores', values: [], points: [{ x: 120, y: 2.1 }, { x: 180, y: 3.2 }, { x: 240, y: 3.9 }, { x: 310, y: 5.4 }, { x: 350, y: 5.1 }, { x: 420, y: 7.2 }, { x: 480, y: 7.9 }, { x: 560, y: 9.4 }, { x: 610, y: 9.1 }, { x: 700, y: 11.6 }] }] },
    opt: { title: 'Floor area and monthly sales', legend: 'none', ax: { x: { title: 'Floor area (m2)', show: true }, y: { title: 'Sales (R million)', show: true } } },
  }),
  bubble: () => ({
    type: 'bubble',
    data: { categories: [], series: [{ name: 'Products', values: [], points: [{ x: 12, y: 34, r: 800, label: 'Alpha' }, { x: 25, y: 22, r: 2400, label: 'Beta' }, { x: 38, y: 41, r: 1500, label: 'Gamma' }, { x: 52, y: 18, r: 300, label: 'Delta' }, { x: 66, y: 36, r: 3100, label: 'Epsilon' }] }] },
    opt: { title: 'Price, margin and volume', legend: 'none', labels: 'value', ax: { x: { title: 'Price (R)', show: true }, y: { title: 'Margin (%)', show: true } } },
  }),
  waterfall: () => ({
    type: 'waterfall',
    data: { categories: ['Opening profit', 'New clients', 'Upsell', 'Churn', 'Salaries', 'Tools', 'Closing profit'], series: [{ name: 'Profit', values: [2.4, 1.1, 0.6, -0.5, -0.9, -0.2, null] }], totals: [0, 6] },
    opt: { title: 'Profit bridge', sub: 'Rand millions', legend: 'bottom', labels: 'value', dec: 1 },
  }),
  funnel: () => ({
    type: 'funnel',
    data: { categories: ['Visited', 'Signed up', 'Activated', 'Subscribed', 'Renewed'], series: [{ name: 'People', values: [12400, 4100, 2300, 880, 610] }] },
    opt: { title: 'From visit to renewal', legend: 'none', labels: 'value' },
  }),
  radar: () => ({
    type: 'radar',
    data: { categories: ['Speed', 'Reliability', 'Support', 'Price', 'Features', 'Usability'], series: [{ name: 'Us', values: [8, 9, 7, 6, 8, 9] }, { name: 'Rival', values: [6, 7, 9, 8, 7, 6] }] },
    opt: { title: 'Scorecard (out of 10)', legend: 'bottom' },
  }),
  combo: () => ({
    type: 'combo',
    data: { categories: MONTHS.slice(0, 6), series: [{ name: 'Actual', values: [120, 135, 128, 150, 162, 171], kind: 'bar' }, { name: 'Target', values: [125, 130, 135, 140, 150, 160], kind: 'line' }] },
    opt: { title: 'Sales against target', sub: 'Units, one shared axis', legend: 'bottom' },
  }),
  kpi: () => ({
    type: 'kpi',
    data: { categories: [], series: [], kpis: [{ label: 'Revenue', value: 8420000, delta: 12.4, note: 'vs last quarter' }, { label: 'Active customers', value: 3280, delta: 5.1 }, { label: 'Churn', value: 2.8, unit: '%', delta: -0.6, good: 'down' }, { label: 'Net promoter score', value: 54, delta: 0 }] },
    opt: { title: 'This quarter at a glance', fmt: 'compact', dec: 2 },
  }),
  map: () => ({
    type: 'map',
    data: { categories: [], series: [], regions: [{ code: 'ZA', value: 42 }, { code: 'NG', value: 31 }, { code: 'KE', value: 18 }, { code: 'EG', value: 24 }, { code: 'GH', value: 12 }, { code: 'TZ', value: 9 }, { code: 'MA', value: 15 }, { code: 'ET', value: 7 }, { code: 'BR', value: 20 }, { code: 'US', value: 36 }, { code: 'GB', value: 28 }, { code: 'IN', value: 22 }, { code: 'AU', value: 10 }] },
    opt: { title: 'Customers by country', legend: 'bottom', labels: 'none' },
  }),
};

/** A realistic, complete example for each chart type. Unknown types give a column chart. */
export function sampleChart(type) {
  const make = S[type] || S.column;
  const raw = make();
  raw.src = { kind: 'manual' };
  return normalizeChart(raw);
}

export const SAMPLE_TYPES = CHART_IDS.slice();
