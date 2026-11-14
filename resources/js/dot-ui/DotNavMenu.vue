<script setup>
// DotNavMenu: one dropdown group of the primary nav. Same markup and classes as blade/nav-menu.blade.php; the behaviour is
// js/dot-nav.js (delegated, DOM-driven: load it once in the page shell). Prints an <li>: place it in <ul class="dh-nav__list">.
// Props: label (required), items [{label, href, current, badge, heading, rule}], id, align ('start'|'end').
// Slot: default replaces the item list.
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    items: { type: Array, default: () => [] },
    id: { type: String, default: null },
    align: { type: String, default: 'start' },
});

const panelId = computed(() => props.id || `dh-menu-${String(props.label).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')}`);
const hasCurrent = computed(() => props.items.some((i) => i && i.current));
</script>

<template>
    <li class="dh-nav__item" data-dh-menu :data-dh-label="label" :data-has-current="hasCurrent ? '' : null">
        <button type="button" class="dh-nav__link" data-dh-menu-trigger aria-expanded="false" :aria-controls="panelId">{{ label }}<span class="dh-chev" aria-hidden="true"></span></button>
        <div :id="panelId" class="dh-menu" :data-align="align === 'end' ? 'end' : null" data-dh-menu-panel hidden>
            <slot>
                <template v-for="(item, i) in items" :key="i">
                    <div v-if="item.rule" class="dh-menu__rule" role="separator"></div>
                    <p v-else-if="item.heading && !item.href" class="dh-menu__head">{{ item.heading }}</p>
                    <a v-else class="dh-menu__item" :href="item.href || '#'" :aria-current="item.current ? 'page' : null" v-bind="item.attrs || {}"><span class="dh-menu__label">{{ item.label }}</span><span v-if="item.badge !== undefined && item.badge !== null && item.badge !== ''" class="dh-count">{{ item.badge }}</span></a>
                </template>
            </slot>
        </div>
    </li>
</template>
