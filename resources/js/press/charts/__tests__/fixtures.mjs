// Stress and edge-case charts shared by the tests and the dev gallery.
const cats12 = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const wave = (base, amp, phase = 0) => cats12.map((_, i) => Math.round(base + amp * Math.sin(i / 2 + phase)));

export const HOSTILE = [
  '<script>alert(1)</script>',
  '"><svg onload=alert(1)>',
  "'; DROP TABLE charts; --",
  '&amp; &lt; &#x41; ]]>',
  'a\u0000b\u0008c\u001fd',
  '\ud800 lone surrogate',
  'javascript:alert(1)',
  '</title></svg><text>',
  'x'.repeat(400),
  '‮ right to left override',
];

export const STRESS = [
  {
    name: 'column mixed signs, 2 series, labels',
    chart: { type: 'column', data: { categories: ['A', 'B', 'C', 'D', 'E'], series: [{ name: 'Profit', values: [12, -8, 20, -3, 7] }, { name: 'Budget', values: [10, 5, 15, 2, 9] }] }, opt: { title: 'Profit against budget', labels: 'value', legend: 'bottom' } },
  },
  {
    name: 'stacked bar, long names, labels',
    chart: { type: 'bar', data: { categories: ['Department of Higher Education and Training', 'Health', 'Social Development and Special Programmes', 'Transport'], series: [{ name: 'Capital', values: [420, 310, 150, 520] }, { name: 'Operating', values: [900, 1200, 640, 380] }, { name: 'Transfers', values: [120, 80, 400, 60] }] }, opt: { title: 'Budget by department', stacked: true, labels: 'value', legend: 'bottom' } },
  },
  {
    name: 'stacked column with negatives',
    chart: { type: 'column', data: { categories: ['Q1', 'Q2', 'Q3', 'Q4'], series: [{ name: 'Income', values: [40, 55, 60, 70] }, { name: 'Refunds', values: [-5, -8, -4, -12] }, { name: 'Other', values: [10, 6, 12, 9] }] }, opt: { stacked: true, labels: 'value', legend: 'bottom', title: 'Income net of refunds' } },
  },
  {
    name: '10 series line with gaps',
    chart: { type: 'line', data: { categories: cats12, series: Array.from({ length: 10 }, (_, i) => ({ name: 'Region ' + (i + 1), values: wave(50 + i * 8, 10 + i, i).map((v, k) => (i === 3 && k > 3 && k < 7 ? null : v)) })) }, opt: { title: 'Ten regions', legend: 'right' } },
  },
  {
    name: '18 series column, more than 16',
    chart: { type: 'column', data: { categories: ['A', 'B', 'C'], series: Array.from({ length: 18 }, (_, i) => ({ name: 'Series ' + (i + 1), values: [i + 1, 20 - i, 10] })) }, opt: { legend: 'bottom' } },
  },
  {
    name: '40 categories with long names',
    chart: { type: 'column', data: { categories: Array.from({ length: 40 }, (_, i) => 'Customer segment number ' + (i + 1)), series: [{ name: 'Orders', values: Array.from({ length: 40 }, (_, i) => 50 + ((i * 37) % 60)) }] }, opt: { title: 'Orders by segment' } },
  },
  {
    name: 'truncated column axis with break mark',
    chart: { type: 'column', data: { categories: ['2023', '2024', '2025'], series: [{ name: 'Score', values: [91, 94, 97] }] }, opt: { title: 'Satisfaction score', labels: 'value', ax: { x: { show: true }, y: { show: true, min: 80, max: 100 } } } },
  },
  {
    name: 'line that does not need zero',
    chart: { type: 'line', data: { categories: cats12, series: [{ name: 'Rand per dollar', values: [18.2, 18.4, 18.1, 17.9, 18.3, 18.0, 17.8, 17.6, 17.9, 18.1, 18.4, 18.2] }] }, opt: { title: 'Exchange rate', smooth: true } },
  },
  {
    name: 'pie 12 slices folded into Other',
    chart: { type: 'pie', data: { categories: Array.from({ length: 12 }, (_, i) => 'Item ' + (i + 1)), series: [{ name: 'v', values: [30, 22, 15, 9, 6, 5, 4, 3, 2.5, 2, 1, 0.5] }] }, opt: { title: 'Fold to Other', other: true, labels: 'percent', legend: 'right' } },
  },
  {
    name: 'doughnut with small slices',
    chart: { type: 'doughnut', data: { categories: ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta'], series: [{ name: 'v', values: [900, 400, 12, 8, 2, 1] }] }, opt: { title: 'Tiny slices get leaders', labels: 'percent', legend: 'right' } },
  },
  {
    name: 'single data point',
    chart: { type: 'column', data: { categories: ['Only'], series: [{ name: 'Value', values: [42] }] }, opt: { labels: 'value', title: 'One bar' } },
  },
  {
    name: 'all zeros',
    chart: { type: 'column', data: { categories: ['a', 'b', 'c'], series: [{ name: 's', values: [0, 0, 0] }] }, opt: { title: 'All zero', labels: 'value' } },
  },
  {
    name: 'empty',
    chart: { type: 'line', data: { categories: [], series: [] }, opt: { title: 'Nothing yet' } },
  },
  {
    name: 'hostile text',
    chart: { type: 'column', data: { categories: HOSTILE.slice(0, 5), series: [{ name: HOSTILE[1], values: [3, 5, 2, 8, 1] }, { name: HOSTILE[2], values: [4, 1, 6, 2, 3] }] }, opt: { title: HOSTILE[0], sub: HOSTILE[3], legend: 'bottom', labels: 'value', ax: { x: { title: HOSTILE[6], show: true }, y: { title: HOSTILE[7], show: true } } } },
  },
  {
    name: 'bad numbers',
    chart: { type: 'line', data: { categories: ['a', 'b', 'c', 'd', 'e'], series: [{ name: 's', values: [1, NaN, 'x', Infinity, null] }, { name: 't', values: ['4', '5,5', undefined, 7, -Infinity] }] }, opt: { title: 'Bad values' } },
  },
  {
    name: 'waterfall crossing zero',
    chart: { type: 'waterfall', data: { categories: ['Start', 'Gain', 'Loss', 'Big loss', 'Gain', 'End'], series: [{ name: 'v', values: [10, 15, -12, -20, 8, null] }], totals: [0, 5] }, opt: { labels: 'value', title: 'Crossing zero', legend: 'bottom' } },
  },
  {
    name: 'negative area',
    chart: { type: 'area', data: { categories: ['a', 'b', 'c', 'd', 'e'], series: [{ name: 's', values: [3, -2, 4, -5, 2] }] }, opt: { title: 'Negative area' } },
  },
  {
    name: 'radar 3 series, 8 spokes',
    chart: { type: 'radar', data: { categories: ['Strategy', 'Operations', 'Finance', 'People', 'Technology', 'Marketing', 'Legal', 'Customer care'], series: [{ name: 'Now', values: [6, 7, 5, 8, 4, 6, 7, 5] }, { name: 'Target', values: [8, 8, 7, 9, 8, 7, 8, 8] }, { name: 'Peer', values: [5, 6, 6, 5, 6, 7, 6, 6] }] }, opt: { title: 'Maturity', labels: 'value', legend: 'bottom' } },
  },
  {
    name: 'kpi nine tiles',
    chart: { type: 'kpi', data: { categories: [], series: [], kpis: Array.from({ length: 9 }, (_, i) => ({ label: 'Metric ' + (i + 1), value: 1000 * (i + 1) + 7, delta: i % 2 ? -(i + 0.5) : i + 0.5, note: 'vs last month' })) }, opt: { title: 'Nine figures', fmt: 'num' } },
  },
  {
    name: 'kpi one hero figure',
    chart: { type: 'kpi', data: { categories: [], series: [], kpis: [{ label: 'Net revenue retention', value: 118, unit: '%', delta: '+6 pts', note: 'Trailing twelve months' }] }, opt: {} },
  },
  {
    name: 'map unknown codes and labels',
    chart: { type: 'map', data: { categories: [], series: [], regions: [{ code: 'ZA', value: 120 }, { code: 'US', value: 80 }, { code: 'CN', value: 200 }, { code: 'SG', value: 30 }, { code: 'MT', value: 5 }, { code: 'ZZ', value: 1 }, { code: 'FR', value: 60 }, { code: 'AU', value: 15 }] }, opt: { title: 'Offices', labels: 'value', legend: 'bottom' } },
  },
  {
    name: 'scatter with 3 series and labels',
    chart: { type: 'scatter', data: { categories: [], series: [{ name: 'Gauteng', values: [], points: [{ x: 1, y: 3, label: 'JHB' }, { x: 2, y: 4 }, { x: 3, y: 6 }, { x: 4, y: 5 }] }, { name: 'Western Cape', values: [], points: [{ x: 1.5, y: 2 }, { x: 2.5, y: 3.5 }, { x: 3.5, y: 4.5 }] }, { name: 'KZN', values: [], points: [{ x: 1.2, y: 5 }, { x: 2.2, y: 6.5, label: 'DBN' }, { x: 3.2, y: 7 }] }] }, opt: { title: 'Three provinces', legend: 'bottom', labels: 'value' } },
  },
  {
    name: 'combo with negative bars and two lines',
    chart: { type: 'combo', data: { categories: ['Q1', 'Q2', 'Q3', 'Q4'], series: [{ name: 'Revenue', values: [30, -5, 22, 40], kind: 'bar' }, { name: 'Plan', values: [25, 20, 25, 35], kind: 'line' }, { name: 'Last year', values: [20, 15, 18, 28], kind: 'line' }] }, opt: { title: 'Combo', labels: 'value', legend: 'bottom' } },
  },
  {
    name: 'currency compact axis',
    chart: { type: 'bar', data: { categories: ['North', 'South', 'East', 'West'], series: [{ name: 'Revenue', values: [12500000, 8300000, 15100000, 4200000] }] }, opt: { title: 'Revenue', fmt: 'cur', cur: 'R', labels: 'value' } },
  },
];
