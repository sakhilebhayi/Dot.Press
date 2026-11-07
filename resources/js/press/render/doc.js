// Tiptap/ProseMirror JSON (restricted by spec section 6) to safe HTML. Pure, no DOM.
// Everything that reaches the HTML is either an escaped text node, a fixed tag, or a validated attribute value.
// Unknown nodes and marks are dropped. Depth and node count are capped so hostile documents cannot hang the player.
import { colorCss } from './theme.js';
import { fontStack, isKnownFont } from '../fonts.js';

const MAX_DEPTH = 10;
const MAX_NODES = 20000;
const MAX_TEXT = 200000;

const ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
export function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ESC[c]);
}

/** Only https URLs and in deck slide links are allowed on links. Returns a clean string or null. */
export function safeHref(href) {
    if (typeof href !== 'string') return null;
    const h = href.trim();
    if (h.length > 2048 || /[\u0000-\u001f\u007f\s<>"'`\\]/.test(h)) return null;
    if (/^#slide:[A-Za-z0-9_-]{1,40}$/.test(h)) return h;
    if (/^https:\/\/[^/?#]+/i.test(h)) {
        try {
            const u = new URL(h);
            return u.protocol === 'https:' ? u.href : null;
        } catch {
            return null;
        }
    }
    return null;
}

const ALIGN = new Set(['left', 'center', 'right', 'justify']);

function styleFor(textStyle) {
    const out = [];
    if (textStyle && typeof textStyle === 'object') {
        const col = typeof textStyle.color === 'string' ? colorCss(textStyle.color, '') : '';
        if (col) out.push(`color:${col}`);
        const fs = Number.parseFloat(textStyle.fontSize);
        if (Number.isFinite(fs) && fs >= 1 && fs <= 2000) out.push(`font-size:calc(${+fs.toFixed(2)}px * var(--p-fit, 1))`);
        const ff = textStyle.fontFamily;
        if (typeof ff === 'string') {
            if (ff === '@heading') out.push('font-family:var(--p-font-heading)');
            else if (ff === '@body') out.push('font-family:var(--p-font-body)');
            else if (isKnownFont(ff)) out.push(`font-family:${fontStack(ff).replace(/"/g, "'")}`);
        }
    }
    return out.join(';');
}

/**
 * @param {object} doc Tiptap JSON document
 * @param {{ links?: boolean, highlight?: string }} [opts]
 *   links: render link marks as <a> (view and present modes). Otherwise as inert <span class="p-link">.
 *   highlight: a CSS colour; wraps each text run in a marker background (the `highlight` effect).
 * @returns {string} HTML made of <p>, <ul>, <ol>, <li>, <br> and inline tags
 */
export function docToHtml(doc, opts = {}) {
    const state = { nodes: 0, chars: 0, links: !!opts.links, highlight: opts.highlight || '' };
    if (!doc || typeof doc !== 'object' || doc.type !== 'doc' || !Array.isArray(doc.content)) return '';
    return blocks(doc.content, 0, state);
}

function blocks(list, depth, st) {
    let html = '';
    for (const node of list) {
        if (++st.nodes > MAX_NODES) break;
        if (!node || typeof node !== 'object') continue;
        html += block(node, depth, st);
    }
    return html;
}

function block(node, depth, st) {
    if (depth > MAX_DEPTH) return '';
    switch (node.type) {
        case 'paragraph': {
            const a = ALIGN.has(node.attrs?.textAlign) ? ` style="text-align:${node.attrs.textAlign}"` : '';
            const inner = inline(node.content, depth + 1, st);
            return `<p${a}>${inner || '<br>'}</p>`;
        }
        case 'bulletList':
            return `<ul>${listItems(node.content, depth + 1, st)}</ul>`;
        case 'orderedList': {
            const start = Number.isInteger(node.attrs?.start) && node.attrs.start > 1 && node.attrs.start < 100000 ? ` start="${node.attrs.start}"` : '';
            return `<ol${start}>${listItems(node.content, depth + 1, st)}</ol>`;
        }
        default:
            return '';
    }
}

function listItems(list, depth, st) {
    if (!Array.isArray(list) || depth > MAX_DEPTH) return '';
    let html = '';
    for (const li of list) {
        if (++st.nodes > MAX_NODES) break;
        if (!li || li.type !== 'listItem' || !Array.isArray(li.content)) continue;
        html += `<li>${blocks(li.content, depth + 1, st)}</li>`;
    }
    return html;
}

function inline(list, depth, st) {
    if (!Array.isArray(list)) return '';
    let html = '';
    for (const node of list) {
        if (++st.nodes > MAX_NODES || st.chars > MAX_TEXT) break;
        if (!node || typeof node !== 'object') continue;
        if (node.type === 'hardBreak') {
            html += '<br>';
        } else if (node.type === 'text' && typeof node.text === 'string' && node.text !== '') {
            const text = node.text.slice(0, MAX_TEXT - st.chars);
            st.chars += text.length;
            html += marked(escapeHtml(text), node.marks, st);
        }
    }
    return html;
}

function marked(text, marks, st) {
    let out = text;
    if (st.highlight) out = `<span class="p-hl" style="--p-hl:${escapeHtml(st.highlight)}">${out}</span>`;
    if (!Array.isArray(marks)) return out;
    let style = '';
    let link = null;
    const tags = [];
    for (const m of marks.slice(0, 12)) {
        if (!m || typeof m !== 'object') continue;
        switch (m.type) {
            case 'bold':
                tags.push('strong');
                break;
            case 'italic':
                tags.push('em');
                break;
            case 'underline':
                tags.push('u');
                break;
            case 'strike':
                tags.push('s');
                break;
            case 'textStyle':
                style = styleFor(m.attrs) || style;
                break;
            case 'link':
                link = safeHref(m.attrs?.href) || link;
                break;
        }
    }
    for (const t of tags) out = `<${t}>${out}</${t}>`;
    if (style) out = `<span style="${escapeHtml(style)}">${out}</span>`;
    if (link) {
        out = st.links
            ? `<a class="p-link" href="${escapeHtml(link)}"${link[0] === '#' ? ` data-slide="${escapeHtml(link.slice(7))}"` : ' target="_blank" rel="noopener noreferrer"'}>${out}</a>`
            : `<span class="p-link">${out}</span>`;
    }
    return out;
}

/** Plain text of a doc, paragraphs joined by newlines. For aria labels, search and notes. */
export function docToPlain(doc) {
    const parts = [];
    let n = 0;
    const walk = (node, depth) => {
        if (!node || typeof node !== 'object' || depth > MAX_DEPTH + 4 || ++n > MAX_NODES) return;
        if (node.type === 'text' && typeof node.text === 'string') parts.push(node.text);
        else if (node.type === 'hardBreak') parts.push('\n');
        if (Array.isArray(node.content)) {
            node.content.forEach((c) => walk(c, depth + 1));
            if (node.type === 'paragraph') parts.push('\n');
        }
    };
    walk(doc, 0);
    return parts.join('').replace(/\n+$/, '');
}

/** True when the doc holds no visible text. */
export function docIsEmpty(doc) {
    return docToPlain(doc).trim() === '';
}

/** A stable short key for a doc, used to cache measurements. */
export function docKey(doc) {
    const s = JSON.stringify(doc ?? null);
    let h = 5381;
    for (let i = 0; i < s.length; i++) h = ((h << 5) + h + s.charCodeAt(i)) | 0;
    return (h >>> 0).toString(36) + ':' + s.length;
}
