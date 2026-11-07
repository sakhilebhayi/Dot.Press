<script setup>
// "Consistency after theme change": colours typed in by hand that match no token of the theme, each with the nearest token
// as a proposal. Nothing happens until the user confirms the ticked ones.
import { computed, ref, watch } from 'vue';
import { InlineNotice, Toggle } from '../../../ui/controls';
import { tokenName } from '../../../ui/controls/themeKit.js';

const props = defineProps({
    proposals: { type: Array, required: true },
    alphaKept: { type: Number, default: 0 },
    disabled: { type: Boolean, default: false },
    fresh: { type: Boolean, default: false }, // the theme was just changed
});
const emit = defineEmits(['map']);
const open = ref(false);
const ticked = ref(new Set());

watch(() => props.proposals, (list) => { ticked.value = new Set(list.filter((p) => p.close).map((p) => p.hex)); }, { immediate: true });
watch(() => props.fresh, (f) => { if (f && props.proposals.length) open.value = true; });

const chosen = computed(() => props.proposals.filter((p) => ticked.value.has(p.hex)));
const elementCount = computed(() => chosen.value.reduce((n, p) => n + p.count, 0));
function toggle(p, on) {
    const next = new Set(ticked.value);
    if (on) next.add(p.hex); else next.delete(p.hex);
    ticked.value = next;
}
const pickClose = () => { ticked.value = new Set(props.proposals.filter((p) => p.close).map((p) => p.hex)); };
const noun = (n) => `${n} ${n === 1 ? 'element' : 'elements'}`;
</script>

<template>
    <div class="c-stack">
        <InlineNotice v-if="!proposals.length" kind="ok">
            Every colour in the deck is a theme token or matches one.<template v-if="alphaKept"> {{ alphaKept }} see-through {{ alphaKept === 1 ? 'colour is' : 'colours are' }} left as they are.</template>
        </InlineNotice>
        <template v-else>
            <InlineNotice :kind="fresh ? 'warn' : 'info'" :title="`${proposals.length} hard coded ${proposals.length === 1 ? 'colour matches' : 'colours match'} no theme colour`">
                These colours were typed in by hand, so they will not follow the theme. Map them to the closest theme colour, or leave them.
            </InlineNotice>
            <button type="button" class="pr-btn pr-btn--sm" :aria-expanded="open" @click="open = !open">{{ open ? 'Hide the list' : 'Review the list' }}</button>
            <div v-if="open" class="c-stack">
                <ul class="dz-prop" aria-label="Colours to map">
                    <li v-for="p in proposals" :key="p.hex" class="dz-prop-r">
                        <span class="dz-prop-s" aria-hidden="true">
                            <span class="dz-sw" :style="{ backgroundColor: p.hex }"></span>
                            <span class="dz-sw" :style="{ backgroundColor: p.color }"></span>
                        </span>
                        <Toggle
                            :model-value="ticked.has(p.hex)" :label="`${p.hex.toUpperCase()} to ${tokenName(p.token)}`" :disabled="disabled"
                            :hint="`${noun(p.count)}${p.close ? ', close match' : ', not a close match'}`" @update:model-value="toggle(p, $event)"
                        />
                    </li>
                </ul>
                <div class="dz-actions">
                    <button type="button" class="pr-btn pr-btn--sm pr-btn--primary" :disabled="disabled || !chosen.length" @click="emit('map', chosen)">
                        Map {{ chosen.length }} {{ chosen.length === 1 ? 'colour' : 'colours' }} ({{ noun(elementCount) }})
                    </button>
                    <button type="button" class="pr-btn pr-btn--sm pr-btn--ghost" @click="pickClose">Close matches only</button>
                </div>
                <p v-if="alphaKept" class="c-hint">{{ alphaKept }} see-through {{ alphaKept === 1 ? 'colour is' : 'colours are' }} not listed because a theme colour cannot be see-through.</p>
            </div>
        </template>
    </div>
</template>
