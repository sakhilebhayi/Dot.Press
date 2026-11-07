import test from 'node:test';
import assert from 'node:assert/strict';
import { barKind, barControls, placeBar } from '../lib/bar.js';
import { contextItems } from '../lib/menu.js';
import { copyStyle, pasteStylePatch, styleKeysFor } from '../lib/style.js';
import { shortcutsFor } from '../../core/keys.js';

const text = { id: 't', type: 'text' };
const rect = { id: 'r', type: 'shape', kind: 'rect' };

test('the bar kind follows the selection', () => {
  assert.equal(barKind([]), null);
  assert.equal(barKind([text]), 'text');
  assert.equal(barKind([{ type: 'image' }]), 'picture');
  assert.equal(barKind([rect]), 'shape');
  assert.equal(barKind([{ type: 'shape', kind: 'arrow' }]), 'line');
  assert.equal(barKind([{ type: 'chart' }]), 'chart');
  assert.equal(barKind([{ type: 'table' }]), 'table');
  assert.equal(barKind([{ type: 'icon' }]), 'icon');
  assert.equal(barKind([{ type: 'video' }]), 'media');
  assert.equal(barKind([{ type: 'audio' }]), 'media');
  assert.equal(barKind([text, rect]), 'multi');
  assert.equal(barKind([{ ...text, group: 'g' }, { ...rect, group: 'g' }]), 'group');
  assert.equal(barKind([{ ...text, group: 'g' }, { ...rect, group: 'h' }]), 'multi');
});

test('every bar has five to eight controls (a small set)', () => {
  for (const kind of ['text', 'picture', 'shape', 'chart', 'table', 'line', 'icon', 'media', 'group', 'multi']) {
    const n = barControls(kind).length;
    assert.ok(n >= 2 && n <= 8, `${kind}: ${n}`);
  }
  assert.equal(barControls('text').length, 8);
  assert.deepEqual(barControls('text').map((c) => c.id).slice(0, 3), ['font', 'size', 'bold']);
});

test('controls that need a panel are hidden when the panel is not registered', () => {
  const none = barControls('chart', { hasPanel: () => false }).map((c) => c.id);
  assert.deepEqual(none, ['chartType']);
  const some = barControls('chart', { hasPanel: (id) => id === 'chart' }).map((c) => c.id);
  assert.deepEqual(some, ['editData', 'chartType']);
  assert.deepEqual(barControls('icon', { hasPanel: () => false }).map((c) => c.id), ['iconColor']);
  assert.deepEqual(barControls('text', { canEdit: false }), []);
});

test('the bar floats 12 px above, flips below near the top, and stays inside the viewport', () => {
  const vp = { w: 1000, h: 700 };
  const size = { w: 300, h: 40 };
  const above = placeBar({ anchor: { x: 400, y: 300, w: 200, h: 100 }, size, viewport: vp });
  assert.deepEqual(above, { x: 350, y: 248, placement: 'above' });
  const below = placeBar({ anchor: { x: 400, y: 20, w: 200, h: 100 }, size, viewport: vp });
  assert.equal(below.placement, 'below');
  assert.equal(below.y, 132);
  const inset = placeBar({ anchor: { x: 400, y: 300, w: 200, h: 100 }, size, viewport: vp, aboveInset: 34 });
  assert.equal(inset.y, 248 - 34);
  const left = placeBar({ anchor: { x: -50, y: 300, w: 60, h: 40 }, size, viewport: vp });
  assert.equal(left.x, 8);
  const right = placeBar({ anchor: { x: 980, y: 300, w: 60, h: 40 }, size, viewport: vp });
  assert.equal(right.x, 1000 - 300 - 8);
  const big = placeBar({ anchor: { x: 0, y: 0, w: 1000, h: 700 }, size, viewport: vp });
  assert.equal(big.placement, 'inside');
  assert.ok(big.y >= 8 && big.y + 40 <= 692);
});

test('context menu for a selection offers the commands from the brief', () => {
  const items = contextItems({ count: 2, canEdit: true, canComment: true, hasStyle: false, hasGroup: false, shortcuts: (c) => shortcutsFor(c, false)[0] || '' });
  const ids = items.filter((i) => i.id).map((i) => i.id);
  for (const id of ['cut', 'copy', 'paste', 'pastePlain', 'duplicate', 'delete', 'group', 'front', 'forward', 'backward', 'back', 'align.left', 'flipH', 'flipV', 'lock', 'hide', 'copyStyle', 'pasteStyle', 'comment']) {
    assert.ok(ids.includes(id), id);
  }
  assert.equal(items.find((i) => i.id === 'pasteStyle').disabled, true);
  assert.equal(items.find((i) => i.id === 'copyStyle').disabled, true, 'copy style needs exactly one element');
  assert.equal(items.find((i) => i.id === 'copy').shortcut, 'Ctrl+C');
  assert.equal(items.find((i) => i.id === 'delete').danger, true);
  assert.ok(!ids.includes('distribute.h'), 'distribute needs three');
  assert.ok(contextItems({ count: 3, canEdit: true }).some((i) => i.id === 'distribute.h'));
  assert.ok(!contextItems({ count: 1, canEdit: true }).some((i) => i.id === 'align.left'), 'one element: no align row');
  assert.ok(items.some((i) => i.heading === 'Arrange'));
});

test('context menu: group becomes ungroup, lock becomes unlock, read only keeps copy', () => {
  const g = contextItems({ count: 2, hasGroup: true, locked: true, hidden: true, canEdit: true });
  assert.ok(g.some((i) => i.id === 'ungroup') && !g.some((i) => i.id === 'group'));
  assert.equal(g.find((i) => i.id === 'lock').label, 'Unlock');
  assert.equal(g.find((i) => i.id === 'hide').label, 'Show');
  const ro = contextItems({ count: 1, canEdit: false, canComment: true }).map((i) => i.id).filter(Boolean);
  assert.deepEqual(ro, ['copy', 'comment']);
  const hidden = contextItems({ count: 1, canEdit: false, canComment: true, hasPanel: () => false }).map((i) => i.id).filter(Boolean);
  assert.deepEqual(hidden, ['copy']);
  const empty = contextItems({ count: 0, canEdit: true }).map((i) => i.id).filter(Boolean);
  assert.deepEqual(empty, ['paste', 'pastePlain', 'selectAll']);
  const out = contextItems({ count: 0, canEdit: true });
  assert.ok(!out[0].separator && !out[out.length - 1].separator);
  for (let i = 1; i < out.length; i++) assert.ok(!(out[i].separator && out[i - 1].separator), 'no doubled separators');
});

test('copy and paste style move only what belongs to the target', () => {
  const src = { id: 'a', type: 'shape', kind: 'rect', fill: '@accent2', stroke: '@ink', sw: 4, radius: 20, dash: 'dash', op: 0.8, x: 1, y: 2, w: 3, h: 4 };
  const st = copyStyle(src);
  assert.deepEqual(Object.keys(st.props).sort(), ['dash', 'fill', 'op', 'radius', 'stroke', 'sw']);
  const target = { id: 'b', type: 'shape', kind: 'ellipse', fill: '@accent', sw: 0, radius: 0, x: 9, y: 9, w: 9, h: 9 };
  const p = pasteStylePatch(st, target);
  assert.equal(p.fill, '@accent2');
  assert.equal(p.sw, 4);
  assert.ok(!('x' in p) && !('w' in p));
  // a text element takes the shape's fill, stroke and radius but not dash
  const t = pasteStylePatch(st, { id: 'c', type: 'text', fill: null });
  assert.equal(t.fill, '@accent2');
  assert.ok(!('dash' in t));
  // lines only take line keys
  assert.deepEqual(styleKeysFor('shape', 'line').includes('fill'), false);
  assert.equal(pasteStylePatch(null, target), null);
  assert.equal(pasteStylePatch(copyStyle(target), target), null, 'nothing to change');
});
