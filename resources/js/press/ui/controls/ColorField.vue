<script setup>
// A colour: theme token swatches first (they follow a theme change), recent colours, a validated hex input with alpha, an
// optional "none" and an optional gradient. modelValue: '#rrggbb[aa]' | '@token' | gradient object | null | 'mixed'.
import { computed, ref } from 'vue';
import Field from './Field.vue';
import Popover from './Popover.vue';
import Segmented from './Segmented.vue';
import Slider from './Slider.vue';
import GradientField from './GradientField.vue';
import Ico from '../../editor/ui/Ico.vue';
import { isGradient, isToken, resolveColor } from '../../editor/core/theme.js';
import { gradientCss, joinAlpha, newGradient, normalizeHex, pushRecent, splitAlpha } from './colors.js';
import { MIXED } from './mixed.js';
import { lsGet, lsSet } from './persist.js';
import { TOKEN_GROUPS, tokenName, useDeckTheme } from './themeKit.js';

const props = defineProps({
    modelValue: { type: [String, Object], default: null },
    label: { type: String, default: '' },
    hideLabel: { type: Boolean, default: false },
    theme: { type: Object, default: null },
    allowNone: { type: Boolean, default: false },
    allowGradient: { type: Boolean, default: false },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);
const getTheme = useDeckTheme(props.theme);
const open = ref(false);
const btn = ref(null);
const RECENT = 'press:colors:recent';
const recent = ref(lsGet(RECENT, []));
const mixed = computed(() => props.modelValue === MIXED);
const grad = computed(() => isGradient(props.modelValue));
const css = (c) => resolveColor(c, getTheme());
const swatchStyle = computed(() => {
  const v = props.modelValue;
  if (mixed.value || v == null || v === '') return {};
  if (grad.value) return { backgroundImage: gradientCss(v, css) };
  return { backgroundColor: css(v) || 'transparent' };
});
const text = computed(() => {
  const v = props.modelValue;
  if (mixed.value) return 'Mixed';
  if (v == null || v === '') return 'None';
  if (grad.value) return 'Gradient';
  return isToken(v) ? tokenName(v) : String(v).toUpperCase();
});
const mode = computed({ get: () => (grad.value ? 'gradient' : 'solid'), set(m) { if (m === 'gradient' && !grad.value) emit('update:modelValue', newGradient(css(props.modelValue) || '#2563eb', css('@accent2') || '#f59e0b')); else if (m === 'solid' && grad.value) emit('update:modelValue', css(props.modelValue.stops?.[0]?.[1]) || '#000000'); } });
const resolved = computed(() => (grad.value || mixed.value ? null : css(props.modelValue)));
const parts = computed(() => (resolved.value ? splitAlpha(resolved.value) : { rgb: '#000000', alpha: 1 }));

const hexText = ref('');
const hexError = ref('');
function syncHex() { hexText.value = resolved.value ? resolved.value.toUpperCase() : ''; hexError.value = ''; }
function remember(hex) { recent.value = pushRecent(recent.value, hex); lsSet(RECENT, recent.value); }
function pick(c) { emit('update:modelValue', c); if (!isToken(c) && c) remember(c); syncHex(); }
function commitHex() {
  if (!hexText.value.trim()) { hexError.value = ''; return; }
  const n = normalizeHex(hexText.value);
  if (!n) { hexError.value = 'Use 3, 6 or 8 hex digits, like #2563EB'; return; }
  hexError.value = '';
  pick(n);
}
function setAlpha(pct) { const hex = joinAlpha(parts.value.rgb, pct / 100); if (hex) emit('update:modelValue', hex); syncHex(); }
function onOpened() { syncHex(); }
const eyedropper = typeof window !== 'undefined' && 'EyeDropper' in window;
async function eyedrop() { try { const r = await new window.EyeDropper().open(); pick(normalizeHex(r.sRGBHex)); } catch { /* cancelled */ } }
const isCurrent = (t) => props.modelValue === t;
</script>

<template>
    <Field v-slot="{ id, describedby }" :label="label" :hide-label="hideLabel" :hint="hint" :error="error">
        <button :id="id" ref="btn" type="button" class="c-color" :class="{ 'is-open': open }" aria-haspopup="dialog" :aria-expanded="open" :aria-describedby="describedby" :disabled="disabled" @click="open = !open">
            <span class="c-swatch" :class="{ 'is-none': !mixed && !grad && (modelValue == null || modelValue === '') }" :style="swatchStyle" aria-hidden="true"></span>
            <span class="c-color-t" :class="{ 'c-muted': mixed || modelValue == null }">{{ text }}</span>
            <Ico name="chevronDown" size="sm" />
        </button>
        <Popover v-model:open="open" :anchor="btn" :width="288" :label="`${label || 'Colour'} picker`" @opened="onOpened">
            <div class="c-cp">
                <Segmented v-if="allowGradient" v-model="mode" label="Fill type" hide-label :options="[{ value: 'solid', label: 'Solid' }, { value: 'gradient', label: 'Gradient' }]" />
                <GradientField v-if="grad" :model-value="modelValue" :theme="theme" hide-label label="Gradient" @update:model-value="emit('update:modelValue', $event)" />
                <template v-else>
                    <div v-for="g in TOKEN_GROUPS" :key="g.name" class="c-cp-g">
                        <p class="c-cp-h">{{ g.name }}</p>
                        <div class="c-cp-sw">
                            <button v-for="t in g.tokens" :key="t" type="button" class="c-sw" :class="{ 'is-on': isCurrent(t) }" :style="{ backgroundColor: css(t) || 'transparent' }" :aria-label="tokenName(t)" :aria-pressed="isCurrent(t)" :title="tokenName(t)" @click="pick(t)"></button>
                        </div>
                    </div>
                    <div v-if="recent.length" class="c-cp-g">
                        <p class="c-cp-h">Recent</p>
                        <div class="c-cp-sw">
                            <button v-for="c in recent" :key="c" type="button" class="c-sw" :class="{ 'is-on': isCurrent(c) }" :style="{ backgroundColor: c }" :aria-label="`Colour ${c}`" :title="c" @click="pick(c)"></button>
                        </div>
                    </div>
                    <div class="c-cp-hex">
                        <Field v-slot="f" label="Hex" :error="hexError">
                            <input :id="f.id" v-model="hexText" type="text" class="pr-field c-mono" maxlength="9" spellcheck="false" autocomplete="off" placeholder="#2563EB" :aria-invalid="f.invalid || undefined" :aria-describedby="f.describedby" @keydown.enter.prevent="commitHex" @blur="commitHex" />
                        </Field>
                        <button v-if="eyedropper" type="button" class="pr-btn pr-btn--icon" aria-label="Pick a colour from the screen" @click="eyedrop"><Ico name="search" size="sm" /></button>
                    </div>
                    <Slider v-if="resolved" label="Opacity" :model-value="Math.round(parts.alpha * 100)" :min="0" :max="100" :step="1" unit="%" @update:model-value="setAlpha" />
                    <button v-if="allowNone" type="button" class="pr-btn pr-btn--sm c-cp-none" @click="pick(null)">No colour</button>
                </template>
            </div>
        </Popover>
    </Field>
</template>
