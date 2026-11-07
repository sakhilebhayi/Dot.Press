<script setup>
// The guest shell: the mark, one quiet column, the legal links. Used by sign in, password reset, confirmation and the
// legal pages. The page opens on its own rail: a hairline with the amber dot, which settles into place once.
import '../../css/press/tokens.css';
import '../../css/press/fonts-ui.css';
import '../../css/press/base.css';
import '../../css/press/account.css';
import { Link } from '@inertiajs/vue3';
import ThemeToggle from '@/press/editor/ui/ThemeToggle.vue';

defineProps({ wide: { type: Boolean, default: false } });
const has = (name) => { try { return route().has(name); } catch { return false; } };
</script>

<template>
    <div class="pr gs">
        <a class="pr-skip" href="#gs-main">Skip to content</a>
        <header class="gs-top">
            <slot name="logo" />
            <ThemeToggle />
        </header>
        <main id="gs-main" class="gs-main" tabindex="-1">
            <div class="gs-col" :class="{ 'gs-col--wide': wide }">
                <div class="gs-rail" aria-hidden="true"><i /></div>
                <slot />
            </div>
        </main>
        <footer class="gs-legal">
            <Link v-if="has('terms.show')" :href="route('terms.show')">Terms</Link>
            <Link v-if="has('policy.show')" :href="route('policy.show')">Privacy</Link>
            <Link v-if="has('cookies')" :href="route('cookies')">Cookies</Link>
        </footer>
    </div>
</template>
