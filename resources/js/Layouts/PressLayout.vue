<script setup>
// The frame of the library: the Press logo, the team switcher, search, the Dot Apps switcher, New, the person menu and the theme toggle.
import '../../css/press/tokens.css';
import '../../css/press/fonts-ui.css';
import '../../css/press/base.css';
import '../../css/press/library.css';
import '../../css/press/dotui.css';
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import DropMenu from '@/press/editor/ui/DropMenu.vue';
import Ico from '@/press/editor/ui/Ico.vue';
import ThemeToggle from '@/press/editor/ui/ThemeToggle.vue';
import DotSwitcher from '@/dot-ui/DotSwitcher.vue';

// ownHeading: the page draws its own visible h1 (the library's landing block), so the layout adds none.
defineProps({ title: { type: String, default: 'Presentations' }, ownHeading: { type: Boolean, default: false } });

// The Dot Apps model arrives as a deferred prop (the page never waits on InfoDot). A page change drops and re-fetches a
// deferred prop, so the last model is kept here and the button does not blink between pages.
const dotModel = ref(null);
const page = usePage();
watch(() => page.props.dotSwitcher, (m) => { if (m) dotModel.value = m; }, { immediate: true });
const user = computed(() => page.props.auth?.user || {});
const teams = computed(() => user.value.all_teams || []);
const current = computed(() => user.value.current_team || null);
const canCreateTeams = computed(() => !!page.props.jetstream?.canCreateTeams);
const has = (name) => { try { return route().has(name); } catch { return false; } };
const initials = computed(() => String(user.value.name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join(''));

function switchTeam(team) {
    if (current.value && team.id === current.value.id) return;
    router.put(route('current-team.update'), { team_id: team.id }, { preserveState: false });
}
const teamItems = computed(() => [
    ...(teams.value.length > 1 ? [{ heading: 'Switch team' }] : []),
    ...teams.value.map((t) => ({ label: t.name, checked: current.value?.id === t.id, run: () => switchTeam(t) })),
    ...(teams.value.length ? [{ separator: true }] : []),
    ...(current.value && has('teams.show') ? [{ label: 'Team settings', icon: 'gear', href: route('teams.show', current.value.id) }] : []),
    ...(canCreateTeams.value && has('teams.create') ? [{ label: 'New team', icon: 'plus', href: route('teams.create') }] : []),
]);
const userItems = computed(() => [
    { heading: user.value.email || user.value.name },
    // the team button leaves the header on a phone, so the account menu carries the switch
    ...(teams.value.length > 1 ? [{ heading: 'Switch team' }, ...teams.value.map((t) => ({ label: t.name, checked: current.value?.id === t.id, run: () => switchTeam(t) })), { separator: true }] : []),
    ...(has('profile.show') ? [{ label: 'Profile', icon: 'user', href: route('profile.show') }] : []),
    ...(current.value && has('teams.show') ? [{ label: 'Teams', icon: 'users', href: route('teams.show', current.value.id) }] : []),
    ...(page.props.jetstream?.hasApiFeatures && has('api-tokens.index') ? [{ label: 'API tokens', icon: 'key', href: route('api-tokens.index') }] : []),
    { separator: true },
    { label: 'Sign out', icon: 'arrow-right', run: () => router.post(route('logout')) },
]);
</script>

<template>
    <Head :title="title" />
    <div class="pr lib">
        <a class="pr-skip" href="#lib-main">Skip to presentations</a>
        <header class="lh">
            <Link :href="route('dashboard')" class="lh-mark" aria-label="Dot.Press library">
                <img class="lh-logo lh-logo--light" src="/images/logo.png" alt="" width="44" height="44" decoding="async" />
                <img class="lh-logo lh-logo--dark" src="/images/logo-light.png" alt="" width="44" height="44" decoding="async" />
            </Link>
            <DropMenu v-if="current" :items="teamItems" label="Team" :min-width="220">
                <template #trigger="{ toggle, open }">
                    <button type="button" class="pr-btn pr-btn--ghost lh-team" aria-haspopup="menu" :aria-expanded="open" @click="toggle">
                        <span class="lh-team-n">{{ current.name }}</span><Ico name="chevronDown" size="sm" />
                    </button>
                </template>
            </DropMenu>
            <div class="lh-search"><slot name="search" /></div>
            <div class="lh-actions">
                <DotSwitcher v-if="dotModel" :model="dotModel" :company-name="dotModel.company_name" logos="/dot-ui/img/platforms" />
                <slot name="actions" />
                <ThemeToggle />
                <DropMenu :items="userItems" align="end" label="Account" :min-width="230">
                    <template #trigger="{ toggle, open }">
                        <button type="button" class="lh-user" aria-haspopup="menu" :aria-expanded="open" :aria-label="`Account menu for ${user.name}`" @click="toggle"><span class="pr-avatar" style="--c: var(--amber)" aria-hidden="true">{{ initials }}</span></button>
                    </template>
                </DropMenu>
            </div>
        </header>
        <main id="lib-main" class="lm" tabindex="-1">
            <h1 v-if="!ownHeading" class="pr-sr">{{ title }}</h1>
            <slot />
        </main>
    </div>
</template>
