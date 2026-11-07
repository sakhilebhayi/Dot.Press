<script setup>
// Insert: text styles, shapes, icons, charts, tables and media. Every item adds through the store (one undo step) at the slide centre,
// or where the stage's insertion point is; items can also be dragged onto the stage.
// panelProps: {kind} from the dock. Opens the tab that kind belongs to.
import { computed, onMounted, ref, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import { Tabs, InlineNotice } from '../../../ui/controls/index.js';
import { lsGet, lsSet } from '../../../ui/controls/persist.js';
import { ensureFonts } from '../../../render/index.js';
import { INSERT_TABS, tabForKind } from './insertModel.js';
import { useInsert } from './useInsert.js';
import InsertText from './InsertText.vue';
import InsertShapes from './InsertShapes.vue';
import InsertIcons from './InsertIcons.vue';
import InsertCharts from './InsertCharts.vue';
import InsertTables from './InsertTables.vue';
import InsertMedia from './InsertMedia.vue';
import './insert.css';

const props = defineProps({ panelProps: { type: Object, default: null } });
const editor = useEditorContext();
const ins = useInsert(editor);
ins.listenForDrops();

const KEY = 'press:insert:tab';
const tab = ref(tabForKind(props.panelProps?.kind) || lsGet(KEY, 'text'));
if (!INSERT_TABS.some((t) => t.id === tab.value)) tab.value = 'text';
watch(tab, (t) => lsSet(KEY, t));
const focusKind = ref(props.panelProps?.kind || null);

// The dock can reopen the panel with another kind while it is already showing.
watch(() => props.panelProps, (p) => {
    const t = tabForKind(p?.kind);
    if (t) { tab.value = t; focusKind.value = p.kind; }
}, { deep: true });

const items = INSERT_TABS.map((t) => ({ ...t, id: t.id }));
const readOnly = computed(() => !ins.canEdit);
onMounted(() => { ensureFonts(); });
</script>

<template>
    <div class="ip" :class="{ 'is-readonly': readOnly }">
        <div class="ip-head">
            <Tabs v-model="tab" :items="items" label="What to insert" variant="pill" class="ip-tabs" />
            <InlineNotice v-if="readOnly" kind="info">You can view this deck but not change it.</InlineNotice>
        </div>
        <div class="ip-body" role="tabpanel" :aria-labelledby="`tab-${tab}`">
            <InsertText v-if="tab === 'text'" />
            <InsertShapes v-else-if="tab === 'shapes'" :focus-kind="focusKind" />
            <InsertIcons v-else-if="tab === 'icons'" />
            <InsertCharts v-else-if="tab === 'charts'" />
            <InsertTables v-else-if="tab === 'tables'" />
            <InsertMedia v-else :focus-kind="focusKind" />
        </div>
    </div>
</template>
