<script setup>
// In place text editing with Tiptap. The editor sits exactly on the element's box and lays text out with the same style the
// slide renderer uses (lib/textstyle.js mirrors render/TextBox.vue), so nothing moves when editing starts or ends.
//
// - Only the allowed nodes and marks of spec section 6 exist in the schema; pasted rich text is pruned by the schema, colours,
//   sizes and families are normalised by TextStyleAttrs.
// - Nothing from the store reaches the editor while it is open (the document is read once), so an IME composition can
//   never be interrupted by a remote change. The result is written back once, as one `el.set`, on Escape or blur.
// - A box with fit "none" grows downward while typing (core growVertically); a "shrink" box shows its fitted type size.
import {
    computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef,
} from 'vue';
import { Editor, EditorContent, Extension } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { TextStyle } from '@tiptap/extension-text-style';
import { colorCss, normalizeTheme } from '../../render/theme.js';
import { fontStack, isKnownFont } from '../../fonts.js';
import { fitFactor } from '../../render/fit.js';
import { safeHref } from '../../render/doc.js';
import { growVertically } from '../core/geometry.js';
import { textBoxStyle } from './lib/textstyle.js';
import {
    sanitizeDoc, normalizeColor, normalizeFontFamily, normalizeFontSize, docEqual, docIsBlank,
} from './lib/textdoc.js';

const props = defineProps({
    el: { type: Object, required: true },
    deck: { type: Object, required: true },
    isDraft: { type: Boolean, default: false },
    zoom: { type: Number, default: 1 },
    insert: { type: String, default: '' },
    at: { type: Object, default: null },
});
const emit = defineEmits(['commit']);

const theme = computed(() => normalizeTheme(props.deck.theme));
const local = reactive({}); // element level changes made from the bar while editing (align, font, size, colour ...)
const tick = ref(0);
const frame = ref(null);
const box = ref(null);
const grown = ref(null);
const blank = ref(true);
let finished = false;

const TextStyleAttrs = Extension.create({
    name: 'textStyleAttrs',
    addGlobalAttributes() {
        return [{
            types: ['textStyle'],
            attributes: {
                color: {
                    default: null,
                    parseHTML: (e) => normalizeColor(e.style?.color) || null,
                    renderHTML: (a) => (a.color ? { style: `color: ${colorCss(a.color, 'inherit')}` } : {}),
                },
                fontSize: {
                    default: null,
                    parseHTML: (e) => normalizeFontSize(e.style?.fontSize) || null,
                    renderHTML: (a) => (a.fontSize ? { style: `font-size: calc(${a.fontSize} * var(--p-fit, 1))` } : {}),
                },
                fontFamily: {
                    default: null,
                    parseHTML: (e) => normalizeFontFamily(e.style?.fontFamily) || null,
                    renderHTML: (a) => {
                        const f = a.fontFamily;
                        if (!f) return {};
                        if (f === '@heading') return { style: 'font-family: var(--p-font-heading)' };
                        if (f === '@body') return { style: 'font-family: var(--p-font-body)' };
                        return isKnownFont(f) ? { style: `font-family: ${fontStack(f).replace(/"/g, "'")}` } : {};
                    },
                },
            },
        }];
    },
});

const view = computed(() => ({ ...props.el, ...local, ...(grown.value || {}) }));
const kind = computed(() => (props.el.type === 'shape' ? 'shape' : 'text'));
const boxStyle = computed(() => textBoxStyle(view.value, theme.value, kind.value));
const frameStyle = computed(() => {
    const e = view.value;
    return {
        width: `${e.w}px`,
        height: `${e.h}px`,
        transform: `translate(${e.x}px, ${e.y}px)${e.rot ? ` rotate(${e.rot}deg)` : ''}`,
        opacity: e.op == null ? undefined : e.op,
    };
});

const editor = shallowRef(null);

function measureGrowth() {
    const e = props.el;
    const inner = box.value?.querySelector('.pt-in');
    if (!inner) return;
    if (e.type === 'text' && (view.value.fit || 'none') === 'none') {
        const pad = Number.isFinite(+view.value.pad) ? +view.value.pad : 0;
        const g = growVertically({ ...e, ...local }, inner.offsetHeight + pad * 2);
        grown.value = g || null;
    } else if (view.value.fit === 'shrink') {
        const host = box.value;
        const pad = Number.isFinite(+view.value.pad) ? +view.value.pad : kind.value === 'shape' ? 16 : 0;
        const available = Math.max(1, view.value.h - 2 * pad);
        const f = fitFactor((x) => { host.style.setProperty('--p-fit', String(x)); return inner.offsetHeight; }, available);
        host.style.setProperty('--p-fit', String(f));
    }
}

let growTimer = 0;
function scheduleMeasure() {
    cancelAnimationFrame(growTimer);
    growTimer = requestAnimationFrame(measureGrowth);
}

function build() {
    const doc = sanitizeDoc(props.el.doc);
    editor.value = new Editor({
        content: doc,
        extensions: [
            StarterKit.configure({
                heading: false,
                blockquote: false,
                codeBlock: false,
                code: false,
                horizontalRule: false,
                dropcursor: false,
                gapcursor: false,
                trailingNode: false,
                link: {
                    openOnClick: false,
                    autolink: false,
                    linkOnPaste: false,
                    HTMLAttributes: { class: 'p-link', rel: 'noopener noreferrer' },
                    isAllowedUri: (url) => !!safeHref(url),
                },
            }),
            TextStyle,
            TextStyleAttrs,
        ],
        editorProps: {
            attributes: {
                class: 'pt-in',
                spellcheck: 'true',
                role: 'textbox',
                'aria-multiline': 'true',
                'aria-label': 'Text',
                autocapitalize: 'sentences',
            },
            handleKeyDown: (_v, ev) => {
                if (ev.key === 'Escape') { ev.preventDefault(); commit(); return true; }
                return false;
            },
        },
        onUpdate: ({ editor: ed }) => { blank.value = docIsBlank(ed.getJSON()); tick.value++; scheduleMeasure(); },
        onSelectionUpdate: () => { tick.value++; },
        onTransaction: () => { tick.value++; },
        onBlur: ({ event }) => {
            const next = event?.relatedTarget;
            // focus moving into the contextual bar keeps the editor open
            if (next && next.closest && next.closest('[data-es-ui]')) return;
            setTimeout(() => { if (!finished && !frame.value?.contains(document.activeElement) && !document.activeElement?.closest?.('[data-es-ui]')) commit(); }, 0);
        },
    });
    blank.value = docIsBlank(doc);
}

function focus(where = 'end') {
    editor.value?.commands.focus(where);
}

function commit(cancelled = false) {
    if (finished) return;
    finished = true;
    const ed = editor.value;
    const doc = sanitizeDoc(ed ? ed.getJSON() : props.el.doc);
    const patch = { ...local };
    if (!docEqual(doc, props.el.doc)) patch.doc = doc;
    else if (props.isDraft) patch.doc = doc;
    if (grown.value && !cancelled) Object.assign(patch, grown.value);
    emit('commit', {
        id: props.el.id, patch, doc, isDraft: props.isDraft, cancelled,
    });
}

onMounted(async () => {
    build();
    await nextTick();
    scheduleMeasure();
    if (props.at) {
        const ed = editor.value;
        const pos = ed?.view.posAtCoords({ left: props.at.x, top: props.at.y });
        if (pos) ed.chain().focus().setTextSelection(pos.pos).run(); else focus('end');
    } else focus('end');
    if (props.insert) editor.value?.commands.insertContent(props.insert);
});
onBeforeUnmount(() => {
    cancelAnimationFrame(growTimer);
    finished = true;
    editor.value?.destroy();
    editor.value = null;
});

defineExpose({
    commit, focus, editor, local, tick, kind, el: computed(() => props.el),
    setProp(key, value) { if (value == null) delete local[key]; else local[key] = value; scheduleMeasure(); tick.value++; },
});
</script>

<template>
    <div ref="frame" class="es-text-frame" data-es-ui :style="frameStyle">
        <div ref="box" class="pt" :class="'pt-' + kind" :style="boxStyle">
            <EditorContent v-if="editor" :editor="editor" class="pt-in-host" />
        </div>
        <span v-if="blank" class="es-text-hint" :style="{ transform: `translateY(calc(${view.h}px + ${6 / zoom}px)) scale(${1 / zoom})`, transformOrigin: '0 0' }">{{ isDraft ? 'Type, then press Esc' : 'Esc to finish' }}</span>
    </div>
</template>

<style>
.es-text-frame .pt-in-host { display: contents; }
.es-text-frame .pt-in-host > div { width: 100%; }
</style>
