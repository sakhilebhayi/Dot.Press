<script setup>
// DotAccountMenu: the avatar button and its menu, or (inline) the expanded list for the drawer. Same markup and classes as
// blade/account-menu.blade.php; behaviour is js/dot-nav.js (open, Escape, arrows, outside click, theme buttons).
// Props: name, email, team, initials, avatar, links [{label, href, badge, current, rule} | {label, form: {action, method, fields}} | {label, button, attrs} | {heading}], signOut {action, method, label},
// csrf (a token string: an Inertia host passes the page's), theme (Light / Dark / System buttons), inline, id, label.
import { computed } from 'vue';
import DotMenuItem from './DotMenuItem.vue';

const props = defineProps({
    name: { type: String, default: '' },
    email: { type: String, default: null },
    team: { type: String, default: null },
    initials: { type: String, default: null },
    avatar: { type: String, default: null },
    links: { type: Array, default: () => [] },
    signOut: { type: Object, default: null },
    csrf: { type: String, default: null },
    theme: { type: Boolean, default: false },
    inline: { type: Boolean, default: false },
    id: { type: String, default: 'dh-account-menu' },
    label: { type: String, default: 'Account menu' },
});

const letters = computed(() => props.initials || String(props.name).trim().split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join(''));
const out = computed(() => (props.signOut ? { method: 'post', label: 'Sign out', ...props.signOut } : null));
const outMethod = computed(() => (out.value && String(out.value.method).toLowerCase() === 'get' ? 'get' : 'post'));
</script>

<template>
    <div v-if="!inline" class="dh-account" data-dh-menu data-dh-account>
        <button type="button" class="dh-account__btn" data-dh-menu-trigger aria-expanded="false" :aria-controls="id" :aria-label="name ? `${label}: ${name}` : label">
            <span class="dh-avatar" aria-hidden="true"><img v-if="avatar" :src="avatar" alt="" width="34" height="34" /><template v-else>{{ letters }}</template></span>
        </button>
        <div :id="id" class="dh-menu dh-menu--end" data-dh-menu-panel hidden>
            <div class="dh-menu__who">
                <span class="dh-menu__name" :title="name">{{ name }}</span>
                <span v-if="email" class="dh-menu__sub" :title="email">{{ email }}</span>
                <span v-if="team" class="dh-menu__sub" :title="team">{{ team }}</span>
            </div>
            <div class="dh-menu__rule" role="separator"></div>
            <DotMenuItem v-for="(link, i) in links" :key="i" :item="link" :csrf="csrf" />
            <template v-if="theme">
                <div class="dh-menu__rule" role="separator"></div>
                <div class="dh-theme" role="group" aria-label="Theme">
                    <button type="button" class="dh-theme__btn" data-dh-theme-set="light" aria-pressed="false">Light</button>
                    <button type="button" class="dh-theme__btn" data-dh-theme-set="dark" aria-pressed="false">Dark</button>
                    <button type="button" class="dh-theme__btn" data-dh-theme-set="system" aria-pressed="false">System</button>
                </div>
            </template>
            <template v-if="out">
                <div class="dh-menu__rule" role="separator"></div>
                <form class="dh-signout" :method="outMethod" :action="out.action">
                    <input v-if="csrf && outMethod === 'post'" type="hidden" name="_token" :value="csrf" />
                    <button type="submit" class="dh-menu__item">{{ out.label }}</button>
                </form>
            </template>
        </div>
    </div>
    <section v-else class="dh-dacct" aria-label="Account">
        <div class="dh-dacct__who">
            <span class="dh-avatar" aria-hidden="true"><img v-if="avatar" :src="avatar" alt="" width="34" height="34" /><template v-else>{{ letters }}</template></span>
            <span>
                <span class="dh-menu__name" :title="name">{{ name }}</span>
                <span v-if="email" class="dh-menu__sub" :title="email">{{ email }}</span>
                <span v-if="team" class="dh-menu__sub" :title="team">{{ team }}</span>
            </span>
        </div>
        <DotMenuItem v-for="(link, i) in links" :key="i" :item="link" :csrf="csrf" />
        <div v-if="theme" class="dh-theme" role="group" aria-label="Theme">
            <button type="button" class="dh-theme__btn" data-dh-theme-set="light" aria-pressed="false">Light</button>
            <button type="button" class="dh-theme__btn" data-dh-theme-set="dark" aria-pressed="false">Dark</button>
            <button type="button" class="dh-theme__btn" data-dh-theme-set="system" aria-pressed="false">System</button>
        </div>
        <form v-if="out" class="dh-signout" :method="outMethod" :action="out.action">
            <input v-if="csrf && outMethod === 'post'" type="hidden" name="_token" :value="csrf" />
            <button type="submit" class="dh-menu__item">{{ out.label }}</button>
        </form>
    </section>
</template>
