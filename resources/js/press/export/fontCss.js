// Pure helpers for putting the fonts a slide uses into an exported picture. The picture is an SVG that cannot load
// anything from the network, so every font file the slide needs travels inside it as a base64 data URI.
//
//   parseFontFaces(css, baseUrl)            [{family, style, weight, url, unicodeRange}] from public/fonts/fonts.css
//   familiesFromStacks(stacks)              family names named first in CSS font-family values
//   parseUnicodeRange(text)                 [[from, to], ...]
//   codepointsOf(text)                      Set of code points in a string
//   facesNeeded(faces, families, codepoints) the faces of those families whose unicode-range covers some text
//   fontFaceCss(face, dataUri)              one @font-face rule with the file inlined
//   bytesToBase64(bytes), toDataUri(mime, bytes)

export function parseUnicodeRange(text) {
    if (!text) return null;
    const out = [];
    for (const part of String(text).split(',')) {
        const m = /^\s*U\+([0-9A-Fa-f?]{1,6})(?:-([0-9A-Fa-f]{1,6}))?\s*$/.exec(part);
        if (!m) continue;
        if (m[1].includes('?')) {
            out.push([parseInt(m[1].replace(/\?/g, '0'), 16), parseInt(m[1].replace(/\?/g, 'F'), 16)]);
        } else {
            const from = parseInt(m[1], 16);
            out.push([from, m[2] ? parseInt(m[2], 16) : from]);
        }
    }
    return out.length ? out : null;
}

function resolveUrl(raw, baseUrl) {
    const u = String(raw).trim().replace(/^['"]|['"]$/g, '');
    if (/^(data:|https?:\/\/|\/)/i.test(u)) return u;
    return `${String(baseUrl || '/fonts/').replace(/[^/]*$/, '')}${u}`;
}

export function parseFontFaces(css, baseUrl = '/fonts/fonts.css') {
    const faces = [];
    const blocks = String(css || '').match(/@font-face\s*\{[^}]*\}/g) || [];
    for (const b of blocks) {
        const get = (prop) => {
            const m = new RegExp(`${prop}\\s*:\\s*([^;}]+)`, 'i').exec(b);
            return m ? m[1].trim() : null;
        };
        const family = (get('font-family') || '').replace(/^['"]|['"]$/g, '');
        const src = /url\(\s*([^)]+?)\s*\)/i.exec(get('src') || '');
        if (!family || !src) continue;
        faces.push({
            family,
            style: get('font-style') || 'normal',
            weight: get('font-weight') || '400',
            url: resolveUrl(src[1], baseUrl),
            unicodeRange: get('unicode-range'),
            ranges: parseUnicodeRange(get('unicode-range')),
        });
    }
    return faces;
}

/** The family a CSS font-family value asks for first, unquoted. Generic names and empty values give null. */
export function firstFamily(stack) {
    const m = /^\s*(?:"([^"]+)"|'([^']+)'|([^,]+))/.exec(String(stack || ''));
    const name = (m && (m[1] || m[2] || (m[3] || '').trim())) || '';
    if (!name || /^(serif|sans-serif|monospace|cursive|fantasy|system-ui|ui-[a-z-]+|inherit|initial|unset)$/i.test(name)) return null;
    return name;
}

/** Every family named anywhere in a list of font-family values (the fallbacks count: a later one may be the one in use). */
export function familiesFromStacks(stacks) {
    const out = new Set();
    for (const s of stacks) {
        for (const m of String(s || '').matchAll(/"([^"]+)"|'([^']+)'/g)) out.add(m[1] || m[2]);
    }
    return out;
}

export function codepointsOf(text) {
    const set = new Set();
    for (const ch of String(text || '')) set.add(ch.codePointAt(0));
    return set;
}

const covers = (ranges, cps) => {
    if (!ranges) return true;
    for (const cp of cps) for (const [a, b] of ranges) if (cp >= a && cp <= b) return true;
    return false;
};

/** The faces of the wanted families whose unicode-range covers at least one character of the text. */
export function facesNeeded(faces, families, codepoints) {
    const want = new Set([...families]);
    const cps = codepoints && codepoints.size ? codepoints : new Set([0x20]);
    return faces.filter((f) => want.has(f.family) && covers(f.ranges, cps));
}

export function fontFaceCss(face, dataUri) {
    const q = (s) => String(s).replace(/['\\]/g, '');
    const range = face.unicodeRange ? `;unicode-range:${face.unicodeRange}` : '';
    return `@font-face{font-family:'${q(face.family)}';font-style:${q(face.style)};font-weight:${q(face.weight)};src:url(${dataUri}) format('woff2')${range}}`;
}

export function bytesToBase64(bytes) {
    let bin = '';
    const chunk = 0x8000;
    for (let i = 0; i < bytes.length; i += chunk) bin += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
    return btoa(bin);
}

export function toDataUri(mime, bytes) {
    return `data:${mime};base64,${bytesToBase64(bytes)}`;
}
