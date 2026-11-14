<script setup>
// DotHeader: the shared Dot top bar. Same markup and classes as blade/header.blade.php, so css/dot-header.css styles it unchanged;
// behaviour is js/dot-nav.js (load it once in the page shell: it is DOM-driven and survives Vue re-renders). A CSS Grid of three
// columns, so the search is centred in the viewport. The platform keeps its own logo (slot `brand`).
// Props: brandHref, brandLabel, nav, navLabel, moreLabel, account, drawerId, drawerTarget (the id of a platform's own nav element),
// drawerSide, drawerTitle, menuLabel, searchLabel, closeSearchLabel, context, skip, sticky, id.
// Slots: brand, context, nav (your own <li> items), search (<DotSearch>), tools (<DotSwitcher>, <DotBell>), account, drawer,
// drawer-before, drawer-after. The drawer is printed as a SIBLING after the header (a fragment root), never inside it.
import { computed, useSlots } from 'vue';
import DotNavMenu from './DotNavMenu.vue';
import DotAccountMenu from './DotAccountMenu.vue';
import DotDrawer from './DotDrawer.vue';

const props = defineProps({
    brandHref: { type: String, default: '/' },
    brandLabel: { type: String, default: 'Home' },
    nav: { type: Array, default: () => [] },
    navLabel: { type: String, default: 'Primary' },
    moreLabel: { type: String, default: 'More' },
    account: { type: Object, default: null },
    drawerId: { type: String, default: 'dh-drawer' },
    drawerTarget: { type: String, default: null },
    drawerSide: { type: String, default: 'right' },
    drawerTitle: { type: String, default: 'Menu' },
    menuLabel: { type: String, default: 'Menu' },
    searchLabel: { type: String, default: 'Search' },
    closeSearchLabel: { type: String, default: 'Close search' },
    context: { type: String, default: null },
    skip: { type: String, default: null },
    sticky: { type: Boolean, default: true },
    id: { type: String, default: 'dh' },
});

const slots = useSlots();
const hasSearch = computed(() => !!slots.search);
const searchId = computed(() => `${props.id}-search`);
const controls = computed(() => props.drawerTarget || props.drawerId);
const hasContext = computed(() => !!slots.context || !!props.context);
</script>

<template>
    <header class="dh" :class="{ 'dh--nosearch': !hasSearch, 'dh--static': !sticky }" :id="id" data-dh>
        <a v-if="skip" class="dh-skip skip-link" :href="skip">Skip to content</a>
        <div class="dh-bar">
            <div class="dh-start">
                <a class="dh-brand" :href="brandHref" :aria-label="brandLabel"><slot name="brand" /></a>
                <div v-if="hasContext" class="dh-context"><span class="dh-context__text" :title="context || null"><slot name="context">{{ context }}</slot></span></div>
                <nav class="dh-nav" :aria-label="navLabel" data-dh-nav>
                    <ul class="dh-nav__list">
                        <slot name="nav">
                            <template v-for="(item, i) in nav" :key="i">
                                <DotNavMenu v-if="item.items && item.items.length" :label="item.label" :items="item.items" />
                                <li v-else-if="item.href" class="dh-nav__item"><a class="dh-nav__link" :href="item.href" :aria-current="item.current ? 'page' : null" v-bind="item.attrs || {}">{{ item.label }}<span v-if="item.badge !== undefined && item.badge !== null && item.badge !== ''" class="dh-count">{{ item.badge }}</span></a></li>
                            </template>
                        </slot>
                        <li class="dh-nav__item dh-more" data-dh-menu data-dh-more :data-dh-label="moreLabel" hidden>
                            <button type="button" class="dh-nav__link" data-dh-menu-trigger aria-expanded="false" :aria-controls="`${id}-more`">{{ moreLabel }}<span class="dh-chev" aria-hidden="true"></span></button>
                            <div :id="`${id}-more`" class="dh-menu" data-dh-menu-panel hidden>
                                <ul class="dh-more__list" data-dh-more-list></ul>
                            </div>
                        </li>
                    </ul>
                </nav>
            </div>
            <div v-if="hasSearch" class="dh-search" :id="searchId" data-dh-search>
                <slot name="search" />
                <button type="button" class="dh-icon-btn dh-search__close" data-dh-search-close :aria-label="closeSearchLabel"><svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7" /></svg></button>
            </div>
            <div class="dh-end">
                <button v-if="hasSearch" type="button" class="dh-icon-btn dh-search-btn" data-dh-search-toggle aria-expanded="false" :aria-controls="searchId" :aria-label="searchLabel"><svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="6.5" /><path d="M16 16l4.5 4.5" /></svg></button>
                <slot name="tools" />
                <slot name="account"><DotAccountMenu v-if="account" v-bind="account" /></slot>
                <button type="button" class="dh-icon-btn dh-menu-btn" data-dh-menu-button aria-expanded="false" :aria-controls="controls" :aria-label="menuLabel"><svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16M4 12h16M4 17h16" /></svg></button>
            </div>
        </div>
    </header>
    <DotDrawer v-if="!drawerTarget" :id="drawerId" :nav="nav" :title="drawerTitle" :side="drawerSide" :context="context" :account="account">
        <template v-if="$slots.drawer" #default><slot name="drawer" /></template>
        <template v-if="$slots['drawer-before']" #before><slot name="drawer-before" /></template>
        <template v-if="$slots['drawer-after']" #after><slot name="drawer-after" /></template>
    </DotDrawer>
</template>
