<script setup>
// A font family from the 12 of the deck font list (press/fonts.js), each previewed in its own face, plus the theme roles
// "@heading" and "@body" that follow a theme change. modelValue: '@heading' | '@body' | 'Family' | 'mixed'.
import { computed, onMounted } from 'vue';
import SelectMenu from './SelectMenu.vue';
import { FONTS, ensureFonts, fontStack } from '../../fonts.js';
import { useDeckTheme } from './themeKit.js';

const props = defineProps({
    modelValue: { type: String, default: null },
    label: { type: String, default: 'Font' },
    hideLabel: { type: Boolean, default: false },
    theme: { type: Object, default: null },
    roles: { type: Boolean, default: true }, // offer @heading and @body
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const getTheme = useDeckTheme(props.theme);
onMounted(() => ensureFonts());
const cap = (s) => s[0].toUpperCase() + s.slice(1);
const options = computed(() => {
  const t = getTheme()?.t || {};
  return [
    ...(props.roles ? [
      { value: '@heading', label: 'Heading font', hint: t.heading || '', group: 'Theme', family: t.heading },
      { value: '@body', label: 'Body font', hint: t.body || '', group: 'Theme', family: t.body },
    ] : []),
    ...FONTS.map((f) => ({ value: f.family, label: f.family, hint: f.role, group: cap(f.category), family: f.family })),
  ];
});
const stack = (family) => fontStack(family) || undefined;
</script>

<template>
    <SelectMenu :model-value="modelValue" :options="options" :label="label" :hide-label="hideLabel" :hint="hint" :error="error" :disabled="disabled" searchable placeholder="Choose a font" @update:model-value="emit('update:modelValue', $event)">
        <template #value="{ option }"><span v-if="option" :style="{ fontFamily: stack(option.family) }">{{ option.label }}</span><span v-else-if="modelValue === 'mixed'" class="c-muted">Mixed</span><span v-else class="c-muted">{{ modelValue || 'Choose a font' }}</span></template>
        <template #option="{ option }">
            <span class="c-font"><span class="c-font-n" :style="{ fontFamily: stack(option.family) }">{{ option.label }}</span><span class="c-font-p" :style="{ fontFamily: stack(option.family) }" aria-hidden="true">Aa 123</span><span class="c-sel-oh">{{ option.group === 'Theme' ? option.hint : option.hint }}</span></span>
        </template>
    </SelectMenu>
</template>
