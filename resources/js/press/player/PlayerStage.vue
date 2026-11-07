<script setup>
// PlayerStage: the slide area of every player surface. It shows one position {slide, step} of a deck and moves
// between positions with the element animations and slide transitions of the document. It is controlled: the
// parent sets `position`, the stage plays whatever movement leads there (one click group forward, one backward
// un-played, a slide transition, or a plain jump) and emits `settled` when the picture is final.
//
// Determinism: every end state comes from sequence.stateAt(), never from the animations, so jumping to slide N step
// K shows exactly the picture that playing there shows.
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import SlideView from '../render/SlideView.vue';
import { reducedMotion, supportsLinearEasing } from '../render/env.js';
import { slideAssetIds, resolveSrc } from '../render/media.js';
import { createPlan, samePos } from './state.js';
import { playGroup, runTransition } from './runtime.js';
import { normalizeTransition, transitionFor } from './transitions.js';

const props = defineProps({
    deck: { type: Object, required: true },
    slides: { type: Array, required: true },
    assets: { type: Object, default: () => ({}) },
    position: { type: Object, required: true },
    /** true on the surface the audience watches: media plays and embeds may load */
    media: { type: Boolean, default: true },
    /** play animations and transitions. false jumps straight to each picture (used for small previews) */
    animate: { type: Boolean, default: true },
    /** preload and pre-render the next slide */
    preload: { type: Boolean, default: true },
});
const emit = defineEmits(['settled', 'link', 'busy']);

const plan = computed(() => createPlan(props.slides, props.deck));
const supportsLinear = supportsLinearEasing();

let counter = 0;
const layers = ref([]);
const viewRefs = new Map();
const layerEls = new Map();
const root = ref(null);

const layer = (slide, state, role, active) => reactive({ id: ++counter, slide, state, role, active });

// the layer currently shown, and what it shows
let main = null;
let shown = null;
let pending = null;
let working = false;
let skip = null;
let destroyed = false;

function dims() {
    return { w: +props.deck?.w || 1920, h: +props.deck?.h || 1080 };
}

async function mounted() {
    await nextTick();
    await nextTick();
}

function nodesOf(l) {
    return viewRefs.get(l.id)?.nodes?.() || new Map();
}

async function imagesReady(l, limit = 350) {
    const el = layerEls.get(l.id);
    if (!el) return;
    const imgs = [...el.querySelectorAll('img')].filter((i) => !i.complete);
    if (!imgs.length) return;
    await Promise.race([Promise.allSettled(imgs.map((i) => (i.decode ? i.decode() : Promise.resolve()))), new Promise((r) => setTimeout(r, limit))]);
}

function setPreload() {
    layers.value = layers.value.filter((l) => l === main || l.role !== 'preload');
    if (!props.preload || !shown) return;
    const next = plan.value.nextSlide({ slide: shown.slide, step: 0 });
    if (next.slide === shown.slide) return;
    layers.value.push(layer(next.slide, -1, 'preload', false));
    const s = props.slides[next.slide];
    if (s) preloadImages(s);
}

const warmed = new Set();
function preloadImages(slide) {
    if (typeof Image === 'undefined') return;
    for (const id of slideAssetIds(slide)) {
        const a = resolveSrc({ asset: id }, props.assets);
        if (!a || warmed.has(a.url)) continue;
        if (a.mime && !a.mime.startsWith('image/')) continue;
        warmed.add(a.url);
        const img = new Image();
        img.decoding = 'async';
        img.src = a.url;
    }
}

function wait(p, handle) {
    skip = () => handle.finish();
    return p.finally(() => {
        skip = null;
    });
}

async function playEntry(l) {
    const seq = plan.value.seqs[l.slide];
    const g = seq?.groups[0];
    if (!g || !g.items.length || !props.animate) {
        l.state = 0;
        return;
    }
    const h = playGroup(nodesOf(l), g, { slide: props.slides[l.slide], deck: dims(), reduced: reducedMotion.value, supportsLinear });
    await wait(h.finished, h);
    l.state = 0;
    await nextTick();
    h.release();
}

async function stepTo(l, from, to) {
    const seq = plan.value.seqs[l.slide];
    const reverse = to < from;
    const g = seq?.groups[reverse ? from : to];
    if (props.animate && g && Math.abs(to - from) === 1 && g.items.length) {
        const h = playGroup(nodesOf(l), g, {
            slide: props.slides[l.slide],
            deck: dims(),
            reduced: reducedMotion.value,
            supportsLinear,
            reverse,
            fromState: seq.stateAt(from),
        });
        await wait(h.finished, h);
    }
    l.state = to;
    await nextTick();
    // release any animation still holding styles once Vue has painted the end state
    for (const n of nodesOf(l).values()) n.querySelector(':scope > .p-fx')?.getAnimations?.().forEach((a) => a.cancel());
}

async function moveTo(target) {
    const from = shown;
    if (from && samePos(from, target)) return;
    if (!from || !main) {
        // first picture: no animation, but the entry group still plays
        main = layer(target.slide, target.step === 0 ? -1 : target.step, 'main', props.media);
        layers.value = [main];
        shown = { slide: target.slide, step: target.step };
        await mounted();
        await imagesReady(main);
        if (main.state === -1) await playEntry(main);
        setPreload();
        return;
    }
    if (target.slide === from.slide) {
        await stepTo(main, from.step, target.step);
        shown = { ...target };
        return;
    }

    const direction = target.slide > from.slide ? 1 : -1;
    const adjacent = plan.value.nextSlide({ slide: from.slide, step: 0 }).slide === target.slide || plan.value.prevSlide({ slide: from.slide, step: 0 }).slide === target.slide;
    const incomingState = direction > 0 && target.step === 0 ? -1 : target.step;

    let incoming = layers.value.find((l) => l.role === 'preload' && l.slide === target.slide);
    if (incoming) {
        incoming.state = incomingState;
        incoming.role = 'incoming';
    } else {
        incoming = layer(target.slide, incomingState, 'incoming', false);
        layers.value = [...layers.value.filter((l) => l.role !== 'preload'), incoming];
    }
    const outgoing = main;
    layers.value = layers.value.filter((l) => l === outgoing || l === incoming);
    await mounted();
    await imagesReady(incoming);

    let t = props.animate ? transitionFor(props.slides[from.slide], props.slides[target.slide], direction) : null;
    if (props.animate && !adjacent && !reducedMotion.value) t = normalizeTransition({ type: 'fade', dur: 220, ease: 'out' });
    if (reducedMotion.value && t) t = { ...t, type: 'fade', dur: Math.min(t.dur, 160) };

    const outEl = layerEls.get(outgoing.id);
    const inEl = layerEls.get(incoming.id);
    if (t && t.dur > 0 && outEl && inEl) {
        emit('busy', true);
        const h = runTransition({
            outEl,
            inEl,
            outNodes: nodesOf(outgoing),
            inNodes: nodesOf(incoming),
            from: props.slides[from.slide],
            to: props.slides[target.slide],
            t,
            deck: dims(),
            reduced: reducedMotion.value,
            supportsLinear,
        });
        await wait(h.finished, h);
        incoming.role = 'main';
        incoming.active = props.media;
        layers.value = layers.value.filter((l) => l === incoming);
        await nextTick();
        h.release();
    } else {
        incoming.role = 'main';
        incoming.active = props.media;
        layers.value = layers.value.filter((l) => l === incoming);
        await nextTick();
    }
    main = incoming;
    shown = { slide: target.slide, step: target.step };
    if (incoming.state === -1) await playEntry(incoming);
    emit('busy', false);
    setPreload();
}

async function pump() {
    if (working) return;
    working = true;
    try {
        while (pending && !destroyed) {
            const target = pending;
            pending = null;
            await moveTo(target);
            emit('settled', { ...(shown || target) });
        }
    } finally {
        working = false;
        emit('busy', false);
    }
}

function request(pos) {
    pending = props.slides.length ? plan.value.clamp(pos) : null;
    if (!pending) return;
    // a newer request speeds the running movement up instead of waiting for it
    if (working && skip) skip();
    pump();
}

watch(() => [props.position.slide, props.position.step], () => request(props.position));
watch(
    () => props.slides,
    () => {
        // the document changed under us (a reload): redraw from scratch at the clamped position
        shown = null;
        main = null;
        request(props.position);
    },
);
watch(() => props.media, (v) => {
    if (main) main.active = v;
});
onMounted(() => request(props.position));
onBeforeUnmount(() => {
    destroyed = true;
});

defineExpose({ root, layerCount: () => layers.value.length });
</script>

<template>
    <div ref="root" class="pp-stage" data-press-stage>
        <div v-for="l in layers" :key="l.id" :ref="(el) => (el ? layerEls.set(l.id, el) : layerEls.delete(l.id))" class="pp-layer" :class="{ 'pp-layer-wait': l.role === 'preload' || l.role === 'incoming' }" :data-slide="l.slide" :aria-hidden="l.role === 'preload' ? 'true' : undefined" :inert="l.role === 'preload' || undefined">
            <SlideView :ref="(v) => (v ? viewRefs.set(l.id, v) : viewRefs.delete(l.id))" :slide="slides[l.slide]" :deck="deck" :assets="assets" mode="present" fill :state="l.state" :active="l.active && media" :allow-embeds="media" eager @link="(id) => emit('link', id)" />
        </div>
    </div>
</template>
