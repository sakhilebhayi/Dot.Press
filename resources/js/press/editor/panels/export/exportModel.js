// Pure model for the export panel: what the options mean, what the preflight answer looks like to a person, and how
// server and network errors read in plain language. No Vue and no DOM, tested under node:test.
//
//   FORMATS                       the five ways out, with the words the panel shows
//   defaultForm(slideCount)       the option state of the panel
//   rangeToIds(slides, from, to)  slide ids for a 1 based inclusive range, or null for the whole deck
//   requestOptions(format, form, slides)   the `options` body the server accepts for a format (and nothing it would ignore)
//   printUrl(deckId, {hidden, notes})
//   preflightView(response, {format, range, lookup})   the preflight answer as rows the panel can draw
//   summaryLine(counts)           "142 native, 6 approximated, 2 dropped"
//   describeError(err, context)   a plain sentence for an error from the api helper
//   expiryWord(iso, now)          "Kept until 8 Nov, 14:20" or "Expired"

export const FORMATS = [
    { id: 'pdf', label: 'PDF', blurb: 'A document that looks the same everywhere. Text stays selectable.', job: true },
    { id: 'pptx', label: 'PowerPoint', blurb: 'An editable .pptx. Check what changes first.', job: true },
    { id: 'package', label: 'Present package', blurb: 'One HTML file that plays offline, with fonts and media inside.' },
    { id: 'images', label: 'Images', blurb: 'One PNG per slide in a ZIP, made in this browser.' },
    { id: 'print', label: 'Print view', blurb: 'The browser prints it. Highest fidelity.' },
];

export const LEVELS = ['native', 'approximated', 'flattened', 'dropped'];
export const LEVEL_WORDS = {
    native: 'native',
    approximated: 'approximated',
    flattened: 'flattened',
    dropped: 'dropped',
};
export const LEVEL_HELP = {
    native: 'Carried over as it is, still editable where the format allows.',
    approximated: 'Carried over close to the original, not exactly.',
    flattened: 'Turned into a picture.',
    dropped: 'Not in the file.',
};

export function defaultForm(slideCount = 1) {
    return { includeHidden: false, notes: false, range: 'all', from: 1, to: Math.max(1, slideCount), scale: 2 };
}

const TYPE_WORDS = { text: 'Text', shape: 'Shape', image: 'Picture', video: 'Video', audio: 'Audio', icon: 'Icon', chart: 'Chart', table: 'Table', background: 'Background' };
export const typeWord = (t) => TYPE_WORDS[t] || (t ? String(t).charAt(0).toUpperCase() + String(t).slice(1) : 'Element');

/** Slide ids in an inclusive 1 based range, in deck order. null means the whole deck. */
export function rangeToIds(slides, from, to) {
    const list = Array.isArray(slides) ? slides : [];
    const n = list.length;
    if (!n) return null;
    let a = Math.round(Number(from));
    let b = Math.round(Number(to));
    if (!Number.isFinite(a)) a = 1;
    if (!Number.isFinite(b)) b = n;
    a = Math.min(n, Math.max(1, a));
    b = Math.min(n, Math.max(1, b));
    if (a > b) [a, b] = [b, a];
    if (a === 1 && b === n) return null;
    return list.slice(a - 1, b).map((s) => String(s.id));
}

/**
 * The options the server reads. PDF takes include_hidden, notes and a slide list. PowerPoint always carries hidden
 * slides (marked hidden) and has no range or notes option, so those are never sent.
 */
export function requestOptions(format, form, slides) {
    if (format === 'pptx') return {};
    const out = { include_hidden: !!form.includeHidden };
    if (format === 'pdf') {
        out.notes = !!form.notes;
        if (form.range === 'range') {
            const ids = rangeToIds(slides, form.from, form.to);
            if (ids) out.slides = ids;
        }
    }
    return out;
}

/** The preflight call takes only include_hidden. */
export function preflightOptions(format, form) {
    return format === 'pdf' ? { include_hidden: !!form.includeHidden } : {};
}

export function printUrl(deckId, { hidden = false, notes = false } = {}) {
    const q = [];
    if (hidden) q.push('hidden=1');
    if (notes) q.push('notes=1');
    return `/decks/${encodeURIComponent(deckId)}/print${q.length ? `?${q.join('&')}` : ''}`;
}

export function summaryLine(counts) {
    const c = counts || {};
    const parts = [`${c.native || 0} native`];
    for (const k of ['approximated', 'flattened', 'dropped']) if (c[k]) parts.push(`${c[k]} ${k}`);
    return parts.join(', ');
}

/** How many elements are animated, read from the preflight answer ("Animations on 3 elements are not exported."). */
export function animatedCount(response) {
    let n = 0;
    for (const s of response?.slides || []) {
        for (const note of s.notes || []) {
            const m = /Animations on (\d+) elements? (?:is|are) not exported/i.exec(note);
            if (m) n += Number(m[1]);
        }
    }
    if (n) return n;
    for (const g of response?.summary?.general || []) {
        const m = /\((\d+) in this deck\)/.exec(g);
        if (m) return Number(m[1]);
    }
    return 0;
}

const worst = (c) => (c.dropped ? 'dropped' : c.flattened ? 'flattened' : c.approximated ? 'approximated' : 'native');

/**
 * @param response  the answer of POST /api/decks/{deck}/exports/preflight
 * @param opts.format  'pdf' | 'pptx'
 * @param opts.range   slide ids to keep (a PDF range), or null for all. The server judges the whole deck; the view narrows it.
 * @param opts.lookup  (slideId, elementId) -> {name?, text?} to name elements the way the editor does
 */
export function preflightView(response, { format = response?.format, range = null, lookup = null } = {}) {
    const keep = range ? new Set(range.map(String)) : null;
    const counts = { native: 0, approximated: 0, flattened: 0, dropped: 0 };
    const slides = [];
    let skipped = 0;
    for (const s of response?.slides || []) {
        if (keep && !keep.has(String(s.id))) continue;
        const sc = { native: 0, approximated: 0, flattened: 0, dropped: 0 };
        const rows = (s.elements || []).map((e, i) => {
            const level = LEVELS.includes(e.level) ? e.level : 'approximated';
            sc[level]++;
            const named = lookup ? lookup(String(s.id), String(e.id)) : null;
            return {
                key: `${s.id}:${e.id || i}`,
                id: e.id,
                type: e.type,
                kind: typeWord(e.type),
                name: named?.name || named?.text || '',
                level,
                word: LEVEL_WORDS[level],
                reason: e.reason || null,
            };
        });
        if (s.skipped) skipped++;
        else for (const k of LEVELS) counts[k] += sc[k];
        slides.push({
            key: String(s.id),
            id: String(s.id),
            index: s.index,
            title: s.title || '',
            hidden: !!s.hidden,
            skipped: !!s.skipped,
            counts: sc,
            worst: s.skipped ? 'native' : worst(sc),
            changed: !s.skipped && (sc.approximated + sc.flattened + sc.dropped) > 0,
            rows,
            notes: s.notes || [],
        });
    }
    const total = counts.native + counts.approximated + counts.flattened + counts.dropped;
    const pptx = format === 'pptx';
    const animated = pptx ? animatedCount(response) : 0;
    const general = response?.summary?.general || [];
    const fontsNote = general.find((g) => /fonts are not embedded/i.test(g)) || null;
    // The PDF header lines are still true for a narrower range except the hidden slide line, which is about the whole deck.
    const rest = general.filter((g) => g !== fontsNote && !/^Element animations/.test(g));
    const top = pptx
        ? (animated > 0
            ? `PowerPoint will not play these animations: ${animated} ${animated === 1 ? 'element animates' : 'elements animate'} in Press and will be still in the file.`
            : 'Element animations are not exported. This deck has none, so nothing is lost there.')
        : 'A PDF is a still document: animations, transitions, video and audio do not play.';
    const warnings = (response?.summary?.warnings || []).map((w) => ({ level: w.level, text: w.text, count: w.count }));
    return {
        format,
        total,
        counts,
        line: summaryLine(counts),
        topNote: top,
        fontsNote,
        notes: rest.filter((g) => !/^A PDF is a still document/.test(g)),
        warnings: keep ? [] : warnings,
        slides,
        slideCount: slides.filter((s) => !s.skipped).length,
        skipped,
        changed: slides.filter((s) => s.changed).length,
        level: counts.dropped ? 'dropped' : counts.flattened ? 'flattened' : counts.approximated ? 'approximated' : 'native',
        clean: total > 0 && counts.approximated + counts.flattened + counts.dropped === 0,
        animated,
    };
}

/** A plain sentence for an error thrown by editor.api (it carries .status, .network, .retryAfter). */
export function describeError(err, context = 'do that') {
    if (!err) return `Could not ${context}.`;
    if (err.network) return 'The network is not reachable. Check your connection and try again.';
    switch (err.status) {
        case 401:
        case 419:
            return 'You were signed out. Reload the page and sign in again.';
        case 403:
            return `You do not have permission to ${context}.`;
        case 404:
            return 'That deck or file was not found. It may have been deleted.';
        case 410:
            return 'That file has expired. Make a new one.';
        case 409:
            return 'That file is not ready yet.';
        case 413:
            return 'That file is too large.';
        case 429:
            return err.retryAfter ? `That is too many requests for now. Try again in ${err.retryAfter} seconds.` : 'That is too many requests for now. Wait a moment and try again.';
        case 503:
            return err.message || 'This is not available on the server right now.';
        default:
            if (err.status >= 500) return `The server had a problem while trying to ${context}. Try again in a moment.`;
            return err.message || `Could not ${context}.`;
    }
}

export function expiryWord(iso, now = Date.now()) {
    if (!iso) return '';
    const t = Date.parse(iso);
    if (!Number.isFinite(t)) return '';
    if (t <= now) return 'Expired';
    const d = new Date(t);
    const day = d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
    const time = d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    return `Kept until ${day}, ${time}`;
}

/** A name for an element in the editor's own words: its name, else a snippet of its text, else null. */
export function elementName(el, textOf = null) {
    if (!el) return null;
    if (typeof el.name === 'string' && el.name.trim()) return { name: el.name.trim().slice(0, 40) };
    const text = textOf ? textOf(el) : '';
    if (text) {
        const t = text.replace(/\s+/g, ' ').trim();
        return { text: t.length > 36 ? `${t.slice(0, 35)}…` : t };
    }
    return null;
}
