<script setup>
// The Chart panel (R-40): type, data, format and accessibility for the selected chart element.
//   panelProps { id }      edit the chart element with that id (on the current slide)
//   panelProps { dialog }  also open the large "Edit data" dialog
// A change that converts or replaces data shows a notice at the top with what was kept, what was not, and Undo.
import { computed, ref, watch } from 'vue';
import Dialog from '../../../ui/controls/Dialog.vue';
import EmptyState from '../../../ui/controls/EmptyState.vue';
import InlineNotice from '../../../ui/controls/InlineNotice.vue';
import PanelSection from '../../../ui/controls/PanelSection.vue';
import { useEditorContext } from '../../context.js';
import AccessSection from './AccessSection.vue';
import DataEditor from './DataEditor.vue';
import FormatSection from './FormatSection.vue';
import ImportBox from './ImportBox.vue';
import TypeSection from './TypeSection.vue';
import { dataSummary } from './grid.js';
import { useChartTarget } from './useChartTarget.js';

const props = defineProps({ panelProps: { type: Object, default: null } });
const editor = useEditorContext();
const t = useChartTarget(editor, () => props.panelProps);

const notice = ref(null);
const epoch = ref(0);
const dialog = ref(false);
const summary = computed(() => (t.chart.value ? dataSummary(t.chart.value) : ''));

watch(() => props.panelProps, (p) => { if (p?.dialog && t.el.value) dialog.value = true; }, { immediate: true });
watch(() => t.el.value?.id, () => { notice.value = null; });
watch(() => t.el.value, (el) => { if (!el) dialog.value = false; });

// Undo reverses the latest step, so a notice with Undo is only kept while the chart is exactly as that change left it.
function say(n) {
    notice.value = n ? { ...n, sig: JSON.stringify(t.chart.value) } : null;
    if (n?.text) editor.announce?.(n.text);
}
watch(() => t.chart.value, (c) => { if (notice.value && JSON.stringify(c) !== notice.value.sig) notice.value = null; });
function undo() {
    t.undo();
    epoch.value += 1;
    notice.value = null;
}
function closeDialog() {
    dialog.value = false;
    if (editor.ui?.inspector?.props?.dialog) editor.ui.inspector.props = null;
}
const dropText = (d) => `${d.count} ${d.what.toLowerCase()}${d.detail ? ` (${d.detail})` : ''}`;
</script>

<template>
    <div class="cp">
        <EmptyState v-if="!t.chart.value" compact title="No chart selected." body="Select a chart on the slide to change its type, data and format." />
        <template v-else>
            <InlineNotice v-if="t.multi.value" kind="info">{{ editor.selected.value.length }} elements are selected. This panel edits the first chart.</InlineNotice>
            <InlineNotice v-if="!t.canEdit.value" kind="info">You can look at this chart but not change it.</InlineNotice>
            <div v-if="notice" class="cp-notice">
                <InlineNotice :kind="notice.kind === 'warn' ? 'warn' : 'ok'">
                    {{ notice.text }}
                    <template v-if="notice.dropped && notice.dropped.length">
                        Not kept: {{ notice.dropped.map(dropText).join('; ') }}.
                    </template>
                    <ul v-if="notice.lines && notice.lines.length" class="cp-lines"><li v-for="l in notice.lines" :key="l">{{ l }}</li></ul>
                    <span class="cp-act">
                        <button v-if="notice.undo" type="button" class="pr-btn pr-btn--sm" @click="undo">Undo</button>
                        <button type="button" class="pr-btn pr-btn--sm pr-btn--ghost" @click="notice = null">Dismiss</button>
                    </span>
                </InlineNotice>
            </div>

            <TypeSection :t="t" @notice="say" />

            <PanelSection id="chart.data" title="Data" :state="summary">
                <template #actions>
                    <button type="button" class="pr-btn pr-btn--sm pr-btn--ghost cp-big" @click="dialog = true">Larger</button>
                </template>
                <DataEditor :t="t" dense :epoch="epoch" @notice="say" />
            </PanelSection>
            <PanelSection id="chart.import" title="Import" :default-open="false" state="paste or file">
                <ImportBox :t="t" @notice="say" @applied="epoch += 1" />
            </PanelSection>

            <FormatSection :t="t" @notice="say" />
            <AccessSection :t="t" />
        </template>

        <Dialog v-if="t.chart.value" v-model:open="dialog" title="Edit data" size="lg" @closed="closeDialog">
            <div class="cp-dlg">
                <DataEditor :t="t" :dense="false" :epoch="epoch" @notice="say" />
                <details class="cp-dlg-imp">
                    <summary>Import from pasted text or a file</summary>
                    <ImportBox :t="t" @notice="say" @applied="epoch += 1" />
                </details>
                <InlineNotice v-if="notice" :kind="notice.kind === 'warn' ? 'warn' : 'ok'">
                    {{ notice.text }}
                    <template v-if="notice.dropped && notice.dropped.length"> Not kept: {{ notice.dropped.map(dropText).join('; ') }}.</template>
                    <span class="cp-act"><button v-if="notice.undo" type="button" class="pr-btn pr-btn--sm" @click="undo">Undo</button></span>
                </InlineNotice>
            </div>
            <template #footer="{ close }">
                <span class="cp-dlg-sum pr-state">{{ summary }}</span>
                <button type="button" class="pr-btn pr-btn--primary" @click="close">Done</button>
            </template>
        </Dialog>
    </div>
</template>

<style>
.cp { display: grid; align-content: start; min-width: 0; padding-bottom: 24px; }
.cp > .c-note { margin: 12px 16px 0; }
.cp-notice { padding: 12px 16px 0; position: sticky; top: 0; z-index: 5; background: linear-gradient(var(--paper) 80%, transparent); padding-bottom: 8px; }
.cp-lines { margin: 4px 0 0; padding-left: 16px; }
.cp-act { display: flex; gap: 6px; margin-top: 8px; }
.cp-act:empty { display: none; }
.cp-dlg { display: grid; gap: 14px; }
.cp-dlg-imp { border-top: 1px solid var(--line); padding-top: 10px; }
.cp-dlg-imp > summary { cursor: pointer; font-size: var(--t-sm); font-weight: 600; min-height: 32px; display: flex; align-items: center; }
.cp-dlg-imp[open] > summary { margin-bottom: 10px; }
.cp-dlg-sum { margin-right: auto; align-self: center; color: var(--muted); }
.c-dlg--lg:has(.cp-dlg) { width: min(1040px, 100%); max-height: min(860px, 100%); }
</style>
