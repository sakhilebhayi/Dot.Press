import test from 'node:test';
import assert from 'node:assert/strict';
import {
  serializeElements, serializeSlides, toClipboardText, parseClipboard, classifyText, cloneElements, conformPreview, pasteElements,
  pasteSlides, duplicateElements, textElementFromString, imageElementFromUrl,
} from '../clipboard.js';
import { DEFAULT_THEME } from '../theme.js';
import { applyOps } from '../ops.js';
import { mkDoc, S, T, R, mulberry32 } from './helpers.mjs';

const dark = {
  ...DEFAULT_THEME, id: 'ink',
  c: { ...DEFAULT_THEME.c, bg: '#0b0f14', ink: '#f3f4f6', accent: '#38bdf8' },
  t: { heading: 'Fraunces', body: 'Inter', hw: 600, scale: 1 },
};
const srcDoc = () => mkDoc([S('s1', [
  T('a', 100, 100, 200, 80, { color: '@ink' }),
  R('b', 400, 100, 100, 100, { fill: '#2563eb', group: 'g' }),
  R('c', 520, 100, 100, 100, { fill: '#ff00aa', group: 'g' }),
])], { id: 'deckA' });
const dstDoc = (extra = {}) => mkDoc([S('t1', [T('z', 0, 0, 1, 1)]), S('t2')], { id: 'deckB', theme: dark, ...extra });

test('serialise keeps z-order and the source theme, and survives text round trips', () => {
  const p = serializeElements(srcDoc(), 's1', ['c', 'a']);
  assert.deepEqual(p.els.map((e) => e.id), ['a', 'c']);
  assert.equal(p.v, 1);
  assert.equal(p.theme.id, 'slate');
  assert.equal(p.deckId, 'deckA');
  const back = parseClipboard(toClipboardText(p));
  assert.deepEqual(back, p);
  assert.equal(parseClipboard('hello'), null);
  assert.equal(parseClipboard('{"kind":"other","v":1}'), null);
  assert.equal(parseClipboard('{"kind":"dot-press/elements","v":2,"els":[]}'), null);
  assert.equal(parseClipboard('{ not json'), null);
});

test('classifyText: payload, image url, plain text, nothing', () => {
  const p = serializeElements(srcDoc(), 's1', ['a']);
  assert.equal(classifyText(toClipboardText(p)).kind, 'payload');
  assert.deepEqual(classifyText('https://example.com/pic.PNG?w=2'), { kind: 'image-url', url: 'https://example.com/pic.PNG?w=2' });
  assert.equal(classifyText('http://example.com/pic.png').kind, 'text', 'only https images');
  assert.equal(classifyText('two\nlines').kind, 'text');
  assert.equal(classifyText('   '), null);
  assert.equal(classifyText(null), null);
});

test('cloneElements gives fresh element and group ids and never reuses taken ones', () => {
  const els = srcDoc().slides[0].els;
  const r = cloneElements(els, new Set(['a', 'b', 'c', 'g']), mulberry32(3));
  assert.equal(new Set(r.els.map((e) => e.id)).size, 3);
  assert.ok(r.els.every((e, i) => e.id !== els[i].id));
  assert.equal(r.els[1].group, r.els[2].group);
  assert.notEqual(r.els[1].group, 'g');
  assert.equal(r.idMap.get('a'), r.els[0].id);
  assert.equal(els[0].id, 'a', 'input untouched');
});

test('conform: colours that are exactly a source theme colour become the target token of the same role', () => {
  const els = [R('x', 0, 0, 1, 1, { fill: '#2563eb', stroke: '#ff00aa' }), T('y', 0, 0, 1, 1, { color: '#111827', font: 'Public Sans' })];
  const { els: out, report } = conformPreview(els, DEFAULT_THEME, dark, 'conform');
  assert.equal(out[0].fill, '@accent');
  assert.equal(out[0].stroke, '#ff00aa', 'custom hex colours are kept');
  assert.equal(out[1].color, '@ink');
  assert.equal(out[1].font, '@body', 'the source body family becomes the body role');
  assert.equal(report.mode, 'conform');
  assert.equal(report.sameTheme, false);
  const accent = report.changes.find((c) => c.from === '#2563eb');
  assert.deepEqual([accent.kind, accent.to, accent.count, accent.elements], ['color', '@accent', 1, ['x']]);
  assert.ok(report.changes.some((c) => c.kind === 'font' && c.to === '@body'));
  assert.equal(els[0].fill, '#2563eb', 'preview does not touch the input');
});

test('conform handles gradients, text marks and heading fonts', () => {
  const el = T('t', 0, 0, 1, 1, {
    fill: { g: 'linear', a: 0, stops: [[0, '#2563eb'], [1, '#123456']] },
    font: 'Fraunces',
    doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'x', marks: [{ type: 'textStyle', attrs: { color: '#f59e0b', fontFamily: 'Inter' } }] }] }] },
  });
  const src = { ...DEFAULT_THEME, t: { heading: 'Fraunces', body: 'Inter', hw: 700, scale: 1 } };
  const { els } = conformPreview([el], src, dark, 'conform');
  assert.deepEqual(els[0].fill.stops, [[0, '@accent'], [1, '#123456']]);
  assert.equal(els[0].font, '@heading');
  const mark = els[0].doc.content[0].content[0].marks[0].attrs;
  assert.equal(mark.color, '@accent2');
  assert.equal(mark.fontFamily, '@body');
});

test('conform keep mode resolves tokens to the source look; none leaves everything', () => {
  const els = [R('x', 0, 0, 1, 1, { fill: '@accent' }), T('y', 0, 0, 1, 1, { color: '@ink', font: '@heading' })];
  const keep = conformPreview(els, DEFAULT_THEME, dark, 'keep');
  assert.equal(keep.els[0].fill, '#2563eb');
  assert.equal(keep.els[1].color, '#111827');
  assert.equal(keep.els[1].font, 'Public Sans');
  assert.equal(conformPreview(els, DEFAULT_THEME, dark, 'none').els, els);
  const same = conformPreview(els, DEFAULT_THEME, DEFAULT_THEME, 'conform');
  assert.equal(same.els, els);
  assert.equal(same.report.sameTheme, true);
  assert.deepEqual(same.report.changes, []);
});

test('paste into another deck: relative layout kept, tokens followed, ids fresh, links to slides removed', () => {
  const src = srcDoc();
  src.slides[0].els[0] = { ...src.slides[0].els[0], link: '#slide:s9' };
  const payload = serializeElements(src, 's1', ['a', 'b', 'c']);
  const dst = dstDoc();
  const r = pasteElements(dst, payload, { slideId: 't2', rng: mulberry32(4) });
  assert.equal(r.ops.length, 3);
  assert.ok(r.ops.every((o) => o.op === 'el.add' && o.slide === 't2'));
  const els = r.ops.map((o) => o.el);
  assert.deepEqual(els.map((e) => e.x), [100, 400, 520]);
  assert.equal(els[0].link, undefined);
  assert.equal(r.report.droppedLinks, 1);
  assert.equal(els[1].fill, '@accent', 'conformed');
  assert.equal(els[1].group, els[2].group);
  assert.deepEqual(applyOps(dst, r.ops).skipped, []);
  assert.deepEqual(r.ids, els.map((e) => e.id));
});

test('paste onto the slide it came from offsets by a step; repeated pastes keep stepping', () => {
  const doc = srcDoc();
  const payload = serializeElements(doc, 's1', ['a']);
  const r0 = pasteElements(doc, payload, { slideId: 's1', rng: mulberry32(1) });
  assert.deepEqual([r0.ops[0].el.x, r0.ops[0].el.y], [124, 124]);
  const r1 = pasteElements(doc, payload, { slideId: 's1', repeat: 1, rng: mulberry32(2) });
  assert.deepEqual([r1.ops[0].el.x, r1.ops[0].el.y], [148, 148]);
  const small = mkDoc([S('s1', [T('a', 100, 100, 200, 80)])], { id: 'deckA', w: 1280, h: 720 });
  const ps = serializeElements(small, 's1', ['a']);
  assert.equal(pasteElements(small, ps, { slideId: 's1' }).ops[0].el.x, 116, 'the step scales with the deck width');
});

test('paste across deck sizes scales geometry and type', () => {
  const src = mkDoc([S('s1', [T('a', 960, 540, 480, 270, { size: 60 })])], { id: 'deckA' });
  const payload = serializeElements(src, 's1', ['a']);
  const dst = mkDoc([S('t1')], { id: 'deckB', w: 1280, h: 720 });
  const el = pasteElements(dst, payload, { slideId: 't1', mode: 'none' }).ops[0].el;
  assert.deepEqual([el.x, el.y, el.w, el.h, el.size], [640, 360, 320, 180, 40]);
});

test('a pasted block that would hang off the slide is pulled back inside', () => {
  const src = mkDoc([S('s1', [T('a', 1800, 1000, 100, 50)])], { id: 'deckA' });
  const dst = mkDoc([S('t1', [T('z', 1800, 1000, 100, 50)])], { id: 'deckA' });
  const payload = serializeElements(src, 's1', ['a']);
  payload.slide = 'other';
  const el = pasteElements(dst, payload, { slideId: 't1', repeat: 3 }).ops[0].el;
  assert.ok(el.x + el.w <= 1920 && el.y + el.h <= 1080);
});

test('paste text and image url create elements', () => {
  const deck = { w: 1920, h: 1080 };
  const t = textElementFromString(deck, 'a\nb');
  assert.equal(t.type, 'text');
  assert.equal(t.doc.content.length, 2);
  const i = imageElementFromUrl(deck, 'https://example.com/x.png');
  assert.deepEqual(i.src, { url: 'https://example.com/x.png' });
  assert.equal(i.type, 'image');
});

test('slide copy and paste: fresh slide and element ids, section dropped, placed after the anchor', () => {
  const src = mkDoc([S('s1', [T('a', 10, 10, 100, 50), R('b', 200, 10, 50, 50, { group: 'g' }), R('c', 300, 10, 50, 50, { group: 'g' })], { title: 'One', section_id: 'x1', notes: 'n' })], { id: 'deckA' });
  const payload = serializeSlides(src, ['s1']);
  const dst = dstDoc();
  const r = pasteSlides(dst, payload, { after: 't1', rng: mulberry32(5) });
  assert.equal(r.ops.length, 1);
  const op = r.ops[0];
  assert.equal(op.op, 'slide.add');
  assert.equal(op.after, 't1');
  assert.equal(op.slide.title, 'One');
  assert.equal(op.slide.section_id, null);
  assert.notEqual(op.slide.els[0].id, 'a');
  assert.equal(op.slide.els[1].group, op.slide.els[2].group);
  const applied = applyOps(dst, r.ops);
  assert.deepEqual(applied.skipped, []);
  assert.equal(applied.doc.slides[1].id, op.tid);
  assert.deepEqual(r.ids, [op.tid]);
  // several slides chain in order
  const two = serializeSlides(mkDoc([S('s1'), S('s2')], { id: 'deckA' }), ['s1', 's2']);
  const rr = pasteSlides(dst, two, {});
  assert.equal(rr.ops[0].after, 't2');
  assert.equal(rr.ops[1].after, rr.ops[0].tid);
});

test('duplicateElements pastes in place with an offset and never changes the theme', () => {
  const doc = srcDoc();
  const r = duplicateElements(doc, 's1', ['b', 'c'], { rng: mulberry32(7) });
  assert.equal(r.ops.length, 2);
  assert.equal(r.ops[0].el.x, 424);
  assert.equal(r.ops[0].el.fill, '#2563eb', 'duplicate does not conform');
  assert.equal(r.ops[0].el.group, r.ops[1].el.group);
  assert.notEqual(r.ops[0].el.group, 'g');
});
