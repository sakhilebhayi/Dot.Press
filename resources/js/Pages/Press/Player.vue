<script setup>
// Full screen present for one deck. All behaviour lives in press/player/PresentPlayer.vue so the public viewer and
// the offline package share it.
import { Head } from '@inertiajs/vue3';
import PresentPlayer from '../../press/player/PresentPlayer.vue';

defineProps({
    deck: { type: Object, required: true },
    slides: { type: Array, required: true },
    assets: { type: Object, default: () => ({}) },
    startIndex: { type: Number, default: 0 },
    mode: { type: String, default: 'present' },
    exitUrl: { type: String, default: null },
});
</script>

<template>
    <Head :title="deck.title" />
    <PresentPlayer :deck="deck" :slides="slides" :assets="assets" :start-index="startIndex" :mode="mode" :exit-url="exitUrl" />
</template>
