<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { generateHTML } from '@tiptap/html';
import StarterKit from '@tiptap/starter-kit';
import { TextStyle } from '@tiptap/extension-text-style';
import Color from '@tiptap/extension-color';
import Underline from '@tiptap/extension-underline';
import TextAlign from '@tiptap/extension-text-align';

const STAGE_WIDTH = 1280;
const STAGE_HEIGHT = 720;

const props = defineProps({
    deck: {
        type: Object,
        required: true,
    },
    slides: {
        type: Array,
        required: true,
    },
    slide: {
        type: Object,
        required: true,
    },
});

const elements = computed(() => {
    const state = props.slide.canvas_state ?? {};

    if (!Array.isArray(state.elements)) {
        return [];
    }

    return state.elements;
});

const currentIndex = computed(() => props.slides.findIndex((item) => item.id === props.slide.id));
const previousSlide = computed(() => currentIndex.value > 0 ? props.slides[currentIndex.value - 1] : null);
const nextSlide = computed(() => currentIndex.value >= 0 && currentIndex.value < props.slides.length - 1 ? props.slides[currentIndex.value + 1] : null);
const runtimeAssetUrls = ref({});
const stageContainerRef = ref(null);
const isFullscreen = ref(false);

const escapeHtml = (str) =>
    String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

const textHtml = (element) => {
    const richContent = element.richContent;

    if (!richContent || typeof richContent !== 'object') {
        return `<p>${escapeHtml(element.text)}</p>`;
    }

    return generateHTML(richContent, [
        StarterKit,
        TextStyle,
        Color,
        Underline,
        TextAlign.configure({ types: ['heading', 'paragraph'] }),
    ]);
};

const textStyle = (element) => ({
    color: element.fill ?? '#111827',
    fontSize: `${element.fontSize ?? 34}px`,
    lineHeight: `${element.lineHeight ?? 1.3}`,
    textAlign: element.textAlign ?? 'left',
    textIndent: `${element.textIndent ?? 0}px`,
    width: `${element.width ?? 280}px`,
    minHeight: `${element.height ?? 84}px`,
});

const styleFor = (element) => ({
    position: 'absolute',
    left: `${element.x ?? 0}px`,
    top: `${element.y ?? 0}px`,
    width: `${element.width ?? 120}px`,
    height: `${element.height ?? 80}px`,
    transform: `rotate(${element.rotation ?? 0}deg)`,
    transformOrigin: 'top left',
});

const imageStyle = (element) => ({
    width: `${element.width ?? 120}px`,
    height: `${element.height ?? 80}px`,
    objectFit: element.objectFit ?? 'contain',
    objectPosition: 'center',
    display: 'block',
});

const embedVideoUrl = (url, type) => {
    const input = String(url ?? '').trim();

    if (!input) {
        return '';
    }

    if (type === 'youtube') {
        const watchMatch = input.match(/(?:v=|youtu\.be\/)([A-Za-z0-9_-]{6,})/);
        const id = watchMatch?.[1];
        return id ? `https://www.youtube.com/embed/${id}` : input;
    }

    if (type === 'vimeo') {
        const match = input.match(/vimeo\.com\/(\d+)/);
        const id = match?.[1];
        return id ? `https://player.vimeo.com/video/${id}` : input;
    }

    return input;
};

const visualStyle = (element, defaults = {}) => ({
    background: element.fill ?? defaults.fill,
    border: `${element.strokeWidth ?? defaults.strokeWidth ?? 2}px solid ${element.stroke ?? defaults.stroke ?? 'transparent'}`,
    opacity: element.opacity ?? 1,
    boxShadow: element.shadowColor ? `0 0 ${element.shadowBlur ?? 0}px ${element.shadowColor}` : 'none',
});

const refreshAssetUrls = async () => {
    const targets = elements.value.filter((element) => element.type === 'image' && element.assetId && !element.assetUrl);

    elements.value
        .filter((element) => element.type === 'image' && element.assetUrl)
        .forEach((element) => {
            runtimeAssetUrls.value[element.id] = element.assetUrl;
        });

    for (const element of targets) {
        try {
            const response = await axios.get(`/api/assets/${element.assetId}`);
            runtimeAssetUrls.value[element.id] = response.data?.delivery_url ?? null;
        } catch {
            runtimeAssetUrls.value[element.id] = null;
        }
    }
};

const goToSlide = (targetSlide) => {
    if (!targetSlide) {
        return;
    }

    router.visit(route('presentation.slides.show', [props.deck.id, targetSlide.id]));
};

const toggleFullscreen = async () => {
    const element = stageContainerRef.value;

    if (!element) {
        return;
    }

    if (!document.fullscreenElement) {
        await element.requestFullscreen();
        return;
    }

    await document.exitFullscreen();
};

const onFullscreenChange = () => {
    isFullscreen.value = Boolean(document.fullscreenElement);
};

const onKeyDown = (event) => {
    if (event.key === 'ArrowRight' || event.key === 'PageDown') {
        event.preventDefault();
        goToSlide(nextSlide.value);
        return;
    }

    if (event.key === 'ArrowLeft' || event.key === 'PageUp') {
        event.preventDefault();
        goToSlide(previousSlide.value);
        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();

        if (document.fullscreenElement) {
            document.exitFullscreen();
            return;
        }

        router.visit(route('editor.slides.show', [props.deck.id, props.slide.id]));
        return;
    }

    if (event.key === 'Home') {
        event.preventDefault();
        goToSlide(props.slides[0] ?? null);
        return;
    }

    if (event.key === 'End') {
        event.preventDefault();
        goToSlide(props.slides[props.slides.length - 1] ?? null);
        return;
    }

    if (event.key.toLowerCase() === 'f') {
        event.preventDefault();
        toggleFullscreen();
    }
};

onMounted(() => {
    refreshAssetUrls();
    window.addEventListener('keydown', onKeyDown);
    document.addEventListener('fullscreenchange', onFullscreenChange);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeyDown);
    document.removeEventListener('fullscreenchange', onFullscreenChange);
});
</script>

<template>
    <div class="min-h-screen bg-gray-950 text-white">
        <Head :title="`Present - ${slide.title || 'Slide'}`" />

        <header class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
            <div>
                <p class="text-xs uppercase tracking-[0.16em] text-gray-400">{{ deck.project.name }}</p>
                <h1 class="text-lg font-semibold">{{ deck.title }} · {{ slide.title || `Slide ${slide.sort_order + 1}` }}</h1>
            </div>
            <div class="flex items-center gap-2">
                <Link
                    :href="route('editor.slides.show', [deck.id, slide.id])"
                    class="rounded border border-gray-700 px-3 py-2 text-sm text-gray-200 hover:bg-gray-900"
                >
                    Back to Editor
                </Link>
                <button
                    type="button"
                    class="rounded border border-gray-700 px-3 py-2 text-sm text-gray-200 hover:bg-gray-900"
                    @click="toggleFullscreen"
                >
                    {{ isFullscreen ? 'Exit Fullscreen' : 'Fullscreen' }}
                </button>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-6 pb-10">
            <div ref="stageContainerRef" class="rounded-xl border border-gray-700 bg-black/40 p-4">
                <div class="mx-auto overflow-hidden rounded-lg border border-gray-600 bg-white" :style="{ width: `${STAGE_WIDTH}px`, height: `${STAGE_HEIGHT}px` }">
                    <div class="relative h-full w-full bg-white text-gray-900">
                        <template v-for="element in elements" :key="element.id">
                            <div
                                v-if="element.type === 'text'"
                                :style="styleFor(element)"
                                class="presentation-text"
                            >
                                <div
                                    class="presentation-prose"
                                    :style="textStyle(element)"
                                    v-html="textHtml(element)"
                                />
                            </div>

                            <div
                                v-else-if="element.type === 'image'"
                                :style="styleFor(element)"
                                class="rounded-lg border-2 border-dashed border-slate-500 bg-slate-100 overflow-hidden"
                            >
                                <img v-if="runtimeAssetUrls[element.id]" :src="runtimeAssetUrls[element.id]" alt="Slide image" :style="imageStyle(element)">
                                <div v-else class="h-full w-full flex items-center justify-center text-slate-600 font-semibold">Image</div>
                            </div>

                            <div
                                v-else-if="element.type === 'group'"
                                :style="{ ...styleFor(element), ...visualStyle(element, { fill: '#eef2ff', stroke: '#4338ca', strokeWidth: 2 }), borderStyle: 'dashed' }"
                                class="rounded-xl border-2 border-dashed border-indigo-500 bg-indigo-50 text-indigo-700 p-3"
                            >
                                Group Container
                            </div>

                            <div
                                v-else-if="element.type === 'video'"
                                :style="{ ...styleFor(element), ...visualStyle(element, { fill: '#111827', stroke: '#334155', strokeWidth: 2 }), borderRadius: '10px', overflow: 'hidden' }"
                            >
                                <video
                                    v-if="element.videoType === 'direct' && element.videoUrl"
                                    class="h-full w-full object-cover"
                                    :src="element.videoUrl"
                                    :autoplay="Boolean(element.videoAutoplay)"
                                    :muted="Boolean(element.videoMuted ?? true)"
                                    :loop="Boolean(element.videoLoop)"
                                    controls
                                />
                                <iframe
                                    v-else-if="element.videoUrl"
                                    title="Embedded video"
                                    class="h-full w-full"
                                    :src="embedVideoUrl(element.videoUrl, element.videoType)"
                                    allow="autoplay; encrypted-media; picture-in-picture"
                                    allowfullscreen
                                />
                                <div v-else class="h-full w-full flex items-center justify-center text-slate-200 font-semibold">Video</div>
                            </div>

                            <div
                                v-else-if="element.type === 'icon'"
                                :style="{ ...styleFor(element), color: element.fill ?? '#111827', fontSize: `${element.fontSize ?? 72}px`, opacity: element.opacity ?? 1, textAlign: 'center', lineHeight: `${element.height ?? 96}px`, textShadow: element.shadowColor ? `0 0 ${element.shadowBlur ?? 0}px ${element.shadowColor}` : 'none' }"
                            >
                                {{ element.icon ?? '★' }}
                            </div>

                            <div
                                v-else-if="element.type === 'circle'"
                                :style="{ ...styleFor(element), ...visualStyle(element, { fill: '#fee2e2', stroke: '#dc2626', strokeWidth: 2 }), borderRadius: '9999px' }"
                            />

                            <div
                                v-else-if="element.type === 'line'"
                                :style="{ ...styleFor(element), height: `${element.height ?? 18}px`, display: 'flex', alignItems: 'center', opacity: element.opacity ?? 1 }"
                            >
                                <div :style="{ width: `${element.width ?? 260}px`, borderTop: `${element.strokeWidth ?? 4}px solid ${element.stroke ?? '#0f172a'}`, boxShadow: element.shadowColor ? `0 0 ${element.shadowBlur ?? 0}px ${element.shadowColor}` : 'none' }" />
                            </div>

                            <div
                                v-else-if="element.type === 'arrow'"
                                :style="{ ...styleFor(element), width: `${element.width ?? 260}px`, height: `${element.height ?? 30}px`, opacity: element.opacity ?? 1 }"
                            >
                                <svg :width="element.width ?? 260" :height="element.height ?? 30" viewBox="0 0 260 30" preserveAspectRatio="none">
                                    <defs>
                                        <marker id="arrowHead" markerWidth="8" markerHeight="8" refX="6" refY="4" orient="auto" markerUnits="strokeWidth">
                                            <path d="M0,0 L0,8 L8,4 z" :fill="element.stroke ?? '#1d4ed8'" />
                                        </marker>
                                    </defs>
                                    <line x1="0" y1="15" x2="250" y2="15" :stroke="element.stroke ?? '#1d4ed8'" :stroke-width="element.strokeWidth ?? 4" marker-end="url(#arrowHead)" />
                                </svg>
                            </div>

                            <div
                                v-else-if="element.type === 'polygon'"
                                :style="{ ...styleFor(element), opacity: element.opacity ?? 1 }"
                            >
                                <svg :width="element.width ?? 220" :height="element.height ?? 180" :viewBox="`0 0 ${element.width ?? 220} ${element.height ?? 180}`" preserveAspectRatio="none">
                                    <polygon :points="`${(element.width ?? 220) / 2},0 ${(element.width ?? 220)},${element.height ?? 180} 0,${element.height ?? 180}`" :fill="element.fill ?? '#dcfce7'" :stroke="element.stroke ?? '#15803d'" :stroke-width="element.strokeWidth ?? 2" />
                                </svg>
                            </div>

                            <div
                                v-else
                                :style="{ ...styleFor(element), ...visualStyle(element, { fill: '#dbeafe', stroke: '#2563eb', strokeWidth: 2 }), borderRadius: `${element.radius ?? 8}px` }"
                            />
                        </template>
                    </div>
                </div>
            </div>

            <div class="mt-4 flex items-center justify-between text-sm text-gray-300">
                <button
                    type="button"
                    class="rounded border border-gray-700 px-3 py-2 disabled:opacity-40"
                    :disabled="!previousSlide"
                    @click="goToSlide(previousSlide)"
                >
                    Previous
                </button>
                <p>Slide {{ currentIndex + 1 }} / {{ slides.length }}</p>
                <button
                    type="button"
                    class="rounded border border-gray-700 px-3 py-2 disabled:opacity-40"
                    :disabled="!nextSlide"
                    @click="goToSlide(nextSlide)"
                >
                    Next
                </button>
            </div>
            <p class="mt-3 text-xs text-gray-400">Keys: Left/Right or PageUp/PageDown to navigate, Home/End for first/last, F for fullscreen, Esc to exit.</p>
        </main>
    </div>
</template>

<style scoped>
.presentation-prose :deep(p) {
    margin: 0;
}

.presentation-prose :deep(ul),
.presentation-prose :deep(ol) {
    margin: 0.2rem 0;
    padding-left: 1.2rem;
}

.presentation-prose :deep(li) {
    margin: 0.08rem 0;
}
</style>
