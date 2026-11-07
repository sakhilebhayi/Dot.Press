<script setup>
// One suggestion as a proposal: what it would change, who produced it, what to check, and Accept or Reject.
import { computed } from 'vue';
import { opLines } from '../intel/opsText.js';
import DiffView from '../intel/DiffView.vue';
import ModeChip from '../intel/ModeChip.vue';

const props = defineProps({ card: { type: Object, required: true }, doc: { type: Object, required: true }, canEdit: { type: Boolean, default: true }, busy: { type: Boolean, default: false } });
const emit = defineEmits(['accept', 'reject', 'copy']);
const lines = computed(() => (props.card.diff.kind === 'lines' || !props.card.ops.length ? [] : props.card.ops.some((o) => o.op !== 'el.set') ? opLines(props.card.ops, props.doc, { max: 4 }) : []));
</script>

<template>
    <article class="iq-prop" :aria-label="card.title">
        <div class="iq-prop-h">
            <h4 class="iq-prop-t">{{ card.title }}</h4>
            <ModeChip :mode="card.mode" />
            <span v-if="card.ai" class="iq-ai-badge">AI draft</span>
            <span v-if="card.slideNo" class="pr-state pr-muted">slide {{ card.slideNo }}</span>
        </div>
        <DiffView v-if="!card.isQuestion" :diff="card.diff" />
        <ul v-if="lines.length" class="iq-pm"><li v-for="(l, i) in lines" :key="i">{{ l }}</li></ul>
        <p v-if="card.needsLayout" class="iq-prop-s">Your elements move into the places of the new layout. Nothing is deleted.</p>
        <div v-if="card.unsupported" class="iq-warn" role="note"><span class="iq-warn-w pr-state">check</span>{{ card.unsupported }}</div>
        <p v-if="card.copyOnly" class="iq-prop-s">This was made from text you highlighted, so it cannot be put back for you. Copy it and paste it over your selection.</p>
        <div class="iq-prop-acts">
            <button v-if="card.canApply" type="button" class="pr-btn pr-btn--primary pr-btn--sm" :disabled="!canEdit || busy" :title="canEdit ? '' : 'You can read this deck, not change it'" @click="emit('accept', card)">Accept</button>
            <button v-else-if="card.copyOnly" type="button" class="pr-btn pr-btn--primary pr-btn--sm" @click="emit('copy', card)">Copy the new text</button>
            <button type="button" class="pr-btn pr-btn--sm" @click="emit('reject', card)">{{ card.isQuestion ? 'Dismiss' : 'Reject' }}</button>
        </div>
    </article>
</template>
