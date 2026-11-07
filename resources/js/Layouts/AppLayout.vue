<script setup>
// The frame of every account page (profile, teams, API tokens, operator pages): a thin wrapper around PressLayout so
// they share the library header, the team switcher, the theme toggle and the account menu. It adds the flash banner,
// a small account navigation and the page title. PressLayout writes the page's single h1 (visually hidden); the title
// shown here is the same words as a plain line.
import '../../css/press/account.css';
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Banner from '@/Components/Banner.vue';
import PressLayout from '@/Layouts/PressLayout.vue';

defineProps({
    title: { type: String, default: 'Account' },
});

const page = usePage();
const user = computed(() => page.props.auth?.user || {});
const team = computed(() => user.value.current_team || null);
const has = (name) => { try { return route().has(name); } catch { return false; } };
const here = (name) => { try { return route().current(name); } catch { return false; } };

const links = computed(() => [
    { label: 'Library', href: route('dashboard'), current: here('dashboard') },
    ...(has('profile.show') ? [{ label: 'Profile', href: route('profile.show'), current: here('profile.show') }] : []),
    ...(team.value && has('teams.show') ? [{ label: 'Team settings', href: route('teams.show', team.value.id), current: here('teams.show') || here('teams.create') }] : []),
    ...(page.props.jetstream?.hasApiFeatures && has('api-tokens.index') ? [{ label: 'API tokens', href: route('api-tokens.index'), current: here('api-tokens.index') }] : []),
]);
</script>

<template>
    <PressLayout :title="title">
        <Banner />
        <div class="ac">
            <nav class="ac-nav" aria-label="Account">
                <Link v-for="l in links" :key="l.label" :href="l.href" :aria-current="l.current ? 'page' : undefined">
                    <span v-if="l.current" class="pr-bead pr-bead--sm" data-state="solid" aria-hidden="true" />{{ l.label }}
                </Link>
            </nav>
            <p class="ac-title" aria-hidden="true">{{ title }}</p>
            <slot />
        </div>
    </PressLayout>
</template>
