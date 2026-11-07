import test from 'node:test';
import assert from 'node:assert/strict';
import { FORMATS, defaultForm, rangeToIds, requestOptions, preflightOptions, printUrl, summaryLine, animatedCount, preflightView, describeError, expiryWord, elementName } from '../exportModel.js';
import { groupReport, importCounts, importHeadline } from '../importReport.js';

const slides = [{ id: '11' }, { id: '12' }, { id: '13' }, { id: '14' }];

test('five formats, in the order the panel lists them', () => {
    assert.deepEqual(FORMATS.map((f) => f.id), ['pdf', 'pptx', 'package', 'images', 'print']);
    assert.deepEqual(FORMATS.filter((f) => f.job).map((f) => f.id), ['pdf', 'pptx']);
});

test('rangeToIds clamps, swaps and returns null for the whole deck', () => {
    assert.deepEqual(rangeToIds(slides, 2, 3), ['12', '13']);
    assert.deepEqual(rangeToIds(slides, 3, 2), ['12', '13']);
    assert.equal(rangeToIds(slides, 0, 99), null);
    assert.equal(rangeToIds(slides, 1, 4), null);
    assert.deepEqual(rangeToIds(slides, 3, 99), ['13', '14']);
    assert.deepEqual(rangeToIds(slides, 'x', 2), ['11', '12']);
    assert.equal(rangeToIds([], 1, 2), null);
});

test('request options only carry what the server reads', () => {
    const form = { ...defaultForm(4), includeHidden: true, notes: true, range: 'range', from: 2, to: 3 };
    assert.deepEqual(requestOptions('pdf', form, slides), { include_hidden: true, notes: true, slides: ['12', '13'] });
    assert.deepEqual(requestOptions('pdf', { ...form, range: 'all' }, slides), { include_hidden: true, notes: true });
    assert.deepEqual(requestOptions('pdf', { ...form, from: 1, to: 4 }, slides), { include_hidden: true, notes: true });
    assert.deepEqual(requestOptions('pptx', form, slides), {});
    assert.deepEqual(preflightOptions('pdf', form), { include_hidden: true });
    assert.deepEqual(preflightOptions('pptx', form), {});
});

test('print url', () => {
    assert.equal(printUrl(5), '/decks/5/print');
    assert.equal(printUrl(5, { hidden: true }), '/decks/5/print?hidden=1');
    assert.equal(printUrl(5, { hidden: true, notes: true }), '/decks/5/print?hidden=1&notes=1');
});

test('summary line leaves out empty levels except native', () => {
    assert.equal(summaryLine({ native: 142, approximated: 6, flattened: 0, dropped: 2 }), '142 native, 6 approximated, 2 dropped');
    assert.equal(summaryLine({ native: 10, approximated: 0, flattened: 0, dropped: 0 }), '10 native');
    assert.equal(summaryLine({}), '0 native');
    assert.equal(summaryLine({ native: 1, flattened: 3 }), '1 native, 3 flattened');
});

const PPTX = {
    format: 'pptx',
    slides: [
        { index: 1, id: '11', title: 'Claim one', hidden: false, skipped: false, notes: ['Animations on 2 elements are not exported.'], elements: [
            { id: 'a', type: 'text', level: 'native', reason: null },
            { id: 'b', type: 'image', level: 'flattened', reason: 'Saturation, blur, sepia, hue and partial greyscale are baked into the picture pixels.' },
        ] },
        { index: 2, id: '12', title: 'Two', hidden: true, skipped: false, notes: ['Zoom transition is exported as a fade.'], elements: [
            { id: 'c', type: 'chart', level: 'dropped', reason: 'This chart type has no PowerPoint equivalent.' },
            { id: 'd', type: 'video', level: 'approximated', reason: 'PowerPoint cannot take this video from a link.' },
        ] },
        { index: 3, id: '13', title: 'Three', hidden: false, skipped: false, notes: [], elements: [{ id: 'e', type: 'shape', level: 'native', reason: null }] },
    ],
    summary: {
        native: 3, approximated: 1, flattened: 1, dropped: 1, total: 6, slides: 3, skipped_slides: 0, level: 'major',
        warnings: [{ level: 'flattened', text: 'Saturation...', count: 1 }, { level: 'dropped', text: 'This chart type has no PowerPoint equivalent.', count: 1 }],
        general: ['Element animations (2 in this deck) are not exported; slide transitions (fade, push, wipe, reveal) are.', 'Fonts are not embedded in the file. Machines without Public Sans show a substitute font.'],
        has_video: true,
    },
};

test('animated element count is read from the slide notes', () => {
    assert.equal(animatedCount(PPTX), 2);
    assert.equal(animatedCount({ slides: [], summary: { general: ['Element animations (5 in this deck) are not exported; x'] } }), 5);
    assert.equal(animatedCount({ slides: [], summary: { general: ['Element animations are not exported; x'] } }), 0);
});

test('preflight view for PowerPoint: honest top line, counts, fonts note, per slide rows', () => {
    const v = preflightView(PPTX, { format: 'pptx', lookup: (s, e) => (e === 'a' ? { text: 'Hello' } : null) });
    assert.match(v.topNote, /^PowerPoint will not play these animations: 2 elements animate/);
    assert.equal(v.line, '2 native, 1 approximated, 1 flattened, 1 dropped');
    assert.match(v.fontsNote, /Fonts are not embedded/);
    assert.equal(v.level, 'dropped');
    assert.equal(v.clean, false);
    assert.equal(v.changed, 2);
    assert.equal(v.slides.length, 3);
    assert.equal(v.slides[0].worst, 'flattened');
    assert.equal(v.slides[1].worst, 'dropped');
    assert.equal(v.slides[2].worst, 'native');
    assert.equal(v.slides[2].changed, false);
    assert.equal(v.slides[0].rows[0].name, 'Hello');
    assert.equal(v.slides[0].rows[1].kind, 'Picture');
    assert.equal(v.slides[0].rows[1].word, 'flattened');
    assert.equal(v.slides[1].hidden, true);
    assert.deepEqual(v.slides[1].notes, ['Zoom transition is exported as a fade.']);
    assert.equal(v.warnings.length, 2);
    assert.deepEqual(v.notes, []);
});

test('with no animations the PowerPoint note does not pretend there are some', () => {
    const none = { ...PPTX, slides: PPTX.slides.map((s) => ({ ...s, notes: [] })), summary: { ...PPTX.summary, general: ['Element animations are not exported; slide transitions (fade, push, wipe, reveal) are.', 'Fonts are not embedded in the file. Machines without Public Sans show a substitute font.'] } };
    const v = preflightView(none, { format: 'pptx' });
    assert.doesNotMatch(v.topNote, /will not play these animations/);
    assert.match(v.topNote, /has none/);
});

const PDF = {
    format: 'pdf',
    slides: [
        { index: 1, id: '11', title: 'A', hidden: false, skipped: false, notes: ['A PDF has no slide transitions.'], elements: [{ id: 'a', type: 'text', level: 'native', reason: null }, { id: 'b', type: 'shape', level: 'flattened', reason: 'The gradient fill is drawn as a picture in the PDF.' }] },
        { index: 2, id: '12', title: 'B', hidden: true, skipped: true, notes: [], elements: [] },
        { index: 3, id: '13', title: 'C', hidden: false, skipped: false, notes: [], elements: [{ id: 'c', type: 'text', level: 'approximated', reason: 'Shadows in the PDF have a stepped blur.' }] },
    ],
    summary: { native: 1, approximated: 1, flattened: 1, dropped: 0, total: 3, slides: 2, skipped_slides: 1, level: 'minor', warnings: [{ level: 'flattened', text: 'g', count: 1 }], general: ['A PDF is a still document: animations, transitions, video and audio do not play.', '1 hidden slide is left out of the PDF. Turn on "Include hidden slides" to add them.'], has_video: false },
};

test('preflight view for PDF, whole deck', () => {
    const v = preflightView(PDF, { format: 'pdf' });
    assert.match(v.topNote, /still document/);
    assert.equal(v.skipped, 1);
    assert.equal(v.slideCount, 2);
    assert.equal(v.slides[1].skipped, true);
    assert.equal(v.fontsNote, null);
    assert.deepEqual(v.notes, ['1 hidden slide is left out of the PDF. Turn on "Include hidden slides" to add them.']);
    assert.equal(v.line, '1 native, 1 approximated, 1 flattened');
});

test('preflight view narrows to a range and recounts', () => {
    const v = preflightView(PDF, { format: 'pdf', range: ['13'] });
    assert.equal(v.slides.length, 1);
    assert.equal(v.line, '0 native, 1 approximated');
    assert.deepEqual(v.warnings, []);
});

test('an unknown level from the server is shown as approximated, not hidden', () => {
    const v = preflightView({ slides: [{ id: '1', index: 1, elements: [{ id: 'x', type: 'text', level: 'strange' }] }], summary: {} }, { format: 'pdf' });
    assert.equal(v.counts.approximated, 1);
});

test('errors are said in plain language', () => {
    assert.match(describeError({ network: true }), /network is not reachable/);
    assert.match(describeError({ status: 403 }, 'export this deck'), /permission to export this deck/);
    assert.match(describeError({ status: 429, retryAfter: 20 }), /20 seconds/);
    assert.match(describeError({ status: 429 }), /Wait a moment/);
    assert.match(describeError({ status: 500 }, 'build the file'), /server had a problem while trying to build the file/);
    assert.equal(describeError({ status: 503, message: 'The offline player is not built.' }), 'The offline player is not built.');
    assert.match(describeError({ status: 422, message: 'Choose a PowerPoint file ending in .pptx.' }), /ending in \.pptx/);
    assert.match(describeError({ status: 410 }), /expired/);
    assert.match(describeError(null, 'x'), /Could not x/);
});

test('expiry words', () => {
    const now = Date.parse('2026-11-07T12:00:00Z');
    assert.equal(expiryWord('2026-11-07T11:00:00Z', now), 'Expired');
    assert.match(expiryWord('2026-11-08T12:00:00Z', now), /^Kept until /);
    assert.equal(expiryWord(null), '');
    assert.equal(expiryWord('garbage'), '');
});

test('element names prefer the name, then a text snippet', () => {
    assert.deepEqual(elementName({ name: ' Logo ' }), { name: 'Logo' });
    assert.deepEqual(elementName({}, () => 'Quarterly   revenue'), { text: 'Quarterly revenue' });
    assert.equal(elementName({}, () => 'x'.repeat(60)).text.length, 36);
    assert.equal(elementName({}, () => ''), null);
    assert.equal(elementName(null), null);
});

test('import report groups by slide, whole file first, repeats merged', () => {
    const report = [
        { slide: 4, element: null, kind: 'approximated', message: 'The SmartArt graphic "Process" was imported as shapes.' },
        { slide: null, element: null, kind: 'unsupported', message: 'Pictures were not imported because asset storage is not available.' },
        { slide: 2, element: null, kind: 'unsupported', message: 'Animations were not imported.' },
        { slide: 4, element: null, kind: 'unsupported', message: 'Animations were not imported.' },
        { slide: 4, element: null, kind: 'approximated', message: 'The SmartArt graphic "Process" was imported as shapes.' },
        { slide: 4, kind: 'approximated', message: '  ' },
    ];
    const g = groupReport(report);
    assert.deepEqual(g.map((x) => x.label), ['Whole file', 'Slide 2', 'Slide 4']);
    assert.equal(g[2].items.length, 2);
    assert.equal(g[2].items[0].count, 2);
    assert.equal(g[2].items[0].word, 'approximated');
    assert.equal(g[2].items[1].word, 'left out');
    assert.deepEqual(importCounts(report), { unsupported: 3, approximated: 3 });
    assert.deepEqual(groupReport(null), []);
    assert.equal(importHeadline({ deck: { slides: 5, title: 'Board pack' } }), 'Imported 5 slides as "Board pack".');
    assert.equal(importHeadline({ deck: { slides: 1, title: 'X' } }), 'Imported 1 slide as "X".');
});
