<script setup>
// A message inside a panel. kind: info | ok | warn | error. Always an icon and a word, never colour alone. Errors are announced.
import { computed } from 'vue';
import Ico from '../../editor/ui/Ico.vue';

const props = defineProps({ kind: { type: String, default: 'info' }, title: { type: String, default: '' } });
const icon = computed(() => ({ info: 'info', ok: 'check-circle', warn: 'warning', error: 'cross-circle' })[props.kind] || 'info');
</script>

<template>
    <div class="c-note" :class="`c-note--${kind}`" :role="kind === 'error' ? 'alert' : 'status'">
        <Ico :name="icon" size="sm" />
        <div class="c-note-b"><p v-if="title" class="c-note-t"><span class="c-note-w pr-state">{{ kind }}</span>{{ title }}</p><div class="c-note-x"><slot /></div></div>
    </div>
</template>
