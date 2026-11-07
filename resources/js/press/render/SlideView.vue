<script setup>
// SlideView renders one slide of the presentation document as DOM and inline SVG (never canvas), inside a fixed
// deck.w x deck.h stage that is scaled to its container with a CSS transform. See ./index.js for the public surface.
import { computed, onBeforeUnmount, onMounted, provide, ref, shallowRef } from 'vue';
import { buildSequence } from '../player/sequence.js';
import { RENDER_CTX } from './context.js';
import { dropShadow } from './geometry.js';
import { nextUid, observeSize, reducedMotion } from './env.js';
import { normalizeTheme, resolveColor, themeVars, px } from './theme.js';
import SlideBackground from './SlideBackground.vue';
import ElText from './ElText.vue';
import ElShape from './ElShape.vue';
import ElImage from './ElImage.vue';
import ElVideo from './ElVideo.vue';
import ElAudio from './ElAudio.vue';
import ElIcon from './ElIcon.vue';
import ElChart from './ElChart.vue';
import ElTable from './ElTable.vue';
import './render.css';

const props = defineProps({
    slide: { type: Object, required: true },
    deck: { type: Object, required: true },
    assets: { type: Object, default: () => ({}) },
    /** edit: every element drawn, hidden ones dimmed. view: read only, links live. thumb: cheap, no media, no embeds. present: animation steps apply. */
    mode: { type: String, default: 'view', validator: (v) => ['edit', 'view', 'thumb', 'present'].includes(v) },
    /** Animation step visited (see player/sequence.js): -1 initial, 0 entry group played, k after k clicks. Infinity shows everything. Only used in present mode. */
    state: { type: Number, default: Infinity },
    /** present mode: true while this slide is the one on screen. Drives video and audio autoplay. */
    active: { type: Boolean, default: true },
    /** fill the container height and letterbox. Default: the wrapper takes the deck aspect ratio from the container width. */
    fill: { type: Boolean, default: false },
    allowEmbeds: { type: Boolean, default: true },
    /** load images eagerly (the player uses this for the slide on screen and the next one) */
    eager: { type: Boolean, default: false },
});
const emit = defineEmits(['link', 'scale']);

const wrap = ref(null);
const stage = ref(null);
const size = shallowRef({ w: 0, h: 0 });
const uid = nextUid();
const idPrefix = `ps${uid}`;

const theme = computed(() => normalizeTheme(props.deck?.theme));
const deckDims = computed(() => ({ w: Math.max(1, +props.deck?.w || 1920), h: Math.max(1, +props.deck?.h || 1080) }));
const modeRef = computed(() => props.mode);

provide(RENDER_CTX, {
    theme,
    deck: computed(() => ({ ...deckDims.value, theme: theme.value })),
    assets: computed(() => props.assets || {}),
    mode: modeRef,
    links: computed(() => props.mode === 'view' || props.mode === 'present'),
    allowEmbeds: computed(() => props.allowEmbeds),
    active: computed(() => props.active),
    eager: computed(() => props.eager || props.mode === 'present'),
    idPrefix,
    openSlide: (id) => emit('link', id),
});

const components = { text: ElText, shape: ElShape, image: ElImage, video: ElVideo, audio: ElAudio, icon: ElIcon, chart: ElChart, table: ElTable };

const seq = computed(() => (props.mode === 'present' ? buildSequence(props.slide) : null));
const states = computed(() => (seq.value ? seq.value.stateAt(props.state) : null));

const items = computed(() => {
    const out = [];
    for (const el of props.slide?.els || []) {
        if (!el || typeof el !== 'object' || typeof el.id !== 'string' || !components[el.type]) continue;
        if (el.hide && props.mode !== 'edit') continue;
        out.push(el);
    }
    return out;
});

const num = (v, d = 0) => (Number.isFinite(+v) ? +v : d);

function elStyle(el) {
    const op = el.op == null ? 1 : Math.min(1, Math.max(0, num(el.op, 1)));
    const s = {
        width: px(Math.max(0, num(el.w))),
        height: px(Math.max(0, num(el.h))),
        transform: `translate(${px(num(el.x))}, ${px(num(el.y))})${el.rot ? ` rotate(${+num(el.rot).toFixed(3)}deg)` : ''}`,
    };
    if (op !== 1 || el.hide) s.opacity = el.hide ? Math.min(op, 0.3) : op;
    return s;
}

function fxStyle(el) {
    const s = {};
    const st = states.value?.get(el.id);
    if (st) {
        if (!st.visible) s.opacity = 0;
        if (st.scale !== 1) s.transform = `scale(${st.scale})`;
    }
    if (el.shadow) {
        const color = resolveColor(el.shadow.c, theme.value, '#00000066');
        const f = dropShadow(el.shadow, color);
        if (f) s.filter = f;
    }
    return s;
}

function isOff(el) {
    const st = states.value?.get(el.id);
    return !!st && !st.visible;
}

function onElClick(el, e) {
    if (!el.link || (props.mode !== 'view' && props.mode !== 'present')) return;
    const link = String(el.link);
    e.stopPropagation();
    if (link.startsWith('#slide:')) emit('link', link.slice(7));
    else if (/^https:\/\//i.test(link)) window.open(link, '_blank', 'noopener,noreferrer');
}

const linked = (el) => !!el.link && (props.mode === 'view' || props.mode === 'present');

// ---- scaling ----
const scale = computed(() => {
    const { w, h } = size.value;
    if (!w || !h) return 1;
    return props.fill ? Math.min(w / deckDims.value.w, h / deckDims.value.h) : w / deckDims.value.w;
});
const stageStyle = computed(() => {
    const { w, h } = size.value;
    const s = scale.value;
    const left = (w - deckDims.value.w * s) / 2;
    const top = (h - deckDims.value.h * s) / 2;
    return {
        width: px(deckDims.value.w),
        height: px(deckDims.value.h),
        transform: `translate(${px(left)}, ${px(props.fill ? top : 0)}) scale(${+s.toFixed(5)})`,
        ...themeVars(theme.value),
    };
});
const wrapStyle = computed(() => (props.fill ? undefined : { aspectRatio: `${deckDims.value.w} / ${deckDims.value.h}` }));

let stop = null;
onMounted(() => {
    stop = observeSize(wrap.value, (w, h) => {
        if (size.value.w !== w || size.value.h !== h) {
            size.value = { w, h };
            emit('scale', scale.value);
        }
    });
});
onBeforeUnmount(() => stop && stop());

defineExpose({
    /** the element that fills the container (the layer the player animates) */
    root: wrap,
    /** the stage element, deck.w x deck.h before scaling */
    stage,
    scale,
    /** element wrappers by element id, for the player */
    nodes() {
        const map = new Map();
        wrap.value?.querySelectorAll('[data-eid]').forEach((n) => map.set(n.getAttribute('data-eid'), n));
        return map;
    },
    reduced: reducedMotion,
});
</script>

<template>
    <div ref="wrap" class="ps-wrap" :class="['ps-' + mode, { 'ps-fill': fill }]" :style="wrapStyle" :aria-hidden="mode === 'thumb' ? 'true' : undefined">
        <div ref="stage" class="ps-stage" :style="stageStyle" role="group" :aria-label="slide.title || undefined">
            <SlideBackground :slide="slide" />
            <div v-for="el in items" :key="el.id" class="p-el" :class="{ 'p-el-link': linked(el), 'p-el-hidden': el.hide }" :data-eid="el.id" :data-type="el.type" :style="elStyle(el)" :aria-hidden="isOff(el) ? 'true' : undefined" :inert="isOff(el) || undefined" :tabindex="linked(el) ? 0 : undefined" :role="linked(el) ? 'link' : undefined" :aria-label="linked(el) && el.alt ? el.alt : undefined" @click="onElClick(el, $event)" @keydown.enter="onElClick(el, $event)">
                <div class="p-fx" :style="fxStyle(el)">
                    <component :is="components[el.type]" :el="el" />
                </div>
            </div>
        </div>
    </div>
</template>
