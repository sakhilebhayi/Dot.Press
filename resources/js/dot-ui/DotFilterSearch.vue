<script setup>
// DotFilterSearch: the compact in-page filter (InfoDot's 36px .ix-input), NOT the pill. Same markup as
// blade/filter-search.blade.php. Props: name, placeholder, modelValue, label (aria-label), id (default "dsr-filter-<name>").
// Other attributes fall onto the input.
import { computed } from 'vue';
const props = defineProps({
    modelValue: { type: String, default: '' },
    name: { type: String, default: 'q' },
    placeholder: { type: String, default: 'Filter' },
    label: { type: String, default: 'Filter' },
    id: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);
const inputId = computed(() => props.id || `dsr-filter-${String(props.name).replace(/[^A-Za-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || 'q'}`);
defineOptions({ inheritAttrs: false });
</script>

<template>
    <input
        v-bind="$attrs"
        :id="inputId"
        class="dsr-filter"
        type="search"
        :name="name"
        :value="modelValue"
        :placeholder="placeholder"
        :aria-label="label"
        autocomplete="off"
        @input="emit('update:modelValue', $event.target.value)"
    />
</template>
