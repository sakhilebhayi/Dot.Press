<script setup>
// A label, the control (default slot, gets {id, describedby, invalid}), a hint and an error, wired with for and aria-describedby.
import { computed, useId } from 'vue';

const props = defineProps({
    label: { type: String, default: '' },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    id: { type: String, default: '' },
    inline: { type: Boolean, default: false }, // label beside the control
    hideLabel: { type: Boolean, default: false }, // keep the label for screen readers only
});
const uid = useId();
const fid = computed(() => props.id || `c${uid}`);
const hintId = computed(() => `${fid.value}-hint`);
const errId = computed(() => `${fid.value}-err`);
const describedby = computed(() => [props.error ? errId.value : null, props.hint ? hintId.value : null].filter(Boolean).join(' ') || undefined);
</script>

<template>
    <div class="c-field" :class="{ 'is-inline': inline, 'has-error': !!error }">
        <label v-if="label" :for="fid" class="c-label" :class="{ 'pr-sr': hideLabel }">{{ label }}</label>
        <slot :id="fid" :describedby="describedby" :invalid="!!error" />
        <p v-if="error" :id="errId" class="c-error" role="alert"><span class="pr-bead pr-bead--bad pr-bead--sm" aria-hidden="true"></span>{{ error }}</p>
        <p v-else-if="hint" :id="hintId" class="c-hint">{{ hint }}</p>
    </div>
</template>
