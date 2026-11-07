import test from 'node:test';
import assert from 'node:assert/strict';
import { exportImages, pickSlides, abortError } from '../exportImages.js';

const png = () => new Uint8Array([0x89, 0x50, 0x4e, 0x47, 1, 2, 3, 4, 5]);
const slides = [
    { id: '1', title: 'Intro', hidden: false },
    { id: '2', title: 'Hidden one', hidden: true },
    { id: '3', title: 'Claim', hidden: false },
];

test('pickSlides honours range, hidden slides and keeps deck numbers', () => {
    assert.deepEqual(pickSlides(slides).map((p) => p.number), [1, 3]);
    assert.deepEqual(pickSlides(slides, { includeHidden: true }).map((p) => p.number), [1, 2, 3]);
    assert.deepEqual(pickSlides(slides, { range: ['3'] }).map((p) => p.number), [3]);
    assert.deepEqual(pickSlides(slides, { range: [3, 2], includeHidden: true }).map((p) => p.number), [2, 3]);
    assert.deepEqual(pickSlides(null), []);
});

test('exportImages renders in order, reports progress and returns zip parts', async () => {
    const picked = pickSlides(slides);
    const seen = [];
    const events = [];
    const out = await exportImages({
        slides: picked,
        scale: 2,
        renderSlide: async (s, o) => { seen.push([s.id, o.number, o.scale, o.total]); return { png: png(), warnings: s.id === '3' ? ['A picture could not be included.'] : [] }; },
        onProgress: (p) => events.push(`${p.phase}:${p.done}/${p.total}`),
    });
    assert.deepEqual(seen, [['1', 1, 2, 2], ['3', 3, 2, 2]]);
    assert.equal(out.count, 2);
    assert.deepEqual(out.files, ['slide-01-intro.png', 'slide-03-claim.png']);
    assert.deepEqual(out.warnings, [{ number: 3, text: 'A picture could not be included.' }]);
    assert.equal(out.bytes, out.parts.reduce((n, p) => n + p.length, 0));
    assert.equal(events[0], 'start:0/2');
    assert.equal(events.at(-1), 'done:2/2');
    assert.ok(events.includes('slide:1/2') && events.includes('slide:2/2'));
});

test('a slide that fails is skipped and listed, the rest still export', async () => {
    const out = await exportImages({
        slides: pickSlides(slides),
        renderSlide: async (s) => { if (s.id === '1') throw new Error('Fonts did not load.'); return { png: png() }; },
    });
    assert.equal(out.count, 1);
    assert.deepEqual(out.failed, [{ number: 1, reason: 'Fonts did not load.' }]);
});

test('an empty picture counts as a failure; all failing throws the first reason', async () => {
    await assert.rejects(exportImages({ slides: pickSlides(slides), renderSlide: async () => ({ png: new Uint8Array(0) }) }), /came out empty/);
    await assert.rejects(exportImages({ slides: pickSlides(slides), renderSlide: async () => { throw new Error('no canvas'); } }), /no canvas/);
});

test('cancelling stops before the next slide and throws AbortError', async () => {
    const ctl = new AbortController();
    let calls = 0;
    await assert.rejects(
        exportImages({
            slides: pickSlides(slides),
            signal: ctl.signal,
            renderSlide: async () => { calls++; ctl.abort(); return { png: png() }; },
        }),
        (e) => e.name === 'AbortError',
    );
    assert.equal(calls, 1);
});

test('an abort raised inside the renderer is not turned into a skipped slide', async () => {
    await assert.rejects(exportImages({ slides: pickSlides(slides), renderSlide: async () => { throw abortError(); } }), (e) => e.name === 'AbortError');
});

test('no slides and no renderer are plain errors', async () => {
    await assert.rejects(exportImages({ slides: [], renderSlide: async () => ({}) }), /no slides/);
    await assert.rejects(exportImages({ slides: pickSlides(slides) }), /No renderer/);
});
