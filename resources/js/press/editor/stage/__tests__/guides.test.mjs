import test from 'node:test';
import assert from 'node:assert/strict';
import {
  tickStep, rulerTicks, gridLines, centreGuides, marginGuides, addGuide, moveGuide, removeGuide,
} from '../lib/guides.js';

const deck = { w: 1920, h: 1080 };

test('ruler ticks stay readable at every zoom', () => {
  for (const z of [0.1, 0.25, 0.5, 1, 2, 4]) {
    const step = tickStep(z);
    assert.ok(step * z >= 8, `${z}: ${step}`);
    const ticks = rulerTicks(0, 1920, z);
    assert.ok(ticks.length > 3);
    assert.ok(ticks.some((t) => t.major && t.label), `major tick at ${z}`);
    const major = ticks.filter((t) => t.major);
    if (major.length > 1) assert.ok((major[1].pos - major[0].pos) * z >= 60, `major spacing at ${z}`);
  }
  assert.equal(rulerTicks(0, 100, 1)[0].pos, 0);
  assert.equal(rulerTicks(0, 100, 1)[0].label, '0');
});

test('grid, centre and margin guides', () => {
  const g = gridLines(deck, 100);
  assert.equal(g.x.length, 19);
  assert.equal(g.y.length, 10);
  assert.deepEqual(centreGuides(deck), { x: 960, y: 540 });
  assert.deepEqual(marginGuides(deck).x, [96, 1824]);
});

test('user guides are rounded, deduplicated, kept inside the slide, and dragged off to delete', () => {
  let g = addGuide([], 'v', 300.333, deck);
  assert.deepEqual(g, [{ orient: 'v', pos: 300.33 }]);
  assert.equal(addGuide(g, 'v', 300.5, deck), g, 'duplicate');
  assert.equal(addGuide(g, 'h', 0, deck), g);
  assert.equal(addGuide(g, 'h', 2000, deck), g);
  g = addGuide(g, 'h', 400, deck);
  assert.equal(g.length, 2);
  g = moveGuide(g, 0, 500, deck);
  assert.equal(g[0].pos, 500);
  g = moveGuide(g, 0, -20, deck);
  assert.equal(g.length, 1, 'dragged out of the slide: removed');
  assert.deepEqual(removeGuide(g, 0), []);
});
