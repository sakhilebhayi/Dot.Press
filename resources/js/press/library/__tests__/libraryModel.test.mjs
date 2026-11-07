import test from 'node:test';
import assert from 'node:assert/strict';
import { FILTERS, SORTS, SIZES, relativeTime, sizeLabel, statusBead, emptyCopy, gridMove, previewDeck, previewSlide } from '../libraryModel.js';

const NOW = Date.parse('2026-11-07T12:00:00');

test('filters match the API filters and sorts match the API sorts', () => {
  assert.deepEqual(FILTERS.map((f) => f.id), [null, 'mine', 'team', 'shared', 'favourites', 'recent', 'archived']);
  assert.deepEqual(SORTS.map((s) => s.id), ['updated', 'title', 'created']);
  assert.deepEqual(SIZES.map((s) => s.id), ['16:9', '4:3', 'portrait', 'custom']);
});

test('relativeTime reads like a person', () => {
  const at = (s) => relativeTime(s, NOW);
  assert.equal(at('2026-11-07T11:59:40'), 'just now');
  assert.equal(at('2026-11-07T11:55:00'), '5 min ago');
  assert.equal(at('2026-11-07T11:00:00'), '1 hour ago');
  assert.equal(at('2026-11-07T08:00:00'), '4 hours ago');
  assert.equal(at('2026-11-06T09:00:00'), 'yesterday');
  assert.equal(at('2026-11-04T09:00:00'), '3 days ago');
  assert.equal(at('2026-10-20T09:00:00'), '20 Oct');
  assert.equal(at('2025-03-02T09:00:00'), '2 Mar 2025');
  assert.equal(at(null), '');
  assert.equal(at('not a date'), '');
});

test('sizeLabel names the common shapes', () => {
  assert.equal(sizeLabel(1920, 1080), '16:9');
  assert.equal(sizeLabel(1280, 720), '16:9');
  assert.equal(sizeLabel(1440, 1080), '4:3');
  assert.equal(sizeLabel(1080, 1920), 'Portrait');
  assert.equal(sizeLabel(1000, 1000), '1000 x 1000');
});

test('status beads follow the Spine language', () => {
  assert.deepEqual(statusBead('draft'), { state: 'hollow', word: 'draft' });
  assert.deepEqual(statusBead('in_review'), { state: 'issue', word: 'in review' });
  assert.deepEqual(statusBead('approved'), { state: 'solid', word: 'approved' });
  assert.deepEqual(statusBead(undefined), { state: 'hollow', word: 'draft' });
});

test('empty copy is specific to the filter and to a search', () => {
  assert.match(emptyCopy('archived'), /Archived/);
  assert.match(emptyCopy(null, 'budget'), /"budget"/);
  assert.notEqual(emptyCopy('mine'), emptyCopy('team'));
  assert.ok(emptyCopy('unknown').length > 0);
});

test('gridMove: arrows across and between lines of tiles', () => {
  const t = (c, r) => ({ left: c * 100, right: c * 100 + 90, top: r * 100, bottom: r * 100 + 80 });
  const rects = [t(0, 0), t(1, 0), t(2, 0), t(0, 1), t(1, 1), t(2, 1), t(0, 2)];
  assert.equal(gridMove(rects, 1, 'ArrowRight'), 2);
  assert.equal(gridMove(rects, 0, 'ArrowLeft'), 0);
  assert.equal(gridMove(rects, 6, 'ArrowRight'), 6);
  assert.equal(gridMove(rects, 1, 'ArrowDown'), 4);
  assert.equal(gridMove(rects, 4, 'ArrowUp'), 1);
  assert.equal(gridMove(rects, 5, 'ArrowDown'), 6); // nearest in the last, shorter line
  assert.equal(gridMove(rects, 0, 'ArrowUp'), 0);
  assert.equal(gridMove(rects, 6, 'ArrowDown'), 6);
  assert.equal(gridMove(rects, 3, 'Home'), 0);
  assert.equal(gridMove(rects, 3, 'End'), 6);
  assert.equal(gridMove(rects, -1, 'ArrowDown'), 0);
  assert.equal(gridMove([], 0, 'ArrowDown'), -1);
});

test('preview helpers fall back sensibly', () => {
  assert.deepEqual(previewDeck({ w: 1280, h: 720 }), { w: 1280, h: 720, theme: undefined });
  assert.equal(previewDeck({}).w, 1920);
  assert.deepEqual(previewSlide({ id: 4, first_slide: 9, preview: { bg: null, els: [{ id: 'a' }] } }), { id: '9', bg: null, els: [{ id: 'a' }] });
  assert.deepEqual(previewSlide({ id: 4 }), { id: '4', bg: null, els: [] });
});
