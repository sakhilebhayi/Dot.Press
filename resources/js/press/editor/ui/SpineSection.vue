<script setup>
// A section label on the rail: a knot on the line, the name, a count, collapse and rename.
import { nextTick, ref, watch } from 'vue';
import Ico from './Ico.vue';

const props = defineProps({
    row: { type: Object, required: true },
    renaming: { type: Boolean, default: false },
    readOnly: { type: Boolean, default: false },
});
const emit = defineEmits(['toggle', 'menu', 'rename', 'rename-start', 'rename-cancel']);
const input = ref(null);
const draft = ref('');
let settled = false;
watch(() => props.renaming, async (on) => {
    if (!on) return;
    settled = false;
    draft.value = props.row.title;
    await nextTick();
    input.value?.focus();
    input.value?.select();
}, { immediate: true });
function commit() { if (settled) return; settled = true; emit('rename', props.row.id, draft.value.trim()); }
function cancel() { settled = true; emit('rename-cancel'); }
</script>

<template>
    <li class="sp-sec" :class="{ 'is-collapsed': row.collapsed, 'is-empty': row.empty }" data-row="section" :data-flip="row.key" aria-hidden="true" @contextmenu.prevent="emit('menu', $event)">
        <span class="sp-knot"></span>
        <button class="sp-sec-toggle" type="button" tabindex="-1" data-nodrag :title="row.collapsed ? 'Expand section' : 'Collapse section'" @click.stop="emit('toggle')">
            <Ico :name="row.collapsed ? 'chevronRight' : 'chevronDown'" size="sm" />
        </button>
        <input v-if="renaming" ref="input" v-model="draft" class="sp-edit sp-edit--sec" maxlength="120" data-nodrag tabindex="-1" @keydown.enter.prevent.stop="commit" @keydown.esc.prevent.stop="cancel" @keydown.stop @blur="commit" @click.stop />
        <span v-else class="sp-sec-title" @dblclick.stop="!readOnly && row.id && emit('rename-start')">{{ row.title }}</span>
        <span class="sp-sec-count pr-state">{{ row.empty ? 'empty' : row.count }}</span>
        <button v-if="row.id && !readOnly" class="sp-more" type="button" tabindex="-1" data-nodrag title="Section actions" @click.stop="emit('menu', $event)"><Ico name="more" size="sm" /></button>
    </li>
</template>
