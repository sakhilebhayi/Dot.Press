<script setup>
// Every chart type in CHART_TYPES with a live preview. Inserting adds a chart with the type's sample data at a sensible size, selects it
// and opens the chart panel (when one is registered) so the data can be replaced.
import { computed } from 'vue';
import { useEditorContext } from '../../context.js';
import { CHART_TYPES } from '../../../charts/index.js';
import ChartThumb from './ChartThumb.vue';
import { useInsert } from './useInsert.js';

const editor = useEditorContext();
const ins = useInsert(editor);
const theme = computed(() => editor.deck.value?.theme || null);
</script>

<template>
    <section class="ip-sec" aria-labelledby="ip-charts-h">
        <h3 id="ip-charts-h" class="ip-h">Charts <span class="pr-state">{{ CHART_TYPES.length }}</span></h3>
        <p class="ip-note">A chart starts with example numbers. Replace them in the chart panel, which opens next.</p>
        <ul class="ip-list" style="margin-top: 10px">
            <li v-for="c in CHART_TYPES" :key="c.id">
                <button
                    type="button" class="ip-item ip-chart" draggable="true" :aria-label="`Add ${c.label} chart. ${c.desc}`"
                    @click="ins.insertChart(c.id)"
                    @dragstart="ins.dragStart($event, { kind: 'insert', what: 'chart', type: c.id }, c.label)"
                    @dragend="ins.dragEnd"
                >
                    <ChartThumb :type="c.id" :theme="theme" :label="c.label" />
                    <span class="ip-chart-b"><span class="ip-name">{{ c.label }}</span><span class="ip-chart-d">{{ c.desc }}</span></span>
                </button>
            </li>
        </ul>
    </section>
</template>
