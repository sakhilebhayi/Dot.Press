<script setup>
// The report shown before a theme is applied, pinned to the bottom of the panel so choosing a card never moves the cards.
import { computed } from 'vue';
import { changeSentence } from './themeReport.js';
import { IconButton, InlineNotice } from '../../../ui/controls';

const props = defineProps({
    theme: { type: Object, required: true },
    report: { type: Object, required: true },
    changes: { type: Object, required: true },
    same: { type: Boolean, default: false }, // the deck already uses this theme unchanged
    disabled: { type: Boolean, default: false },
    warnings: { type: Array, default: () => [] },
    editLabel: { type: String, default: '' }, // 'Edit' for a team theme, 'Duplicate' for a built in one, '' for a brand kit
});
const emit = defineEmits(['apply', 'preview', 'cancel', 'edit']);
const name = computed(() => props.theme.kitName || props.theme.name);
const note = computed(() => {
    const parts = [changeSentence(props.changes)];
    if (props.report.partial) parts.push(`${props.report.partial} of those ${props.report.partial === 1 ? 'also has' : 'also have'} a fixed colour, which stays.`);
    return parts.filter(Boolean).join(' ');
});
</script>

<template>
    <section class="dz-bar" aria-label="Before you apply this theme">
        <p class="dz-bar-h">
            <span class="dz-bar-n">{{ name }}</span>
            <span class="dz-bar-k"><span class="pr-state pr-muted">{{ theme.kitName ? 'brand kit' : 'theme' }}</span><IconButton icon="close" label="Cancel" size="sm" @click="emit('cancel')" /></span>
        </p>
        <p v-if="same" class="pr-small">The deck already uses this theme.</p>
        <template v-else>
            <p class="pr-small dz-bar-s">{{ report.sentence }}</p>
            <p v-if="note" class="c-hint dz-bar-c">{{ note }}</p>
            <InlineNotice v-if="warnings.length" kind="warn">{{ warnings[0] }}</InlineNotice>
        </template>
        <div class="dz-bar-a">
            <button type="button" class="pr-btn pr-btn--sm pr-btn--primary" :disabled="disabled || same" @click="emit('apply')">Apply theme</button>
            <button type="button" class="pr-btn pr-btn--sm" @click="emit('preview')">Preview</button>
            <button v-if="editLabel" type="button" class="pr-btn pr-btn--sm" :aria-label="editLabel === 'Duplicate' ? 'Copy this theme to edit it' : 'Edit this theme'" @click="emit('edit')">{{ editLabel }}</button>
        </div>
    </section>
</template>
