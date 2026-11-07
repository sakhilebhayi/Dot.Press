// The model behind the public page. Pure functions, no DOM: node tests cover them.
//
//   SECTIONS                the beads of the page's own spine, in order
//   sampleBeads(deck)       the sample deck's slides with the real evidence verdict (editor/ui/evidence.js) for each
//   sectionState(i, cur)    'solid' | 'current' | 'hollow' for a bead of the page rail
//   currentSection(tops, y) which section the reader is in, from the section tops and a reading line
//   railFill(tops, y)       how far the rail's fill reaches, 0..1
//   progress(y, max)        0..1 scroll progress, clamped
//   applyTitleSize(slide, size)  the slide with its title element at that size (the review proposal, applied)
//   COMPAT                  the export compatibility table, read from app/Services/Press/Export/ExportPreflight.php
//   splitCompat(rows)       the four most telling rows (where a format does not simply keep the thing) and the rest
//   withTextScale(deck, k)  the deck with its theme text scale at k, so a sample drawn small stays legible (hero sheet)
import { slideEvidence, slideTitle, evidenceSummary } from '../editor/ui/evidence.js';

export const SECTIONS = [
    { id: 'start', label: 'Start' },
    { id: 'spine', label: 'The Spine' },
    { id: 'exports', label: 'Exports' },
    { id: 'review', label: 'Review' },
    { id: 'proposals', label: 'Proposals' },
    { id: 'present', label: 'Presenting' },
    { id: 'signin', label: 'Sign in' },
];

/** Each slide of a document with the verdict of the real evidence function. */
export function sampleBeads(doc) {
    return (doc?.slides || []).map((slide, i) => {
        const ev = slideEvidence(slide);
        return {
            id: slide.id,
            index: i,
            title: slideTitle(slide) || 'Untitled',
            state: ev.state,
            evidence: ev.evidence,
            claim: ev.claim,
            summary: evidenceSummary(ev),
            reasons: ev.reasons,
        };
    });
}

export function sectionState(index, current) {
    if (index === current) return 'current';
    return index < current ? 'solid' : 'hollow';
}

/** tops: the offsetTop of each section in order. The reader is in the last section whose top is above the line. */
export function currentSection(tops, y, line = 0.4, viewport = 800) {
    const at = y + viewport * line;
    let cur = 0;
    for (let i = 0; i < tops.length; i++) if (tops[i] <= at) cur = i;
    return cur;
}

/**
 * How far the rail's fill reaches, 0..1. The fill meets bead i exactly when section i reaches the reading line, and runs
 * between two beads as the reader moves through the section between them.
 */
export function railFill(tops, y, line = 0.4, viewport = 800) {
    const n = tops.length;
    if (n < 2) return 0;
    const at = y + viewport * line;
    const cur = currentSection(tops, y, line, viewport);
    if (cur >= n - 1) return 1;
    if (at <= tops[0]) return 0;
    const span = tops[cur + 1] - tops[cur];
    const seg = span > 0 ? (at - tops[cur]) / span : 0;
    return Math.min(1, Math.max(0, (cur + seg) / (n - 1)));
}

export function progress(y, max) {
    if (!(max > 0)) return 0;
    return Math.min(1, Math.max(0, y / max));
}

/** The slide with the title element set to `size` (what the review's "Set this title to 60" proposal does). */
export function applyTitleSize(slide, size) {
    return { ...slide, els: slide.els.map((el) => (el.role === 'title' ? { ...el, size } : el)) };
}

/** The size of a slide's title element, or null. */
export function titleSize(slide) {
    return slide.els.find((el) => el.role === 'title')?.size ?? null;
}

// level: native | approximated | flattened | dropped | none (not applicable)
// Every line is a case in ExportPreflight::classify() or its general notes; nothing here is a promise beyond them.
export const COMPAT = [
    {
        what: 'Text',
        pptx: { level: 'native', note: 'Runs, lists, links and alignment stay editable.' },
        pdf: { level: 'native', note: 'Outlined text is drawn plain. Shrink to fit is estimated from font metrics.' },
    },
    {
        what: 'Shapes and icons',
        pptx: { level: 'native', note: 'Icons outside the exporter’s icon set become a placeholder square.' },
        pdf: { level: 'native', note: 'A gradient fill becomes a picture.' },
    },
    {
        what: 'Pictures',
        pptx: { level: 'native', note: 'Saturation, blur, sepia and hue are baked into the pixels. SVG pictures need PowerPoint 2016 or later.' },
        pdf: { level: 'native', note: 'Masks and adjustments are baked into the pixels.' },
    },
    {
        what: 'Charts',
        pptx: { level: 'native', note: 'Supported types stay editable charts. Key figure tiles, waterfall and funnel are approximated. A type with no PowerPoint equivalent is not exported.' },
        pdf: { level: 'native', note: 'Drawn the way the editor draws them. A map becomes a table of region values.' },
    },
    {
        what: 'Tables',
        pptx: { level: 'native', note: 'A rotated table is exported upright.' },
        pdf: { level: 'native', note: '' },
    },
    {
        what: 'Video and audio',
        pptx: { level: 'approximated', note: 'The slide gets a poster picture, with a link when the video is online.' },
        pdf: { level: 'approximated', note: 'A poster frame or a labelled box. A PDF cannot play media.' },
    },
    {
        what: 'Animation',
        pptx: { level: 'dropped', note: 'Element animations are not exported. Slide transitions are; zoom and morph become a fade.' },
        pdf: { level: 'none', note: 'A PDF is a still document.' },
    },
    {
        what: 'Fonts',
        pptx: { level: 'approximated', note: 'Fonts are not embedded. A machine without them shows a substitute.' },
        pdf: { level: 'approximated', note: 'Only the bundled fonts print. Any other family prints in Public Sans.' },
    },
    {
        what: 'Hidden slides',
        pptx: { level: 'native', note: 'Included.' },
        pdf: { level: 'dropped', note: 'Left out unless you turn on Include hidden slides.' },
    },
];

export const LEVEL_WORD = { native: 'native', approximated: 'approximated', flattened: 'flattened', dropped: 'not exported', none: 'not applicable' };

// The rows a reader needs first: the ones where the two formats differ or one of them gives something up. The other rows
// (text, shapes, pictures, tables) are "kept" in both and sit behind a control.
export const TELLING_ROWS = ['Video and audio', 'Animation', 'Fonts', 'Hidden slides'];

/** { lead, rest }: the telling rows in their table order, then everything else in its table order. */
export function splitCompat(rows = COMPAT, telling = TELLING_ROWS) {
    const lead = rows.filter((r) => telling.includes(r.what));
    const rest = rows.filter((r) => !telling.includes(r.what));
    return { lead, rest };
}

/** The deck with the theme's text scale set to k (never below 1). Charts and tables multiply their type by it. */
export function withTextScale(deck, k) {
    const scale = Math.max(1, Number(k) || 1);
    const theme = deck?.theme || {};
    return { ...deck, theme: { ...theme, t: { ...(theme.t || {}), scale } } };
}
