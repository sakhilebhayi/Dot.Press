<script setup>
// The frame of the library and of every account page: the shared Dot header (PressHeader: logo, nav, search, Dot Apps
// switcher, account menu, phone drawer), then the page's main region and an optional foot.
import '../../css/press/tokens.css';
import '../../css/press/base.css';
import '../../css/press/library.css';
import '../../css/press/dotui.css';
import { Head } from '@inertiajs/vue3';
import PressHeader from '@/press/ui/PressHeader.vue';

// ownHeading: the page draws its own visible h1 (the library's page bar), so the layout adds none.
// shell: the library's three-row frame (header, one working region, foot): the page does not scroll, the region does.
defineProps({ title: { type: String, default: 'Presentations' }, ownHeading: { type: Boolean, default: false }, shell: { type: Boolean, default: false } });
</script>

<template>
    <Head :title="title" />
    <div class="pr lib" :class="{ 'lib--shell': shell }">
        <PressHeader :sticky="!shell">
            <template v-if="$slots.search" #search><slot name="search" /></template>
        </PressHeader>
        <main id="lib-main" class="lm" tabindex="-1">
            <h1 v-if="!ownHeading" class="pr-sr">{{ title }}</h1>
            <slot />
        </main>
        <slot name="foot" />
    </div>
</template>
