<script setup>
import { computed } from 'vue';
import { useRenderCtx } from './context.js';
import { dashArray, lineGeometry, shapePath, svgGradientCoords } from './geometry.js';
import { colorCss, resolveFill } from './theme.js';
import TextBox from './TextBox.vue';

const props = defineProps({ el: { type: Object, required: true } });
const ctx = useRenderCtx();

const isLine = computed(() => props.el.kind === 'line' || props.el.kind === 'arrow');
const w = computed(() => Math.max(0, +props.el.w || 0));
const h = computed(() => Math.max(0, +props.el.h || 0));
const sw = computed(() => {
    const v = Number.isFinite(+props.el.sw) ? +props.el.sw : isLine.value ? ctx.theme.value.s.stroke : 0;
    return Math.max(0, Math.min(200, v));
});
const gid = computed(() => `${ctx.idPrefix}-g-${props.el.id}`);

const fill = computed(() => (isLine.value ? { kind: 'none', css: 'none' } : resolveFill(props.el.fill, ctx.theme.value)));
const strokeColor = computed(() => {
    if (props.el.stroke) return colorCss(props.el.stroke, 'none');
    if (isLine.value) return colorCss(props.el.fill ?? '@ink', 'var(--p-ink)');
    return 'none';
});
const dash = computed(() => dashArray(props.el.dash, sw.value));
const gradient = computed(() => {
    const f = fill.value;
    if (f.kind === 'linear') return { type: 'linear', coords: svgGradientCoords(f.angle, w.value, h.value), stops: f.stops };
    if (f.kind === 'radial') return { type: 'radial', stops: f.stops };
    return null;
});
const fillCss = computed(() => {
    if (gradient.value) return `url(#${gid.value})`;
    return fill.value.kind === 'solid' ? fill.value.css : 'none';
});
const path = computed(() => (isLine.value ? null : shapePath(props.el.kind, w.value, h.value, +props.el.radius || 0)));
const line = computed(() => (isLine.value ? lineGeometry(w.value, h.value, sw.value, props.el.ends, props.el.kind) : null));
const hasText = computed(() => props.el.doc && props.el.doc.type === 'doc');
</script>

<template>
    <div class="psh">
        <svg class="psh-svg" :width="Math.max(1, w)" :height="Math.max(1, h)" overflow="visible" aria-hidden="true" focusable="false">
            <defs v-if="gradient">
                <linearGradient v-if="gradient.type === 'linear'" :id="gid" gradientUnits="userSpaceOnUse" v-bind="gradient.coords">
                    <stop v-for="(s, i) in gradient.stops" :key="i" :offset="s.offset" :style="{ stopColor: s.css }" />
                </linearGradient>
                <radialGradient v-else :id="gid" cx="50%" cy="50%" r="70%">
                    <stop v-for="(s, i) in gradient.stops" :key="i" :offset="s.offset" :style="{ stopColor: s.css }" />
                </radialGradient>
            </defs>
            <path v-if="path" :d="path" :style="{ fill: fillCss, stroke: sw > 0 ? strokeColor : 'none', strokeWidth: sw, strokeDasharray: dash.array, strokeLinecap: dash.cap, strokeLinejoin: 'round' }" />
            <template v-else-if="line">
                <line :x1="line.x1" :x2="line.x2" :y1="line.y" :y2="line.y" :style="{ stroke: strokeColor, strokeWidth: sw, strokeDasharray: dash.array, strokeLinecap: dash.array ? dash.cap : 'butt' }" />
                <path v-for="(m, i) in line.marks" :key="i" :d="m.d" :style="{ fill: strokeColor, stroke: strokeColor, strokeWidth: 1, strokeLinejoin: 'round' }" />
            </template>
        </svg>
        <TextBox v-if="hasText && !isLine" :el="el" kind="shape" />
    </div>
</template>
