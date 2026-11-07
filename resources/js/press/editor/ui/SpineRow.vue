<script setup>
// One slide on the rail: bead, number, the claim (editable), and a thumbnail while it is the current slide.
import { computed, nextTick, ref, watch } from 'vue';
import Bead from './Bead.vue';
import Ico from './Ico.vue';
import LazyThumb from './LazyThumb.vue';
import { evidenceSummary, slideEvidence } from './evidence.js';

const props = defineProps({
    slide: { type: Object, required: true },
    number: { type: Number, required: true },
    sectionTitle: { type: String, default: '' },
    current: { type: Boolean, default: false },
    selected: { type: Boolean, default: false },
    focusable: { type: Boolean, default: false },
    issues: { type: Number, default: 0 },
    pos: { type: String, default: '' }, // first | last | only | ''
    renaming: { type: Boolean, default: false },
    dragging: { type: Boolean, default: false },
    deck: { type: Object, required: true },
    assets: { type: Object, default: () => ({}) },
    readOnly: { type: Boolean, default: false },
});
const emit = defineEmits(['activate', 'menu', 'insert', 'rename', 'rename-start', 'rename-cancel', 'hover', 'drag']);

const ev = computed(() => slideEvidence(props.slide));
const beadState = computed(() => (props.current ? 'current' : props.issues > 0 ? 'issue' : ev.value.state));
const stateWord = computed(() => {
    const parts = [];
    if (props.current) parts.push('here');
    parts.push(ev.value.state);
    if (props.issues > 0) parts.push(`${props.issues} ${props.issues === 1 ? 'issue' : 'issues'}`);
    if (props.slide.hidden) parts.push('hidden');
    return parts.join(' · ');
});
const why = computed(() => evidenceSummary(ev.value, props.slide.hidden) + (props.issues ? `. ${props.issues} open review ${props.issues === 1 ? 'issue' : 'issues'}` : ''));
const label = computed(() => ev.value.title);
const aria = computed(() => `Slide ${props.number}${props.sectionTitle ? ` in ${props.sectionTitle}` : ''}: ${label.value || 'untitled'}. ${ev.value.state}. ${why.value}`);

const input = ref(null);
const draft = ref('');
watch(() => props.renaming, async (on) => {
    if (!on) return;
    draft.value = props.slide.title || label.value || '';
    await nextTick();
    input.value?.focus();
    input.value?.select();
}, { immediate: true });
let settled = false;
function commit() {
    if (settled) return;
    settled = true;
    emit('rename', props.slide.id, draft.value.trim());
}
function cancel() { settled = true; emit('rename-cancel'); }
watch(() => props.renaming, (on) => { if (on) settled = false; });
</script>

<template>
    <li
        class="sp-row" :class="{ 'is-current': current, 'is-selected': selected, 'is-dragging': dragging, 'is-hidden': slide.hidden }"
        role="option" :id="`sp-${slide.id}`" :aria-selected="selected || current" :aria-current="current ? 'true' : undefined"
        :aria-label="aria" :tabindex="focusable ? 0 : -1" data-row="slide" :data-id="slide.id" :data-flip="`s:${slide.id}`" :data-pos="pos || null"
        @click="emit('activate', $event)" @contextmenu.prevent="emit('menu', $event)" @pointerdown="emit('drag', $event)" @mouseenter="emit('hover', $event, true)" @mouseleave="emit('hover', $event, false)"
    >
        <span class="sp-rail" data-handle>
            <span class="sp-bead" :data-why="why"><Bead :state="beadState" :evidence="ev.state" :hidden="slide.hidden" /></span>
        </span>
        <div class="sp-body">
            <div class="sp-line">
                <span class="sp-num pr-state">{{ number }}</span>
                <input
                    v-if="renaming" ref="input" v-model="draft" class="sp-edit" data-nodrag maxlength="255" aria-label="Slide claim"
                    @keydown.enter.prevent.stop="commit" @keydown.esc.prevent.stop="cancel" @keydown.stop @blur="commit" @click.stop @pointerdown.stop
                />
                <span v-else class="sp-title" :class="{ 'is-untitled': !label }" @dblclick.stop="!readOnly && emit('rename-start')">{{ label || 'Untitled slide' }}</span>
            </div>
            <div class="sp-meta pr-state">{{ stateWord }}</div>
            <div v-if="current" class="sp-thumb"><LazyThumb :slide="slide" :deck="deck" :assets="assets" eager /></div>
        </div>
        <!-- pointer shortcuts only (the keyboard has the context menu and Enter), so they are plain spans: a button inside an option is nested interactive content -->
        <span v-if="!readOnly" class="sp-ins" role="presentation" data-nodrag title="Add a slide here" @click.stop="emit('insert', $event)" @pointerdown.stop><Ico name="plus" size="sm" :stroke="2.2" /></span>
        <span class="sp-more" role="presentation" data-nodrag title="Slide actions" @click.stop="emit('menu', $event)" @pointerdown.stop><Ico name="more" size="sm" /></span>
    </li>
</template>
