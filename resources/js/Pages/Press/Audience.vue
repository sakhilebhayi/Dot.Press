<script setup>
// Audience view: the picture and nothing else. It runs in two ways.
//   window mode  (channelKey set): a second browser window on another display. The presenter drives it over
//                BroadcastChannel with the same state machine, so it never waits on the network.
//   follow mode  (follow.token set): a person following a live session on their own device. It polls
//                /live/{token}/state every second (ETag aware) and shows the slide the presenter is on.
// It never receives speaker notes, in either mode.
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import '../../../css/press-player.css';
import PlayerStage from '../../press/player/PlayerStage.vue';
import { createPlan } from '../../press/player/state.js';
import { channelName, openChannel } from '../../press/player/channel.js';
import { followSession } from '../../press/player/live.js';
import { ensureFonts } from '../../press/fonts.js';

const props = defineProps({
    deck: { type: Object, required: true },
    slides: { type: Array, required: true },
    assets: { type: Object, default: () => ({}) },
    // sent for every presenting page; an audience window follows the presenter instead of starting anywhere
    startIndex: { type: Number, default: 0 },
    follow: { type: Object, default: null },
    channelKey: { type: String, default: null },
});

const plan = computed(() => createPlan(props.slides, props.deck));
const pos = ref(null);
const cover = ref(null);
const status = ref(props.follow ? 'connecting' : 'waiting');
const message = ref('');
const hint = ref(true);
const laser = ref({ x: 0, y: 0, on: false });
const root = ref(null);
const live = computed(() => !!props.follow);
let bc = null;
let stop = null;
let lastSeq = -1;

function apply(slide, step) {
    const p = plan.value.clamp({ slide, step });
    if (!pos.value || pos.value.slide !== p.slide || pos.value.step !== p.step) pos.value = p;
}

function key() {
    return props.channelKey || new URLSearchParams(location.search).get('c');
}

onMounted(() => {
    ensureFonts();
    root.value?.focus();
    if (props.follow?.token) {
        stop = followSession({
            token: props.follow.token,
            onState: (s) => {
                status.value = 'live';
                message.value = '';
                apply(s.slide, s.step);
                cover.value = s.cover;
            },
            onEnded: () => {
                status.value = 'ended';
            },
            onError: (m) => {
                message.value = m;
                if (!pos.value) status.value = 'connecting';
            },
        });
    } else {
        const k = key();
        if (k) {
            bc = openChannel(channelName(props.deck.id, k), (m) => {
                if (m.t === 'state') {
                    if (m.seq < lastSeq && lastSeq - m.seq < 1000) return;
                    lastSeq = m.seq;
                    status.value = 'live';
                    apply(m.slide, m.step);
                    cover.value = m.cover;
                } else if (m.t === 'laser') {
                    laser.value = m;
                } else if (m.t === 'bye' && m.from === 'presenter') {
                    status.value = 'ended';
                }
            });
            bc?.send({ t: 'hello', from: 'audience' });
        }
        if (!bc) {
            status.value = 'unsupported';
        }
    }
    setTimeout(() => (hint.value = false), 5000);
    window.addEventListener('beforeunload', onUnload);
    document.addEventListener('keydown', onKey);
});
function onUnload() {
    bc?.send({ t: 'bye', from: 'audience' });
}
function onKey(e) {
    if (e.metaKey || e.ctrlKey || e.altKey) return;
    if (e.key === 'f' || e.key === 'F') {
        e.preventDefault();
        if (document.fullscreenElement) document.exitFullscreen?.();
        else root.value?.requestFullscreen?.().catch(() => {});
    }
}
onBeforeUnmount(() => {
    stop?.();
    bc?.close();
    window.removeEventListener('beforeunload', onUnload);
    document.removeEventListener('keydown', onKey);
});
</script>

<template>
    <Head :title="deck.title" />
    <div ref="root" class="pp-root pa-view" tabindex="0" :aria-label="`${deck.title}, audience view`">
        <PlayerStage v-if="pos && slides.length" :deck="deck" :slides="slides" :assets="assets" :position="pos" :media="true" />
        <div class="pp-cover" :class="{ 'is-black': cover === 'black', 'is-white': cover === 'white' }" aria-hidden="true"></div>
        <div v-if="laser.on" class="pp-laser is-on" :style="{ transform: `translate(${laser.x * 100}vw, ${laser.y * 100}vh)` }" aria-hidden="true"></div>
        <div v-if="!pos" class="pp-empty" role="status">
            <template v-if="status === 'unsupported'">This window needs the presenter console to open it. Open the audience window from the presenter view.</template>
            <template v-else-if="status === 'ended'">The presenter ended this session.</template>
            <template v-else-if="live">Waiting for the presenter to start<span v-if="message">. {{ message }}</span></template>
            <template v-else>Waiting for the presenter console. Keep it open in the other window.</template>
        </div>
        <p v-else-if="status === 'ended'" class="pp-toast is-on" role="status">The session ended. This is the last slide shown.</p>
        <p v-else-if="live && message" class="pp-toast is-on" role="status">{{ message }}</p>
        <p class="pp-hint" :class="{ 'is-on': hint && pos }" aria-hidden="true">Press <kbd>F</kbd> for full screen</p>
    </div>
</template>
