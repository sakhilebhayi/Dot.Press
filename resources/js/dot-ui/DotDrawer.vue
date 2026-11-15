<script setup>
// DotDrawer: the mobile menu drawer. Same markup and classes as blade/drawer.blade.php. DotHeader prints it for you; use it
// alone for a custom shell. Behaviour is js/dot-nav.js: scrim (the root's own inset:0 layer), focus trap, Escape, link click
// closes, scroll lock. Closed state is [hidden] (static: js owns it, Vue never re-patches it).
// Props: id, nav [{label, href, current, badge} | {label, items:[...], open}], title, side ('right'|'left'), context, closeLabel,
// account (props of DotAccountMenu), guest (0.6.0: { login, register }, two full-width rows at the TOP of the drawer). Slots: default (replaces the nav sections), before, after, guest.
import { computed } from 'vue';
import DotAccountMenu from './DotAccountMenu.vue';
import DotGuest from './DotGuest.vue';

const props = defineProps({
    id: { type: String, default: 'dh-drawer' },
    nav: { type: Array, default: () => [] },
    title: { type: String, default: 'Menu' },
    side: { type: String, default: 'right' },
    context: { type: String, default: null },
    closeLabel: { type: String, default: 'Close menu' },
    account: { type: Object, default: null },
    guest: { type: Object, default: null },
});

// group ids count groups only (dh-drawer-g1, -g2), as the Blade's do
const hasNav = computed(() => props.nav.some((item) => (item.items && item.items.length) || item.href));
const groups = computed(() => {
    let g = 0;
    return props.nav.map((item) => (item.items && item.items.length ? { ...item, gid: `${props.id}-g${++g}`, open: !!item.open || item.items.some((s) => s && s.current) } : item));
});
</script>

<template>
    <div class="dh-drawer" :id="id" data-dh-drawer :data-side="side" hidden>
        <div class="dh-drawer__scrim" data-dh-scrim aria-hidden="true"></div>
        <div class="dh-drawer__panel" role="dialog" aria-modal="true" :aria-label="title" tabindex="-1">
            <div class="dh-drawer__head">
                <span class="dh-drawer__title">{{ title }}</span>
                <button type="button" class="dh-icon-btn" data-dh-close :aria-label="closeLabel"><svg class="dh-ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6L6 18" /></svg></button>
            </div>
            <div class="dh-drawer__body">
                <p v-if="context" class="dh-drawer__context">{{ context }}</p>
                <slot name="guest"><DotGuest v-if="guest" inline v-bind="guest" /></slot>
                <slot name="before" />
                <slot>
                    <nav v-if="hasNav" class="dh-dnav" :aria-label="title">
                        <ul>
                            <template v-for="(item, i) in groups" :key="i">
                                <li v-if="item.items && item.items.length" class="dh-dgroup">
                                    <button type="button" class="dh-dgroup__btn" data-dh-disclosure :aria-expanded="item.open ? 'true' : 'false'" :aria-controls="item.gid"><span class="dh-menu__label">{{ item.label }}</span><span class="dh-chev" aria-hidden="true"></span></button>
                                    <ul :id="item.gid" class="dh-dgroup__list" :hidden="!item.open">
                                        <li v-for="(sub, j) in item.items.filter((s) => s.href)" :key="j"><a class="dh-dnav__link" :href="sub.href" :aria-current="sub.current ? 'page' : null" v-bind="sub.attrs || {}"><span class="dh-menu__label">{{ sub.label }}</span><span v-if="sub.badge !== undefined && sub.badge !== null && sub.badge !== ''" class="dh-count">{{ sub.badge }}</span></a></li>
                                    </ul>
                                </li>
                                <li v-else-if="item.href"><a class="dh-dnav__link" :href="item.href" :aria-current="item.current ? 'page' : null" v-bind="item.attrs || {}"><span class="dh-menu__label">{{ item.label }}</span><span v-if="item.badge !== undefined && item.badge !== null && item.badge !== ''" class="dh-count">{{ item.badge }}</span></a></li>
                            </template>
                        </ul>
                    </nav>
                </slot>
                <DotAccountMenu v-if="account" inline v-bind="account" />
                <slot name="after" />
            </div>
        </div>
    </div>
</template>
