<script setup>
// Video: poster plus native <video>, or a privacy friendly facade for YouTube and Vimeo that creates its iframe
// only on a click or when presenting with embeds allowed and autoplay set.
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useRenderCtx } from './context.js';
import { embedInfo, resolveSrc } from './media.js';
import { reducedMotion } from './env.js';
import { px } from './theme.js';

const props = defineProps({ el: { type: Object, required: true } });
const ctx = useRenderCtx();

const video = ref(null);
const blocked = ref(false);
const frameOn = ref(false);

const src = computed(() => resolveSrc(props.el.src, ctx.assets.value));
const poster = computed(() => {
    const p = resolveSrc(props.el.poster, ctx.assets.value);
    return p ? (ctx.mode.value === 'thumb' ? p.thumb : p.url) : null;
});
const embed = computed(() => embedInfo(props.el.embed, props.el.start));
const present = computed(() => ctx.mode.value === 'present');
const thumb = computed(() => ctx.mode.value === 'thumb');
const edit = computed(() => ctx.mode.value === 'edit');
const label = computed(() => (typeof props.el.alt === 'string' && props.el.alt ? props.el.alt : 'Video'));

// A real <video> exists only when the slide is in view or present mode. Editing and thumbnails show the poster only.
const useNative = computed(() => !!src.value && !embed.value && (present.value || ctx.mode.value === 'view'));
const showControls = computed(() => !!props.el.controls || ctx.mode.value === 'view');

const iframeOk = computed(() => !!embed.value && ctx.allowEmbeds.value && (frameOn.value || (present.value && ctx.active.value && !!props.el.autoplay)));

function tryPlay() {
    const v = video.value;
    if (!v) return;
    const start = Math.max(0, +props.el.start || 0);
    if (start && v.currentTime < start) v.currentTime = start;
    const p = v.play();
    if (p && p.catch) p.catch(() => (blocked.value = true));
}

watch(
    () => [ctx.active.value, video.value],
    async ([active]) => {
        await nextTick();
        const v = video.value;
        if (!v) return;
        if (active && present.value && props.el.autoplay && !reducedMotion.value) tryPlay();
        else if (!active) v.pause();
    },
    { immediate: true },
);
onBeforeUnmount(() => video.value?.pause());

function onMeta() {
    const start = Math.max(0, +props.el.start || 0);
    if (start && video.value) video.value.currentTime = start;
}
function manualPlay(e) {
    e.stopPropagation();
    blocked.value = false;
    tryPlay();
}
function loadFrame(e) {
    e.stopPropagation();
    frameOn.value = true;
}
</script>

<template>
    <div class="pv" :class="{ 'pv-edit': edit }">
        <template v-if="embed">
            <iframe v-if="iframeOk" class="pv-frame" :src="embed.src" :title="label" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"></iframe>
            <template v-else>
                <img v-if="poster" class="pv-poster" :src="poster" alt="" loading="lazy" decoding="async" />
                <component :is="ctx.allowEmbeds.value && !thumb && !edit ? 'button' : 'div'" class="pv-facade" type="button" :aria-label="`Play ${label} from ${embed.label}`" @click="loadFrame">
                    <span class="pv-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z" /></svg></span>
                    <span class="pv-note"><strong>{{ embed.label }}</strong><template v-if="!thumb"><span v-if="ctx.allowEmbeds.value">Loads from {{ embed.host }} when you press play</span><span v-else>Embeds are off here</span></template></span>
                </component>
            </template>
        </template>
        <template v-else-if="useNative">
            <video ref="video" class="pv-video" :src="src.url" :poster="poster || undefined" :controls="showControls" :loop="!!el.loop" :muted="!!el.muted" playsinline :preload="ctx.active.value ? 'auto' : 'metadata'" :aria-label="label" @loadedmetadata="onMeta"></video>
            <button v-if="blocked" class="pv-facade" type="button" @click="manualPlay"><span class="pv-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z" /></svg></span><span class="pv-note"><strong>Play</strong><span>The browser blocked autoplay</span></span></button>
        </template>
        <template v-else>
            <img v-if="poster" class="pv-poster" :src="poster" alt="" loading="lazy" decoding="async" />
            <div class="pv-facade pv-static" role="img" :aria-label="label">
                <span class="pv-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5z" /></svg></span>
                <span v-if="edit && !src" class="pv-note"><span>Video not available</span></span>
            </div>
        </template>
    </div>
</template>
