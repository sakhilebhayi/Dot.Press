import test from 'node:test';
import assert from 'node:assert/strict';
import {
  normalizeColor, normalizeFontSize, normalizeFontFamily, sanitizeDoc, docIsBlank, docSetMark, docMarkOn, docSetTextStyle, docTextStyleValue, docEqual,
} from '../lib/textdoc.js';
import { textBoxStyle, shapeTextDefaults } from '../lib/textstyle.js';
import {
  elementLabel, elementPosition, selectionAnnouncement, resizeByKey, moveAnnouncement, resizeAnnouncement, slideAnnouncement, stageLabel,
} from '../lib/a11y.js';
import { textDoc } from '../../core/doc.js';
import { docToHtml } from '../../../render/doc.js';

test('colours from pasted html become hex or stay tokens', () => {
  assert.equal(normalizeColor('rgb(255, 0, 10)'), '#ff000a');
  assert.equal(normalizeColor('rgba(0, 0, 0, 0.5)'), '#00000080');
  assert.equal(normalizeColor('#ABC'), '#aabbcc');
  assert.equal(normalizeColor('@accent'), '@accent');
  assert.equal(normalizeColor('red'), null);
  assert.equal(normalizeColor('@nope'), null);
  assert.equal(normalizeColor(5), null);
});

test('font sizes and families', () => {
  assert.equal(normalizeFontSize('18pt'), '24px');
  assert.equal(normalizeFontSize(44), '44px');
  assert.equal(normalizeFontSize('3em'), null);
  assert.equal(normalizeFontSize(0), null);
  assert.equal(normalizeFontSize(9999), null);
  assert.equal(normalizeFontFamily('"Inter", sans-serif'), 'Inter');
  assert.equal(normalizeFontFamily('@heading'), '@heading');
  assert.equal(normalizeFontFamily('Comic Sans MS'), null);
});

test('sanitize keeps the allowed nodes and marks and drops the rest', () => {
  const dirty = {
    type: 'doc',
    content: [
      { type: 'heading', attrs: { level: 1 }, content: [{ type: 'text', text: 'Title', marks: [{ type: 'bold' }, { type: 'code' }] }] },
      { type: 'blockquote', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'quoted' }] }] },
      { type: 'bulletList', content: [{ type: 'listItem', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'one', marks: [{ type: 'link', attrs: { href: 'javascript:alert(1)' } }] }] }] }] },
      { type: 'paragraph', content: [{ type: 'text', text: 'ok', marks: [{ type: 'link', attrs: { href: 'https://example.com/a' } }, { type: 'textStyle', attrs: { color: 'rgb(1,2,3)', fontSize: '20px', fontFamily: 'Lobster', x: 1 } }] }] },
      { type: 'codeBlock', content: [{ type: 'text', text: 'code' }] },
    ],
  };
  const clean = sanitizeDoc(dirty);
  const types = [];
  const walk = (n) => { types.push(n.type); (n.content || []).forEach(walk); };
  walk(clean);
  assert.ok(types.every((t) => ['doc', 'paragraph', 'bulletList', 'listItem', 'text', 'hardBreak', 'orderedList'].includes(t)), types.join());
  assert.equal(clean.content[0].content[0].marks.length, 1, 'code mark dropped, bold kept');
  assert.equal(clean.content[1].content[0].text, 'quoted', 'blockquote unwrapped');
  const li = clean.content[2].content[0].content[0].content[0];
  assert.equal(li.marks, undefined, 'unsafe link removed');
  const last = clean.content[3].content[0];
  assert.deepEqual(last.marks, [{ type: 'link', attrs: { href: 'https://example.com/a' } }, { type: 'textStyle', attrs: { color: '#010203', fontSize: '20px' } }]);
  assert.equal(clean.content[4].content[0].text, 'code', 'code block text survives as a paragraph');
  assert.deepEqual(sanitizeDoc(null), { type: 'doc', content: [{ type: 'paragraph' }] });
  assert.deepEqual(sanitizeDoc({ type: 'doc', content: [] }).content, [{ type: 'paragraph' }]);
});

test('blank documents', () => {
  assert.ok(docIsBlank(textDoc('')));
  assert.ok(docIsBlank(textDoc('  \n ')));
  assert.ok(!docIsBlank(textDoc('a')));
});

test('whole element formatting maps marks over every run', () => {
  const doc = { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'a', marks: [{ type: 'bold' }] }, { type: 'text', text: 'b' }] }] };
  assert.ok(!docMarkOn(doc, 'bold'));
  const bold = docSetMark(doc, 'bold', null, true);
  assert.ok(docMarkOn(bold, 'bold'));
  assert.equal(bold.content[0].content[0].marks.length, 1, 'no duplicate mark');
  const off = docSetMark(bold, 'bold', null, false);
  assert.equal(off.content[0].content[0].marks, undefined);
  assert.equal(doc.content[0].content[1].marks, undefined, 'input is not mutated');
  const col = docSetTextStyle(doc, 'color', '#ff0000');
  assert.equal(docTextStyleValue(col, 'color'), '#ff0000');
  assert.equal(docTextStyleValue(doc, 'color'), undefined);
  const cleared = docSetTextStyle(col, 'color', null);
  assert.equal(cleared.content[0].content[1].marks, undefined);
  const link = docSetMark(doc, 'link', { href: 'https://a.example' }, true);
  assert.ok(docMarkOn(link, 'link'));
  assert.ok(docEqual(doc, JSON.parse(JSON.stringify(doc))));
  assert.ok(!docMarkOn(textDoc(''), 'bold'), 'no runs: nothing is bold');
});

test('the output renders through the same html path as the slide', () => {
  const doc = docSetTextStyle(docSetMark(textDoc('Hello'), 'italic', null, true), 'color', '@accent');
  const html = docToHtml(doc);
  assert.match(html, /<em>Hello<\/em>/);
  assert.match(html, /color:var\(--p-accent\)/);
});

test('text box style mirrors the renderer', () => {
  const el = { type: 'text', font: '@heading', size: 72, weight: 700, color: '@accent', align: 'center', valign: 'middle', lh: 1.1, ls: -0.02, ps: 0.3, pad: 24, fill: '@surface', radius: 12, sw: 2, stroke: '@line' };
  const s = textBoxStyle(el, undefined, 'text');
  assert.equal(s.fontSize, 'calc(72px * var(--p-fit, 1))');
  assert.equal(s.padding, '24px');
  assert.equal(s.textAlign, 'center');
  assert.equal(s.justifyContent, 'center');
  assert.equal(s.lineHeight, 1.1);
  assert.equal(s.letterSpacing, '-0.02em');
  assert.equal(s.color, 'var(--p-accent)');
  assert.equal(s.background, 'var(--p-surface)');
  assert.equal(s.borderRadius, '12px');
  assert.match(s.boxShadow, /inset 0 0 0 2px var\(--p-line\)/);
  const shape = textBoxStyle({ type: 'shape', fill: '@accent' }, undefined, 'shape');
  assert.equal(shape.padding, '16px', 'shapes default to 16 padding');
  assert.equal(shape.background, undefined, 'the shape draws its own fill');
  assert.equal(shape.fontSize, 'calc(48px * var(--p-fit, 1))');
  const outline = textBoxStyle({ type: 'text', size: 40, fx: 'outline', color: '@ink' }, undefined, 'text');
  assert.equal(outline.color, 'transparent');
  assert.ok(outline.WebkitTextStroke);
});

test('a shape that gets its first text receives centred defaults', () => {
  const d = shapeTextDefaults({ w: 1920 }, { type: 'shape' });
  assert.equal(d.align, 'center');
  assert.equal(d.valign, 'middle');
  assert.equal(d.size, 44);
  assert.deepEqual(shapeTextDefaults({ w: 1920 }, { type: 'shape', size: 10, font: '@body', color: '@bg', align: 'left', valign: 'top', pad: 1, lh: 1 }), {});
});

test('labels and announcements', () => {
  const t = { id: 't', type: 'text', x: 120.4, y: 80, w: 640, h: 360, doc: textDoc('Quarterly results') };
  assert.equal(elementLabel(t), 'Text: Quarterly results');
  assert.equal(elementLabel({ ...t, lock: true }), 'Text: Quarterly results (locked)');
  assert.equal(elementLabel({ type: 'image', alt: 'Team on site' }), 'Picture: Team on site');
  assert.equal(elementLabel({ type: 'image', src: { asset: 4 } }, { 4: { name: 'logo.png' } }), 'Picture: logo.png');
  assert.equal(elementLabel({ type: 'shape', kind: 'ellipse' }), 'Shape: ellipse');
  assert.equal(elementLabel({ type: 'chart', chart: { type: 'bar', opt: {} } }), 'Chart: bar');
  assert.equal(elementLabel({ type: 'text', name: 'Headline', doc: textDoc('x') }), 'Text: Headline');
  assert.equal(elementPosition(t), 'at 120, 80, 640 by 360');
  assert.equal(selectionAnnouncement([t], 1, 7), 'Selected Text: Quarterly results. 2 of 7');
  assert.equal(selectionAnnouncement([t, t]), '2 elements selected');
  assert.equal(selectionAnnouncement([]), 'Selection cleared');
  assert.equal(moveAnnouncement(t), 'Moved to 120, 80');
  assert.equal(resizeAnnouncement(t), 'Resized to 640 by 360');
  assert.equal(slideAnnouncement({ title: 'Market' }, 2, 12), 'Slide 3 of 12, Market');
  assert.match(stageLabel({ title: '' }, 0, 3), /^Slide 1 of 3\./);
});

test('alt and arrow keys resize by 1 or 10', () => {
  const el = { type: 'shape', kind: 'rect', w: 100, h: 50 };
  assert.deepEqual(resizeByKey(el, 'ArrowRight'), { w: 101, h: 50 });
  assert.deepEqual(resizeByKey(el, 'ArrowDown', { shift: true }), { w: 100, h: 60 });
  assert.deepEqual(resizeByKey(el, 'ArrowLeft', { shift: true }), { w: 90, h: 50 });
  assert.equal(resizeByKey({ ...el, w: 8 }, 'ArrowLeft'), null, 'minimum size');
  assert.equal(resizeByKey(el, 'a'), null);
});
