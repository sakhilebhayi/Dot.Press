import test from 'node:test';
import assert from 'node:assert/strict';
import {
  changeTransition, describeTransition, isDirectional, previewCycle, previewPair, readChoice, sameTransition, targetSlideIds, transitionOps, HOLD_AFTER, HOLD_BEFORE,
} from '../transitionModel.js';

const doc = {
  deck: { sections: [{ id: 's1', title: 'Intro' }, { id: 's2', title: 'Body' }] },
  slides: [
    { id: '1', section_id: 's1', transition: null },
    { id: '2', section_id: 's1', transition: { type: 'fade', dir: 'r', dur: 600, ease: 'inout' } },
    { id: '3', section_id: 's2', hidden: true },
    { id: '4', section_id: 's2' },
    { id: '5', section_id: null },
  ],
};

test('a slide without a transition reads as none with sensible defaults', () => {
  assert.deepEqual(readChoice(doc.slides[0]), { type: 'none', dir: 'r', dur: 600, ease: 'inout' });
  assert.equal(readChoice(doc.slides[1]).type, 'fade');
});

test('choosing a type from none creates a full transition; none removes it', () => {
  const t = changeTransition(doc.slides[0], { type: 'push' });
  assert.deepEqual(t, { type: 'push', dir: 'r', dur: 600, ease: 'inout' });
  assert.equal(changeTransition(doc.slides[1], { type: 'none' }), null);
});

test('changing one control keeps the others and clamps duration', () => {
  const s = { transition: { type: 'wipe', dir: 'u', dur: 900, ease: 'spring' } };
  assert.deepEqual(changeTransition(s, { dir: 'd' }), { type: 'wipe', dir: 'd', dur: 900, ease: 'spring' });
  assert.equal(changeTransition(s, { dur: 99999 }).dur, 4000);
  assert.equal(changeTransition(s, { dur: 5 }).dur, 100);
  assert.equal(changeTransition(s, { dir: 'x' }).dir, 'r');
});

test('direction applies to push, wipe and reveal only', () => {
  assert.deepEqual(['fade', 'push', 'wipe', 'reveal', 'zoom', 'morph'].filter(isDirectional), ['push', 'wipe', 'reveal']);
});

test('sameTransition compares normalised values', () => {
  assert.equal(sameTransition(null, null), true);
  assert.equal(sameTransition({ type: 'fade' }, { type: 'fade', dir: 'r', dur: 600, ease: 'inout' }), true);
  assert.equal(sameTransition({ type: 'fade' }, null), false);
});

test('scopes: all, section (including the unsectioned run) and this', () => {
  assert.deepEqual(targetSlideIds(doc, doc.slides[0], 'all'), ['1', '2', '3', '4', '5']);
  assert.deepEqual(targetSlideIds(doc, doc.slides[0], 'section'), ['1', '2']);
  assert.deepEqual(targetSlideIds(doc, doc.slides[4], 'section'), ['5']);
  assert.deepEqual(targetSlideIds(doc, doc.slides[3], 'this'), ['4']);
});

test('ops skip slides that already have the transition and null clears', () => {
  const t = { type: 'fade', dir: 'r', dur: 600, ease: 'inout' };
  const ops = transitionOps(doc, ['1', '2', '4'], t);
  assert.deepEqual(ops.map((o) => o.id), ['1', '4']);
  assert.deepEqual(ops[0], { op: 'slide.set', id: '1', transition: t });
  assert.deepEqual(transitionOps(doc, ['2', '1'], null), [{ op: 'slide.set', id: '2', transition: null }]);
});

test('the preview pair is previous to this, skipping hidden slides; the first slide falls back to this to next', () => {
  assert.deepEqual([previewPair(doc, '4').from.id, previewPair(doc, '4').to.id, previewPair(doc, '4').fallback], ['2', '4', false]);
  const first = previewPair(doc, '1');
  assert.deepEqual([first.from.id, first.to.id, first.fallback, first.arriving.id], ['1', '2', true, '2']);
  assert.equal(previewPair({ slides: [{ id: 'a' }] }, 'a'), null);
  assert.equal(previewPair(doc, 'zzz'), null);
});

test('preview timing: hold, run for the transition duration, hold, loop', () => {
  const c = previewCycle({ type: 'push', dir: 'l', dur: 800, ease: 'out' });
  assert.deepEqual(c, { instant: false, holdA: HOLD_BEFORE, run: 800, holdB: HOLD_AFTER, total: HOLD_BEFORE + 800 + HOLD_AFTER });
});

test('reduced motion previews an instant change with no run time', () => {
  const c = previewCycle({ type: 'push', dir: 'l', dur: 800, ease: 'out' }, { reduced: true });
  assert.equal(c.instant, true);
  assert.equal(c.run, 0);
  assert.equal(c.total, HOLD_BEFORE + HOLD_AFTER);
  assert.equal(previewCycle(null).run, 0);
});

test('describeTransition says what will happen', () => {
  assert.equal(describeTransition(null), 'No transition. Slides cut.');
  assert.equal(describeTransition({ type: 'push', dir: 'l', dur: 600, ease: 'out' }), 'Push from the left, 0.6 s.');
  assert.equal(describeTransition({ type: 'fade', dir: 'r', dur: 450, ease: 'out' }), 'Fade, 0.45 s.');
});
