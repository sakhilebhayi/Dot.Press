<script setup>
// DotBell: the notifications bell for the header's `tools` slot. Same markup as blade/bell.blade.php.
// Props: href ('#'), count (0 = no badge), label ('Notifications'), max (99). Other attributes land on the <a>.
import { computed } from 'vue';

const props = defineProps({
    href: { type: String, default: '#' },
    count: { type: [Number, String], default: 0 },
    label: { type: String, default: 'Notifications' },
    max: { type: Number, default: 99 },
});

const n = computed(() => (Number.isFinite(Number(props.count)) ? Math.trunc(Number(props.count)) : 0));
const shown = computed(() => (n.value > props.max ? `${props.max}+` : String(n.value)));
</script>

<template>
    <a class="dh-icon-btn dh-bell" :href="href" :aria-label="n > 0 ? `${label}, ${n} unread` : label">
        <svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15L6 17zM10 21h4" /></svg>
        <span v-if="n > 0" class="dh-badge" aria-hidden="true">{{ shown }}</span>
    </a>
</template>
