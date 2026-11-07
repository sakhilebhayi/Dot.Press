// Formatting commands for the contextual bar and the text shortcuts.
//
// Two targets, one API:
//   - while a text session is open, commands go to its Tiptap editor (marks on the selection) or to its element level
//     overrides (alignment, box fill ...), which are written with the text when the session ends;
//   - otherwise they edit the selected elements through store.patch, with whole-element semantics (a mark is put on or taken off
//     every run of text, a colour replaces the element colour and clears per-run overrides).
//
//   const f = createFormat({editor, store, text, deck})
//   f.get(name, els)            current value of a control ('bold', 'size', 'color', 'fill' ...)
//   f.set(name, value, els)     set it
//   f.toggle(name, els)         toggle a boolean control
//   f.command(cmd, event)       'text.bold' and friends from the keymap; returns true when handled
//   f.step(name, dir, els)      stepper controls (size, radius, lineWidth)
import {
    docMarkOn, docSetMark, docSetTextStyle, normalizeFontSize,
} from './lib/textdoc.js';
import { safeHref } from '../../render/doc.js';
import { resolveWeight, normalizeTheme } from '../../render/theme.js';
import { isLine } from './lib/hit.js';

const MARKS = { bold: 'bold', italic: 'italic', underline: 'underline', strike: 'strike' };
const TIPTAP_TOGGLE = { bold: 'toggleBold', italic: 'toggleItalic', underline: 'toggleUnderline', strike: 'toggleStrike' };

export function stepFor(value) {
    if (value < 40) return 2;
    if (value < 100) return 4;
    return 8;
}

export function createFormat({
    editor, store, text, deck,
}) {
    const session = () => {
        const t = text(); // the TextEditor component instance: its exposed refs are already unwrapped
        return t && t.editor ? t : null;
    };
    const theme = () => normalizeTheme(deck()?.theme);

    function patch(els, fn, key) {
        const patches = els.map((el) => ({ id: el.id, p: fn(el) })).filter((p) => p.p && Object.keys(p.p).length);
        if (patches.length) store.patch(patches, { undoKey: `fmt:${key}:${els.map((e) => e.id).join(',')}`, label: 'Format' });
    }

    function markOn(name, el) {
        if (name === 'bold') return docMarkOn(el.doc, 'bold') || resolveWeight(el, theme()) >= 600;
        return docMarkOn(el.doc, MARKS[name]);
    }

    function get(name, els = []) {
        const s = session();
        const first = els[0];
        if (s) {
            const ed = s.editor;
            void s.tick;
            const local = { ...s.el, ...s.local };
            switch (name) {
                case 'bold': return ed.isActive('bold');
                case 'italic': case 'underline': case 'strike': return ed.isActive(MARKS[name]);
                case 'link': return ed.isActive('link');
                case 'font': return ed.getAttributes('textStyle').fontFamily || local.font || '@body';
                case 'size': return parseFloat(ed.getAttributes('textStyle').fontSize) || local.size || 48;
                case 'color': return ed.getAttributes('textStyle').color || local.color || '@ink';
                case 'align': return local.align || 'left';
                default: break;
            }
        }
        if (!first) return undefined;
        switch (name) {
            case 'bold': case 'italic': case 'underline': case 'strike': return els.every((e) => markOn(name, e));
            case 'link': return !!first.link;
            case 'font': return first.font || '@body';
            case 'size': return first.size || 48;
            case 'color': return first.color || '@ink';
            case 'align': return first.align || 'left';
            case 'fill': return first.fill === undefined ? null : first.fill;
            case 'stroke': return first.stroke ?? (isLine(first) ? first.fill ?? '@ink' : null);
            case 'radius': return first.radius ?? 0;
            case 'opacity': return Math.round((first.op ?? 1) * 100);
            case 'lineWidth': return first.sw ?? 2;
            case 'iconColor': return first.color || '@accent';
            case 'mask': return first.mask || 'none';
            case 'alt': return first.alt || '';
            case 'chartType': return first.chart?.type || 'column';
            case 'loop': return !!first.loop;
            case 'dash': return first.dash || 'solid';
            case 'ends': return first.ends || { s: 'none', e: 'none' };
            default: return undefined;
        }
    }

    function set(name, value, els = []) {
        const s = session();
        if (s) {
            const ed = s.editor;
            const chain = () => ed.chain().focus();
            switch (name) {
                case 'font': chain().setMark('textStyle', { fontFamily: value }).run(); return;
                case 'size': {
                    const px = normalizeFontSize(value);
                    if (px) chain().setMark('textStyle', { fontSize: px }).run();
                    return;
                }
                case 'color': chain().setMark('textStyle', { color: value }).run(); return;
                case 'align': s.setProp('align', value); return;
                case 'link': {
                    if (value == null || value === '') { chain().extendMarkRange('link').unsetLink().run(); return; }
                    const href = safeHref(value);
                    if (href) chain().extendMarkRange('link').setLink({ href }).run();
                    return;
                }
                case 'fill': s.setProp('fill', value); return;
                default: break;
            }
        }
        switch (name) {
            case 'font': patch(els, (e) => ({ font: value, ...(e.doc ? { doc: docSetTextStyle(e.doc, 'fontFamily', null) } : {}) }), 'font'); break;
            case 'size': patch(els, (e) => ({ size: Math.max(1, Math.round(+value * 100) / 100), ...(e.doc ? { doc: docSetTextStyle(e.doc, 'fontSize', null) } : {}) }), 'size'); break;
            case 'color': patch(els, (e) => ({ color: value, ...(e.doc ? { doc: docSetTextStyle(e.doc, 'color', null) } : {}) }), 'color'); break;
            case 'align': patch(els, () => ({ align: value }), 'align'); break;
            case 'link': {
                const href = value ? safeHref(value) : null;
                if (value && !href) return;
                patch(els, () => ({ link: href }), 'link');
                break;
            }
            case 'fill': patch(els, () => ({ fill: value }), 'fill'); break;
            case 'stroke': {
                patch(els, (e) => {
                    if (value == null) return { stroke: null, sw: 0 };
                    const sw = (e.sw || 0) > 0 ? e.sw : Math.round((theme().s.stroke || 2) * ((deck().w || 1920) / 1920) * 100) / 100;
                    return { stroke: value, sw };
                }, 'stroke');
                break;
            }
            case 'radius': patch(els, () => ({ radius: Math.max(0, Math.round(+value)) }), 'radius'); break;
            case 'opacity': patch(els, () => ({ op: Math.min(1, Math.max(0, Math.round(+value) / 100)) }), 'opacity'); break;
            case 'lineWidth': patch(els, () => ({ sw: Math.max(0, Math.round(+value * 100) / 100) }), 'sw'); break;
            case 'iconColor': patch(els, () => ({ color: value }), 'iconColor'); break;
            case 'mask': patch(els, () => ({ mask: value }), 'mask'); break;
            case 'alt': patch(els, () => ({ alt: value ? String(value).slice(0, 240) : null }), 'alt'); break;
            case 'chartType': patch(els, (e) => ({ chart: { ...e.chart, type: value } }), 'chartType'); break;
            case 'loop': patch(els, () => ({ loop: !!value }), 'loop'); break;
            case 'dash': patch(els, () => ({ dash: value }), 'dash'); break;
            case 'ends': patch(els, (e) => ({ ends: { ...(e.ends || { s: 'none', e: 'none' }), ...value } }), 'ends'); break;
            default: break;
        }
    }

    function toggle(name, els = []) {
        const s = session();
        if (s && TIPTAP_TOGGLE[name]) {
            s.editor.chain().focus()[TIPTAP_TOGGLE[name]]().run();
            return;
        }
        if (!MARKS[name]) {
            if (name === 'loop') set('loop', !get('loop', els), els);
            return;
        }
        const on = !get(name, els);
        patch(els, (e) => {
            if (!e.doc) return {};
            const p = { doc: docSetMark(e.doc, MARKS[name], null, on) };
            // "bold" on an element whose weight is already bold has to lower the weight, otherwise it can never turn off
            if (name === 'bold' && !on && resolveWeight(e, theme()) >= 600) p.weight = 400;
            return p;
        }, name);
    }

    function step(name, dir, els = []) {
        const cur = +get(name === 'lineWidth' ? 'lineWidth' : name, els) || 0;
        const d = stepFor(cur || 1);
        set(name, Math.max(name === 'size' ? 1 : 0, cur + dir * (name === 'size' ? d : name === 'radius' ? 4 : 1)), els);
    }

    function command(cmd) {
        const s = session();
        const els = s ? [s.el] : store.selectedElements().filter((e) => e.type === 'text' || e.type === 'shape');
        if (!els.length) return false;
        switch (cmd) {
            case 'text.bold': toggle('bold', els); return true;
            case 'text.italic': toggle('italic', els); return true;
            case 'text.underline': toggle('underline', els); return true;
            case 'text.alignLeft': set('align', 'left', els); return true;
            case 'text.alignCenter': set('align', 'center', els); return true;
            case 'text.alignRight': set('align', 'right', els); return true;
            case 'text.link': {
                if (typeof editor.stage?.openLink === 'function') { editor.stage.openLink(); return true; }
                return false;
            }
            default: return false;
        }
    }

    return {
        get, set, toggle, step, command, session,
    };
}
