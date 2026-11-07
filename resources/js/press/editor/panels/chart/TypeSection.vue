<script setup>
// Chart type: every type drawn with the user's own numbers (a small preview each), types that cannot show this data are
// disabled with the reason written on the card, and one suggested type with the reason, which is a proposal to accept.
// Changing type converts the data (convert.js) and reports what could not travel, with an Undo.
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import { chartToSvg, suggestChartType } from '../../../charts/index.js';
import { resolveTheme } from '../../../charts/theme.js';
import PanelSection from '../../../ui/controls/PanelSection.vue';
import Ico from '../../ui/Ico.vue';
import { useEditorContext } from '../../context.js';
import { convertType, typeChoices } from './convert.js';
import { normData } from './grid.js';
import { setOpt } from './format.js';

const props = defineProps({ t: { type: Object, required: true } });
const emit = defineEmits(['notice']);
const editor = useEditorContext();
const t = props.t;

const root = ref(null);
const previews = shallowRef({});
const chart = computed(() => t.chart.value);
const choices = computed(() => typeChoices(chart.value));
const current = computed(() => choices.value.find((c) => c.current));
const surface = computed(() => resolveTheme(t.theme.value).c.bg);

const suggestion = computed(() => {
    const s = suggestChartType(normData(chart.value));
    if (!s || s.type === chart.value.type) return null;
    const r = convertType(chart.value, s.type);
    if (!r.ok) return null;
    const label = choices.value.find((c) => c.id === s.type)?.label || s.type;
    return { ...s, label, because: s.why.charAt(0).toLowerCase() + s.why.slice(1) };
});

// ---- previews of the user's own data (kept small; recomputed a moment after the data settles)
function slim(c) {
    const d = c.data;
    const cats = (d.categories || []).slice(0, 24);
    const out = { ...c, data: { ...d, categories: cats, series: (d.series || []).slice(0, 4).map((s) => ({ ...s, values: (s.values || []).slice(0, 24), points: s.points ? s.points.slice(0, 80) : undefined })) } };
    if (d.totals) out.data.totals = d.totals.filter((i) => i < 24);
    if (d.kpis) out.data.kpis = d.kpis.slice(0, 4);
    return out;
}
function draw(c, id) {
    try {
        const s = slim(c);
        const quiet = { ...s, opt: { ...s.opt, title: '', sub: '', center: '', legend: 'none', labels: 'none', anim: false, ax: { x: { show: false }, y: { ...(s.opt.ax?.y || {}), show: false, title: '' } } } };
        return chartToSvg(quiet, { w: 240, h: 150, theme: t.theme.value, reduced: true, idPrefix: `cpt-${id}` });
    } catch {
        return '';
    }
}
let timer = null;
function compute() {
    const out = {};
    for (const ch of choices.value) {
        if (!ch.ok) continue;
        const r = convertType(chart.value, ch.id);
        out[ch.id] = draw(r.chart, ch.id);
    }
    previews.value = out;
}
watch(() => [chart.value?.type, chart.value?.data, t.theme.value], () => {
    clearTimeout(timer);
    timer = setTimeout(compute, Object.keys(previews.value).length ? 220 : 0);
}, { immediate: true });
onBeforeUnmount(() => clearTimeout(timer));

// ---- choosing
function pick(c) {
    if (!c.ok || c.current || !t.canEdit.value) return;
    const r = convertType(chart.value, c.id);
    if (!r.ok) return;
    t.commit(r.chart, { key: 'type', label: `Change to ${c.label.toLowerCase()}` });
    const lines = r.notes.slice();
    emit('notice', { kind: r.dropped.length ? 'warn' : 'ok', text: `Changed to ${c.label.toLowerCase()}.`, dropped: r.dropped, lines, undo: true });
    editor.announce?.(`Changed to ${c.label.toLowerCase()}${r.dropped.length ? `. ${r.dropped.map((d) => `${d.count} ${d.what.toLowerCase()} not kept`).join(', ')}` : ''}`);
}
function accept() {
    const s = suggestion.value;
    if (!s) return;
    const r = convertType(chart.value, s.type);
    if (!r.ok) return;
    const next = s.stacked && !r.chart.opt?.stacked ? setOpt(r.chart, 'stacked', true) : r.chart;
    t.commit(next, { key: 'type', label: `Change to ${s.label.toLowerCase()}` });
    emit('notice', { kind: r.dropped.length ? 'warn' : 'ok', text: `Changed to ${s.label.toLowerCase()}${s.stacked ? ', stacked' : ''}.`, dropped: r.dropped, lines: r.notes, undo: true });
    editor.announce?.(`Changed to ${s.label.toLowerCase()}`);
}

// ---- roving focus over the radios (arrows move focus, Space and Enter choose)
function onKey(e, i) {
    const radios = [...root.value.querySelectorAll('[role="radio"]')];
    const cols = Math.max(1, Math.round(root.value.clientWidth / (radios[0]?.offsetWidth || 1)));
    const to = { ArrowRight: i + 1, ArrowLeft: i - 1, ArrowDown: i + cols, ArrowUp: i - cols, Home: 0, End: radios.length - 1 }[e.key];
    if (to === undefined) return;
    e.preventDefault();
    radios[Math.max(0, Math.min(radios.length - 1, to))].focus();
}
</script>

<template>
    <PanelSection id="chart.type" title="Type" :state="current?.label || ''">
        <div v-if="suggestion" class="cpt-sug">
            <p class="cpt-sug-t"><span class="pr-state">Suggested:</span> {{ suggestion.label.toLowerCase() }}{{ suggestion.stacked ? ', stacked' : '' }}, because {{ suggestion.because }}</p>
            <button type="button" class="pr-btn pr-btn--sm" :disabled="!t.canEdit.value" @click="accept">Use {{ suggestion.label.toLowerCase() }}</button>
        </div>
        <div ref="root" class="cpt-grid" role="radiogroup" aria-label="Chart type">
            <button
                v-for="(c, i) in choices" :key="c.id" type="button" role="radio" class="cpt-card" :class="{ 'is-on': c.current, 'is-off': !c.ok }" :aria-checked="c.current" :aria-disabled="!c.ok || !t.canEdit.value"
                :aria-describedby="!c.ok ? `cpt-why-${c.id}` : undefined" :tabindex="c.current || (!current && i === 0) ? 0 : -1" :title="c.ok ? c.desc : c.why" @click="pick(c)" @keydown="onKey($event, i)"
            >
                <span v-if="c.ok" class="cpt-pv" :style="{ background: surface }" v-html="previews[c.id] || ''"></span>
                <span v-else class="cpt-pv is-none"><Ico name="cross-circle" size="sm" /></span>
                <span class="cpt-l"><i class="cpt-bead" aria-hidden="true"></i>{{ c.label }}</span>
                <span v-if="!c.ok" :id="`cpt-why-${c.id}`" class="cpt-why">{{ c.why }}</span>
            </button>
        </div>
        <p class="c-hint">{{ current?.desc }}</p>
    </PanelSection>
</template>

<style>
.cpt-sug { display: grid; gap: 8px; justify-items: start; padding: 10px 12px; border: 1px solid var(--line-strong); border-radius: var(--r-2); background: var(--raised); }
.cpt-sug-t::before { content: ''; display: inline-block; width: 9px; height: 9px; margin-right: 8px; border-radius: 50%; border: 2px solid var(--amber); vertical-align: -1px; }
.cpt-sug-t { margin: 0; font-size: var(--t-sm); line-height: 1.45; color: var(--ink); }
.cpt-sug-t .pr-state { color: var(--amber-ink); margin-right: 4px; }
.cpt-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(124px, 1fr)); gap: 8px; }
.cpt-card { display: grid; grid-template-rows: auto auto 1fr; gap: 6px; align-content: start; padding: 6px; border: 1px solid var(--line); border-radius: var(--r-2); background: var(--sheet); color: var(--ink); text-align: left; font: inherit; cursor: pointer; transition: border-color var(--d-1) var(--ease-snap), background-color var(--d-1) var(--ease-snap); }
.cpt-card:hover { border-color: var(--line-strong); background: var(--raised); }
.cpt-card:focus-visible { outline: 2px solid var(--focus); outline-offset: 1px; }
.cpt-card.is-on { border-color: var(--ink); box-shadow: inset 0 0 0 1px var(--ink); }
.cpt-card.is-off { cursor: not-allowed; background: transparent; border-style: dashed; }
.cpt-card.is-off:hover { background: transparent; border-color: var(--line); }
.cpt-pv { display: block; aspect-ratio: 8 / 5; overflow: hidden; border-radius: var(--r-1); border: 1px solid var(--line); }
.cpt-pv svg { display: block; width: 100%; height: 100%; }
.cpt-pv.is-none { aspect-ratio: auto; height: 34px; display: grid; place-items: center; background: repeating-linear-gradient(135deg, var(--raised) 0 6px, transparent 6px 12px); color: var(--faint); }
.cpt-l { display: flex; align-items: center; gap: 6px; font-size: var(--t-sm); font-weight: 600; }
.cpt-bead { flex: none; width: 10px; height: 10px; border-radius: 50%; border: 1.5px solid var(--ink-2); }
.cpt-card.is-on .cpt-bead { background: var(--ink); border-color: var(--ink); }
.cpt-card.is-off .cpt-bead { border-style: dashed; border-color: var(--faint); }
.cpt-why { font-size: var(--t-xs); line-height: 1.35; color: var(--muted); font-weight: 500; }
@media (pointer: coarse) { .cpt-card { padding: 8px; } }
@media (prefers-reduced-motion: reduce) { .cpt-card { transition: none; } }
</style>
