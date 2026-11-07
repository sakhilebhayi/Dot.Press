// The report of POST /api/imports/pptx, grouped for reading. Each entry is {slide: ?int, element: ?string, kind:
// 'unsupported' | 'approximated', message}. Entries about the whole file have slide null.
//
//   groupReport(report)   [{key, slide, label, items: [{kind, word, message, count}]}]  whole-file group first, then by slide
//   importHeadline(result)   "Imported 5 slides as Board pack."
//   importCounts(report)  {unsupported, approximated}

const WORD = { unsupported: 'left out', approximated: 'approximated' };

export function importCounts(report) {
    const c = { unsupported: 0, approximated: 0 };
    for (const r of report || []) if (r.kind in c) c[r.kind]++;
    return c;
}

export function groupReport(report) {
    const groups = new Map();
    for (const r of Array.isArray(report) ? report : []) {
        const slide = Number.isInteger(r.slide) && r.slide > 0 ? r.slide : null;
        if (!groups.has(slide)) groups.set(slide, { key: slide === null ? 'file' : `s${slide}`, slide, label: slide === null ? 'Whole file' : `Slide ${slide}`, items: [] });
        const g = groups.get(slide);
        const kind = r.kind === 'unsupported' ? 'unsupported' : 'approximated';
        const message = String(r.message || '').trim();
        if (!message) continue;
        const same = g.items.find((i) => i.kind === kind && i.message === message);
        if (same) same.count++;
        else g.items.push({ kind, word: WORD[kind], message, count: 1 });
    }
    return [...groups.values()].filter((g) => g.items.length).sort((a, b) => (a.slide ?? -1) - (b.slide ?? -1));
}

export function importHeadline(result) {
    const n = Number(result?.deck?.slides) || 0;
    const title = String(result?.deck?.title || 'your file');
    return `Imported ${n} ${n === 1 ? 'slide' : 'slides'} as "${title}".`;
}
