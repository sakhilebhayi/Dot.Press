import test from 'node:test';
import assert from 'node:assert/strict';
import { toolKind, shapeKindOf, dragRect, isClick, buildFromTool } from '../lib/draw.js';

const deck = { w: 1920, h: 1080, theme: undefined };

test('tool names', () => {
  assert.equal(toolKind('shape:ellipse'), 'shape');
  assert.equal(shapeKindOf('shape:ellipse'), 'ellipse');
  assert.equal(shapeKindOf('shape'), 'rect');
  assert.equal(toolKind('select'), 'select');
  assert.equal(toolKind(null), 'select');
});

test('a click is a drag shorter than four screen pixels', () => {
  assert.ok(isClick({ x: 0, y: 0 }, { x: 3, y: 0 }, 1));
  assert.ok(!isClick({ x: 0, y: 0 }, { x: 3, y: 0 }, 2));
});

test('drag rect normalises direction, shift squares, alt grows from the first point', () => {
  assert.deepEqual(dragRect({ x: 100, y: 100 }, { x: 50, y: 80 }), { x: 50, y: 80, w: 50, h: 20 });
  assert.deepEqual(dragRect({ x: 100, y: 100 }, { x: 160, y: 130 }, { shift: true }), { x: 100, y: 100, w: 60, h: 60 });
  assert.deepEqual(dragRect({ x: 100, y: 100 }, { x: 160, y: 130 }, { alt: true }), { x: 40, y: 70, w: 120, h: 60 });
  assert.deepEqual(dragRect({ x: 100, y: 100 }, { x: 40, y: 40 }, { shift: true }), { x: 40, y: 40, w: 60, h: 60 });
});

test('dragging a shape creates it at the dragged rect, clicking centres the default size', () => {
  const dragged = buildFromTool('shape:ellipse', deck, { x: 200, y: 300 }, { x: 600, y: 500 });
  assert.equal(dragged.type, 'shape');
  assert.equal(dragged.kind, 'ellipse');
  assert.deepEqual([dragged.x, dragged.y, dragged.w, dragged.h], [200, 300, 400, 200]);
  const clicked = buildFromTool('shape:rect', deck, { x: 960, y: 540 }, { x: 960, y: 540 }, { click: true });
  assert.equal(clicked.w, 270);
  assert.equal(clicked.x, 960 - 135);
  assert.equal(clicked.y, 540 - 135);
});

test('new shapes stay inside the slide', () => {
  const e = buildFromTool('shape:rect', deck, { x: 10, y: 10 }, { x: 10, y: 10 }, { click: true });
  assert.ok(e.x >= 0 && e.y >= 0);
  const f = buildFromTool('shape:rect', deck, { x: 1915, y: 1075 }, { x: 1915, y: 1075 }, { click: true });
  assert.ok(f.x + f.w <= 1920 && f.y + f.h <= 1080);
});

test('lines and arrows run between the two points; shift snaps to 15 degrees', () => {
  const l = buildFromTool('line', deck, { x: 100, y: 100 }, { x: 400, y: 100 });
  assert.deepEqual([l.x, l.y, l.w, l.h, l.rot], [100, 100, 300, 0, 0]);
  const a = buildFromTool('arrow', deck, { x: 100, y: 100 }, { x: 100 + 300 * Math.cos(0.3), y: 100 + 300 * Math.sin(0.3) }, { shift: true });
  assert.equal(a.rot, 15);
  assert.equal(a.ends.e, 'arrow');
  const c = buildFromTool('line', deck, { x: 500, y: 500 }, { x: 500, y: 500 }, { click: true });
  assert.equal(c.h, 0);
  assert.ok(c.w > 100);
});

test('text: a click makes a box at the pointer with an empty document, a drag sets the width', () => {
  const t = buildFromTool('text', deck, { x: 300, y: 400 }, { x: 300, y: 400 }, { click: true });
  assert.equal(t.type, 'text');
  assert.equal(t.x, 300);
  assert.equal(t.w, 576);
  assert.equal(t.doc.content[0].type, 'paragraph');
  assert.equal(t.doc.content[0].content, undefined, 'empty');
  const d = buildFromTool('text', deck, { x: 300, y: 400 }, { x: 900, y: 460 });
  assert.equal(d.w, 600);
  assert.ok(d.h >= 80);
});

test('chart, table and icon tools make their elements', () => {
  assert.equal(buildFromTool('chart', deck, { x: 960, y: 540 }, { x: 960, y: 540 }, { click: true }).type, 'chart');
  const tb = buildFromTool('table', deck, { x: 960, y: 300 }, { x: 960, y: 300 }, { click: true });
  assert.equal(tb.type, 'table');
  assert.equal(tb.y, 300);
  const ic = buildFromTool('icon', deck, { x: 100, y: 100 }, { x: 300, y: 220 });
  assert.equal(ic.w, ic.h, 'icons stay square');
  assert.equal(buildFromTool('image', deck, { x: 0, y: 0 }, { x: 0, y: 0 }, { click: true }), null);
});
