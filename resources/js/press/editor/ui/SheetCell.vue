<script setup>
// A slide in the sheets view: the thumbnail, its bead and number.
import { computed } from 'vue';
import Bead from './Bead.vue';
import LazyThumb from './LazyThumb.vue';
import { evidenceSummary, slideEvidence } from './evidence.js';

const props = defineProps({
    slide: { type: Object, required: true },
    number: { type: Number, required: true },
    current: { type: Boolean, default: false },
    selected: { type: Boolean, default: false },
    focusable: { type: Boolean, default: false },
    issues: { type: Number, default: 0 },
    dragging: { type: Boolean, default: false },
    deck: { type: Object, required: true },
    assets: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['activate', 'menu', 'drag']);
const ev = computed(() => slideEvidence(props.slide));
const beadState = computed(() => (props.current ? 'current' : props.issues > 0 ? 'issue' : ev.value.state));
const why = computed(() => evidenceSummary(ev.value, props.slide.hidden));
const aria = computed(() => `Slide ${props.number}: ${ev.value.title || 'untitled'}. ${ev.value.state}. ${why.value}`);
</script>

<template>
    <li
        class="sh-cell" :class="{ 'is-current': current, 'is-selected': selected, 'is-dragging': dragging, 'is-hidden': slide.hidden }"
        role="option" :id="`sp-${slide.id}`" :aria-selected="selected || current" :aria-current="current ? 'true' : undefined" :aria-label="aria"
        :tabindex="focusable ? 0 : -1" data-row="slide" :data-id="slide.id" :data-flip="`s:${slide.id}`"
        @click="emit('activate', $event)" @contextmenu.prevent="emit('menu', $event)" @pointerdown="emit('drag', $event)"
    >
        <div class="sh-thumb"><LazyThumb :slide="slide" :deck="deck" :assets="assets" /></div>
        <div class="sh-meta" :title="why">
            <Bead :state="beadState" :evidence="ev.state" :hidden="slide.hidden" />
            <span class="sp-num pr-state">{{ number }}</span>
            <span class="sh-title">{{ ev.title || 'Untitled slide' }}</span>
        </div>
    </li>
</template>
