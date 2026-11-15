<script setup>
// The one Dot.Press top bar (R-52): the shared dot-ui header, filled with Press's own logo, routes, team and account.
// Used by the library shell (PressLayout, so the account pages too) and by the editor, on every width.
//   - brand      the original Dot.Press logo files (light ground / dark ground), never redrawn
//   - nav        Library, and a Team group (team settings, new team): the same list prints the bar and the phone drawer
//   - search     the pill. A page may fill the `search` slot with its own (the library does: its query, debounce and
//                address are unchanged). Everywhere else it opens the library with the query (GET /dashboard?q=).
//   - tools      the Dot Apps switcher (deferred Inertia prop, never blocks a page)
//   - account    avatar menu on desktop, expanded in the drawer on a phone: profile, API tokens, team switch, theme, sign out
// Press has no notifications feature, so there is no bell (nothing is faked).
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import DotHeader from '@/dot-ui/DotHeader.vue';
import DotSearch from '@/dot-ui/DotSearch.vue';
import DotSwitcher from '@/dot-ui/DotSwitcher.vue';

const props = defineProps({
    // the library shell positions the header itself; the editor puts it in its own grid
    sticky: { type: Boolean, default: true },
    // "Ctrl K" focuses the search. The editor has no hint (its own shortcuts own the keyboard).
    searchHint: { type: String, default: 'Ctrl K' },
    // the id of the page's main region, for the skip link
    skip: { type: String, default: '#lib-main' },
});

const page = usePage();
const dotModel = ref(null);
watch(() => page.props.dotSwitcher, (m) => { if (m) dotModel.value = m; }, { immediate: true });

const user = computed(() => page.props.auth?.user || {});
const teams = computed(() => user.value.all_teams || []);
const current = computed(() => user.value.current_team || null);
const canCreateTeams = computed(() => !!page.props.jetstream?.canCreateTeams);
const has = (name) => { try { return route().has(name); } catch { return false; } };
const here = (name) => { try { return route().current(name); } catch { return false; } };
const initials = computed(() => String(user.value.name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join(''));

function switchTeam(team) {
    if (current.value && team.id === current.value.id) return;
    router.put(route('current-team.update'), { team_id: team.id }, { preserveState: false });
}

const nav = computed(() => {
    const team = [
        ...(current.value && has('teams.show') ? [{ label: 'Team settings', href: route('teams.show', current.value.id), current: here('teams.show') }] : []),
        ...(canCreateTeams.value && has('teams.create') ? [{ label: 'New team', href: route('teams.create'), current: here('teams.create') }] : []),
    ];
    return [
        { label: 'Library', href: route('dashboard'), current: here('dashboard') },
        ...(team.length ? [{ label: 'Team', items: team }] : []),
    ];
});

const account = computed(() => ({
    name: user.value.name || '',
    email: user.value.email || null,
    team: current.value?.name || null,
    initials: initials.value,
    links: [
        ...(has('profile.show') ? [{ label: 'Profile', href: route('profile.show'), current: here('profile.show') }] : []),
        ...(page.props.jetstream?.hasApiFeatures && has('api-tokens.index') ? [{ label: 'API tokens', href: route('api-tokens.index'), current: here('api-tokens.index') }] : []),
        // switching team is a PUT, so each row is a link that runs it (the header's own click handler leaves "#" alone)
        ...(teams.value.length > 1 ? [{ rule: true }, ...teams.value.map((t) => ({ label: `Switch to ${t.name}`, href: '#', current: current.value?.id === t.id, attrs: { onClick: (e) => { e.preventDefault(); switchTeam(t); } } }))] : []),
    ],
    signOut: { action: has('logout') ? route('logout') : '/logout' },
    theme: true,
}));

// ---- search outside the library: open the library with the query ----------------------------------------------------
const q = ref('');
function submitSearch(value) {
    const text = String(value || '').trim();
    router.get(route('dashboard'), text ? { q: text } : {});
}

// ---- links stay inside the app (no full reload) and sign out goes through Inertia, which carries the CSRF token -------
function onDocClick(e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest?.('.dh a[href], .dh-drawer a[href]');
    if (!a || a.target === '_blank' || a.hasAttribute('download')) return;
    const href = a.getAttribute('href');
    if (!href || href.startsWith('#')) return;
    let url;
    try { url = new URL(a.href, window.location.href); } catch { return; }
    if (url.origin !== window.location.origin) return;
    e.preventDefault();
    router.visit(url.pathname + url.search + url.hash);
}
function onDocSubmit(e) {
    const f = e.target;
    if (!f?.classList?.contains('dh-signout')) return;
    e.preventDefault();
    router.post(f.getAttribute('action') || '/logout');
}
onMounted(() => {
    document.addEventListener('click', onDocClick);
    document.addEventListener('submit', onDocSubmit);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('submit', onDocSubmit);
});
</script>

<template>
    <DotHeader
        brand-href="/dashboard" brand-label="Dot.Press library" :nav="nav" :account="account" :context="current ? current.name : null" :sticky="sticky"
        :skip="skip" drawer-title="Menu" menu-label="Menu" search-label="Search presentations" close-search-label="Close search"
    >
        <template #brand>
            <img class="pr-hdr-logo pr-hdr-logo--light" src="/images/logo.png" alt="" width="32" height="32" decoding="async">
            <img class="pr-hdr-logo pr-hdr-logo--dark" src="/images/logo-light.png" alt="" width="32" height="32" decoding="async">
            <span class="dh-brand__name">Dot.Press</span>
        </template>
        <template #search>
            <slot name="search">
                <DotSearch v-model="q" name="q" placeholder="Search presentations" label="Search presentations" :hint="searchHint || null" @submit="submitSearch" />
            </slot>
        </template>
        <template #tools>
            <DotSwitcher v-if="dotModel" :model="dotModel" :company-name="dotModel.company_name" logos="/dot-ui/img/platforms" />
        </template>
    </DotHeader>
</template>
