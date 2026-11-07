<script setup>
// Charts are drawn by resources/js/press/charts (chartToSvg) from the element's data only. A visually hidden data
// table (chartToTable) is the text alternative for assistive technology.
import { computed } from 'vue';
import { CHART_CSS, chartToSvg, chartToTable, describeChart } from '../charts/index.js';
import { useRenderCtx } from './context.js';
import { reducedMotion } from './env.js';
import { resolveFont } from './theme.js';

const props = defineProps({ el: { type: Object, required: true } });
const ctx = useRenderCtx();

// The chart module's entrance animation CSS goes into the page once, however many charts there are.
if (typeof document !== 'undefined' && !document.querySelector('style[data-press-chart]')) {
    const style = document.createElement('style');
    style.setAttribute('data-press-chart', '');
    style.textContent = CHART_CSS;
    document.head.appendChild(style);
}

const svg = computed(() => {
    try {
        const font = resolveFont(props.el.font ?? '@body', ctx.theme.value);
        return chartToSvg(props.el.chart, {
            w: Math.max(1, Math.round(+props.el.w || 1)),
            h: Math.max(1, Math.round(+props.el.h || 1)),
            theme: ctx.theme.value,
            font: font.stack,
            reduced: reducedMotion.value || ctx.mode.value === 'thumb',
            // animate only on the slide that is on screen, so a pre-rendered next slide does not play its entrance unseen
            animate: ctx.mode.value === 'present' && ctx.active.value && props.el.chart?.opt?.anim !== false,
            idPrefix: `${ctx.idPrefix}-${props.el.id}`,
        });
    } catch {
        return '';
    }
});

const table = computed(() => {
    try {
        return chartToTable(props.el.chart, { format: true });
    } catch {
        return null;
    }
});
const label = computed(() => {
    if (props.el.alt) return props.el.alt;
    try {
        return describeChart(props.el.chart);
    } catch {
        return 'Chart';
    }
});
</script>

<template>
    <figure class="pch" role="group" :aria-label="label">
        <div class="pch-svg" aria-hidden="true" v-html="svg"></div>
        <table v-if="table && table.rows" class="ps-sr-only">
            <caption>{{ label }}</caption>
            <thead v-if="table.header"><tr><th v-for="(h, i) in table.header" :key="i" scope="col">{{ h }}</th></tr></thead>
            <tbody><tr v-for="(r, i) in table.rows" :key="i"><template v-for="(c, j) in r" :key="j"><th v-if="j === 0" scope="row">{{ c }}</th><td v-else>{{ c }}</td></template></tr></tbody>
        </table>
    </figure>
</template>
