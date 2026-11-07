<script setup>
// What people who cannot see the chart get: the generated description, an override for it (stored in the element's `alt`),
// and the table twin of the data.
import { computed } from 'vue';
import { chartToTable, describeChart } from '../../../charts/index.js';
import Field from '../../../ui/controls/Field.vue';
import PanelSection from '../../../ui/controls/PanelSection.vue';

const props = defineProps({ t: { type: Object, required: true } });
const t = props.t;
const description = computed(() => { try { return describeChart(t.chart.value); } catch { return ''; } });
const table = computed(() => { try { return chartToTable(t.chart.value, { format: true }); } catch { return null; } });
const alt = computed(() => t.el.value?.alt || '');
const tableCaption = computed(() => (table.value ? `${table.value.rows.length} ${table.value.rows.length === 1 ? 'row' : 'rows'}` : ''));
</script>

<template>
    <div class="cpa">
        <PanelSection id="chart.access" title="Screen reader description" :state="alt ? 'yours' : 'generated'">
            <div>
                <p class="c-label">Generated description</p>
                <p class="cpa-desc">{{ description }}</p>
            </div>
            <Field v-slot="{ id, describedby }" label="Your own description" hint="Replaces the generated one for people using a screen reader. Leave empty to keep it.">
                <textarea :id="id" :aria-describedby="describedby" class="pr-field" rows="3" maxlength="500" :value="alt" :disabled="!t.canEdit.value" placeholder="What should someone who cannot see this chart know?" @input="t.commitAlt($event.target.value)"></textarea>
            </Field>
            <button v-if="alt" type="button" class="pr-btn pr-btn--sm" :disabled="!t.canEdit.value" @click="t.commitAlt('')">Use the generated description</button>
        </PanelSection>
        <PanelSection id="chart.table" title="Table twin" :state="tableCaption" :default-open="false">
            <p class="c-hint">The same numbers as a table. It is what a screen reader reads, and what the chart is drawn from.</p>
            <div v-if="table" class="cpa-tw pr-scroll" tabindex="0" role="region" aria-label="Table of the chart data">
                <table class="cpa-t">
                    <thead v-if="table.header"><tr><th v-for="(h, i) in table.header" :key="i" scope="col">{{ h }}</th></tr></thead>
                    <tbody><tr v-for="(r, i) in table.rows" :key="i"><template v-for="(c, j) in r" :key="j"><th v-if="j === 0" scope="row">{{ c }}</th><td v-else>{{ c }}</td></template></tr></tbody>
                </table>
            </div>
        </PanelSection>
    </div>
</template>

<style>
.cpa-desc { margin: 0; font-size: var(--t-sm); line-height: 1.5; color: var(--ink-2); }
.cpa-tw { overflow: auto; max-height: 240px; border: 1px solid var(--line-strong); border-radius: var(--r-2); background: var(--sheet); }
.cpa-t { border-collapse: collapse; width: max-content; min-width: 100%; font-size: var(--t-xs); }
.cpa-t th, .cpa-t td { padding: 5px 8px; border-bottom: 1px solid var(--line); border-right: 1px solid var(--line); text-align: left; font-weight: 500; white-space: nowrap; }
.cpa-t thead th { position: sticky; top: 0; background: var(--raised); font-weight: 600; }
.cpa-t tbody th { font-weight: 600; background: var(--raised); }
.cpa-t td { font-family: var(--f-mono); text-align: right; }
</style>
