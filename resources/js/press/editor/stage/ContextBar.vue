<script setup>
// The contextual bar: a small toolbar that floats 12 px above the selection (below it near the top edge), with five to eight
// controls chosen by what is selected. Controls that need more room open a popover here or the shell's panel through
// editor.openPanel(id), which is hidden when that panel is not registered.
import {
    computed, nextTick, onBeforeUnmount, onMounted, ref, watch,
} from 'vue';
import { useEditorContext } from '../context.js';
import { FONTS } from '../../fonts.js';
import { normalizeTheme, resolveColor, TOKEN_NAMES } from '../../render/theme.js';
import { safeHref } from '../../render/doc.js';
import { barKind, barControls, placeBar } from './lib/bar.js';
import StageIcon from './StageIcon.vue';

const props = defineProps({
    anchor: { type: Object, required: true },
    size: { type: Object, required: true },
    elements: { type: Array, required: true },
    format: { type: Object, required: true },
    editing: { type: Boolean, default: false },
    hasPanel: { type: Function, default: () => true },
    hidden: { type: Boolean, default: false },
    previewing: { type: String, default: null },
    table: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['crop', 'replace', 'refocus', 'preview', 'table']);

const editor = useEditorContext();
const { store } = editor;
const bar = ref(null);
const open = ref(null);
const barSize = ref({ w: 360, h: 44 });
const hexDraft = ref('');
const linkDraft = ref('');
const linkError = ref('');
const altDraft = ref('');

const kind = computed(() => barKind(props.elements));
const controls = computed(() => {
    let list = barControls(kind.value, { hasPanel: props.hasPanel, canEdit: true });
    const els = props.elements;
    if (kind.value === 'shape' && !els.every((e) => e.kind === 'rect')) list = list.filter((c) => c.id !== 'radius');
    if (kind.value === 'media' && els[0]?.type === 'audio') list = list.filter((c) => c.id !== 'poster');
    if (kind.value === 'text' && props.editing) list = list.filter((c) => c.id !== 'link' || true);
    return list;
});
const label = computed(() => ({
    text: 'Text formatting', picture: 'Picture', shape: 'Shape', chart: 'Chart', table: 'Table', line: 'Line', icon: 'Icon', media: 'Media', group: 'Group', multi: 'Selection',
}[kind.value] || 'Selection'));

const pos = computed(() => placeBar({
    anchor: props.anchor, size: barSize.value, viewport: props.size, aboveInset: props.anchor.inset || 0,
}));
const barStyle = computed(() => ({ transform: `translate(${pos.value.x}px, ${pos.value.y}px)` }));
const popUp = computed(() => pos.value.y + barSize.value.h + 360 > props.size.h && pos.value.y > 380);
const popStyle = (left) => ({ left: `${Math.min(left, Math.max(0, props.size.w - pos.value.x - 232))}px`, ...(popUp.value ? { bottom: `${barSize.value.h + 6}px` } : { top: `${barSize.value.h + 6}px` }) });

function measure() {
    const el = bar.value;
    if (el) barSize.value = { w: el.offsetWidth, h: el.offsetHeight };
}
let ro = null;
onMounted(() => {
    measure();
    if (typeof ResizeObserver !== 'undefined') { ro = new ResizeObserver(measure); ro.observe(bar.value); }
});
onBeforeUnmount(() => ro && ro.disconnect());
watch(controls, () => nextTick(measure));
watch(() => props.elements.map((e) => e.id).join(), () => { open.value = null; nextTick(measure); });

const theme = computed(() => normalizeTheme(store.getState().doc.deck.theme));
const tokenColors = computed(() => {
    const names = [...TOKEN_NAMES, 'chart1', 'chart2', 'chart3', 'chart4', 'chart5', 'chart6'];
    return names.map((n) => ({ token: `@${n}`, label: n, css: resolveColor(`@${n}`, theme.value, '#000000') }));
});
const css = (v) => (typeof v === 'string' ? resolveColor(v, theme.value, null) : null);
const val = (name) => props.format.get(name, props.elements);
const set = (name, v) => props.format.set(name, v, props.elements);

// ---- popovers ---------------------------------------------------------------------------------------
const btnRefs = ref({});
const leftOf = (id) => {
    const b = btnRefs.value[id];
    return b ? b.offsetLeft : 0;
};
function toggleOpen(id) {
    if (open.value === id) { open.value = null; return; }
    open.value = id;
    if (id === 'link') {
        const cur = props.elements[0]?.link || '';
        linkDraft.value = props.format.get('link', props.elements) && cur ? cur : '';
        linkError.value = '';
    }
    if (id === 'alt') altDraft.value = props.elements[0]?.alt || '';
    if (id === 'color' || id === 'fill' || id === 'stroke' || id === 'iconColor') hexDraft.value = typeof val(id === 'iconColor' ? 'iconColor' : id) === 'string' ? css(val(id)) || '' : '';
    nextTick(() => {
        const pop = bar.value?.querySelector('.es-pop');
        (pop?.querySelector('input, textarea, button:not([disabled])'))?.focus?.({ preventScroll: true });
    });
}
function close() {
    if (open.value) { open.value = null; return true; }
    return false;
}
function pick(name, v, keepOpen = false) {
    set(name, v);
    if (!keepOpen) { open.value = null; emit('refocus'); }
}
function applyHex(name) {
    const v = hexDraft.value.trim();
    const m = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(v);
    if (!m) return;
    const h = m[1].length === 3 ? m[1].split('').map((c) => c + c).join('') : m[1];
    pick(name, `#${h.toLowerCase()}`);
}
function applyLink() {
    const v = linkDraft.value.trim();
    if (!v) { set('link', null); open.value = null; emit('refocus'); return; }
    const href = /^(https:\/\/|#slide:)/i.test(v) ? safeHref(v) : safeHref(`https://${v}`);
    if (!href) { linkError.value = 'Use a web address that starts with https://'; return; }
    set('link', href);
    open.value = null;
    emit('refocus');
}
function applyAlt() {
    set('alt', altDraft.value.trim());
    open.value = null;
    emit('refocus');
}

// ---- lists ---------------------------------------------------------------------------------------------
const MASKS = [['none', 'No mask'], ['round', 'Rounded'], ['circle', 'Circle'], ['ellipse', 'Ellipse'], ['triangle', 'Triangle'], ['hexagon', 'Hexagon'], ['star', 'Star']];
const CHARTS = [['column', 'Column'], ['bar', 'Bar'], ['line', 'Line'], ['area', 'Area'], ['pie', 'Pie'], ['doughnut', 'Doughnut'], ['scatter', 'Scatter'], ['bubble', 'Bubble'], ['waterfall', 'Waterfall'], ['funnel', 'Funnel'], ['radar', 'Radar'], ['combo', 'Combo'], ['kpi', 'Key figures'], ['map', 'Map']];
const ALIGNS = [['left', 'Left'], ['center', 'Centre'], ['right', 'Right'], ['justify', 'Justify']];
const DASHES = [['solid', 'Solid'], ['dash', 'Dashed'], ['dot', 'Dotted']];
const ENDS = [['none', 'None'], ['arrow', 'Arrow'], ['dot', 'Dot']];
const fontOptions = computed(() => [
    { value: '@heading', label: `Heading (${theme.value.t.heading})`, stack: null },
    { value: '@body', label: `Body (${theme.value.t.body})`, stack: null },
    ...FONTS.map((f) => ({ value: f.family, label: f.family, stack: f.stack })),
]);
const fontName = computed(() => {
    const v = val('font');
    if (v === '@heading') return theme.value.t.heading;
    if (v === '@body') return theme.value.t.body;
    return v;
});

// ---- other controls -------------------------------------------------------------------------------------
const el = computed(() => props.elements[0]);
function tableOp(op) {
    open.value = null;
    emit('table', op);
}

function sizeInput(e) {
    const n = parseFloat(e.target.value);
    if (Number.isFinite(n) && n > 0) set('size', n);
    else e.target.value = Math.round(val('size'));
}
function radiusInput(e) {
    const n = parseFloat(e.target.value);
    if (Number.isFinite(n) && n >= 0) set('radius', n);
}
function widthInput(e) {
    const n = parseFloat(e.target.value);
    if (Number.isFinite(n) && n >= 0) set('lineWidth', n);
}

function editShapeText() {
  const a = editor.stage;
  if (a?.beginTextEdit && props.elements[0]) a.beginTextEdit(props.elements[0].id);
}

function run(id) {
    switch (id) {
        case 'replace': emit('replace', props.elements[0].id); break;
        case 'crop': emit('crop', props.elements[0].id); break;
        case 'editData': editor.openPanel?.('chart'); break;
        case 'swap': editor.openPanel?.('icon'); break;
        case 'poster': editor.openPanel?.('assets', { for: props.elements[0].id, role: 'poster' }); break;
        case 'play': emit('preview', props.elements[0].id); break;
        case 'loop': props.format.toggle('loop', props.elements); break;
        case 'ungroup': store.ungroup(); break;
        case 'group': store.group(); break;
        case 'addRow': tableOp('rowBelow'); break;
        case 'addCol': tableOp('colRight'); break;
        case 'merge': emit('table', props.table.canMerge ? 'merge' : 'unmerge'); break;
        case 'editText': editShapeText(); break;
        default: break;
    }
}
function alignAct(v) {
    if (v.startsWith('distribute')) store.distribute(v.endsWith('h') ? 'h' : 'v');
    else if (v.startsWith('size')) store.matchSize(v.slice(5));
    else store.align(v);
    open.value = null;
}

// ---- keyboard: a toolbar with arrow key navigation ------------------------------------------------------
function items() {
    return [...bar.value.querySelectorAll(':scope > [data-bar-item]')];
}
function onKey(e) {
    if (e.key === 'Escape') {
        if (open.value) { open.value = null; e.stopPropagation(); bar.value.querySelector('[aria-expanded]')?.focus?.(); return; }
        emit('refocus');
        e.stopPropagation();
        return;
    }
    const t = e.target;
    if (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA') return;
    if (t.closest('.es-pop')) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            const list = [...t.closest('.es-pop').querySelectorAll('button:not([disabled])')];
            const i = list.indexOf(t);
            if (i >= 0) { e.preventDefault(); list[(i + (e.key === 'ArrowDown' ? 1 : -1) + list.length) % list.length].focus(); }
        }
        return;
    }
    const list = items();
    const i = list.findIndex((n) => n === t || n.contains(t));
    if (i < 0) return;
    const go = (j) => { e.preventDefault(); const target = list[(j + list.length) % list.length]; (target.matches('button') ? target : target.querySelector('button, input'))?.focus(); };
    if (e.key === 'ArrowRight') go(i + 1);
    else if (e.key === 'ArrowLeft') go(i - 1);
    else if (e.key === 'Home') go(0);
    else if (e.key === 'End') go(list.length - 1);
}
function focusFirst() {
    (items()[0]?.matches('button') ? items()[0] : items()[0]?.querySelector('button, input'))?.focus();
}
function keepEditorFocus(e) {
    const t = e.target;
    if (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA') return;
    e.preventDefault();
}
defineExpose({ close, focusFirst, openLink: () => toggleOpen('link') });
</script>

<template>
    <div
        ref="bar"
        class="es-bar"
        :class="{ 'es-bar-dragging': hidden }"
        :style="barStyle"
        :data-placement="pos.placement"
        data-es-ui
        role="toolbar"
        :aria-label="label"
        aria-orientation="horizontal"
        @keydown="onKey"
        @mousedown="keepEditorFocus"
    >
        <template v-for="c in controls" :key="c.id">
            <!-- text -->
            <template v-if="c.id === 'font'">
                <button :ref="(n) => (btnRefs[c.id] = n)" data-bar-item type="button" class="es-btn es-btn-font" :aria-expanded="open === c.id" aria-haspopup="listbox" :aria-label="`Font, ${fontName}`" @click="toggleOpen(c.id)">
                    <span>{{ fontName }}</span><StageIcon name="chevron" />
                </button>
                <div v-if="open === c.id" class="es-pop" :style="popStyle(leftOf(c.id))">
                    <ul class="es-list" role="listbox" aria-label="Fonts">
                        <li v-for="f in fontOptions" :key="f.value" role="presentation">
                            <button type="button" role="option" :aria-selected="val('font') === f.value" :aria-checked="val('font') === f.value" :style="f.stack ? { fontFamily: f.stack } : null" @click="pick('font', f.value)">{{ f.label }}</button>
                        </li>
                    </ul>
                </div>
            </template>
            <div v-else-if="c.id === 'size'" data-bar-item class="es-stepper" role="group" aria-label="Font size">
                <button type="button" aria-label="Smaller" @click="format.step('size', -1, elements)"><StageIcon name="minus" /></button>
                <input type="text" inputmode="decimal" :value="Math.round(val('size'))" aria-label="Font size" @change="sizeInput" @keydown.enter.prevent="sizeInput" />
                <button type="button" aria-label="Larger" @click="format.step('size', 1, elements)"><StageIcon name="plus" /></button>
            </div>
            <button v-else-if="c.id === 'bold' || c.id === 'italic' || c.id === 'underline'" data-bar-item type="button" class="es-btn" :aria-pressed="!!val(c.id)" :aria-label="c.label" :title="c.label" @click="format.toggle(c.id, elements)">
                <StageIcon :name="c.id" />
            </button>
            <template v-else-if="c.id === 'color' || c.id === 'fill' || c.id === 'stroke' || c.id === 'iconColor'">
                <button :ref="(n) => (btnRefs[c.id] = n)" data-bar-item type="button" class="es-btn" :aria-expanded="open === c.id" aria-haspopup="dialog" :aria-label="c.label" :title="c.label" @click="toggleOpen(c.id)">
                    <StageIcon :name="c.id === 'fill' ? 'fill' : c.id === 'stroke' ? 'stroke' : 'text'" />
                    <span class="es-swatch" :data-none="val(c.id) == null" :style="{ '--sw': css(val(c.id)) || 'transparent' }"></span>
                </button>
                <div v-if="open === c.id" class="es-pop" role="dialog" :aria-label="c.label" :style="popStyle(leftOf(c.id))">
                    <div class="es-pop-title">THEME</div>
                    <div class="es-swatches">
                        <button v-for="t in tokenColors" :key="t.token" type="button" :style="{ '--sw': t.css }" :aria-label="t.label" :aria-pressed="val(c.id) === t.token" :title="t.label" @click="pick(c.id, t.token)"></button>
                    </div>
                    <div class="es-pop-title">CUSTOM</div>
                    <form class="es-hex" @submit.prevent="applyHex(c.id)">
                        <input v-model="hexDraft" type="text" placeholder="#14171a" maxlength="7" spellcheck="false" aria-label="Hex colour" />
                        <button type="submit" class="es-btn es-btn-text">Apply</button>
                    </form>
                    <div v-if="c.id === 'fill' || c.id === 'stroke'" class="es-pop-actions">
                        <button type="button" @click="pick(c.id, null)">No {{ c.id }}</button>
                    </div>
                </div>
            </template>
            <template v-else-if="c.id === 'align'">
                <button :ref="(n) => (btnRefs[c.id] = n)" data-bar-item type="button" class="es-btn" :aria-expanded="open === c.id" aria-haspopup="menu" aria-label="Text alignment" title="Text alignment" @click="toggleOpen(c.id)">
                    <StageIcon :name="'align-' + (val('align') || 'left')" />
                </button>
                <div v-if="open === c.id" class="es-pop" :style="popStyle(leftOf(c.id))">
                    <ul class="es-list" role="menu">
                        <li v-for="a in ALIGNS" :key="a[0]" role="none"><button type="button" role="menuitemradio" :aria-checked="val('align') === a[0]" @click="pick('align', a[0])">{{ a[1] }}</button></li>
                    </ul>
                </div>
            </template>
            <template v-else-if="c.id === 'link'">
                <button :ref="(n) => (btnRefs[c.id] = n)" data-bar-item type="button" class="es-btn" :aria-pressed="!!val('link')" :aria-expanded="open === c.id" aria-haspopup="dialog" aria-label="Link" title="Link" @click="toggleOpen(c.id)">
                    <StageIcon name="link" />
                </button>
                <form v-if="open === c.id" class="es-pop" novalidate :style="popStyle(leftOf(c.id))" @submit.prevent="applyLink">
                    <div class="es-pop-title">{{ editing ? 'LINK FOR THE SELECTED TEXT' : 'LINK FOR THIS BOX' }}</div>
                    <input v-model="linkDraft" class="es-url" type="text" inputmode="url" autocomplete="off" spellcheck="false" placeholder="https://" aria-label="Link address" />
                    <div v-if="linkError" class="es-pop-title" role="alert" style="color: var(--bad)">{{ linkError }}</div>
                    <div class="es-pop-actions">
                        <button v-if="val('link')" type="button" @click="linkDraft = ''; applyLink()">Remove</button>
                        <button type="submit" class="es-primary">Apply</button>
                    </div>
                </form>
            </template>

            <!-- picture -->
            <button v-else-if="c.id === 'replace' || c.id === 'crop'" data-bar-item type="button" class="es-btn es-btn-text" :aria-label="c.label" @click="run(c.id)">
                <StageIcon :name="c.id" />{{ c.label }}
            </button>
            <template v-else-if="c.id === 'mask' || c.id === 'chartType' || c.id === 'dash'">
                <button :ref="(n) => (btnRefs[c.id] = n)" data-bar-item type="button" class="es-btn es-btn-text" :aria-expanded="open === c.id" aria-haspopup="menu" :aria-label="c.label" @click="toggleOpen(c.id)">
                    <StageIcon :name="c.id === 'mask' ? 'mask' : c.id === 'chartType' ? 'chart' : 'dash'" />{{ c.id === 'chartType' ? 'Type' : c.label }}<StageIcon name="chevron" />
                </button>
                <div v-if="open === c.id" class="es-pop" :style="popStyle(leftOf(c.id))">
                    <ul class="es-list" role="menu">
                        <li v-for="m in c.id === 'mask' ? MASKS : c.id === 'chartType' ? CHARTS : DASHES" :key="m[0]" role="none">
                            <button type="button" role="menuitemradio" :aria-checked="val(c.id) === m[0]" @click="pick(c.id, m[0])">{{ m[1] }}</button>
                        </li>
                    </ul>
                </div>
            </template>
            <button v-else-if="c.id === 'alt'" :ref="(n) => (btnRefs[c.id] = n)" data-bar-item type="button" class="es-btn es-btn-text" :aria-expanded="open === c.id" aria-haspopup="dialog" aria-label="Alt text" @click="toggleOpen(c.id)">
                <StageIcon name="comment" />Alt text
            </button>
            <form v-if="c.id === 'alt' && open === 'alt'" class="es-pop" :style="popStyle(leftOf('alt'))" @submit.prevent="applyAlt">
                <div class="es-pop-title">DESCRIBE THE PICTURE FOR SCREEN READERS</div>
                <textarea v-model="altDraft" maxlength="240" aria-label="Alt text" @keydown.enter.exact.prevent="applyAlt"></textarea>
                <div class="es-pop-actions"><button type="submit" class="es-primary">Save</button></div>
            </form>
            <template v-if="c.id === 'opacity'">
                <button :ref="(n) => (btnRefs[c.id] = n)" data-bar-item type="button" class="es-btn" :aria-expanded="open === c.id" aria-haspopup="dialog" :aria-label="`Opacity, ${val('opacity')} percent`" title="Opacity" @click="toggleOpen(c.id)">
                    <StageIcon name="opacity" /><span class="es-menu-key">{{ val('opacity') }}</span>
                </button>
                <div v-if="open === c.id" class="es-pop" :style="popStyle(leftOf(c.id))">
                    <div class="es-pop-row">
                        <input type="range" min="0" max="100" step="1" :value="val('opacity')" aria-label="Opacity" @input="set('opacity', $event.target.value)" />
                        <output>{{ val('opacity') }}%</output>
                    </div>
                </div>
            </template>

            <!-- shape, line, icon -->
            <div v-if="c.id === 'radius'" data-bar-item class="es-stepper" role="group" aria-label="Corner radius">
                <StageIcon name="radius" style="width: 18px; height: 18px; margin-left: 6px" />
                <input type="text" inputmode="numeric" :value="Math.round(val('radius'))" aria-label="Corner radius" @change="radiusInput" @keydown.enter.prevent="radiusInput" />
            </div>
            <div v-if="c.id === 'lineWidth'" data-bar-item class="es-stepper" role="group" aria-label="Line width">
                <button type="button" aria-label="Thinner" @click="format.step('lineWidth', -1, elements)"><StageIcon name="minus" /></button>
                <input type="text" inputmode="decimal" :value="val('lineWidth')" aria-label="Line width" @change="widthInput" @keydown.enter.prevent="widthInput" />
                <button type="button" aria-label="Thicker" @click="format.step('lineWidth', 1, elements)"><StageIcon name="plus" /></button>
            </div>
            <template v-if="c.id === 'ends'">
                <button :ref="(n) => (btnRefs[c.id] = n)" data-bar-item type="button" class="es-btn es-btn-text" :aria-expanded="open === c.id" aria-haspopup="dialog" aria-label="Line ends" @click="toggleOpen(c.id)">
                    <StageIcon name="ends" />Ends<StageIcon name="chevron" />
                </button>
                <div v-if="open === c.id" class="es-pop" :style="popStyle(leftOf(c.id))">
                    <div class="es-pop-title">START</div>
                    <ul class="es-list" role="menu"><li v-for="m in ENDS" :key="'s' + m[0]" role="none"><button type="button" role="menuitemradio" :aria-checked="val('ends').s === m[0]" @click="set('ends', { s: m[0] }, elements)">{{ m[1] }}</button></li></ul>
                    <div class="es-pop-title">END</div>
                    <ul class="es-list" role="menu"><li v-for="m in ENDS" :key="'e' + m[0]" role="none"><button type="button" role="menuitemradio" :aria-checked="val('ends').e === m[0]" @click="set('ends', { e: m[0] }, elements)">{{ m[1] }}</button></li></ul>
                </div>
            </template>
            <button v-if="c.id === 'editText'" data-bar-item type="button" class="es-btn es-btn-text" aria-label="Edit text" @click="run('editText')"><StageIcon name="text" />Edit text</button>
            <button v-if="c.id === 'swap'" data-bar-item type="button" class="es-btn es-btn-text" aria-label="Swap icon" @click="run('swap')"><StageIcon name="swap" />Swap</button>

            <!-- chart, table -->
            <button v-if="c.id === 'editData'" data-bar-item type="button" class="es-btn es-btn-text" aria-label="Edit data" @click="run('editData')"><StageIcon name="chart" />Edit data</button>
            <button v-if="c.id === 'addRow' || c.id === 'addCol'" data-bar-item type="button" class="es-btn es-btn-text" :aria-label="c.label" @click="run(c.id)"><StageIcon :name="c.id === 'addRow' ? 'row-add' : 'col-add'" />{{ c.id === 'addRow' ? 'Row' : 'Column' }}</button>
            <template v-if="c.id === 'del'">
                <button :ref="(n) => (btnRefs[c.id] = n)" data-bar-item type="button" class="es-btn" :aria-expanded="open === c.id" aria-haspopup="menu" aria-label="Delete" title="Delete" @click="toggleOpen(c.id)"><StageIcon name="trash" /><StageIcon name="chevron" /></button>
                <div v-if="open === c.id" class="es-pop" :style="popStyle(leftOf(c.id))">
                    <ul class="es-list" role="menu">
                        <li role="none"><button type="button" role="menuitem" :disabled="(table.rows || 0) < 2" @click="tableOp('delRow')">Delete row</button></li>
                        <li role="none"><button type="button" role="menuitem" :disabled="(table.cols || 0) < 2" @click="tableOp('delCol')">Delete column</button></li>
                        <li role="none"><button type="button" role="menuitem" @click="tableOp('delTable')">Delete table</button></li>
                    </ul>
                </div>
            </template>
            <button v-if="c.id === 'merge'" data-bar-item type="button" class="es-btn es-btn-text" :disabled="!table.canMerge && !table.canUnmerge" :aria-label="table.canMerge || !table.canUnmerge ? 'Merge cells' : 'Split cell'" :title="table.active ? 'Select two or more cells to merge them' : 'Double click the table, then select cells'" @click="run('merge')"><StageIcon name="merge" />{{ table.canUnmerge && !table.canMerge ? 'Split' : 'Merge' }}</button>

            <!-- media -->
            <button v-if="c.id === 'play'" data-bar-item type="button" class="es-btn es-btn-text" :aria-pressed="previewing === elements[0].id" aria-label="Play preview" @click="run('play')"><StageIcon :name="previewing === elements[0].id ? 'pause' : 'play'" />Preview</button>
            <button v-if="c.id === 'poster'" data-bar-item type="button" class="es-btn es-btn-text" aria-label="Poster" @click="run('poster')"><StageIcon name="poster" />Poster</button>
            <button v-if="c.id === 'loop'" data-bar-item type="button" class="es-btn" :aria-pressed="!!val('loop')" aria-label="Loop" title="Loop" @click="run('loop')"><StageIcon name="loop" /></button>

            <!-- group, several -->
            <button v-if="c.id === 'ungroup' || c.id === 'group'" data-bar-item type="button" class="es-btn es-btn-text" :aria-label="c.label" @click="run(c.id)"><StageIcon :name="c.id" />{{ c.label }}</button>
            <template v-if="c.id === 'alignMenu' || c.id === 'distribute' || c.id === 'matchSize'">
                <button :ref="(n) => (btnRefs[c.id] = n)" data-bar-item type="button" class="es-btn es-btn-text" :aria-expanded="open === c.id" aria-haspopup="menu" :aria-label="c.label" @click="toggleOpen(c.id)">
                    <StageIcon :name="c.id === 'alignMenu' ? 'align' : c.id === 'distribute' ? 'distribute' : 'size'" />{{ c.label }}<StageIcon name="chevron" />
                </button>
                <div v-if="open === c.id" class="es-pop" :style="popStyle(leftOf(c.id))">
                    <ul class="es-list" role="menu">
                        <template v-if="c.id === 'alignMenu'">
                            <li v-for="a in [['left', 'Left'], ['center', 'Centre'], ['right', 'Right'], ['top', 'Top'], ['middle', 'Middle'], ['bottom', 'Bottom']]" :key="a[0]" role="none"><button type="button" role="menuitem" @click="alignAct(a[0])">{{ a[1] }}</button></li>
                        </template>
                        <template v-else-if="c.id === 'distribute'">
                            <li role="none"><button type="button" role="menuitem" :disabled="elements.length < 3" @click="alignAct('distribute-h')">Horizontally</button></li>
                            <li role="none"><button type="button" role="menuitem" :disabled="elements.length < 3" @click="alignAct('distribute-v')">Vertically</button></li>
                        </template>
                        <template v-else>
                            <li role="none"><button type="button" role="menuitem" @click="alignAct('size-w')">Same width</button></li>
                            <li role="none"><button type="button" role="menuitem" @click="alignAct('size-h')">Same height</button></li>
                            <li role="none"><button type="button" role="menuitem" @click="alignAct('size-both')">Same size</button></li>
                        </template>
                    </ul>
                </div>
            </template>
        </template>
    </div>
</template>
