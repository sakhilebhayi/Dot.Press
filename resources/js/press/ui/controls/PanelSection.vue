<script setup>
// A titled group of controls. Collapsible (the state is remembered per `id` in localStorage), with an optional count or
// state word in Plex Mono and a single reset button.
import { computed, ref, useId } from 'vue';
import Ico from '../../editor/ui/Ico.vue';
import { lsGet, lsSet } from './persist.js';

const props = defineProps({
    id: { type: String, default: '' }, // 'text.font': panel id and section, the key of the remembered state
    title: { type: String, required: true },
    state: { type: [String, Number], default: '' }, // 'mixed', '3', 'on' ...
    collapsible: { type: Boolean, default: true },
    defaultOpen: { type: Boolean, default: true },
    resettable: { type: Boolean, default: false },
    resetLabel: { type: String, default: 'Reset' },
});
const emit = defineEmits(['reset', 'toggle']);
const KEY = 'press:panel:sections';
const open = ref(props.collapsible && props.id ? (lsGet(KEY, {})[props.id] ?? props.defaultOpen) : props.defaultOpen);
const uid = useId();
const bodyId = computed(() => `sec${uid}`);
function toggle() {
    if (!props.collapsible) return;
    open.value = !open.value;
    if (props.id) lsSet(KEY, { ...lsGet(KEY, {}), [props.id]: open.value });
    emit('toggle', open.value);
}
</script>

<template>
    <section class="c-sec" :class="{ 'is-closed': !open }">
        <header class="c-sec-h">
            <button v-if="collapsible" type="button" class="c-sec-t" :aria-expanded="open" :aria-controls="bodyId" @click="toggle">
                <Ico :name="open ? 'chevronDown' : 'chevronRight'" size="sm" /><span>{{ title }}</span><span v-if="state !== ''" class="c-sec-s pr-state">{{ state }}</span>
            </button>
            <h3 v-else class="c-sec-t c-sec-t--static"><span>{{ title }}</span><span v-if="state !== ''" class="c-sec-s pr-state">{{ state }}</span></h3>
            <span class="c-sec-actions"><slot name="actions" /><button v-if="resettable" type="button" class="c-sec-reset" @click="emit('reset')">{{ resetLabel }}</button></span>
        </header>
        <div v-show="open" :id="bodyId" class="c-sec-b"><slot /></div>
    </section>
</template>
