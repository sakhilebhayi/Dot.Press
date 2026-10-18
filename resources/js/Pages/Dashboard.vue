<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { computed, ref, nextTick } from 'vue';
import axios from 'axios';

const props = defineProps({
    projects: {
        type: Array,
        default: () => [],
    },
});

const totalDecks = computed(() =>
    props.projects.reduce((sum, p) => sum + (p.decks?.length ?? 0), 0)
);

// Inline rename state
const editingDeckId = ref(null);
const editingTitle = ref('');

function startRename(deck) {
    editingDeckId.value = deck.id;
    editingTitle.value = deck.title;
    nextTick(() => {
        const el = document.querySelector('.deck-rename-input');
        el?.focus();
        el?.select();
    });
}

async function commitRename(deck) {
    const newTitle = editingTitle.value.trim();
    if (newTitle && newTitle !== deck.title) {
        try {
            await axios.patch(`/api/decks/${deck.id}`, { title: newTitle });
            deck.title = newTitle;
        } catch {
            // revert silently
        }
    }
    editingDeckId.value = null;
    editingTitle.value = '';
}

function cancelRename() {
    editingDeckId.value = null;
    editingTitle.value = '';
}
</script>

<template>
    <AppLayout title="Dashboard — Dot.Press">

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">

            <!-- Page header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-10">
                <div>
                    <h1 class="text-2xl font-bold text-white">My Presentations</h1>
                    <p class="text-white/50 text-sm mt-1">
                        {{ totalDecks }} deck{{ totalDecks !== 1 ? 's' : '' }} across {{ projects.length }} project{{ projects.length !== 1 ? 's' : '' }}
                    </p>
                </div>
                <Link
                    :href="route('editor.start')"
                    class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-amber-400 to-orange-500 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-amber-500/25 transition hover:brightness-110 active:scale-95 shrink-0"
                >
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    New Presentation
                </Link>
            </div>

            <!-- Empty state -->
            <div
                v-if="projects.length === 0"
                class="flex flex-col items-center justify-center rounded-3xl border border-dashed border-white/15 bg-white/[0.02] px-8 py-20 text-center"
            >
                <!-- Decorative slide icon -->
                <div class="mb-6 w-20 h-20 rounded-2xl bg-gradient-to-br from-amber-400/20 to-orange-500/20 border border-white/10 flex items-center justify-center">
                    <svg class="size-10 text-amber-400/70" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5" />
                    </svg>
                </div>
                <h3 class="text-lg font-semibold text-white mb-2">No presentations yet</h3>
                <p class="text-white/50 text-sm max-w-sm mb-8">
                    Start building your first slide deck with our canvas editor and AI-powered tools.
                </p>
                <Link
                    :href="route('editor.start')"
                    class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-amber-400 to-orange-500 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-amber-500/25 transition hover:brightness-110 active:scale-95"
                >
                    <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Create your first presentation
                </Link>
            </div>

            <!-- Projects list -->
            <div v-else class="space-y-10">
                <div v-for="project in projects" :key="project.id">

                    <!-- Project header -->
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h2 class="text-base font-semibold text-white">{{ project.name }}</h2>
                            <p v-if="project.description" class="text-xs text-white/40 mt-0.5">{{ project.description }}</p>
                        </div>
                        <span class="text-xs text-white/30">
                            {{ project.decks?.length ?? 0 }} deck{{ (project.decks?.length ?? 0) !== 1 ? 's' : '' }}
                        </span>
                    </div>

                    <!-- Empty project -->
                    <div
                        v-if="!project.decks || project.decks.length === 0"
                        class="rounded-2xl border border-dashed border-white/10 bg-white/[0.02] px-6 py-8 text-center"
                    >
                        <p class="text-sm text-white/40">No decks in this project yet.</p>
                        <Link :href="route('editor.start')" class="inline-block mt-3 text-sm text-amber-400 hover:text-amber-300 transition">
                            Add a deck →
                        </Link>
                    </div>

                    <!-- Decks list -->
                    <div v-else class="rounded-2xl border border-white/10 bg-white/[0.03] overflow-hidden [&>*+*]:border-t [&>*+*]:border-white/[0.06]">
                        <div
                            v-for="deck in project.decks"
                            :key="deck.id"
                            class="flex items-center gap-4 px-5 py-4 hover:bg-white/[0.04] transition group"
                        >
                            <!-- Deck icon -->
                            <div class="shrink-0 w-10 h-10 rounded-xl bg-gradient-to-br from-amber-400/15 to-orange-500/15 border border-white/10 flex items-center justify-center">
                                <svg class="size-5 text-amber-400/80" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5" />
                                </svg>
                            </div>

                            <!-- Deck info -->
                            <div class="flex-1 min-w-0">
                                <!-- Inline rename input -->
                                <input
                                    v-if="editingDeckId === deck.id"
                                    class="deck-rename-input w-full bg-transparent border-b border-amber-400/60 text-sm font-medium text-white outline-none focus:border-amber-400 py-0.5 pr-2"
                                    v-model="editingTitle"
                                    @keydown.enter="commitRename(deck)"
                                    @keydown.escape="cancelRename"
                                    @blur="commitRename(deck)"
                                />
                                <p v-else class="text-sm font-medium text-white truncate">{{ deck.title }}</p>
                                <p class="text-xs text-white/40 mt-0.5">
                                    {{ deck.slides_count ?? 0 }} slide{{ (deck.slides_count ?? 0) !== 1 ? 's' : '' }}
                                    <span v-if="deck.is_template" class="ml-2 inline-flex items-center rounded-full bg-amber-400/10 border border-amber-400/20 px-2 py-0.5 text-amber-400 text-[10px] font-medium">Template</span>
                                </p>
                            </div>

                            <!-- Updated date -->
                            <span class="hidden sm:block text-xs text-white/30 shrink-0">
                                {{ new Date(deck.updated_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) }}
                            </span>

                            <!-- Actions -->
                            <div class="flex items-center gap-2 shrink-0 opacity-0 group-hover:opacity-100 transition">
                                <button
                                    type="button"
                                    class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-medium text-white hover:bg-white/20 transition"
                                    title="Rename"
                                    @click.prevent="startRename(deck)"
                                >
                                    <svg class="size-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                    </svg>
                                </button>
                                <Link
                                    :href="route('editor.start', { deck_id: deck.id })"
                                    class="rounded-lg bg-white/10 px-3 py-1.5 text-xs font-medium text-white hover:bg-white/20 transition"
                                >
                                    Edit
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
