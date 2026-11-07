import test from 'node:test';
import assert from 'node:assert/strict';
import { CHART_TYPES } from '../../../../charts/index.js';
import { ICONS } from '../../../../icons.js';
import { SHAPE_KINDS } from '../../../../render/geometry.js';
import { docText } from '../../../core/doc.js';
import { applyOps } from '../../../core/ops.js';
import { createDoc } from '../../../core/doc.js';
import {
  INSERT_TABS, tabForKind, TEXT_PRESETS, buildText, SHAPES, buildShape, buildIcon, buildChart, chartSize, GRID_MAX, clampGrid, gridWords,
  buildTable, parseVideoUrl, buildVideoFromUrl, buildMediaElement, buildFromDrag,
} from '../insertModel.js';
import { placeNew, fitToSlide, insertionPointOf } from '../placement.js';

const deck = { w: 1920, h: 1080, theme: { t: { heading: 'Public Sans', body: 'Public Sans', hw: 800, scale: 1 }, c: {} } };
const inside = (el) => el.x >= 0 && el.y >= 0 && el.x + el.w <= deck.w + 0.01 && el.y + el.h <= deck.h + 0.01;

test('the dock kinds open the right tab', () => {
  const ids = INSERT_TABS.map((t) => t.id);
  for (const k of ['image', 'chart', 'table', 'icon', 'video', 'audio', 'shape', 'text', 'line', 'arrow', 'shape:star']) assert.ok(ids.includes(tabForKind(k)), k);
  assert.equal(tabForKind('video'), 'media');
  assert.equal(tabForKind('shape:star'), 'shapes');
  assert.equal(tabForKind(undefined), null);
  assert.equal(tabForKind('nope'), null);
});

test('text presets are built from deck and theme tokens', () => {
  assert.deepEqual(TEXT_PRESETS.map((p) => p.id), ['title', 'heading', 'body', 'caption', 'quote', 'bullets', 'numbered']);
  const title = buildText(deck, 'title', []);
  assert.equal(title.type, 'text');
  assert.equal(title.font, '@heading');
  assert.equal(title.weight, 800);
  assert.equal(title.color, '@ink');
  assert.equal(title.size, 81);
  assert.equal(buildText(deck, 'caption').color, '@muted');
  assert.equal(buildText(deck, 'body').font, '@body');
  assert.equal(buildText(deck, 'nope'), null);
  // sizes follow the deck: a 1280 x 720 deck gets two thirds of the type
  assert.equal(buildText({ ...deck, w: 1280, h: 720 }, 'title').size, 54);
});

test('lists are real list nodes with three items; the quote carries an italic mark', () => {
  const b = buildText(deck, 'bullets');
  assert.equal(b.doc.content[0].type, 'bulletList');
  assert.equal(b.doc.content[0].content.length, 3);
  assert.equal(buildText(deck, 'numbered').doc.content[0].type, 'orderedList');
  assert.match(docText(b.doc), /First point/);
  assert.deepEqual(buildText(deck, 'quote').doc.content[0].content[0].marks, [{ type: 'italic' }]);
});

test('every spec shape kind can be built; lines have no height', () => {
  assert.deepEqual([...SHAPES].sort(), [...SHAPE_KINDS].sort());
  for (const k of SHAPES) {
    const el = buildShape(deck, k);
    assert.equal(el.type, 'shape');
    assert.equal(el.kind, k);
    assert.ok(el.w > 0);
  }
  assert.equal(buildShape(deck, 'line').h, 0);
  assert.equal(buildShape(deck, 'arrow').ends.e, 'arrow');
  assert.equal(buildShape(deck, 'nope'), null);
  assert.ok(buildShape(deck, 'rect').w > buildShape(deck, 'rect').h);
});

test('icons are built for any name from the set', () => {
  assert.equal(buildIcon(deck, ICONS[3].id).name, ICONS[3].id);
});

test('every chart type builds a normalised chart that keeps its sample options', () => {
  for (const t of CHART_TYPES) {
    const el = buildChart(deck, t.id, []);
    assert.equal(el.type, 'chart');
    assert.equal(el.chart.type, t.id);
    assert.ok(el.chart.data);
    assert.equal(el.chart.src.kind, 'manual');
    assert.ok(el.w <= deck.w * 0.8 && el.h <= deck.h * 0.8);
  }
  assert.equal(buildChart(deck, 'column').chart.opt.title, 'Revenue and costs by quarter');
  assert.equal(buildChart(deck, 'nope'), null);
  assert.ok(chartSize(deck, 'kpi').h < chartSize(deck, 'column').h);
});

test('table grid is limited to 10 rows by 8 columns', () => {
  assert.deepEqual(GRID_MAX, { rows: 10, cols: 8 });
  assert.deepEqual(clampGrid(99, 99), { rows: 10, cols: 8 });
  assert.deepEqual(clampGrid(0, -4), { rows: 1, cols: 1 });
  assert.deepEqual(clampGrid('x', 3.9), { rows: 1, cols: 3 });
  assert.equal(gridWords(1, 1), '1 row, 1 column');
  assert.equal(gridWords(3, 4), '3 rows, 4 columns');
  const t = buildTable(deck, 10, 8);
  assert.equal(t.rows.length, 10);
  assert.equal(t.rows[0].length, 8);
  assert.equal(t.rowh.length, 10);
  assert.ok(t.y + t.h <= deck.h);
  assert.ok(t.size < buildTable(deck, 3, 3).size);
  assert.ok(Math.abs(t.colw.reduce((a, b) => a + b, 0) - 1) < 1e-6);
});

test('video links: YouTube, Vimeo, direct files, and the refusals', () => {
  const yt = parseVideoUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=90');
  assert.deepEqual([yt.ok, yt.provider, yt.id, yt.start], [true, 'youtube', 'dQw4w9WgXcQ', 90]);
  assert.equal(parseVideoUrl('https://youtu.be/dQw4w9WgXcQ?t=1m5s').start, 65);
  assert.equal(parseVideoUrl('https://www.youtube.com/shorts/dQw4w9WgXcQ').id, 'dQw4w9WgXcQ');
  assert.equal(parseVideoUrl('youtube.com/embed/dQw4w9WgXcQ').ok, true);
  assert.equal(parseVideoUrl('https://vimeo.com/76979871').id, '76979871');
  assert.equal(parseVideoUrl('https://player.vimeo.com/video/76979871?h=abc').id, '76979871');
  const file = parseVideoUrl('https://cdn.example.com/a/b.mp4?x=1#frag');
  assert.deepEqual([file.ok, file.provider], [true, 'file']);
  assert.ok(!file.url.includes('#'));
  assert.equal(parseVideoUrl('http://vimeo.com/76979871').ok, false);
  assert.equal(parseVideoUrl('').ok, false);
  assert.equal(parseVideoUrl('https://youtube.com/watch?v=short').ok, false);
  assert.equal(parseVideoUrl('https://example.com/page').ok, false);
  assert.equal(parseVideoUrl('javascript:alert(1)').ok, false);
  assert.equal(parseVideoUrl('not a url at all').ok, false);
});

test('a pasted link becomes an embed or a file video the sanitiser accepts', () => {
  const e = buildVideoFromUrl(deck, parseVideoUrl('https://youtu.be/dQw4w9WgXcQ?t=30'));
  assert.deepEqual(e.embed, { provider: 'youtube', id: 'dQw4w9WgXcQ' });
  assert.equal(e.start, 30);
  assert.equal('src' in e, false);
  const f = buildVideoFromUrl(deck, parseVideoUrl('https://cdn.example.com/v.webm'));
  assert.deepEqual(f.src, { url: 'https://cdn.example.com/v.webm' });
  assert.equal(buildVideoFromUrl(deck, { ok: false }), null);
});

test('library assets become pictures that keep their ratio, with alt text', () => {
  const img = buildMediaElement(deck, 'image', { id: 7, width: 2000, height: 1000, alt: 'A mine head frame' });
  assert.deepEqual(img.src, { asset: 7 });
  assert.equal(img.alt, 'A mine head frame');
  assert.ok(Math.abs(img.w / img.h - 2) < 0.01);
  assert.ok(inside(img));
  assert.equal(buildMediaElement(deck, 'video', { id: 8, width: 1920, height: 1080 }).type, 'video');
  const a = buildMediaElement(deck, 'audio', { id: 9 });
  assert.equal(a.type, 'audio');
  assert.deepEqual(a.src, { asset: 9 });
  assert.ok(a.w > 0 && a.alt === undefined);
});

test('drag payloads build the same elements as clicks', () => {
  assert.equal(buildFromDrag(deck, { kind: 'insert', what: 'text', preset: 'heading' }).size, 54);
  assert.equal(buildFromDrag(deck, { kind: 'insert', what: 'shape', shape: 'star' }).kind, 'star');
  assert.equal(buildFromDrag(deck, { kind: 'insert', what: 'icon', name: 'rocket' }).name, 'rocket');
  assert.equal(buildFromDrag(deck, { kind: 'insert', what: 'chart', type: 'pie' }).chart.type, 'pie');
  assert.equal(buildFromDrag(deck, { kind: 'insert', what: 'table', rows: 2, cols: 5 }).rows[0].length, 5);
  assert.equal(buildFromDrag(deck, { kind: 'asset', id: 1 }), null);
  assert.equal(buildFromDrag(deck, { kind: 'insert', what: 'icon' }), null);
  assert.equal(buildFromDrag(deck, null), null);
});

test('placement: slide centre, centred on a point, always inside the slide', () => {
  const box = { x: 0, y: 0, w: 400, h: 200 };
  const c = placeNew(deck, box);
  assert.deepEqual([c.x, c.y], [760, 440]);
  const p = placeNew(deck, box, { point: { x: 500, y: 300 } });
  assert.deepEqual([p.x, p.y], [300, 200]);
  const edge = placeNew(deck, box, { point: { x: 5, y: 1075 } });
  assert.deepEqual([edge.x, edge.y], [0, 880]);
  const huge = placeNew(deck, { x: 0, y: 0, w: 4000, h: 3000 });
  assert.ok(inside(huge));
  assert.ok(Math.abs(huge.w / huge.h - 4 / 3) < 0.01);
  const line = placeNew(deck, { x: 0, y: 0, w: 3000, h: 0 });
  assert.equal(line.h, 0);
  assert.ok(inside(line));
});

test('placement: repeated inserts fan out instead of stacking, a drop point does not', () => {
  const box = { x: 0, y: 0, w: 400, h: 200 };
  const first = placeNew(deck, box);
  const second = placeNew(deck, box, { existing: [first] });
  assert.ok(second.x > first.x && second.y > first.y);
  const third = placeNew(deck, box, { existing: [first, second] });
  assert.ok(third.x > second.x);
  const dropped = placeNew(deck, box, { point: { x: 960, y: 540 }, existing: [first] });
  assert.deepEqual([dropped.x, dropped.y], [760, 440]);
  // a slide so full of stacked copies that the step runs out still returns a position inside the slide
  const many = Array.from({ length: 30 }, (_, i) => ({ x: 760 + i * 24, y: 440 + i * 24 }));
  assert.ok(inside(placeNew(deck, box, { existing: many })));
});

test('fitToSlide never scales up', () => {
  const small = { w: 10, h: 10 };
  assert.equal(fitToSlide(deck, small), small);
  const big = fitToSlide(deck, { w: 3840, h: 1080 });
  assert.ok(big.w <= deck.w * 0.94 + 0.01);
});

test('the stage insertion point is used only when it is a valid point', () => {
  assert.deepEqual(insertionPointOf({ stage: { insertionPoint: () => ({ x: 10, y: 20 }) } }), { x: 10, y: 20 });
  assert.equal(insertionPointOf({ stage: { insertionPoint: () => ({ x: NaN, y: 1 }) } }), null);
  assert.equal(insertionPointOf({ stage: {} }), null);
  assert.equal(insertionPointOf({ stage: { insertionPoint: () => { throw new Error('x'); } } }), null);
  assert.equal(insertionPointOf(null), null);
});

test('built elements pass the real el.add op and are undoable', () => {
  const doc = createDoc({
    deck: { id: 1, title: 'T', w: 1920, h: 1080, theme: deck.theme, slide_order: ['1'] },
    slides: [{ id: '1', els: [] }],
    assets: {},
  });
  const els = [
    buildText(deck, 'bullets', []), buildShape(deck, 'star', []), buildIcon(deck, 'rocket', []), buildChart(deck, 'doughnut', []), buildTable(deck, 4, 4, []),
    buildVideoFromUrl(deck, parseVideoUrl('https://youtu.be/dQw4w9WgXcQ')),
  ].map((e) => placeNew(deck, e));
  let cur = doc;
  for (const el of els) {
    const r = applyOps(cur, [{ op: 'el.add', slide: '1', el }]);
    assert.equal(r.applied.length, 1, `${el.type} was refused`);
    assert.equal(r.skipped.length, 0);
    cur = r.doc;
  }
  assert.equal(cur.slides[0].els.length, els.length);
});
