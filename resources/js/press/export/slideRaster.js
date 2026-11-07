// Draws slides to PNG in the browser, with the same renderer the editor uses (SlideView, mode "view").
//
// How: a slide is rendered off screen at its own size, the styles that apply to it are collected from the page's style
// sheets, the fonts it uses are fetched and inlined as base64 @font-face rules, pictures become data URIs, and the
// result is wrapped in an SVG <foreignObject>. The browser draws that SVG onto a canvas and the canvas gives a PNG.
// Everything the SVG needs travels inside it, because an SVG used as a picture cannot load anything.
//
// Limits (said to the person in the export panel): video is drawn as its poster frame, embeds and audio are not
// drawn, pictures from other websites that do not allow it are left out, and exotic effects the browser's SVG
// renderer does not support may differ from the screen. Print view is the exact route.
import { createApp, h, nextTick } from 'vue';
import SlideView from '../render/SlideView.vue';
import { FONT_CSS_URL, ensureFonts } from '../fonts.js';
import { abortError } from './exportImages.js';
import { codepointsOf, facesNeeded, familiesFromStacks, fontFaceCss, parseFontFaces, toDataUri } from './fontCss.js';
import { clampScale, replaceCssUrls, ruleMayApply, slideSvg, staticSelector, svgDataUrl } from './svgSlide.js';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function bytesOf(res) {
    return new Uint8Array(await res.arrayBuffer());
}

export function createSlideRenderer({ deck, assets = {}, fetchFn = (...a) => fetch(...a), signal = null } = {}) {
    const W = Math.max(1, Math.round(+deck?.w || 1920));
    const H = Math.max(1, Math.round(+deck?.h || 1080));
    const dataCache = new Map();
    const fontData = new Map();
    let faces = null;

    const check = () => { if (signal?.aborted) throw abortError(); };

    async function loadFaces() {
        if (faces) return faces;
        try {
            const res = await fetchFn(FONT_CSS_URL, { credentials: 'same-origin' });
            faces = res.ok ? parseFontFaces(await res.text(), FONT_CSS_URL) : [];
        } catch {
            faces = [];
        }
        return faces;
    }

    /** A URL as a data URI, or null when it cannot be read. The same URL is fetched once per export. */
    function dataUri(url) {
        if (/^data:/i.test(url)) return Promise.resolve(url);
        if (!dataCache.has(url)) {
            dataCache.set(url, (async () => {
                try {
                    const res = await fetchFn(url, { credentials: 'same-origin' });
                    if (!res.ok) return null;
                    const blob = await res.blob();
                    const mime = blob.type || 'application/octet-stream';
                    if (!/^image\//i.test(mime)) return null;
                    return toDataUri(mime, await bytesOf(blob));
                } catch {
                    return null;
                }
            })());
        }
        return dataCache.get(url);
    }

    async function fontCssFor(families, cps) {
        const all = await loadFaces();
        const need = facesNeeded(all, families, cps);
        const out = [];
        for (const f of need) {
            if (!fontData.has(f.url)) {
                fontData.set(f.url, (async () => {
                    try {
                        const res = await fetchFn(f.url, { credentials: 'same-origin' });
                        return res.ok ? toDataUri('font/woff2', await bytesOf(res)) : null;
                    } catch {
                        return null;
                    }
                })());
            }
            const uri = await fontData.get(f.url);
            if (uri) out.push(fontFaceCss(f, uri));
        }
        return out.join('\n');
    }

    /** CSS rules of the page that can apply to something inside `probe`. Keeps @layer so the cascade stays as on screen. */
    function matchingCss(probe) {
        const classes = new Set();
        probe.querySelectorAll('[class]').forEach((n) => n.classList.forEach((c) => classes.add(c)));
        const applies = (selectorText) => {
            if (!ruleMayApply(selectorText, classes)) return false;
            try {
                return probe.querySelector(staticSelector(selectorText)) !== null;
            } catch {
                return false;
            }
        };
        const walk = (rules) => {
            let css = '';
            for (const r of rules) {
                if (typeof CSSStyleRule !== 'undefined' && r instanceof CSSStyleRule) {
                    if (applies(r.selectorText)) css += `${r.cssText}\n`;
                } else if (typeof CSSLayerStatementRule !== 'undefined' && r instanceof CSSLayerStatementRule) {
                    css += `${r.cssText}\n`;
                } else if (typeof CSSLayerBlockRule !== 'undefined' && r instanceof CSSLayerBlockRule) {
                    const inner = walk(r.cssRules);
                    if (inner) css += `@layer ${r.name}{${inner}}\n`;
                } else if (typeof CSSMediaRule !== 'undefined' && r instanceof CSSMediaRule) {
                    if (window.matchMedia(r.conditionText || r.media.mediaText).matches) css += walk(r.cssRules);
                } else if (typeof CSSSupportsRule !== 'undefined' && r instanceof CSSSupportsRule) {
                    if (CSS.supports(r.conditionText)) css += walk(r.cssRules);
                }
            }
            return css;
        };
        let css = '';
        for (const sheet of document.styleSheets) {
            try {
                css += walk(sheet.cssRules);
            } catch {
                // a style sheet from another website cannot be read; nothing of the slide lives there
            }
        }
        return css;
    }

    async function imagesSettled(root, ms = 10000) {
        const pending = [...root.querySelectorAll('img')].filter((i) => !i.complete);
        if (!pending.length) return;
        await Promise.race([
            Promise.allSettled(pending.map((i) => new Promise((r) => { i.addEventListener('load', r, { once: true }); i.addEventListener('error', r, { once: true }); }))),
            sleep(ms),
        ]);
    }

    /** Prepare the clone: pictures inline, video as poster, no live elements. Returns the warnings. */
    async function prepare(clone) {
        const warnings = [];
        clone.style.transform = 'none';
        let videos = 0;
        let embeds = 0;
        clone.querySelectorAll('video').forEach((v) => {
            videos++;
            const poster = v.getAttribute('poster');
            if (poster) {
                const img = document.createElement('img');
                img.className = 'pv-poster';
                img.setAttribute('src', poster);
                img.setAttribute('alt', '');
                v.replaceWith(img);
            } else {
                v.remove();
            }
        });
        clone.querySelectorAll('iframe,audio,script,object,embed,canvas').forEach((n) => { if (n.tagName === 'IFRAME') embeds++; n.remove(); });
        if (videos) warnings.push(videos === 1 ? 'A video is drawn as its poster frame.' : `${videos} videos are drawn as their poster frames.`);
        if (embeds) warnings.push('An embedded video is not drawn.');

        let missing = 0;
        const imgs = [...clone.querySelectorAll('img')];
        await Promise.all(imgs.map(async (img) => {
            const src = img.getAttribute('src');
            const uri = src ? await dataUri(src) : null;
            if (uri) {
                img.setAttribute('src', uri);
                img.removeAttribute('loading');
            } else {
                missing++;
                img.remove();
            }
        }));
        const svgImgs = [...clone.querySelectorAll('image')];
        await Promise.all(svgImgs.map(async (n) => {
            const href = n.getAttribute('href') || n.getAttributeNS('http://www.w3.org/1999/xlink', 'href');
            const uri = href ? await dataUri(href) : null;
            if (uri) n.setAttribute('href', uri); else { missing++; n.remove(); }
        }));
        // url(...) inside style attributes
        const styled = [...clone.querySelectorAll('[style*="url("]')];
        await Promise.all(styled.map(async (n) => {
            // url(#id) points at a gradient or clip path inside the same slide: it stays as it is.
            const urls = [...n.getAttribute('style').matchAll(/url\(\s*(['"]?)(.*?)\1\s*\)/gi)].map((m) => m[2]).filter((u) => !u.startsWith('#'));
            if (!urls.length) return;
            const map = new Map();
            await Promise.all(urls.map(async (u) => { map.set(u, await dataUri(u)); }));
            n.setAttribute('style', replaceCssUrls(n.getAttribute('style'), (u) => (u.startsWith('#') ? null : map.get(u) || 'about:blank')));
            if (urls.some((u) => !map.get(u))) missing++;
        }));
        if (missing) warnings.push(missing === 1 ? 'A picture could not be read and was left out.' : `${missing} pictures could not be read and were left out.`);
        return warnings;
    }

    /** Render one slide to PNG bytes. */
    async function render(slide, { scale = 1 } = {}) {
        check();
        ensureFonts();
        const host = document.createElement('div');
        host.setAttribute('aria-hidden', 'true');
        host.style.cssText = `position:fixed;left:-100000px;top:0;width:${W}px;height:${H}px;overflow:hidden;pointer-events:none;`;
        document.body.appendChild(host);
        const app = createApp({ render: () => h(SlideView, { slide, deck: { w: W, h: H, theme: deck?.theme }, assets, mode: 'view', eager: true, allowEmbeds: false }) });
        try {
            app.mount(host);
            await nextTick();
            await sleep(30);
            await document.fonts?.ready;
            await imagesSettled(host);
            await nextTick();
            await sleep(60);
            await document.fonts?.ready;
            check();

            const stage = host.querySelector('.ps-stage');
            if (!stage) throw new Error('The slide did not draw.');
            const stacks = new Set([getComputedStyle(stage).fontFamily]);
            stage.querySelectorAll('*').forEach((n) => stacks.add(getComputedStyle(n).fontFamily));
            const families = familiesFromStacks(stacks);
            const cps = codepointsOf(stage.textContent);

            const clone = stage.cloneNode(true);
            const warnings = await prepare(clone);
            check();
            const probe = document.createElement('div');
            probe.appendChild(clone);
            const css = `${matchingCss(probe)}\n${await fontCssFor(families, cps)}`;
            check();

            const { scale: s, reduced } = clampScale(W, H, scale);
            if (reduced) warnings.push(`The picture was made smaller than asked (${Math.round(W * s)} by ${Math.round(H * s)} pixels) because the browser limits canvas size.`);
            const xhtml = new XMLSerializer().serializeToString(clone);
            const svg = slideSvg({ xhtml, css, w: W, h: H, scale: s });

            const img = new Image();
            img.decoding = 'sync';
            const loaded = new Promise((res, rej) => { img.onload = res; img.onerror = () => rej(new Error('The browser could not draw this slide as a picture.')); });
            img.src = svgDataUrl(svg);
            await loaded;
            try { await img.decode(); } catch { /* onload already fired */ }
            check();
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(W * s);
            canvas.height = Math.round(H * s);
            const ctx = canvas.getContext('2d');
            if (!ctx) throw new Error('The browser has no canvas available for this size.');
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise((res, rej) => {
                try {
                    canvas.toBlob((b) => (b ? res(b) : rej(new Error('The browser could not save the picture.'))), 'image/png');
                } catch {
                    rej(new Error('The browser blocked saving this slide as a picture. Use Print view instead.'));
                }
            });
            return { png: new Uint8Array(await blob.arrayBuffer()), warnings, scale: s };
        } finally {
            try { app.unmount(); } catch { /* already gone */ }
            host.remove();
        }
    }

    return { render, width: W, height: H };
}
