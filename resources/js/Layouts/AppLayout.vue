<script setup>
// The frame of every account page (profile, teams, API tokens, operator pages): a thin wrapper around PressLayout so
// they share the shared Dot header (logo, Library and Team in the bar, search, Dot Apps switcher, account menu with profile,
// API tokens, team switch, theme and sign out, and the phone drawer). It adds the flash banner and the page title. The old
// second row of links (Library, Profile, Team settings, API tokens) is gone: the header holds every one of them. PressLayout
// writes the page's single h1 (visually hidden); the title shown here is the same words as a plain line.
import '../../css/press/account.css';
import Banner from '@/Components/Banner.vue';
import PressLayout from '@/Layouts/PressLayout.vue';

defineProps({
    title: { type: String, default: 'Account' },
});
</script>

<template>
    <PressLayout :title="title">
        <Banner />
        <div class="ac">
            <p class="ac-title" aria-hidden="true">{{ title }}</p>
            <slot />
        </div>
    </PressLayout>
</template>
