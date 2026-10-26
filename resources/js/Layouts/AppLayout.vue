<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Banner from '@/Components/Banner.vue';

defineProps({
    title: String,
});

const showingMobileMenu = ref(false);
const showingUserMenu = ref(false);

const logout = () => {
    router.post(route('logout'));
};
</script>

<template>
    <div>
        <Head :title="title" />
        <Banner />

        <div class="min-h-screen bg-[#0c0f1a] text-white">

            <!-- Top Nav -->
            <nav class="border-b border-white/10 bg-[#0c0f1a]/90 backdrop-blur-md sticky top-0 z-50">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="flex items-center justify-between h-16">

                        <!-- Logo -->
                        <Link :href="route('dashboard')" class="flex items-center gap-3 shrink-0">
                            <img src="/dot_pres.png" alt="Dot.Press" class="h-8 w-auto drop-shadow-lg" />
                        </Link>

                        <!-- Desktop nav links -->
                        <div class="hidden sm:flex items-center gap-1">
                            <Link
                                :href="route('dashboard')"
                                class="px-4 py-2 rounded-lg text-sm font-medium transition"
                                :class="route().current('dashboard') ? 'bg-white/10 text-white' : 'text-white/60 hover:text-white hover:bg-white/5'"
                            >
                                Dashboard
                            </Link>
                            <Link
                                :href="route('editor.start')"
                                class="px-4 py-2 rounded-lg text-sm font-medium transition"
                                :class="route().current('editor.*') ? 'bg-white/10 text-white' : 'text-white/60 hover:text-white hover:bg-white/5'"
                            >
                                New Presentation
                            </Link>
                        </div>

                        <!-- Desktop right side -->
                        <div class="hidden sm:flex items-center gap-3">
                            <div class="relative">
                                <button
                                    @click="showingUserMenu = !showingUserMenu"
                                    @blur.capture="() => setTimeout(() => showingUserMenu = false, 150)"
                                    class="flex items-center gap-2 rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm font-medium text-white/80 transition hover:bg-white/10"
                                >
                                    <img
                                        v-if="$page.props.jetstream.managesProfilePhotos"
                                        class="size-6 rounded-full object-cover"
                                        :src="$page.props.auth.user.profile_photo_url"
                                        :alt="$page.props.auth.user.name"
                                    />
                                    <span v-else class="size-6 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-xs font-bold text-white shrink-0">
                                        {{ $page.props.auth.user.name.charAt(0).toUpperCase() }}
                                    </span>
                                    <span class="max-w-[120px] truncate">{{ $page.props.auth.user.name }}</span>
                                    <svg class="size-4 text-white/40 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>

                                <transition
                                    enter-active-class="transition ease-out duration-100"
                                    enter-from-class="opacity-0 scale-95"
                                    enter-to-class="opacity-100 scale-100"
                                    leave-active-class="transition ease-in duration-75"
                                    leave-from-class="opacity-100 scale-100"
                                    leave-to-class="opacity-0 scale-95"
                                >
                                    <div
                                        v-if="showingUserMenu"
                                        class="absolute right-0 mt-2 w-56 rounded-2xl border border-white/10 bg-[#14172a] shadow-2xl py-1.5 origin-top-right"
                                    >
                                        <div class="px-4 py-2 border-b border-white/10 mb-1">
                                            <p class="text-xs text-white/40 truncate">{{ $page.props.auth.user.email }}</p>
                                        </div>
                                        <Link :href="route('profile.show')" class="flex items-center gap-2 px-4 py-2 text-sm text-white/70 hover:bg-white/5 hover:text-white transition">
                                            <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                                            Profile
                                        </Link>
                                        <Link v-if="$page.props.jetstream.hasApiFeatures" :href="route('api-tokens.index')" class="flex items-center gap-2 px-4 py-2 text-sm text-white/70 hover:bg-white/5 hover:text-white transition">
                                            <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                                            API Tokens
                                        </Link>
                                        <div class="border-t border-white/10 my-1"></div>
                                        <form @submit.prevent="logout">
                                            <button type="submit" class="flex w-full items-center gap-2 px-4 py-2 text-sm text-white/70 hover:bg-white/5 hover:text-white transition">
                                                <svg class="size-4 shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" /></svg>
                                                Sign out
                                            </button>
                                        </form>
                                    </div>
                                </transition>
                            </div>
                        </div>

                        <!-- Mobile hamburger -->
                        <button
                            class="sm:hidden p-2 rounded-lg text-white/60 hover:text-white hover:bg-white/5 transition"
                            aria-label="Toggle navigation menu"
                            :aria-expanded="showingMobileMenu"
                            @click="showingMobileMenu = !showingMobileMenu"
                        >
                            <svg v-if="!showingMobileMenu" class="size-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                            <svg v-else class="size-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Mobile menu -->
                <div v-if="showingMobileMenu" class="sm:hidden border-t border-white/10 bg-[#0c0f1a]">
                    <div class="px-4 pt-3 pb-2 space-y-1">
                        <Link :href="route('dashboard')" class="block px-3 py-2 rounded-lg text-sm font-medium text-white/80 hover:bg-white/5 hover:text-white transition">Dashboard</Link>
                        <Link :href="route('editor.start')" class="block px-3 py-2 rounded-lg text-sm font-medium text-white/80 hover:bg-white/5 hover:text-white transition">New Presentation</Link>
                    </div>
                    <div class="border-t border-white/10 px-4 py-3 space-y-1">
                        <div class="flex items-center gap-3 px-3 py-2 mb-1">
                            <span class="size-8 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center text-sm font-bold text-white shrink-0">
                                {{ $page.props.auth.user.name.charAt(0).toUpperCase() }}
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-white truncate">{{ $page.props.auth.user.name }}</p>
                                <p class="text-xs text-white/40 truncate">{{ $page.props.auth.user.email }}</p>
                            </div>
                        </div>
                        <Link :href="route('profile.show')" class="block px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/5 hover:text-white transition">Profile</Link>
                        <form @submit.prevent="logout">
                            <button type="submit" class="block w-full text-left px-3 py-2 rounded-lg text-sm text-white/70 hover:bg-white/5 hover:text-white transition">Sign out</button>
                        </form>
                    </div>
                </div>
            </nav>

            <!-- Optional page heading slot -->
            <header v-if="$slots.header" class="border-b border-white/10">
                <div class="max-w-7xl mx-auto py-5 px-4 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
