<script setup>
// A small, read only stage that plays slide copies with the real player runtime (player/PlayerStage.vue): the same
// element animations and slide transitions the audience sees. Used by the transition preview and the sequence player.
// The slides it gets are copies; nothing here writes to the document.
import PlayerStage from '../../../player/PlayerStage.vue';

defineProps({
    deck: { type: Object, required: true },
    slides: { type: Array, required: true },
    assets: { type: Object, default: () => ({}) },
    position: { type: Object, required: true },
    animate: { type: Boolean, default: true },
    label: { type: String, default: 'Preview' },
});
const emit = defineEmits(['settled']);
</script>

<template>
    <div class="ms" role="img" :aria-label="label" :style="{ aspectRatio: `${deck.w || 1920} / ${deck.h || 1080}` }">
        <PlayerStage :deck="deck" :slides="slides" :assets="assets" :position="position" :animate="animate" :media="false" :preload="false" @settled="emit('settled', $event)" />
    </div>
</template>

<style>
.ms { position: relative; width: 100%; overflow: hidden; border-radius: var(--r-1); background: var(--stage); box-shadow: var(--sheet-shadow); isolation: isolate; }
.ms .pp-stage { position: absolute; inset: 0; overflow: hidden; }
.ms .pp-layer { position: absolute; inset: 0; }
.ms .pp-layer-wait { visibility: hidden; }
</style>
