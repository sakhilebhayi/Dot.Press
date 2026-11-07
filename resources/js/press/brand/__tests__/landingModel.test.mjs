import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { SAMPLE_DECK, SAMPLE_PREFLIGHT, SAMPLE_REVIEW } from '../sampleDeck.js';
import { COMPAT, LEVEL_WORD, SECTIONS, applyTitleSize, currentSection, progress, railFill, sampleBeads, sectionState, titleSize } from '../landingModel.js';

const here = dirname(fileURLToPath(import.meta.url));

test('the sample beads come from the real evidence function: a mix of hollow and solid', () => {
    const beads = sampleBeads(SAMPLE_DECK);
    assert.equal(beads.length, 6);
    const states = beads.map((b) => b.state);
    assert.deepEqual(states, ['hollow', 'hollow', 'solid', 'solid', 'solid', 'hollow']);
    for (const b of beads) assert.ok(b.title.length > 3);
    const revenue = beads.find((b) => /Revenue passed target/.test(b.title));
    assert.equal(revenue.state, 'solid');
    assert.equal(revenue.summary, 'Claim with evidence');
    const topic = beads.find((b) => /four numbers/.test(b.title));
    assert.equal(topic.state, 'hollow');
    assert.ok(topic.reasons.some((r) => /topic/.test(r)));
});

test('the rail: earlier beads solid, the current one amber, later ones hollow', () => {
    assert.deepEqual(SECTIONS.map((_, i) => sectionState(i, 2)), ['solid', 'solid', 'current', 'hollow', 'hollow', 'hollow', 'hollow']);
});

test('the current section is the last one above the reading line', () => {
    const tops = [0, 900, 1900, 3000];
    assert.equal(currentSection(tops, 0, 0.4, 800), 0);
    assert.equal(currentSection(tops, 700, 0.4, 800), 1);
    assert.equal(currentSection(tops, 1600, 0.4, 800), 2);
    assert.equal(currentSection(tops, 9000, 0.4, 800), 3);
});

test('the rail fill reaches a bead exactly when its section reaches the reading line', () => {
    const tops = [0, 1000, 2000, 3000];
    assert.equal(railFill(tops, -500, 0.4, 800), 0);
    assert.equal(railFill(tops, 680, 0.4, 800), 1 / 3);
    assert.ok(Math.abs(railFill(tops, 180, 0.4, 800) - (0.5 / 3)) < 1e-9);
    assert.equal(railFill(tops, 9999, 0.4, 800), 1);
    assert.equal(railFill([0], 100), 0);
});

test('progress is clamped and safe on a page that does not scroll', () => {
    assert.equal(progress(0, 0), 0);
    assert.equal(progress(-10, 1000), 0);
    assert.equal(progress(500, 1000), 0.5);
    assert.equal(progress(5000, 1000), 1);
});

test('the review proposal sets the title size and nothing else', () => {
    const slide = SAMPLE_DECK.slides[0];
    assert.equal(titleSize(slide), 112);
    const after = applyTitleSize(slide, 60);
    assert.equal(titleSize(after), 60);
    assert.equal(titleSize(slide), 112, 'the original is untouched');
    assert.equal(after.els.length, slide.els.length);
    assert.ok(SAMPLE_REVIEW.some((r) => r.kind === 'role-size' && /60/.test(r.fix)));
});

test('every compatibility cell uses a known level and carries its word', () => {
    for (const row of COMPAT) {
        for (const col of [row.pptx, row.pdf]) {
            assert.ok(col.level in LEVEL_WORD, `${row.what}: ${col.level}`);
        }
    }
});

test('the preflight counts in the sample add up', () => {
    for (const f of ['pptx', 'pdf']) {
        const p = SAMPLE_PREFLIGHT[f];
        assert.equal(p.native + p.approximated + p.flattened + p.dropped, p.total);
    }
});

test('visible copy has no em or en dashes', () => {
    const files = ['../landingModel.js', '../sampleDeck.js', '../../../Pages/Welcome.vue'];
    for (const f of files) {
        const text = readFileSync(join(here, f), 'utf8');
        const lines = text.split('\n').filter((l) => /[–—]/.test(l));
        assert.deepEqual(lines, [], `${f} has a dash`);
    }
});
