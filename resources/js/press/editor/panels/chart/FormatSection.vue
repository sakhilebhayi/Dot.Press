<script setup>
// Formatting of one chart: titles, legend and labels, number format, axes, options and colours. Every control writes
// chart.opt (or a series colour) through useChartTarget.commit with its own undo key, so a typing burst or a scrub is one step.
import { computed } from 'vue';
import { niceTicks, normalizeChart } from '../../../charts/index.js';
import { isNum } from '../../../charts/util.js';
import ColorField from '../../../ui/controls/ColorField.vue';
import Field from '../../../ui/controls/Field.vue';
import IconButton from '../../../ui/controls/IconButton.vue';
import InlineNotice from '../../../ui/controls/InlineNotice.vue';
import NumberField from '../../../ui/controls/NumberField.vue';
import PanelSection from '../../../ui/controls/PanelSection.vue';
import Segmented from '../../../ui/controls/Segmented.vue';
import SelectMenu from '../../../ui/controls/SelectMenu.vue';
import Toggle from '../../../ui/controls/Toggle.vue';
import Ico from '../../ui/Ico.vue';
import { useEditorContext } from '../../context.js';
import { normData } from './grid.js';
import { CURRENCIES, FORMATS, axisWarnings, colorCollisions, controlsFor, numberSample, resetFormatting, seriesColorState, setOpt, setSeriesColor, setSliceColor } from './format.js';

const props = defineProps({ t: { type: Object, required: true } });
const emit = defineEmits(['notice']);
const editor = useEditorContext();
const t = props.t;

const chart = computed(() => t.chart.value);
const can = computed(() => controlsFor(chart.value.type));
const eff = computed(() => normalizeChart(chart.value).opt); // what the chart really uses (defaults filled in)
const data = computed(() => normData(chart.value));
const warnings = computed(() => axisWarnings(chart.value));
const sample = computed(() => numberSample(eff.value, eff.value.fmt === 'pct' ? 12.5 : 1234.5));
const colors = computed(() => seriesColorState(chart.value));
const collisions = computed(() => (can.value.seriesColors && colors.value.overrides ? colorCollisions(chart.value, t.theme.value) : []));

function set(path, value, label) {
    t.commit(setOpt(chart.value, path, value), { key: `opt:${path}`, label: label || 'Format chart' });
}
const text = (path, label) => (e) => set(path, e.target.value, label);

const LEGEND = [{ value: 'none', label: 'None' }, { value: 'bottom', label: 'Bottom' }, { value: 'right', label: 'Right' }, { value: 'top', label: 'Top' }];
const labelOptions = computed(() => [{ value: 'none', label: 'None' }, { value: 'value', label: 'Value' }, ...(can.value.labelsPercent ? [{ value: 'percent', label: 'Percent' }] : [])]);
const DECIMALS = [{ value: 'auto', label: 'Automatic' }, { value: 0, label: '0 (1,235)' }, { value: 1, label: '1 (1,234.5)' }, { value: 2, label: '2 (1,234.50)' }, { value: 3, label: '3 (1,234.500)' }];
const decValue = computed(() => (Number.isInteger(chart.value.opt?.dec) ? chart.value.opt.dec : 'auto'));

// value axis range
const yMin = computed(() => chart.value.opt?.ax?.y?.min);
const yMax = computed(() => chart.value.opt?.ax?.y?.max);
function dataTop() {
    let top = 0;
    for (const s of data.value.series) {
        for (const v of s.values) if (isNum(v)) top = Math.max(top, v);
        for (const p of s.points || []) if (isNum(p.y)) top = Math.max(top, p.y);
    }
    return top;
}
function rangeMode(which, mode) {
    if (mode === 'auto') { set(`ax.y.${which}`, null, 'Axis range'); return; }
    set(`ax.y.${which}`, which === 'min' ? 0 : niceTicks(0, dataTop() || 1, 5).max, 'Axis range');
}

// colours
const sliceCount = computed(() => Math.min(8, data.value.categories.length));
const sliceColors = computed(() => Array.from({ length: sliceCount.value }, (_, i) => (chart.value.opt?.colors || [])[i] || null));
const tokenFor = (i) => (i < 8 ? `@chart${i + 1}` : null);
function seriesColor(i, v) { t.commit(setSeriesColor(chart.value, i, v), { key: `color:${i}`, label: 'Series colour' }); }
function sliceColor(i, v) { t.commit(setSliceColor(chart.value, i, v, sliceCount.value), { key: `slice:${i}`, label: 'Slice colour' }); }
const colourSeries = computed(() => (can.value.singleColor ? data.value.series.slice(0, 1) : data.value.series));
const overrideCount = computed(() => colors.value.overrides + (chart.value.opt?.colors || []).filter(Boolean).length);

function reset() {
    const r = resetFormatting(chart.value);
    if (!r.changed.length) { editor.toast?.('Formatting is already at its defaults', { kind: 'info' }); return; }
    t.commit(r.chart, { key: 'reset', label: 'Reset formatting' });
    emit('notice', { kind: 'ok', text: `Formatting reset: ${r.changed.join(', ')}. The title, subtitle and data are unchanged.`, undo: true });
    editor.announce?.('Formatting reset');
}
</script>

<template>
    <div class="cpf">
        <PanelSection id="chart.text" title="Titles" :state="chart.opt?.title ? 'set' : ''">
            <Field label="Title" v-slot="{ id }">
                <input :id="id" class="pr-field" :value="chart.opt?.title || ''" :disabled="!t.canEdit.value" maxlength="140" autocomplete="off" @input="text('title', 'Chart title')($event)" />
            </Field>
            <Field label="Subtitle" v-slot="{ id }">
                <input :id="id" class="pr-field" :value="chart.opt?.sub || ''" :disabled="!t.canEdit.value" maxlength="220" autocomplete="off" @input="text('sub', 'Chart subtitle')($event)" />
            </Field>
            <Field v-if="can.center" label="Centre text" hint="Empty shows the total." v-slot="{ id, describedby }">
                <input :id="id" :aria-describedby="describedby" class="pr-field" :value="chart.opt?.center || ''" :disabled="!t.canEdit.value" maxlength="40" autocomplete="off" @input="text('center', 'Centre text')($event)" />
            </Field>
        </PanelSection>

        <PanelSection v-if="can.legend || can.labels" id="chart.legend" title="Legend and labels" :state="can.legend ? eff.legend : ''">
            <Segmented v-if="can.legend" :model-value="eff.legend" :options="LEGEND" label="Legend" :disabled="!t.canEdit.value" :hint="can.legendNote" @update:model-value="(v) => set('legend', v, 'Legend')" />
            <Segmented v-if="can.labels" :model-value="eff.labels" :options="labelOptions" label="Labels on the chart" :disabled="!t.canEdit.value" @update:model-value="(v) => set('labels', v, 'Labels')" />
        </PanelSection>

        <PanelSection id="chart.numbers" title="Numbers" :state="sample">
            <SelectMenu :model-value="eff.fmt" :options="FORMATS" label="Number format" :disabled="!t.canEdit.value" @update:model-value="(v) => set('fmt', v, 'Number format')" />
            <Segmented v-if="eff.fmt === 'cur'" :model-value="eff.cur" :options="CURRENCIES.map((c) => ({ value: c, label: c, title: `Currency symbol ${c}` }))" label="Currency symbol" :disabled="!t.canEdit.value" @update:model-value="(v) => set('cur', v, 'Currency symbol')" />
            <SelectMenu :model-value="decValue" :options="DECIMALS" label="Decimals" :disabled="!t.canEdit.value" @update:model-value="(v) => set('dec', v === 'auto' ? null : v, 'Decimals')" />
            <p class="cpf-ex"><span class="pr-state">Example</span><span class="cpf-ex-v">{{ sample }}</span><span class="c-hint">{{ eff.fmt === 'pct' ? 'Values are percentage points: 12.5 shows as 12.5%.' : eff.fmt === 'compact' ? 'Large numbers shorten to K, M and B.' : eff.fmt === 'cur' ? 'Symbol, then the number.' : 'Plain numbers with thousands separators.' }}</span></p>
        </PanelSection>

        <PanelSection v-if="can.axes" id="chart.axes" title="Axes" :state="warnings.some((w) => w.kind !== 'info') ? 'check' : ''">
            <Field label="Category axis title" v-slot="{ id }">
                <input :id="id" class="pr-field" :value="chart.opt?.ax?.x?.title || ''" :disabled="!t.canEdit.value" maxlength="80" autocomplete="off" @input="text('ax.x.title', 'Axis title')($event)" />
            </Field>
            <Field label="Value axis title" v-slot="{ id }">
                <input :id="id" class="pr-field" :value="chart.opt?.ax?.y?.title || ''" :disabled="!t.canEdit.value" maxlength="80" autocomplete="off" @input="text('ax.y.title', 'Axis title')($event)" />
            </Field>
            <Toggle :model-value="eff.ax.x.show" label="Show the category axis" :disabled="!t.canEdit.value" @update:model-value="(v) => set('ax.x.show', v, 'Axis')" />
            <Toggle :model-value="eff.ax.y.show" label="Show the value axis" :disabled="!t.canEdit.value" @update:model-value="(v) => set('ax.y.show', v, 'Axis')" />
            <template v-if="can.yRange">
                <div class="cpf-range">
                    <Segmented :model-value="isNum(yMin) ? 'custom' : 'auto'" :options="[{ value: 'auto', label: 'Auto' }, { value: 'custom', label: 'Set' }]" label="Minimum" :disabled="!t.canEdit.value" @update:model-value="(m) => rangeMode('min', m)" />
                    <NumberField v-if="isNum(yMin)" :model-value="yMin" label="Minimum value" hide-label :step="1" :disabled="!t.canEdit.value" @update:model-value="(v) => set('ax.y.min', v, 'Axis range')" />
                </div>
                <div class="cpf-range">
                    <Segmented :model-value="isNum(yMax) ? 'custom' : 'auto'" :options="[{ value: 'auto', label: 'Auto' }, { value: 'custom', label: 'Set' }]" label="Maximum" :disabled="!t.canEdit.value" @update:model-value="(m) => rangeMode('max', m)" />
                    <NumberField v-if="isNum(yMax)" :model-value="yMax" label="Maximum value" hide-label :step="1" :disabled="!t.canEdit.value" @update:model-value="(v) => set('ax.y.max', v, 'Axis range')" />
                </div>
                <InlineNotice v-for="w in warnings" :key="w.text" :kind="w.kind === 'error' ? 'error' : w.kind === 'warn' ? 'warn' : 'info'">{{ w.text }}</InlineNotice>
                <p v-if="!warnings.length" class="c-hint">Bars, columns and areas start at zero unless you set a minimum.</p>
            </template>
        </PanelSection>

        <PanelSection v-if="can.stacked || can.smooth || can.grid || can.other || can.animate" id="chart.options" title="Options">
            <Toggle v-if="can.stacked" :model-value="eff.stacked" label="Stacked" hint="Parts add up to a total." :disabled="!t.canEdit.value" @update:model-value="(v) => set('stacked', v, 'Stacked')" />
            <Toggle v-if="can.smooth" :model-value="eff.smooth" label="Smooth lines" hint="Never overshoots the data." :disabled="!t.canEdit.value" @update:model-value="(v) => set('smooth', v, 'Smooth lines')" />
            <Toggle v-if="can.grid" :model-value="eff.grid" label="Gridlines" :disabled="!t.canEdit.value" @update:model-value="(v) => set('grid', v, 'Gridlines')" />
            <Toggle v-if="can.other" :model-value="eff.other" label="Fold small slices into Other" hint="Slices past the eighth." :disabled="!t.canEdit.value" @update:model-value="(v) => set('other', v, 'Other slice')" />
            <Toggle :model-value="eff.anim" label="Animate when presenting" hint="Reduced motion settings are always honoured." :disabled="!t.canEdit.value" @update:model-value="(v) => set('anim', v, 'Chart animation')" />
        </PanelSection>

        <PanelSection v-if="can.seriesColors || can.sliceColors" id="chart.colors" title="Colours" :state="overrideCount ? `${overrideCount} set` : 'theme'">
            <p class="c-hint">Colours follow the deck theme. Set one only when the colour means something.</p>
            <template v-if="can.seriesColors">
                <div v-for="(s, i) in colourSeries" :key="i" class="cpf-col">
                    <ColorField :model-value="colors.colors[i] || tokenFor(i)" :label="s.name" :disabled="!t.canEdit.value" :theme="t.theme.value" @update:model-value="(v) => seriesColor(i, v)" />
                    <IconButton v-if="colors.colors[i]" icon="refresh" :label="`Use the theme colour for ${s.name}`" size="sm" :disabled="!t.canEdit.value" @click="seriesColor(i, null)" />
                </div>
                <InlineNotice v-for="c in collisions" :key="c.a + c.b" kind="warn">{{ c.a }} and {{ c.b }} are hard to tell apart by colour. The legend and the marker shapes still separate them, but a clearer difference helps.</InlineNotice>
            </template>
            <template v-else>
                <div v-for="(c, i) in sliceColors" :key="i" class="cpf-col">
                    <ColorField :model-value="c || tokenFor(i)" :label="data.categories[i] || `Slice ${i + 1}`" :disabled="!t.canEdit.value" :theme="t.theme.value" @update:model-value="(v) => sliceColor(i, v)" />
                    <IconButton v-if="c" icon="refresh" :label="`Use the theme colour for ${data.categories[i] || 'slice ' + (i + 1)}`" size="sm" :disabled="!t.canEdit.value" @click="sliceColor(i, null)" />
                </div>
            </template>
        </PanelSection>

        <div class="cpf-reset">
            <button type="button" class="pr-btn pr-btn--sm" :disabled="!t.canEdit.value" @click="reset"><Ico name="restore" size="sm" />Reset formatting</button>
            <p class="c-hint">Puts legend, labels, number format, axes and colours back to the defaults. Titles and data stay.</p>
        </div>
    </div>
</template>

<style>
.cpf-ex { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; margin: 0; }
.cpf-ex .pr-state { color: var(--muted); }
.cpf-ex-v { font-family: var(--f-mono); font-size: var(--t-md); color: var(--ink); }
.cpf-ex .c-hint { flex-basis: 100%; }
.cpf-range { display: grid; grid-template-columns: minmax(0, 1fr); gap: 6px; }
.cpf-col { display: flex; align-items: flex-end; gap: 6px; }
.cpf-col .c-field { flex: 1; min-width: 0; }
.cpf-reset { display: grid; gap: 8px; justify-items: start; padding: 14px 16px 20px; }
</style>
