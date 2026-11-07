<script setup>
import { computed } from 'vue';
import { useRenderCtx } from './context.js';
import { colorCss, px, resolveFont } from './theme.js';
import { tableModel } from './table.js';

const props = defineProps({ el: { type: Object, required: true } });
const ctx = useRenderCtx();
const model = computed(() => tableModel(props.el));
const size = computed(() => (Number.isFinite(+props.el.size) && +props.el.size > 0 ? +props.el.size : 28));
const lines = computed(() => (['none', 'rows', 'grid'].includes(props.el.lines) ? props.el.lines : 'rows'));
const style = computed(() => ({ fontFamily: resolveFont(props.el.font ?? '@body', ctx.theme.value).stack, fontSize: px(size.value * (ctx.theme.value.t.scale || 1)) }));
const alMap = { l: 'left', c: 'center', r: 'right' };
function cellStyle(c, row) {
    const s = {};
    if (c.al) s.textAlign = alMap[c.al];
    if (c.fill) s.background = colorCss(c.fill, '');
    if (c.color) s.color = colorCss(c.color, '');
    if (c.b || row.header) s.fontWeight = 700;
    if (c.i) s.fontStyle = 'italic';
    return s;
}
</script>

<template>
    <table class="ptb" :class="['ptb-' + lines]" :style="style">
        <colgroup><col v-for="(f, i) in model.colw" :key="i" :style="{ width: (f * 100).toFixed(3) + '%' }" /></colgroup>
        <tbody>
            <tr v-for="(row, r) in model.rows" :key="r" :class="{ 'ptb-head': row.header, 'ptb-band': row.band }" :style="row.height ? { height: px(row.height) } : null">
                <component :is="row.header ? 'th' : 'td'" v-for="(c, j) in row.cells" :key="j" :rowspan="c.rowspan > 1 ? c.rowspan : undefined" :colspan="c.colspan > 1 ? c.colspan : undefined" :scope="row.header ? 'col' : undefined" :style="cellStyle(c, row)">{{ c.t }}</component>
            </tr>
        </tbody>
    </table>
</template>
