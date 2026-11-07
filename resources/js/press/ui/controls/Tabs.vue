<script setup>
// Tabs. items: [{id, label, icon?, count?}]. Panels are yours: render them with v-if on modelValue and give them
// role="tabpanel" aria-labelledby="tab-<id>". variant: underline | pill.
import { ref } from 'vue';
import Ico from '../../editor/ui/Ico.vue';

const props = defineProps({
    modelValue: { type: String, default: null },
    items: { type: Array, required: true },
    label: { type: String, default: 'Tabs' },
    variant: { type: String, default: 'underline' },
});
const emit = defineEmits(['update:modelValue']);
const root = ref(null);
function key(e, i) {
  const n = props.items.length;
  const to = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: n - 1 }[e.key];
  if (to === undefined) return;
  e.preventDefault();
  const j = (to + n) % n;
  emit('update:modelValue', props.items[j].id);
  root.value.querySelectorAll('[role=tab]')[j]?.focus();
}
</script>

<template>
    <div ref="root" class="c-tabs" :class="`c-tabs--${variant}`" role="tablist" :aria-label="label">
        <button v-for="(t, i) in items" :id="`tab-${t.id}`" :key="t.id" type="button" role="tab" class="c-tab" :class="{ 'is-on': t.id === modelValue }" :aria-selected="t.id === modelValue" :tabindex="t.id === modelValue ? 0 : -1" @click="emit('update:modelValue', t.id)" @keydown="key($event, i)">
            <Ico v-if="t.icon" :name="t.icon" size="sm" /><span>{{ t.label }}</span><span v-if="t.count != null" class="pr-state c-tab-n">{{ t.count }}</span>
        </button>
    </div>
</template>
