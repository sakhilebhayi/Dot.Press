<script setup>
// DotBell: the notifications bell for the header's `tools` slot. Same markup as blade/bell.blade.php. Two forms:
//   LINK (no default slot): a 44px link with a count badge, hidden under 480px (the drawer carries the same link) unless `always`.
//   PANEL (content in the default slot, 0.6.0): a 44px button that opens a panel under it (a menu root for js/dot-nav.js: Escape, outside click, one open at a
//   time, flips at the edge, dispatches `dot:menu-open`). On the panel tokens; a fixed sheet under 480px; never hidden. Helpers for the content: .dh-panel__head,
//   .dh-panel__title, .dh-panel__list, .dh-panel__item[data-unread], .dh-panel__empty, .dh-panel__foot, and the 44px text button .dh-mark.
// Props: href ('#'), count (0 = no badge), label ('Notifications'), max (99), always, id ('dh-bell-panel'), panelLabel. Other attributes land on the <a> / <button>.
import { computed, useSlots } from 'vue';

const props = defineProps({
    href: { type: String, default: '#' },
    count: { type: [Number, String], default: 0 },
    label: { type: String, default: 'Notifications' },
    max: { type: Number, default: 99 },
    always: { type: Boolean, default: false },
    id: { type: String, default: 'dh-bell-panel' },
    panelLabel: { type: String, default: null },
});

const slots = useSlots();
const hasPanel = computed(() => !!slots.default);
const n = computed(() => (Number.isFinite(Number(props.count)) ? Math.trunc(Number(props.count)) : 0));
const shown = computed(() => (n.value > props.max ? `${props.max}+` : String(n.value)));
const name = computed(() => (n.value > 0 ? `${props.label}, ${n.value} unread` : props.label));
</script>

<template>
    <div v-if="hasPanel" class="dh-bellwrap" data-dh-menu data-dh-bell>
        <button type="button" class="dh-icon-btn dh-bell dh-bell--panel" data-dh-menu-trigger aria-expanded="false" :aria-controls="id" aria-haspopup="true" :aria-label="name">
            <svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15L6 17zM10 21h4" /></svg>
            <span v-if="n > 0" class="dh-badge" aria-hidden="true">{{ shown }}</span>
        </button>
        <div :id="id" class="dh-menu dh-menu--end dh-bellpanel" role="region" :aria-label="panelLabel || label" data-dh-menu-panel hidden><slot /></div>
    </div>
    <a v-else class="dh-icon-btn dh-bell" :class="{ 'dh-bell--always': always }" :href="href" :aria-label="name">
        <svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 17V11a6 6 0 0 1 12 0v6l1.5 2h-15L6 17zM10 21h4" /></svg>
        <span v-if="n > 0" class="dh-badge" aria-hidden="true">{{ shown }}</span>
    </a>
</template>
