// Runs the image export: one PNG per slide, bundled into a STORE-only ZIP. The drawing itself is injected
// (`renderSlide`), so this module has no DOM and its progress, cancellation and failure handling run under node:test.
//
//   pickSlides(slides, {range, includeHidden})   which slides, in order, as [{slide, number}] (number = place in the deck, 1 based)
//   exportImages({slides, scale, renderSlide, signal, onProgress, now})
//        -> {parts, bytes, count, files, failed, warnings}   parts: Uint8Array[] for new Blob(parts, {type: 'application/zip'})
//   abortError()   the error cancellation throws (name "AbortError")
//
// renderSlide(slide, {number, scale, total}) must resolve {png: Uint8Array, warnings?: string[], scale?: number}.
// A slide that fails is skipped and listed in `failed`; if every slide fails the export throws with the first reason.
import { slideFileName, uniqueNames, zipParts } from './zip.js';

export function abortError(message = 'Cancelled') {
    const e = new Error(message);
    e.name = 'AbortError';
    return e;
}

export function pickSlides(slides, { range = null, includeHidden = false } = {}) {
    const all = Array.isArray(slides) ? slides : [];
    const wanted = Array.isArray(range) && range.length ? new Set(range.map(String)) : null;
    const out = [];
    all.forEach((slide, i) => {
        if (!slide) return;
        if (wanted && !wanted.has(String(slide.id))) return;
        if (slide.hidden && !includeHidden) return;
        out.push({ slide, number: i + 1 });
    });
    return out;
}

export async function exportImages({ slides, scale = 1, renderSlide, signal = null, onProgress = () => {}, now = new Date() }) {
    const picked = Array.isArray(slides) ? slides : [];
    const total = picked.length;
    if (total === 0) throw new Error('There are no slides to export.');
    if (typeof renderSlide !== 'function') throw new Error('No renderer was given.');
    const files = [];
    const failed = [];
    const warnings = [];
    const stems = [];
    onProgress({ done: 0, total, number: null, phase: 'start' });
    for (let i = 0; i < total; i++) {
        if (signal?.aborted) throw abortError();
        const { slide, number } = picked[i];
        onProgress({ done: i, total, number, phase: 'slide' });
        try {
            const res = await renderSlide(slide, { number, scale, total });
            if (signal?.aborted) throw abortError();
            if (!(res?.png instanceof Uint8Array) || res.png.length < 8) throw new Error('The picture came out empty.');
            files.push({ name: slideFileName(number, Math.max(total, picked[total - 1].number), slide.title, 'png'), data: res.png });
            stems.push(number);
            for (const w of res.warnings || []) warnings.push({ number, text: w });
        } catch (e) {
            if (e?.name === 'AbortError' || signal?.aborted) throw abortError();
            failed.push({ number, reason: e?.message || 'The slide could not be drawn.' });
        }
        onProgress({ done: i + 1, total, number, phase: 'slide' });
    }
    if (files.length === 0) throw new Error(failed[0]?.reason || 'No slide could be drawn.');
    const names = uniqueNames(files.map((f) => f.name));
    files.forEach((f, i) => { f.name = names[i]; });
    const parts = zipParts(files, { now });
    const bytes = parts.reduce((n, p) => n + p.length, 0);
    onProgress({ done: total, total, number: null, phase: 'done' });
    return { parts, bytes, count: files.length, files: files.map((f) => f.name), failed, warnings };
}
