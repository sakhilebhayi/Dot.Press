<script setup>
// A group of mutually exclusive choices (radiogroup). options: [{value, label?, icon?, title?}]. Icon only options need a title.
import { nextTick, ref } from 'vue';
import Ico from '../../editor/ui/Ico.vue';
import Field from './Field.vue';

const props = defineProps({
    modelValue: { type: [String, Number, Boolean], default: null }, // 'mixed' leaves nothing selected
    options: { type: Array, required: true },
    label: { type: String, default: '' },
    hideLabel: { type: Boolean, default: false },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    block: { type: Boolean, default: true }, // fill the width
});
const emit = defineEmits(['update:modelValue']);
const root = ref(null);
const active = (i) => props.options[i].value === props.modelValue;
const tabIndex = (i) => (props.options.some((o) => o.value === props.modelValue) ? (active(i) ? 0 : -1) : (i === 0 ? 0 : -1));
async function key(e, i) {
  const n = props.options.length;
  const to = { ArrowRight: i + 1, ArrowDown: i + 1, ArrowLeft: i - 1, ArrowUp: i - 1, Home: 0, End: n - 1 }[e.key];
  if (to === undefined) return;
  e.preventDefault();
  const j = (to + n) % n;
  emit('update:modelValue', props.options[j].value);
  await nextTick();
  root.value.querySelectorAll('[role=radio]')[j]?.focus();
}
</script>

<template>
    <Field v-slot="{ id, describedby }" :label="label" :hide-label="hideLabel" :hint="hint" :error="error">
        <div :id="id" ref="root" class="c-seg" :class="{ 'is-block': block, 'is-disabled': disabled }" role="radiogroup" :aria-label="label || undefined" :aria-describedby="describedby">
            <button
                v-for="(o, i) in options" :key="String(o.value)" type="button" role="radio" class="c-seg-b" :class="{ 'is-on': active(i) }" :aria-checked="active(i)" :aria-label="o.label ? undefined : o.title"
                :title="o.title || undefined" :tabindex="tabIndex(i)" :disabled="disabled" @click="emit('update:modelValue', o.value)" @keydown="key($event, i)"
            >
                <Ico v-if="o.icon" :name="o.icon" size="sm" /><span v-if="o.label">{{ o.label }}</span>
            </button>
        </div>
    </Field>
</template>
