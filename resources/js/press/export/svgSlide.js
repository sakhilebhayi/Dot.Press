// Pure helpers that turn a rendered slide (as XHTML text) into a standalone SVG picture and size the canvas it is
// drawn on. The DOM work (rendering, collecting styles, inlining pictures) is in slideRaster.js.
//
//   escapeXml(text), stripInvalidXml(text)
//   cdata(text)                       text safe inside <style> in XHTML
//   replaceCssUrls(css, fn)           call fn(url) for every url(...) and put the answer back
//   clampScale(w, h, scale)           {scale, reduced}: the largest scale the canvas limits allow
//   slideSvg({xhtml, css, w, h, scale}) the SVG document text
//   svgDataUrl(svg)                   data: URL for an Image
//   selectorsTouched / ruleMayApply   helpers for choosing which CSS rules belong to a slide

export const MAX_CANVAS_SIDE = 16384;
export const MAX_CANVAS_PIXELS = 100_000_000;

export function escapeXml(text) {
    return String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[c]);
}

/** XML 1.0 forbids most control characters, even escaped. They can only be typos in a slide, so they are dropped. */
export function stripInvalidXml(text) {
    // eslint-disable-next-line no-control-regex
    return String(text).replace(/[\u0000-\u0008\u000b\u000c\u000e-\u001f\ufffe\uffff]/g, '');
}

export function cdata(text) {
    return `<![CDATA[${String(text).replace(/\]\]>/g, ']]]]><![CDATA[>')}]]>`;
}

export function replaceCssUrls(css, fn) {
    return String(css).replace(/url\(\s*(['"]?)(.*?)\1\s*\)/gi, (whole, _q, url) => {
        const next = fn(url);
        return next == null ? whole : `url("${String(next).replace(/"/g, '%22')}")`;
    });
}

export function clampScale(w, h, scale) {
    const wanted = Math.max(0.1, Number(scale) || 1);
    const bySide = MAX_CANVAS_SIDE / Math.max(1, w, h);
    const byArea = Math.sqrt(MAX_CANVAS_PIXELS / Math.max(1, w * h));
    const allowed = Math.min(wanted, bySide, byArea);
    return { scale: allowed, reduced: allowed < wanted - 1e-9 };
}

/**
 * @param {string} xhtml  serialised stage element (XHTML, with its own xmlns)
 * @param {string} css    all CSS the slide needs, including @font-face rules with data URIs
 */
export function slideSvg({ xhtml, css, w, h, scale = 1 }) {
    const W = Math.max(1, Math.round(w));
    const H = Math.max(1, Math.round(h));
    const pw = Math.max(1, Math.round(W * scale));
    const ph = Math.max(1, Math.round(H * scale));
    return `<svg xmlns="http://www.w3.org/2000/svg" width="${pw}" height="${ph}" viewBox="0 0 ${W} ${H}">`
        + `<foreignObject x="0" y="0" width="${W}" height="${H}">`
        + `<div xmlns="http://www.w3.org/1999/xhtml" style="width:${W}px;height:${H}px;position:relative;overflow:hidden"><style>${cdata(css)}</style>${stripInvalidXml(xhtml)}</div>`
        + '</foreignObject></svg>';
}

export function svgDataUrl(svg) {
    return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
}

/** Class names a selector needs. A rule that needs a class the slide does not have cannot match, so it is skipped without a query. */
export function selectorClasses(selector) {
    return [...String(selector).matchAll(/\.(-?[_a-zA-Z][\w-]*)/g)].map((m) => m[1]);
}

/**
 * Whether a CSS rule could apply to a slide made of these classes. `classes` is a Set. Rules with several selectors
 * apply when one of them could.
 */
export function ruleMayApply(selectorText, classes) {
    return String(selectorText).split(',').some((sel) => selectorClasses(sel).every((c) => classes.has(c)));
}

/** Drops the parts of a selector that no element can match in a static picture (pseudo elements, hover and focus states). */
export function staticSelector(selector) {
    return String(selector)
        .replace(/::?(before|after|first-line|first-letter|marker|placeholder|selection|backdrop|file-selector-button)\b/gi, '')
        .replace(/:(hover|focus|focus-visible|focus-within|active|visited|target)\b/gi, ':not(*)');
}
