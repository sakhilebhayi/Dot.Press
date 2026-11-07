<script setup>
// The frame of the library: the Press mark, the team switcher, search, New, the person menu and the theme toggle.
import '../../css/press/tokens.css';
import '../../css/press/fonts-ui.css';
import '../../css/press/base.css';
import '../../css/press/library.css';
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import DropMenu from '@/press/editor/ui/DropMenu.vue';
import Ico from '@/press/editor/ui/Ico.vue';
import ThemeToggle from '@/press/editor/ui/ThemeToggle.vue';

defineProps({ title: { type: String, default: 'Presentations' } });

const page = usePage();
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
                <svg class="lh-dot" viewBox="0 0 32 12" aria-hidden="true"><line x1="0" y1="6" x2="32" y2="6" stroke="currentColor" stroke-width="1.5" /><circle cx="9" cy="6" r="4.25" fill="var(--amber)" stroke="currentColor" stroke-width="1.5" /></svg>
                <span class="lh-word">Press</span>
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
            <h1 class="pr-sr">{{ title }}</h1>
            <slot />
        </main>
    </div>
</template>
