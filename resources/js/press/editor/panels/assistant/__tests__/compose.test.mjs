import { test } from 'node:test';
import assert from 'node:assert/strict';
import { composeSlide, composeInsert, buildDoc, decimalsOf } from '../compose.js';
import { docText } from '../../../core/doc.js';
import { ID_RE } from '../../../core/util.js';
import { applyOps } from '../../../core/ops.js';
import { mkDoc, S } from '../../../core/__tests__/helpers.mjs';

const deck = { w: 1920, h: 1080, theme: { t: { heading: 'Public Sans', body: 'Public Sans', hw: 700 } } };
const ph = (role, key, x, y, w, h, o = {}) => ({ role, type: role === 'media' ? 'media' : 'text', key, x, y, w, h, sizeF: 0.04, style: { fit: 'shrink', lh: 1.3 }, ...o });
const LIB = [
  { id: 'bullets', ph: [ph('title', 'title', 0.06, 0.09, 0.35, 0.54, { sizeF: 0.059, style: { font: '@heading', fit: 'shrink' } }), ph('subtitle', 'subtitle', 0.06, 0.67, 0.35, 0.21), ph('body', 'body', 0.46, 0.1, 0.48, 0.78), ph('footer', 'footer', 0.06, 0.92, 0.48, 0.03)] },
  { id: 'two', ph: [ph('title', 'title', 0.06, 0.08, 0.88, 0.16), ph('body', 'body', 0.06, 0.28, 0.43, 0.52), ph('body', 'body2', 0.51, 0.28, 0.43, 0.52)] },
  { id: 'compare', ph: [ph('title', 'title', 0.06, 0.08, 0.88, 0.16), ph('subtitle', 'subtitle', 0.06, 0.28, 0.42, 0.08), ph('body', 'body', 0.06, 0.38, 0.42, 0.42), ph('subtitle', 'subtitle2', 0.52, 0.28, 0.42, 0.08), ph('body', 'body2', 0.52, 0.38, 0.42, 0.42)] },
  { id: 'timeline', ph: [ph('title', 'title', 0.06, 0.08, 0.88, 0.16), ph('body', 'body', 0.06, 0.45, 0.2, 0.44), ph('body', 'body2', 0.29, 0.45, 0.2, 0.44), ph('body', 'body3', 0.51, 0.45, 0.2, 0.44)] },
  { id: 'quote', ph: [ph('body', 'body', 0.1, 0.31, 0.79, 0.39), ph('caption', 'caption', 0.1, 0.74, 0.79, 0.09)] },
  { id: 'data', ph: [ph('title', 'title', 0.06, 0.08, 0.88, 0.16), ph('media', 'media', 0.06, 0.28, 0.57, 0.61), ph('body', 'body', 0.67, 0.28, 0.27, 0.48)] },
  { id: 'kpi', ph: [ph('title', 'title', 0.06, 0.08, 0.88, 0.16), ph('kpi', 'kpi1', 0.06, 0.31, 0.2, 0.19), ph('caption', 'cap1', 0.06, 0.52, 0.2, 0.18), ph('kpi', 'kpi2', 0.29, 0.31, 0.2, 0.19), ph('caption', 'cap2', 0.29, 0.52, 0.2, 0.18), ph('body', 'body', 0.06, 0.74, 0.88, 0.15)] },
  { id: 'image-right', ph: [ph('media', 'media', 0.58, 0, 0.42, 1), ph('title', 'title', 0.06, 0.09, 0.47, 0.22), ph('body', 'body', 0.06, 0.31, 0.47, 0.57)] },
  { id: 'title', ph: [ph('title', 'title', 0.06, 0.26, 0.69, 0.37), ph('subtitle', 'subtitle', 0.06, 0.67, 0.57, 0.13)] },
];
const lib = (id) => LIB.find((l) => l.id === id);
const byRole = (s, role) => s.els.filter((e) => e.role === role);
const textOf = (e) => docText(e.doc);

test('bullets: title and a real bullet list in the layout places, scaled to the deck', () => {
  const s = composeSlide({ title: 'Costs rose', kind: 'bullets', slots: { title: 'Costs rose', bullets: ['Fuel', 'Labour'] }, notes: 'say this' }, { deck, layout: lib('bullets') });
  assert.equal(s.layout, 'bullets');
  assert.equal(s.title, 'Costs rose');
  assert.equal(s.notes, 'say this');
  const title = byRole(s, 'title')[0];
  assert.equal(textOf(title), 'Costs rose');
  assert.equal(title.x, 115.2);
  assert.equal(title.w, 672);
  assert.equal(title.size, 63.72);
  assert.equal(title.font, '@heading');
  assert.equal(title.fit, 'shrink');
  const body = byRole(s, 'body')[0];
  assert.equal(body.doc.content[0].type, 'bulletList');
  assert.equal(body.doc.content[0].content.length, 2);
  assert.equal(textOf(body), 'Fuel\nLabour');
  assert.equal(s.els.length, 2, 'nothing is added that the model did not say');
});

test('every element has a valid unique id and the usual geometry fields', () => {
  const s = composeSlide({ title: 'T', kind: 'kpi', slots: { title: 'T', kpis: [{ label: 'Revenue', value: 'R4.2m', delta: '+8%' }, { label: 'Margin', value: '31%' }], bullets: ['Note'] } }, { deck, layout: lib('kpi') });
  const ids = s.els.map((e) => e.id);
  assert.equal(new Set(ids).size, ids.length);
  for (const e of s.els) { assert.match(e.id, ID_RE); assert.equal(e.rot, 0); assert.equal(e.lock, false); assert.ok(e.w > 0 && e.h > 0); }
});

test('kpi: value in the kpi place, label with delta in the caption below', () => {
  const s = composeSlide({ title: 'T', kind: 'kpi', slots: { title: 'T', kpis: [{ label: 'Revenue', value: 'R4.2m', delta: '+8%' }, { label: 'Margin', value: '31%' }] } }, { deck, layout: lib('kpi') });
  assert.deepEqual(byRole(s, 'kpi').map(textOf), ['R4.2m', '31%']);
  assert.deepEqual(byRole(s, 'caption').map(textOf), ['Revenue (+8%)', 'Margin']);
});

test('two columns split the bullets; compare keeps each side with its heading', () => {
  const two = composeSlide({ title: 'T', kind: 'two', slots: { title: 'T', bullets: ['a', 'b', 'c'] } }, { deck, layout: lib('two') });
  assert.deepEqual(byRole(two, 'body').map(textOf), ['a\nb', 'c']);
  const cmp = composeSlide({ title: 'T', kind: 'compare', slots: { title: 'T', leftTitle: 'Before', rightTitle: 'After', left: ['slow'], right: ['fast', 'cheap'] } }, { deck, layout: lib('compare') });
  assert.deepEqual(byRole(cmp, 'subtitle').map(textOf), ['Before', 'After']);
  assert.deepEqual(byRole(cmp, 'body').map(textOf), ['slow', 'fast\ncheap']);
});

test('timeline puts a step in each place and the overflow in the last, numbered', () => {
  const s = composeSlide({ title: 'T', kind: 'timeline', slots: { title: 'T', steps: ['one', 'two', 'three', 'four'] } }, { deck, layout: lib('timeline') });
  const bodies = byRole(s, 'body');
  assert.equal(textOf(bodies[0]), 'one');
  assert.equal(textOf(bodies[1]), 'two');
  assert.equal(bodies[2].doc.content[0].type, 'orderedList');
  assert.equal(textOf(bodies[2]), 'three\nfour');
});

test('quote and attribution', () => {
  const s = composeSlide({ title: '', kind: 'quote', slots: { quote: 'We start Monday.', attribution: 'Thandi, COO' } }, { deck, layout: lib('quote') });
  assert.equal(textOf(byRole(s, 'body')[0]), 'We start Monday.');
  assert.equal(textOf(byRole(s, 'caption')[0]), 'Thandi, COO');
});

test('data: the chart goes in the media place with the data it was given, plus an alt text', () => {
  const chart = { type: 'column', data: { categories: ['2023', '2024'], series: [{ name: 'Revenue', values: [4, 7] }] }, opt: { legend: 'none' }, src: { kind: 'manual' } };
  const s = composeSlide({ title: 'Revenue grew', kind: 'data', slots: { title: 'Revenue grew', chart, bullets: ['Up 75%'] } }, { deck, layout: lib('data') });
  const el = s.els.find((e) => e.type === 'chart');
  assert.deepEqual(el.chart.data, chart.data);
  assert.equal(el.chart.opt.legend, 'none');
  assert.equal(el.x, 115.2);
  assert.match(el.alt, /Column chart\. 2023 4, 2024 7\./);
});

test('a picture slot is a labelled placeholder, never an invented picture', () => {
  const s = composeSlide({ title: 'T', kind: 'image-right', slots: { title: 'T', bullets: ['x'], imageHint: 'the mine at dawn' } }, { deck, layout: lib('image-right') });
  const pic = s.els.find((e) => e.role === 'media');
  assert.equal(pic.type, 'shape');
  assert.equal(pic.origin, 'template');
  assert.equal(pic.name, 'Picture: the mine at dawn');
  assert.ok(!s.els.some((e) => e.type === 'image'));
});

test('title slides', () => {
  const s = composeSlide({ title: 'Deck', kind: 'title', slots: { title: 'Deck', subtitle: 'Prepared for Investors' } }, { deck, layout: lib('title') });
  assert.deepEqual(s.els.map((e) => e.role), ['title', 'subtitle']);
});

test('needs become a visible muted slot with the label and the question', () => {
  const s = composeSlide({ title: 'The ask', kind: 'bullets', slots: { title: 'The ask', bullets: [] }, needs: [{ slot: 'bullets', label: 'Needs your evidence', question: 'What do you want?' }] }, { deck, layout: lib('bullets') });
  const need = s.els.find((e) => e.name === 'Needs your evidence');
  assert.equal(textOf(need), 'Needs your evidence\nWhat do you want?');
  assert.equal(need.color, '@muted');
  assert.equal(need.x, 883.2, 'it takes the free body place');
  const full = composeSlide({ title: 'T', kind: 'bullets', slots: { title: 'T', bullets: ['a'] }, needs: [{ slot: 'x', label: 'Needs your evidence', question: 'q' }] }, { deck, layout: lib('bullets') });
  assert.equal(full.els.filter((e) => e.name === 'Needs your evidence').length, 1);
  assert.equal(full.els.find((e) => e.name === 'Needs your evidence').y, 864);
});

test('origin ai is put on every written element; local content keeps the default', () => {
  const ai = composeSlide({ title: 'T', origin: 'ai', kind: 'bullets', slots: { title: 'T', bullets: ['a'] } }, { deck, layout: lib('bullets') });
  assert.ok(ai.els.every((e) => e.origin === 'ai'));
  const local = composeSlide({ title: 'T', origin: 'local', kind: 'bullets', slots: { title: 'T', bullets: ['a'] } }, { deck, layout: lib('bullets') });
  assert.ok(local.els.every((e) => !('origin' in e)));
});

test('blank slides have no elements; a missing library falls back to a plain title and body', () => {
  assert.deepEqual(composeSlide({ title: 'x', kind: 'blank', slots: {} }, { deck }).els, []);
  const s = composeSlide({ title: 'T', kind: 'bullets', slots: { title: 'T', bullets: ['a'] } }, { deck, layout: null });
  assert.deepEqual(s.els.map((e) => e.role), ['title', 'body']);
  const steps = composeSlide({ title: 'T', kind: 'timeline', slots: { title: 'T', steps: ['one', 'two'] } }, { deck, layout: null });
  assert.equal(steps.els[1].doc.content[0].type, 'orderedList');
});

test('deck size scales everything (a legacy 1280 x 720 deck)', () => {
  const s = composeSlide({ title: 'T', kind: 'bullets', slots: { title: 'T', bullets: ['a'] } }, { deck: { ...deck, w: 1280, h: 720 }, layout: lib('bullets') });
  assert.equal(byRole(s, 'title')[0].x, 76.8);
  assert.ok(byRole(s, 'title')[0].size < 45);
});

test('buildDoc keeps mixed paragraphs and lists in order', () => {
  const d = buildDoc([{ text: 'Head', list: null }, { text: 'a', list: 'bullet' }, { text: 'b', list: 'bullet' }, { text: 'Tail', list: null }]);
  assert.deepEqual(d.content.map((n) => n.type), ['paragraph', 'bulletList', 'paragraph']);
  assert.equal(buildDoc([]).content.length, 1);
});

test('composeInsert chains slides after the chosen slide and the ops apply to a real document', () => {
  const models = [
    { title: 'A', kind: 'title', slots: { title: 'A' } },
    { title: 'B', kind: 'bullets', slots: { title: 'B', bullets: ['x'] } },
    { title: 'C', kind: 'two', slots: { title: 'C', bullets: ['x', 'y'] } },
  ];
  const ops = composeInsert(models, { deck, layouts: LIB, afterSlide: '2', makeTid: (i) => `t_gen${i}` });
  assert.deepEqual(ops.map((o) => [o.tid, o.after]), [['t_gen0', '2'], ['t_gen1', 't_gen0'], ['t_gen2', 't_gen1']]);
  const doc = mkDoc([S('1'), S('2'), S('3')]);
  const r = applyOps(doc, ops);
  assert.equal(r.skipped.length, 0);
  assert.deepEqual(r.doc.slides.map((s) => s.title), ['', '', 'A', 'B', 'C', '']);
  assert.equal(r.doc.slides[3].els.length >= 2, true);
  assert.equal(r.doc.slides[4].layout, 'two');
});

test('a chart keeps the decimals its numbers have, so 5.5 is not drawn as 6', () => {
  assert.equal(decimalsOf({ series: [{ values: [4.2, 5.5, 7.1] }] }), 1);
  assert.equal(decimalsOf({ series: [{ values: [4, 5, null] }] }), 0);
  assert.equal(decimalsOf({ series: [{ values: [0.125, 2] }] }), 3);
  const chart = { type: 'column', data: { categories: ['a', 'b'], series: [{ name: 's', values: [4.2, 5.5] }] }, opt: { legend: 'none' } };
  const s = composeSlide({ title: 'T', kind: 'data', slots: { title: 'T', chart } }, { deck, layout: lib('data') });
  assert.equal(s.els.find((e) => e.type === 'chart').chart.opt.dec, 1);
  const own = composeSlide({ title: 'T', kind: 'data', slots: { title: 'T', chart: { ...chart, opt: { dec: 2 } } } }, { deck, layout: lib('data') });
  assert.equal(own.els.find((e) => e.type === 'chart').chart.opt.dec, 2);
});
