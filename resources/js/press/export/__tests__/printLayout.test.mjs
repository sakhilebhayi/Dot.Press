import test from 'node:test';
import assert from 'node:assert/strict';
import { pageRule, plan, hasNotes, notesLayout, fitScale, printHint } from '../printLayout.js';

test('the page rule is exactly the slide size with no margin', () => {
    assert.equal(pageRule(1920, 1080), '@page { size: 1920px 1080px; margin: 0 }');
    assert.equal(pageRule(1280.4, 720.2), '@page { size: 1280px 720px; margin: 0 }');
    assert.equal(pageRule(undefined, null), '@page { size: 1920px 1080px; margin: 0 }');
});

test('plan: one page per slide, notes pages only where there are notes', () => {
    const slides = [{ id: 1, notes: 'a' }, { id: 2, notes: '  ' }, { id: 3, notes: '' }, { id: 4 }, { id: 5, notes: 'b' }];
    assert.deepEqual(plan(slides).map((p) => `${p.kind}${p.number}`), ['slide1', 'slide2', 'slide3', 'slide4', 'slide5']);
    assert.deepEqual(plan(slides, { notes: true }).map((p) => `${p.kind}${p.number}`), ['slide1', 'notes1', 'slide2', 'slide3', 'slide4', 'slide5', 'notes5']);
    assert.deepEqual(plan(null), []);
    assert.equal(hasNotes({ notes: ' x ' }), true);
    assert.equal(hasNotes({}), false);
});

test('notes get the biggest type that fits and shrink as the text grows', () => {
    const short = notesLayout('Say hello.', 1920, 1080);
    const medium = notesLayout('word '.repeat(300), 1920, 1080);
    const long = notesLayout('word '.repeat(900), 1920, 1080);
    assert.equal(short.size, 44);
    assert.ok(medium.size < short.size && medium.size >= 16);
    assert.ok(long.size <= medium.size);
    assert.equal(short.truncated, false);
});

test('notes that cannot fit at the smallest size are cut and flagged', () => {
    const huge = notesLayout('lorem ipsum '.repeat(5000), 1920, 1080);
    assert.equal(huge.truncated, true);
    assert.equal(huge.size, 16);
    assert.ok(huge.text.length < 60000);
});

test('a small page also works', () => {
    const r = notesLayout('A note', 1280, 720);
    assert.ok(r.size >= 16 && r.size <= 44);
});

test('screen scale never enlarges a page', () => {
    assert.equal(fitScale(960, 1920), 0.5);
    assert.equal(fitScale(4000, 1920), 1);
    assert.equal(fitScale(0, 1920), 1);
});

test('the hint tells people what to choose', () => {
    assert.match(printHint(), /Save as PDF/);
    assert.match(printHint({ hidden: true, notes: true }), /Hidden slides are included\. Speaker notes follow/);
});
