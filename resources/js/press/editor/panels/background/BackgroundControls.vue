<script setup>
// The controls for one background value: a kind (theme, colour, gradient, image) and what that kind needs. Used for a
// slide, for the deck default and inside the custom theme editor. Pure value in, value out; the caller decides how it is
// stored (slide.set, deck.set or the theme draft).
import { computed, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import {
    ColorField, GradientField, InlineNotice, Segmented, Slider,
} from '../../../ui/controls';
import AssetPicker from './AssetPicker.vue';
import Ico from '../../ui/Ico.vue';
import {
    FITS, DIM_MAX, bgContrast, bgKind, imageBg, startValue, withImage,
} from './backgroundModel.js';

const props = defineProps({
    modelValue: { type: [String, Object], default: null },
    theme: { type: Object, default: null },
    themeLabel: { type: String, default: 'Theme' }, // the first choice: what null means here
    disabled: { type: Boolean, default: false },
    label: { type: String, default: 'Background' },
    idPrefix: { type: String, default: 'bg' },
});
const emit = defineEmits(['update:modelValue', 'commit']);
const editor = useEditorContext();
const picker = ref(false);

const kind = computed(() => bgKind(props.modelValue));
const kinds = computed(() => [
    { value: 'theme', label: props.themeLabel },
    { value: 'solid', label: 'Colour' },
    { value: 'gradient', label: 'Gradient' },
    { value: 'image', label: 'Image' },
]);
const effTheme = computed(() => props.theme || editor.deck.value?.theme);
const contrast = computed(() => bgContrast(props.modelValue, effTheme.value));
const asset = computed(() => (kind.value === 'image' ? editor.assets[props.modelValue.img?.asset] : null));
const fit = computed(() => props.modelValue?.fit || 'cover');
const dim = computed(() => Math.round((props.modelValue?.dim || 0) * 100));

function setKind(k) {
    if (k === kind.value) return;
    if (k === 'image') { picker.value = true; return; }
    emit('update:modelValue', startValue(k, effTheme.value, props.modelValue));
}
function onPick(a) {
    const prev = kind.value === 'image' ? props.modelValue : {};
    emit('update:modelValue', imageBg(a.id, { fit: prev.fit || 'cover', dim: prev.dim || 0 }));
    emit('commit');
}
const set = (v) => emit('update:modelValue', v);
</script>

<template>
    <div class="c-stack dz-bg">
        <Segmented :model-value="kind" :options="kinds" :label="label" :disabled="disabled" @update:model-value="setKind" />

        <template v-if="kind === 'solid'">
            <ColorField :model-value="modelValue" label="Colour" :theme="theme" :disabled="disabled" @update:model-value="set($event ?? '@bg')" />
        </template>
        <template v-else-if="kind === 'gradient'">
            <GradientField :model-value="modelValue" label="Gradient" :theme="theme" @update:model-value="set" />
        </template>
        <template v-else-if="kind === 'image'">
            <div class="dz-img">
                <span class="dz-img-t" aria-hidden="true">
                    <img v-if="asset" :src="asset.thumb || asset.url" alt="" loading="lazy" decoding="async" />
                    <Ico v-else name="image" />
                </span>
                <span class="dz-img-n">
                    <span class="pr-small">{{ asset?.name || 'Picture' }}</span>
                    <span class="pr-state pr-muted">{{ asset?.w && asset?.h ? `${asset.w} x ${asset.h}` : 'no size known' }}</span>
                </span>
                <button type="button" class="pr-btn pr-btn--sm" :disabled="disabled" @click="picker = true">Replace</button>
            </div>
            <Segmented :model-value="fit" :options="FITS" label="Fit" :disabled="disabled" @update:model-value="set(withImage(modelValue, { fit: $event }))" />
            <Slider
                :model-value="dim" label="Dim" :min="0" :max="DIM_MAX * 100" :step="5" unit="%" :disabled="disabled"
                hint="Darkens the picture so text on top stays readable."
                @update:model-value="set(withImage(modelValue, { dim: $event / 100 }))" @commit="emit('commit')"
            />
        </template>

        <InlineNotice v-if="contrast && contrast.level !== 'ok'" :kind="contrast.level === 'issue' ? 'error' : 'warn'">{{ contrast.text }}</InlineNotice>
        <AssetPicker v-model:open="picker" title="Choose a background picture" @pick="onPick" />
    </div>
</template>
