<script setup>
// Presenter console: the current slide, the next slide or step, speaker notes, a timer, the slide strip and the
// controls. It drives the audience window over BroadcastChannel and, when a live session is started, a phone clicker
// and followers over short polling (about once a second; there are no websockets on this hosting).
import { Head } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import '../../../css/press-player.css';
import PlayerStage from '../../press/player/PlayerStage.vue';
import ChromeIcon from '../../press/player/ChromeIcon.vue';
import SlideView from '../../press/render/SlideView.vue';
import { createPlan, samePos } from '../../press/player/state.js';
import { createNumberBuffer, isEditable, mapKey } from '../../press/player/keys.js';
import { channelName, newChannelKey, openChannel, supportsChannel } from '../../press/player/channel.js';
import { createThrottle } from '../../press/player/live.js';
import { ensureFonts } from '../../press/fonts.js';

const props = defineProps({
    deck: { type: Object, required: true },
    slides: { type: Array, required: true },
    assets: { type: Object, default: () => ({}) },
    startIndex: { type: Number, default: 0 },
    exitUrl: { type: String, default: null },
    audienceUrl: { type: String, required: true },
    canShare: { type: Boolean, default: true },
});

const plan = computed(() => createPlan(props.slides, props.deck));
const pos = ref(
    (() => {
        const first = plan.value.goto(props.startIndex, 0);
        return plan.value.order.includes(first.slide) ? first : plan.value.first();
    })(),
);
const cover = ref(null);
const root = ref(null);
const gridOn = ref(false);
const jumpText = ref('');
const numbers = createNumberBuffer(4);
let seq = 0;

const current = computed(() => props.slides[pos.value.slide] || null);
const counter = computed(() => ({ n: pos.value.slide + 1, of: Math.max(1, props.slides.length) }));
const nextPos = computed(() => plan.value.peekNext(pos.value));
const nextIsSlide = computed(() => nextPos.value && nextPos.value.slide !== pos.value.slide);
const nextSlide = computed(() => (nextPos.value ? props.slides[nextPos.value.slide] : null));
const steps = computed(() => plan.value.clicksOf(pos.value.slide));
const notes = computed(() => (current.value && typeof current.value.notes === 'string' ? current.value.notes : ''));
const atEnd = computed(() => plan.value.isLast(pos.value));
const atStart = computed(() => plan.value.isFirst(pos.value));

function setPos(p) {
    const n = plan.value.clamp(p);
    if (samePos(n, pos.value)) return false;
    pos.value = n;
    cover.value = null;
    if (!timer.running && !timer.started && !(n.slide === 0 && n.step === 0)) startTimer();
    return true;
}
const go = {
    next: () => setPos(plan.value.next(pos.value)),
    prev: () => setPos(plan.value.prev(pos.value)),
    first: () => setPos(plan.value.first()),
    last: () => setPos(plan.value.last()),
    number: (n) => setPos(plan.value.jumpNumber(n)),
    slide: (i) => setPos({ slide: i, step: 0 }),
};
function toggleCover(kind) {
    cover.value = cover.value === kind ? null : kind;
}
function onLink(id) {
    const i = props.slides.findIndex((s) => String(s.id) === String(id));
    if (i >= 0) go.slide(i);
}

// ---- timer ----
const timer = ref({ running: false, started: false, elapsed: 0, target: 0, since: 0 });
const clock = ref('');
const elapsedLabel = ref('00:00');
let tick = 0;
function fmt(s) {
    s = Math.max(0, Math.floor(s));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = s % 60;
    return (h ? `${h}:${String(m).padStart(2, '0')}` : String(m).padStart(2, '0')) + `:${String(sec).padStart(2, '0')}`;
}
function elapsedSeconds() {
    return timer.value.elapsed + (timer.value.running ? (Date.now() - timer.value.since) / 1000 : 0);
}
function startTimer() {
    if (timer.value.running) return;
    timer.value = { ...timer.value, running: true, started: true, since: Date.now() };
}
function pauseTimer() {
    if (!timer.value.running) return;
    timer.value = { ...timer.value, running: false, elapsed: elapsedSeconds() };
}
function resetTimer() {
    timer.value = { ...timer.value, running: false, started: false, elapsed: 0, since: 0 };
    elapsedLabel.value = '00:00';
}
function setTarget(e) {
    const m = Math.max(0, Math.min(600, Math.floor(+e.target.value || 0)));
    timer.value = { ...timer.value, target: m };
    try {
        window.localStorage.setItem('press.timer.target', String(m));
    } catch {
        /* the target just does not persist */
    }
}
const timerState = computed(() => {
    const t = timer.value.target * 60;
    if (!t) return 'plain';
    const e = elapsedSecondsReactive.value;
    if (e >= t) return 'over';
    if (e >= t * 0.85) return 'near';
    return 'plain';
});
const elapsedSecondsReactive = ref(0);
const remainingLabel = computed(() => {
    const t = timer.value.target * 60;
    if (!t) return '';
    const r = t - elapsedSecondsReactive.value;
    return r >= 0 ? `${fmt(r)} left` : `${fmt(-r)} over`;
});
function onTick() {
    const e = elapsedSeconds();
    elapsedSecondsReactive.value = e;
    elapsedLabel.value = fmt(e);
    const d = new Date();
    clock.value = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

// ---- notes size ----
const notesSize = ref(26);
function bumpNotes(d) {
    notesSize.value = Math.min(64, Math.max(14, notesSize.value + d));
    try {
        window.localStorage.setItem('press.presenter.notes', String(notesSize.value));
    } catch {
        /* ignore */
    }
}

// ---- audience window over BroadcastChannel ----
const channelKey = newChannelKey();
const audience = ref({ state: 'closed', note: '' });
let win = null;
let bc = null;
let watchWin = 0;
function stateMessage() {
    return { t: 'state', slide: pos.value.slide, step: pos.value.step, cover: cover.value, seq: ++seq };
}
function pushState() {
    bc?.send(stateMessage());
}
function openAudience() {
    const url = `${props.audienceUrl}${props.audienceUrl.includes('?') ? '&' : '?'}c=${encodeURIComponent(channelKey)}`;
    if (!supportsChannel()) {
        audience.value = { state: 'unsupported', note: 'This browser cannot link two windows. Use the live link instead.', url };
        return;
    }
    const w = window.open(url, 'press-audience', 'popup=yes,width=1280,height=720');
    if (!w) {
        audience.value = { state: 'blocked', note: 'Your browser blocked the window. Allow pop-ups for this site and try again, or open this address in a window on the second display.', url };
        return;
    }
    win = w;
    audience.value = { state: 'opening', note: 'Move the new window to the second display and press F in it for full screen.', url };
    clearInterval(watchWin);
    watchWin = setInterval(() => {
        if (win && win.closed) {
            audience.value = { state: 'closed', note: 'The audience window was closed.', url };
            clearInterval(watchWin);
        }
    }, 1500);
}
function onChannel(m) {
    if (m.t === 'hello') {
        audience.value = { ...audience.value, state: 'open', note: 'Audience window connected.' };
        pushState();
    } else if (m.t === 'bye' && m.from === 'audience') {
        audience.value = { ...audience.value, state: 'closed', note: 'The audience window was closed.' };
    }
}

// ---- live session (phone remote and followers), short polling ----
const live = ref({ state: 'off', token: null, audienceUrl: '', remoteUrl: '', expires: '', error: '' });
const shareOpen = ref(false);
const copied = ref('');
let heartbeat = 0;
let pushing = false;
const sendLive = createThrottle(() => pushLive(), 300);

function axios() {
    return window.axios;
}
async function startLive() {
    live.value = { ...live.value, state: 'starting', error: '' };
    try {
        const { data } = await axios().post(`/api/decks/${props.deck.id}/present-sessions`, { slide_index: pos.value.slide, step: pos.value.step });
        live.value = { state: 'on', token: data.token, audienceUrl: data.audience_url, remoteUrl: data.remote_url, expires: data.expires_at, error: '' };
        clearInterval(heartbeat);
        heartbeat = setInterval(pushLive, 1500);
        pushLive();
    } catch (e) {
        live.value = { ...live.value, state: 'off', error: e?.response?.status === 429 ? 'Too many tries. Wait a minute.' : 'Could not start a live session.' };
    }
}
async function pushLive() {
    if (live.value.state !== 'on' || pushing) return;
    pushing = true;
    try {
        const { data } = await axios().put(`/api/present-sessions/${live.value.token}`, { slide_index: pos.value.slide, step: pos.value.step, cover: cover.value });
        for (const c of data.commands || []) applyCommand(c.action);
    } catch (e) {
        if (e?.response?.status === 404 || e?.response?.status === 410) endedRemotely();
        else live.value = { ...live.value, error: 'Live session is not reachable. Retrying.' };
        pushing = false;
        return;
    }
    live.value = live.value.error ? { ...live.value, error: '' } : live.value;
    pushing = false;
}
function endedRemotely() {
    clearInterval(heartbeat);
    live.value = { state: 'off', token: null, audienceUrl: '', remoteUrl: '', expires: '', error: 'The live session ended or expired.' };
}
async function endLive() {
    const token = live.value.token;
    clearInterval(heartbeat);
    live.value = { state: 'off', token: null, audienceUrl: '', remoteUrl: '', expires: '', error: '' };
    if (token) {
        try {
            await axios().delete(`/api/present-sessions/${token}`);
        } catch {
            /* an expired session is already gone */
        }
    }
}
function applyCommand(action) {
    ({ next: go.next, prev: go.prev, first: go.first, last: go.last, blackout: () => toggleCover('black'), whiteout: () => toggleCover('white') })[action]?.();
}
async function copy(text, which) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = which;
        setTimeout(() => (copied.value = ''), 1600);
    } catch {
        copied.value = '';
    }
}

// ---- laser relay ----
const laserOn = ref(false);
function onStageMove(e) {
    if (!laserOn.value) return;
    const r = e.currentTarget.getBoundingClientRect();
    bc?.send({ t: 'laser', x: (e.clientX - r.left) / r.width, y: (e.clientY - r.top) / r.height, on: true });
}
function setLaser(on) {
    laserOn.value = on;
    if (!on) bc?.send({ t: 'laser', x: 0, y: 0, on: false });
}

// ---- keyboard ----
function onKey(e) {
    if (isEditable(e.target)) return;
    const m = mapKey(e, { typing: numbers.active });
    if (!m) return;
    if (m.action === 'laser') {
        if (!e.repeat) setLaser(true);
        e.preventDefault();
        return;
    }
    e.preventDefault();
    switch (m.action) {
        case 'digit':
            jumpText.value = numbers.push(m.digit);
            clearTimeout(onKey.t);
            onKey.t = setTimeout(clearJump, 2500);
            return;
        case 'digit-back':
            jumpText.value = numbers.back();
            return;
        case 'jump': {
            const n = numbers.commit();
            clearJump();
            if (n != null) go.number(n);
            return;
        }
        case 'next':
            return go.next();
        case 'prev':
            return go.prev();
        case 'first':
            return go.first();
        case 'last':
            return go.last();
        case 'grid':
            gridOn.value = !gridOn.value;
            return;
        case 'blackout':
            return toggleCover('black');
        case 'whiteout':
            return toggleCover('white');
        case 'fullscreen':
            return toggleFullscreen();
        case 'escape':
            if (numbers.active) return clearJump();
            if (gridOn.value) return (gridOn.value = false);
            if (shareOpen.value) return (shareOpen.value = false);
            if (cover.value) return (cover.value = null);
            if (document.fullscreenElement) return document.exitFullscreen?.();
    }
}
function onKeyUp(e) {
    if (e.key && e.key.toLowerCase() === 'l') setLaser(false);
}
function clearJump() {
    numbers.clear();
    jumpText.value = '';
}
function exit() {
    if (props.exitUrl) window.location.assign(props.exitUrl);
}
async function toggleFullscreen() {
    try {
        if (document.fullscreenElement) await document.exitFullscreen();
        else await root.value.requestFullscreen();
    } catch {
        /* not available */
    }
}

// ---- strip ----
const strip = ref(null);
watch(
    () => pos.value.slide,
    async () => {
        await nextTick();
        strip.value?.querySelector('.pr-strip-item.is-now')?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    },
);

watch([pos, cover], () => {
    pushState();
    sendLive();
});

onMounted(() => {
    ensureFonts();
    try {
        notesSize.value = Math.min(64, Math.max(14, +window.localStorage.getItem('press.presenter.notes') || 26));
        timer.value.target = Math.max(0, +window.localStorage.getItem('press.timer.target') || 0);
    } catch {
        /* defaults */
    }
    bc = openChannel(channelName(props.deck.id, channelKey), onChannel);
    tick = setInterval(onTick, 1000);
    onTick();
    root.value?.focus();
    window.addEventListener('beforeunload', onUnload);
});
function onUnload() {
    bc?.send({ t: 'bye', from: 'presenter' });
    if (live.value.token && navigator.sendBeacon) {
        // the session ends with the console
        const fd = new FormData();
        fd.append('_method', 'DELETE');
        navigator.sendBeacon(`/api/present-sessions/${live.value.token}`, fd);
    }
}
onBeforeUnmount(() => {
    clearInterval(tick);
    clearInterval(heartbeat);
    clearInterval(watchWin);
    window.removeEventListener('beforeunload', onUnload);
    bc?.close();
});
</script>

<template>
    <Head :title="`Presenter: ${deck.title}`" />
    <div ref="root" class="pp-root pr" tabindex="0" @keydown="onKey" @keyup="onKeyUp">
        <header class="pr-top">
            <div class="pr-title"><strong>{{ deck.title }}</strong><span>Slide {{ counter.n }} of {{ counter.of }}<template v-if="steps"> &middot; step {{ pos.step }} of {{ steps }}</template></span></div>
            <div class="pr-time" :class="'is-' + timerState">
                <div class="pr-read" role="timer" aria-label="Elapsed time">
                    <span class="pr-k">Elapsed</span>
                    <span class="pr-elapsed">{{ elapsedLabel }}</span>
                </div>
                <div v-if="remainingLabel" class="pr-read">
                    <span class="pr-k">Target</span>
                    <span class="pr-left">{{ remainingLabel }}</span>
                </div>
                <div class="pr-read">
                    <span class="pr-k">Time of day</span>
                    <span class="pr-clock">{{ clock }}</span>
                </div>
                <div class="pr-timer-ctl" role="group" aria-label="Timer controls">
                    <button v-if="!timer.running" class="pp-chip" type="button" @click="startTimer">{{ timer.started ? 'Resume' : 'Start' }}</button>
                    <button v-else class="pp-chip" type="button" @click="pauseTimer">Pause</button>
                    <button class="pp-chip" type="button" :disabled="!timer.started && !timer.elapsed" @click="resetTimer">Reset</button>
                    <label class="pr-target"><span>Target</span><input type="number" min="0" max="600" :value="timer.target || ''" placeholder="0" aria-label="Target time in minutes" @change="setTarget" @keydown.stop /><span>min</span></label>
                </div>
            </div>
            <div class="pr-actions">
                <button class="pp-btn" type="button" title="Open the audience window" aria-label="Open the audience window" @click="openAudience"><ChromeIcon name="window" /></button>
                <button v-if="canShare" class="pp-btn" type="button" :aria-pressed="shareOpen" title="Phone remote and live link" aria-label="Phone remote and live link" @click="shareOpen = !shareOpen"><ChromeIcon name="phone" /></button>
                <button class="pp-btn" type="button" title="Full screen (F)" aria-label="Full screen" @click="toggleFullscreen"><ChromeIcon name="fullscreen" /></button>
                <button v-if="exitUrl" class="pp-btn" type="button" title="End presenting" aria-label="End presenting" @click="exit"><ChromeIcon name="close" /></button>
            </div>
        </header>

        <p v-if="audience.state === 'blocked' || audience.state === 'unsupported' || audience.state === 'opening' || audience.state === 'open'" class="pr-note" :class="{ 'is-warn': audience.state === 'blocked' || audience.state === 'unsupported' }" role="status">
            {{ audience.note }}
            <template v-if="audience.state === 'blocked' || audience.state === 'unsupported'"> <code>{{ audience.url }}</code></template>
        </p>

        <section v-if="shareOpen" class="pr-share" aria-label="Phone remote and live link">
            <template v-if="live.state !== 'on'">
                <p>Start a live session to use your phone as a clicker, or to let people follow the slides on their own screens. This uses short polling, about once a second, so a follower can lag by a second or two. Followers see the slides, never your notes. The session ends when you end it or after 12 hours.</p>
                <button class="pp-primary pr-start" type="button" :disabled="live.state === 'starting'" @click="startLive">{{ live.state === 'starting' ? 'Starting' : 'Start live session' }}</button>
                <p v-if="live.error" class="pp-error" role="alert">{{ live.error }}</p>
            </template>
            <template v-else>
                <div class="pr-link"><span>Phone remote (keep private, it has your notes)</span><code>{{ live.remoteUrl }}</code><button class="pp-chip" type="button" @click="copy(live.remoteUrl, 'remote')">{{ copied === 'remote' ? 'Copied' : 'Copy' }}</button></div>
                <div class="pr-link"><span>Audience link (slides only)</span><code>{{ live.audienceUrl }}</code><button class="pp-chip" type="button" @click="copy(live.audienceUrl, 'aud')">{{ copied === 'aud' ? 'Copied' : 'Copy' }}</button></div>
                <p class="pr-fine">Updates travel by short polling every second or so. Expires {{ live.expires ? new Date(live.expires).toLocaleString() : 'in 12 hours' }}.</p>
                <p v-if="live.error" class="pp-error" role="alert">{{ live.error }}</p>
                <button class="pp-chip" type="button" @click="endLive">End live session</button>
            </template>
        </section>

        <main class="pr-main">
            <section class="pr-now" aria-label="Current slide">
                <div class="pr-frame" :style="{ aspectRatio: deck.w + ' / ' + deck.h }" @pointermove="onStageMove">
                    <PlayerStage v-if="slides.length" :deck="deck" :slides="slides" :assets="assets" :position="pos" :media="false" @link="onLink" />
                    <div class="pr-cover" :class="cover ? 'is-' + cover : ''" aria-hidden="true"><span v-if="cover">{{ cover === 'black' ? 'Audience sees a black screen' : 'Audience sees a white screen' }}</span></div>
                </div>
                <div class="pr-controls">
                    <button class="pr-big" type="button" :disabled="atStart" @click="go.prev()"><ChromeIcon name="prev" /><span>Back</span></button>
                    <button class="pr-big pr-big-primary" type="button" :disabled="atEnd" @click="go.next()"><span>{{ steps && pos.step < steps ? 'Next step' : 'Next slide' }}</span><ChromeIcon name="next" /></button>
                    <div class="pr-tools" role="group" aria-label="Audience screen">
                        <button class="pp-chip pr-tool" type="button" :aria-pressed="cover === 'black'" title="Black screen (B)" @click="toggleCover('black')">Black</button>
                        <button class="pp-chip pr-tool" type="button" :aria-pressed="cover === 'white'" title="White screen (W)" @click="toggleCover('white')">White</button>
                        <button class="pp-chip pr-tool" type="button" :aria-pressed="gridOn" title="All slides (G)" @click="gridOn = !gridOn">All slides</button>
                    </div>
                </div>
            </section>

            <aside class="pr-side">
                <section class="pr-next" aria-label="Next">
                    <h2>{{ nextPos ? (nextIsSlide ? 'Next slide' : 'Next step on this slide') : 'End' }}</h2>
                    <div v-if="nextPos && nextSlide" class="pr-next-frame"><SlideView :slide="nextSlide" :deck="deck" :assets="assets" mode="present" :state="nextIsSlide ? 0 : nextPos.step" :active="false" :allow-embeds="false" /></div>
                    <p v-else class="pr-fine">That was the last slide.</p>
                </section>
                <section class="pr-notes" aria-label="Speaker notes">
                    <header><h2>Notes</h2><span><button class="pp-chip" type="button" aria-label="Smaller notes text" @click="bumpNotes(-2)">A-</button> <button class="pp-chip" type="button" aria-label="Larger notes text" @click="bumpNotes(2)">A+</button></span></header>
                    <div class="pr-notes-body" :class="{ 'is-empty': !notes }" :style="{ fontSize: notesSize + 'px' }" tabindex="0">{{ notes || 'No notes on this slide.' }}</div>
                </section>
            </aside>
        </main>

        <nav ref="strip" class="pr-strip" aria-label="Slides">
            <button v-for="(s, i) in slides" :key="s.id ?? i" class="pr-strip-item" :class="{ 'is-now': i === pos.slide, 'is-hidden': s.hidden }" type="button" :aria-current="i === pos.slide ? 'true' : undefined" :aria-label="`Slide ${i + 1}${s.title ? ': ' + s.title : ''}${s.hidden ? ' (hidden)' : ''}`" @click="go.slide(i)">
                <span class="pp-thumb-frame"><SlideView :slide="s" :deck="deck" :assets="assets" mode="thumb" /></span>
                <b>{{ i + 1 }}</b>
            </button>
        </nav>

        <div class="pp-grid pp-ui" :class="{ 'is-on': gridOn }" role="dialog" aria-label="All slides" :inert="!gridOn || undefined">
            <div class="pp-grid-head"><span>{{ deck.title }}</span><button class="pp-btn" type="button" aria-label="Close overview" @click="gridOn = false"><ChromeIcon name="close" /></button></div>
            <ol v-if="gridOn" class="pp-grid-list">
                <li v-for="(s, i) in slides" :key="s.id ?? i">
                    <button class="pp-thumb" type="button" :class="{ 'is-now': i === pos.slide, 'is-hidden': s.hidden }" @click="gridOn = false; go.slide(i)">
                        <span class="pp-thumb-frame"><SlideView :slide="s" :deck="deck" :assets="assets" mode="thumb" /></span>
                        <span class="pp-thumb-meta"><b>{{ i + 1 }}</b><span>{{ s.title || 'Untitled' }}</span></span>
                    </button>
                </li>
            </ol>
        </div>
        <div class="pp-jump" :class="{ 'is-on': jumpText }" aria-hidden="true">{{ jumpText }}</div>
    </div>
</template>

<style>
.pr { display: flex; flex-direction: column; padding: 0; overflow: auto; }
.pr-top { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 24px; padding: 12px clamp(12px, 2vw, 24px); border-bottom: 1px solid var(--pp-line); }
.pr-title { display: flex; flex-direction: column; gap: 2px; min-width: 0; margin-right: auto; }
.pr-title strong { font: 600 15px/1.2 var(--pp-font); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pr-title span { font: 400 12px/1.2 var(--pp-font); color: var(--pp-ink-2); }
.pr-time { display: flex; align-items: flex-end; gap: 10px 22px; font: 500 13px var(--pp-mono); color: var(--pp-ink-2); flex-wrap: wrap; }
.pr-read { display: grid; gap: 4px; }
.pr-k { font: 500 12px/1 var(--pp-font); color: var(--pp-ink-2); }
.pr-timer-ctl { display: flex; align-items: center; gap: 6px; padding-left: 22px; border-left: 1px solid var(--pp-line); }
.pr-left, .pr-clock { font: 500 16px/1.3 var(--pp-mono); font-variant-numeric: tabular-nums; }
.pr-elapsed { font: 500 26px/1 var(--pp-mono); color: var(--pp-ink); font-variant-numeric: tabular-nums; transition: color 600ms var(--pp-ease); }
.pr-time.is-near .pr-elapsed, .pr-time.is-near .pr-left { color: var(--pp-warn); }
.pr-time.is-over .pr-elapsed, .pr-time.is-over .pr-left { color: var(--pp-over); }
.pr-clock { color: var(--pp-ink-2); }
.pr-target { display: inline-flex; align-items: center; gap: 6px; margin-left: 6px; font: 400 12px var(--pp-font); color: var(--pp-ink-2); }
.pr-target input { width: 58px; height: 28px; background: transparent; border: 1px solid var(--pp-line); border-radius: 8px; color: var(--pp-ink); font: 500 12px var(--pp-mono); padding: 0 6px; }
.pr-target input:focus-visible { outline: 2px solid var(--pp-accent); outline-offset: 1px; }
.pr-actions { display: flex; gap: 2px; }
.pr-note { margin: 0; padding: 10px clamp(12px, 2vw, 24px); font: 400 13px/1.45 var(--pp-font); color: var(--pp-ink-2); background: var(--pp-surface); border-bottom: 1px solid var(--pp-line); }
.pr-note.is-warn { color: var(--pp-warn); }
.pr-note code, .pr-link code { font: 500 12px var(--pp-mono); color: var(--pp-ink); overflow-wrap: anywhere; user-select: all; }
.pr-share { padding: 14px clamp(12px, 2vw, 24px) 16px; background: var(--pp-surface); border-bottom: 1px solid var(--pp-line); display: grid; gap: 10px; font: 400 13px/1.5 var(--pp-font); color: var(--pp-ink-2); }
.pr-share p { margin: 0; max-width: 70ch; }
.pr-start { width: auto; padding: 0 18px; margin: 0; justify-self: start; }
.pr-link { display: grid; grid-template-columns: 1fr auto; gap: 4px 12px; align-items: center; }
.pr-link span { grid-column: 1 / -1; color: var(--pp-ink-3); font-size: 12px; }
.pr-fine { color: var(--pp-ink-3); font: 400 12px/1.5 var(--pp-font); }
.pr-main { flex: 1 0 auto; display: grid; grid-template-columns: minmax(0, 1.65fr) minmax(280px, 1fr); gap: clamp(12px, 2vw, 28px); padding: clamp(12px, 2vw, 24px); }
.pr-now { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.pr-frame { position: relative; background: #000; border: 1px solid var(--pp-line); border-radius: 4px; overflow: hidden; width: 100%; }
.pr-cover { position: absolute; inset: 0; display: grid; place-items: center; font: 500 13px var(--pp-font); color: var(--pp-ink-2); background: transparent; opacity: 0; transition: opacity 160ms var(--pp-ease); pointer-events: none; }
.pr-cover.is-black { background: rgb(0 0 0 / 0.82); opacity: 1; }
.pr-cover.is-white { background: rgb(255 255 255 / 0.82); color: #111; opacity: 1; }
.pr-controls { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.pr-tools { display: flex; gap: 6px; margin-left: auto; padding-left: 12px; }
.pr-tool { height: 36px; padding: 0 12px; font: 500 13px var(--pp-font); }
.pr-tool[aria-pressed='true'] { background: var(--pp-surface-2); border-color: var(--pp-accent); color: var(--pp-accent); }
.pr-big { appearance: none; display: inline-flex; align-items: center; gap: 10px; height: 44px; padding: 0 18px; border-radius: 8px; border: 1px solid var(--pp-line-2); background: transparent; color: var(--pp-ink); font: 600 15px var(--pp-font); cursor: pointer; transition: background var(--pp-fast) var(--pp-ease), transform var(--pp-fast) var(--pp-ease); }
.pr-big svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; }
.pr-big:active { transform: scale(0.97); }
.pr-big:disabled { opacity: 0.3; pointer-events: none; }
.pr-big:focus-visible { outline: 2px solid var(--pp-accent); outline-offset: 2px; }
.pr-big-primary { background: var(--pp-ink); color: var(--pp-bg); border-color: var(--pp-ink); }
@media (hover: hover) { .pr-big:hover { background: var(--pp-surface-2); } .pr-big-primary:hover { background: #fff; } }
.pr-side { display: flex; flex-direction: column; gap: 16px; min-width: 0; min-height: 0; }
.pr-side h2 { font: 600 12px/1 var(--pp-font); letter-spacing: 0.02em; color: var(--pp-ink-2); margin: 0 0 8px; }
.pr-next-frame { border: 1px solid var(--pp-line); border-radius: 4px; overflow: hidden; background: #000; max-width: 360px; }
.pr-notes { display: flex; flex-direction: column; min-height: 220px; flex: 1 1 auto; }
.pr-notes header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.pr-notes header h2 { margin: 0; }
.pr-notes-body { flex: 1 1 auto; min-height: 0; overflow: auto; line-height: 1.5; white-space: pre-wrap; overflow-wrap: anywhere; color: var(--pp-ink); padding: 14px 16px; background: var(--pp-surface); border: 1px solid var(--pp-line); border-radius: var(--pp-radius); max-height: 52vh; }
.pr-notes-body.is-empty { color: var(--pp-ink-3); }
.pr-notes-body:focus-visible { outline: 2px solid var(--pp-accent); outline-offset: 2px; }
.pr-strip { display: flex; gap: 10px; overflow-x: auto; padding: 10px clamp(12px, 2vw, 24px) 18px; border-top: 1px solid var(--pp-line); scrollbar-width: thin; }
.pr-strip-item { appearance: none; flex: 0 0 148px; background: transparent; border: 0; padding: 0; color: var(--pp-ink-2); cursor: pointer; text-align: left; border-radius: 4px; }
.pr-strip-item b { display: block; margin-top: 5px; font: 500 12px var(--pp-mono); }
.pr-strip-item .pp-thumb-frame { border: 1px solid var(--pp-line); border-radius: 3px; overflow: hidden; display: block; background: #000; }
.pr-strip-item.is-now .pp-thumb-frame { border-color: var(--pp-accent); box-shadow: 0 0 0 1px var(--pp-accent); }
.pr-strip-item.is-now b { color: var(--pp-accent); }
.pr-strip-item.is-hidden { opacity: 0.45; }
.pr-strip-item:focus-visible { outline: 2px solid var(--pp-accent); outline-offset: 2px; }
@media (max-width: 860px) {
    .pr-main { grid-template-columns: minmax(0, 1fr); }
    .pr-notes-body { max-height: 40vh; }
    .pr-elapsed { font-size: 22px; }
    .pr-strip-item { flex-basis: 110px; }
}
</style>
