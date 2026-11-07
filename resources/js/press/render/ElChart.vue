<script setup>
// Charts are drawn by resources/js/press/charts (chartToSvg) from the element's data only. A visually hidden data
// table is the text alternative for assistive technology.
import { computed } from 'vue';
import { chartToSvg, chartToTable } from '../charts/index.js';
import { useRenderCtx } from './context.js';
import { reducedMotion } from './env.js';
import { resolveFont } from './theme.js';

const props = defineProps({ el: { type: Object, required: true } });
const ctx = useRenderCtx();

const svg = computed(() => {
    try {
        const font = resolveFont(props.el.font ?? '@body', ctx.theme.value);
        return chartToSvg(props.el.chart, {
            w: Math.max(1, Math.round(+props.el.w || 1)),
            h: Math.max(1, Math.round(+props.el.h || 1)),
            theme: ctx.theme.value,
            font: font.stack,
            reduced: reducedMotion.value || ctx.mode.value === 'thumb',
            animate: ctx.mode.value === 'present' && props.el.chart?.opt?.anim !== false,
            idPrefix: `${ctx.idPrefix}-${props.el.id}`,
        });
    } catch (e) {
        return '';
    }
});

const table = computed(() => {
    try {
        return chartToTable(props.el.chart);
    } catch {
        return null;
    }
});
const tableHtml = computed(() => (typeof table.value === 'string' ? table.value : ''));
const label = computed(() => props.el.alt || props.el.chart?.opt?.title || 'Chart');
</script>

<template>
    <figure class="pch" :aria-label="label">
        <div class="pch-svg" aria-hidden="true" v-html="svg"></div>
        <div v-if="tableHtml" class="ps-sr-only" v-html="tableHtml"></div>
        <table v-else-if="table && table.rows" class="ps-sr-only">
            <caption>{{ table.caption || label }}</caption>
            <thead v-if="table.head"><tr><th v-for="(h, i) in table.head" :key="i" scope="col">{{ h }}</th></tr></thead>
            <tbody><tr v-for="(r, i) in table.rows" :key="i"><template v-for="(c, j) in r" :key="j"><th v-if="j === 0" scope="row">{{ c }}</th><td v-else>{{ c }}</td></template></tr></tbody>
        </table>
    </figure>
</template>
