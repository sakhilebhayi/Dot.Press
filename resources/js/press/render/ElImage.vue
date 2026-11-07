<script setup>
import { computed, ref, watch } from 'vue';
import { useRenderCtx } from './context.js';
import { adjustFilter, imagePlacement, maskClip } from './geometry.js';
import { resolveSrc } from './media.js';
import { colorCss, resolveFill, px } from './theme.js';
import { docToHtml } from './doc.js';

const props = defineProps({ el: { type: Object, required: true } });
const ctx = useRenderCtx();

const src = computed(() => resolveSrc(props.el.src, ctx.assets.value));
const url = computed(() => (src.value ? (ctx.mode.value === 'thumb' ? src.value.thumb : src.value.url) : null));
const loaded = ref(null);
watch(url, () => (loaded.value = null));

const hasCaption = computed(() => typeof props.el.cap === 'string' && props.el.cap.trim() !== '');
const capSize = computed(() => Math.max(14, Math.round((ctx.deck.value.h || 1080) * 0.022)));
const capH = computed(() => (hasCaption.value ? Math.round(capSize.value * 1.5 + 8) : 0));
const boxW = computed(() => Math.max(1, +props.el.w || 1));
const boxH = computed(() => Math.max(1, (+props.el.h || 1) - capH.value));

const nat = computed(() => {
    if (src.value?.w && src.value?.h) return { w: src.value.w, h: src.value.h };
    return loaded.value;
});
const fit = computed(() => (['cover', 'contain', 'fill'].includes(props.el.fit) ? props.el.fit : 'cover'));
const place = computed(() => imagePlacement({ crop: props.el.crop, nat: nat.value, box: { w: boxW.value, h: boxH.value }, fit: fit.value }));
const imgStyle = computed(() => ({
    left: px(place.value.left),
    top: px(place.value.top),
    width: px(place.value.width),
    height: px(place.value.height),
    filter: adjustFilter(props.el.adj) || undefined,
}));
const flip = computed(() => {
    const x = props.el.flipH ? -1 : 1;
    const y = props.el.flipV ? -1 : 1;
    return x === 1 && y === 1 ? undefined : `scale(${x}, ${y})`;
});
const boxStyle = computed(() => {
    const s = { height: px(boxH.value) };
    const clip = maskClip(props.el.mask, ctx.theme.value.s.radius);
    if (clip) s.clipPath = clip;
    const f = resolveFill(props.el.fill, ctx.theme.value);
    if (f.kind !== 'none') s.background = f.css;
    return s;
});
const alt = computed(() => (typeof props.el.alt === 'string' ? props.el.alt : ''));

function onLoad(e) {
    const i = e.target;
    if (i && i.naturalWidth) loaded.value = { w: i.naturalWidth, h: i.naturalHeight };
}
</script>

<template>
    <figure class="pi">
        <div class="pi-box" :style="boxStyle">
            <div class="pi-flip" :style="{ transform: flip }">
                <img v-if="url" :src="url" :alt="alt" :style="imgStyle" :loading="ctx.eager.value ? 'eager' : 'lazy'" decoding="async" draggable="false" @load="onLoad" />
            </div>
            <div v-if="!url && ctx.mode.value !== 'present'" class="pi-missing" :aria-label="alt || 'Image not available'" role="img">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4.5" width="18" height="15" rx="2" /><circle cx="8.5" cy="9.5" r="1.5" /><path d="M21 16l-5-5-8 8.5" /></svg>
            </div>
        </div>
        <figcaption v-if="hasCaption" class="pi-cap" :style="{ height: px(capH), fontSize: px(capSize), color: colorCss('@muted') }">{{ el.cap }}</figcaption>
    </figure>
</template>
