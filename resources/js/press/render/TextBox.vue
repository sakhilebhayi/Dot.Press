<script setup>
// Rich text box shared by text elements and shapes that hold text. Renders the Tiptap document as safe HTML,
// applies the text properties of spec section 6, and shrinks type to fit when `fit` is "shrink".
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRenderCtx } from './context.js';
import { docKey, docToHtml } from './doc.js';
import { fitCache, fitFactor, fitKey } from './fit.js';
import { colorCss, px, resolveFont, resolveFill, resolveWeight } from './theme.js';
import { fontEpoch } from './env.js';

const props = defineProps({
    el: { type: Object, required: true },
    // 'text': this component draws the fill, stroke and radius itself. 'shape': the shape draws them.
    kind: { type: String, default: 'text' },
});

const ctx = useRenderCtx();
const box = ref(null);
const inner = ref(null);

const theme = ctx.theme;
const t = computed(() => props.el);
const font = computed(() => resolveFont(t.value.font ?? '@body', theme.value));
const size = computed(() => (Number.isFinite(+t.value.size) && +t.value.size > 0 ? +t.value.size : 48) * (theme.value.t.scale || 1));
const weight = computed(() => resolveWeight(t.value, theme.value));
const pad = computed(() => (Number.isFinite(+t.value.pad) ? Math.max(0, +t.value.pad) : props.kind === 'shape' ? 16 : 0));
const lh = computed(() => (Number.isFinite(+t.value.lh) && +t.value.lh > 0 ? +t.value.lh : 1.25));
const ls = computed(() => (Number.isFinite(+t.value.ls) ? +t.value.ls : 0));
const ps = computed(() => (Number.isFinite(+t.value.ps) ? Math.max(0, +t.value.ps) : 0.4));
const fx = computed(() => (['outline', 'highlight'].includes(t.value.fx) ? t.value.fx : 'none'));
const color = computed(() => colorCss(t.value.color ?? '@ink', 'var(--p-ink)'));
const align = computed(() => (['left', 'center', 'right', 'justify'].includes(t.value.align) ? t.value.align : 'left'));
const justify = computed(() => ({ top: 'flex-start', middle: 'center', bottom: 'flex-end' })[t.value.valign] || 'flex-start');

const html = computed(() =>
    docToHtml(t.value.doc, {
        links: ctx.links.value,
        highlight: fx.value === 'highlight' ? colorCss('@accent2', '#f59e0b') : '',
    }),
);

const boxStyle = computed(() => {
    const s = {
        justifyContent: justify.value,
        padding: px(pad.value),
        fontFamily: font.value.stack,
        fontSize: `calc(${px(size.value)} * var(--p-fit, 1))`,
        fontWeight: weight.value,
        lineHeight: lh.value,
        letterSpacing: `${ls.value}em`,
        textAlign: align.value,
        color: color.value,
        '--p-ps': `${ps.value}em`,
    };
    if (fx.value === 'outline') {
        s.color = 'transparent';
        s.WebkitTextStroke = `${Math.max(1, +(size.value * 0.025).toFixed(2))}px ${color.value === 'transparent' ? 'var(--p-ink)' : color.value}`;
        s.paintOrder = 'stroke fill';
    }
    if (props.kind === 'text') {
        const fill = resolveFill(t.value.fill, theme.value);
        if (fill.kind !== 'none') s.background = fill.css;
        const r = Number.isFinite(+t.value.radius) ? Math.max(0, +t.value.radius) : 0;
        if (r) s.borderRadius = px(r);
        const sw = Number.isFinite(+t.value.sw) ? Math.max(0, +t.value.sw) : 0;
        if (t.value.stroke && sw > 0) {
            s.boxShadow = `inset 0 0 0 ${px(sw)} ${colorCss(t.value.stroke, 'transparent')}`;
        }
    }
    if (t.value.fit === 'shrink') s.overflow = 'hidden';
    return s;
});

// ---- fit ----
const factor = ref(1);
let timer = 0;
let idle = 0;

function key() {
    return fitKey(
        {
            docKey: docKey(t.value.doc),
            w: props.el.w,
            h: props.el.h,
            pad: pad.value,
            size: size.value,
            weight: weight.value,
            font: font.value.family,
            lh: lh.value,
            ls: ls.value,
            ps: ps.value,
        },
        fontEpoch.value,
    );
}

function applyFactor(f) {
    factor.value = f;
    if (box.value) box.value.style.setProperty('--p-fit', String(f));
}

function measure() {
    if (t.value.fit !== 'shrink') {
        if (factor.value !== 1) applyFactor(1);
        return;
    }
    const k = key();
    const cached = fitCache.get(k);
    if (cached !== undefined) {
        applyFactor(cached);
        return;
    }
    const el = inner.value;
    const host = box.value;
    if (!el || !host) return;
    const available = Math.max(1, +props.el.h - 2 * pad.value);
    const f = fitFactor((x) => {
        host.style.setProperty('--p-fit', String(x));
        return el.offsetHeight;
    }, available);
    fitCache.set(k, f);
    applyFactor(f);
}

function schedule(immediate = false) {
    clearTimeout(timer);
    if (idle && typeof cancelIdleCallback !== 'undefined') cancelIdleCallback(idle);
    idle = 0;
    if (immediate) return measure();
    if (ctx.mode.value === 'thumb') {
        // thumbnails measure when the browser is idle, and reuse any result the full view already cached
        const cached = fitCache.get(key());
        if (cached !== undefined) return applyFactor(cached);
        idle = typeof requestIdleCallback !== 'undefined' ? requestIdleCallback(measure, { timeout: 1500 }) : setTimeout(measure, 50);
        return;
    }
    // Editing resizes the box continuously: measure once things settle, never per frame.
    timer = setTimeout(measure, 90);
}

onMounted(() => {
    if (t.value.fit === 'shrink') schedule(ctx.mode.value !== 'thumb');
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    if (idle && typeof cancelIdleCallback !== 'undefined') cancelIdleCallback(idle);
});
watch(
    () => [t.value.fit, key()],
    () => {
        if (t.value.fit !== 'shrink') {
            nextTick(() => measure());
            return;
        }
        schedule(false);
    },
);

function onClick(e) {
    const a = e.target?.closest?.('a.p-link');
    if (!a) return;
    const id = a.getAttribute('data-slide');
    if (id) {
        e.preventDefault();
        e.stopPropagation();
        ctx.openSlide(id);
    } else {
        e.stopPropagation();
    }
}
</script>

<template>
    <div ref="box" class="pt" :class="['pt-' + kind, 'pt-fx-' + fx]" :style="boxStyle" @click="onClick">
        <div ref="inner" class="pt-in" v-html="html"></div>
    </div>
</template>
