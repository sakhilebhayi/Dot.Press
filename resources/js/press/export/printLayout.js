// Pure helpers for the print view (Pages/Press/Print.vue): page rules, how many pages there will be, and the type size
// of a notes page. No DOM.
//
//   pageRule(w, h)                 the @page rule: every page is exactly the slide size, no margin
//   plan(slides, {notes})          [{kind: 'slide' | 'notes', slide, number}] in print order
//   notesLayout(text, w, h)        {size, lines, truncated, text}: the biggest type size (px, 16..44) at which the notes fit the page
//   fitScale(available, w)         the on-screen scale of a page so it fits the available width (never above 1)
//   printHint(options)             the short instruction under the print button

export function pageRule(w, h) {
    const W = Math.max(1, Math.round(+w || 1920));
    const H = Math.max(1, Math.round(+h || 1080));
    return `@page { size: ${W}px ${H}px; margin: 0 }`;
}

export function hasNotes(slide) {
    return typeof slide?.notes === 'string' && slide.notes.trim() !== '';
}

export function plan(slides, { notes = false } = {}) {
    const out = [];
    (Array.isArray(slides) ? slides : []).forEach((slide, i) => {
        out.push({ kind: 'slide', slide, number: i + 1 });
        if (notes && hasNotes(slide)) out.push({ kind: 'notes', slide, number: i + 1 });
    });
    return out;
}

const MIN = 16;
const MAX = 44;

export function notesLayout(text, w, h) {
    const W = Math.max(200, +w || 1920);
    const H = Math.max(200, +h || 1080);
    const clean = String(text || '').replace(/\r\n?/g, '\n').trim();
    const padX = W * 0.07;
    const padTop = H * 0.2;
    const padBottom = H * 0.07;
    const usableW = W - padX * 2;
    const usableH = H - padTop - padBottom;
    const paragraphs = clean.split('\n');
    const lh = 1.45;
    const countLines = (size) => {
        const perLine = Math.max(8, Math.floor(usableW / (size * 0.52)));
        let n = 0;
        for (const p of paragraphs) n += Math.max(1, Math.ceil(p.length / perLine));
        return n;
    };
    for (let size = MAX; size >= MIN; size -= 2) {
        const lines = countLines(size);
        if (lines * size * lh <= usableH) return { size, lines, truncated: false, text: clean };
    }
    // Even the smallest size does not fit: cut at what does and say so.
    const perLine = Math.max(8, Math.floor(usableW / (MIN * 0.52)));
    const maxLines = Math.max(1, Math.floor(usableH / (MIN * lh)) - 1);
    const keep = Math.max(0, maxLines * perLine - 40);
    return { size: MIN, lines: maxLines, truncated: true, text: `${clean.slice(0, keep).trimEnd()}` };
}

export function fitScale(available, w) {
    const a = Number(available);
    const W = Math.max(1, Number(w) || 1);
    if (!Number.isFinite(a) || a <= 0) return 1;
    return Math.min(1, a / W);
}

export function printHint({ hidden = false, notes = false } = {}) {
    const bits = ['In the print dialog choose "Save as PDF". Text stays selectable and the fonts are exact.'];
    bits.push('For the cleanest result set Margins to None and turn Headers and footers off.');
    if (hidden) bits.push('Hidden slides are included.');
    if (notes) bits.push('Speaker notes follow each slide that has them.');
    return bits.join(' ');
}
