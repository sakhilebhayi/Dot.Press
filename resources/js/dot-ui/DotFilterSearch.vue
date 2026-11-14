<script setup>
// DotFilterSearch: the compact in-page filter (InfoDot's 36px .ix-input), NOT the pill. Same markup as
// blade/filter-search.blade.php. Props: name, placeholder, modelValue, label (aria-label). Other attributes fall onto the input.
defineProps({
    modelValue: { type: String, default: '' },
    name: { type: String, default: 'q' },
    placeholder: { type: String, default: 'Filter' },
    label: { type: String, default: 'Filter' },
});
const emit = defineEmits(['update:modelValue']);
defineOptions({ inheritAttrs: false });
</script>

<template>
    <input
        v-bind="$attrs"
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
