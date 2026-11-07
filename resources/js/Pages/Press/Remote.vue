<script setup>
// Phone clicker. Opens from the link in the presenter console (/remote/{token}?k=...). It sends commands to the
// live session and, because it holds the key, also shows the current slide title and the speaker notes. Updates
// come back by short polling about once a second; the page says so.
import { Head } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import '../../../css/press-player.css';
import ChromeIcon from '../../press/player/ChromeIcon.vue';
import { followSession, sendCommand } from '../../press/player/live.js';
import { ensureFonts } from '../../press/fonts.js';

const props = defineProps({
    token: { type: String, required: true },
    deckTitle: { type: String, default: 'Presentation' },
    count: { type: Number, default: 0 },
});

const key = ref('');
const state = ref(null);
const status = ref('connecting');
const message = ref('');
const showNotes = ref(false);
const busy = ref(false);
const notesSize = ref(20);
let stop = null;

const label = computed(() => (state.value ? `${state.value.slide + 1} / ${state.value.count || props.count || '?'}` : '- / -'));
const covered = computed(() => state.value?.cover);

async function send(action) {
    if (busy.value || !key.value) return;
    busy.value = true;
    navigator.vibrate?.(8);
    try {
        const ok = await sendCommand({ token: props.token, key: key.value, action });
        if (!ok) message.value = 'The command was not accepted. Check the link or try again.';
        else message.value = '';
    } catch {
        message.value = 'You are offline.';
    } finally {
        busy.value = false;
    }
}

onMounted(() => {
    ensureFonts();
    key.value = new URLSearchParams(location.search).get('k') || '';
    if (!key.value) {
        status.value = 'nokey';
        return;
    }
    stop = followSession({
        token: props.token,
        key: key.value,
        onState: (s) => {
            state.value = s;
            status.value = 'live';
            message.value = '';
        },
        onEnded: () => (status.value = 'ended'),
        onError: (m) => (message.value = m),
    });
});
onBeforeUnmount(() => stop?.());
</script>

<template>
    <Head :title="`Remote: ${deckTitle}`" />
    <div class="pp-root rm">
        <header class="rm-top">
            <div><strong>{{ deckTitle }}</strong><span v-if="state">{{ state.title || 'Untitled slide' }}</span></div>
            <b class="rm-count" aria-label="Slide number">{{ label }}</b>
        </header>
        <p v-if="status === 'nokey'" class="pp-empty">This remote link is incomplete. Open the full link from the presenter console.</p>
        <p v-else-if="status === 'ended'" class="pp-empty">The presenter ended this session.</p>
        <template v-else>
            <main class="rm-main">
                <section v-if="showNotes" class="rm-notes" aria-label="Speaker notes" :style="{ fontSize: notesSize + 'px' }">
                    <div class="rm-notes-tools"><button class="pp-chip" type="button" aria-label="Smaller text" @click="notesSize = Math.max(14, notesSize - 2)">A-</button><button class="pp-chip" type="button" aria-label="Larger text" @click="notesSize = Math.min(44, notesSize + 2)">A+</button></div>
                    <p :class="{ 'is-empty': !state?.notes }">{{ state?.notes || 'No notes on this slide.' }}</p>
                    <p v-if="state?.nextTitle" class="rm-next">Next: {{ state.nextTitle }}</p>
                </section>
                <section v-else class="rm-pad" aria-label="Controls">
                    <button class="rm-key rm-prev" type="button" @click="send('prev')"><ChromeIcon name="prev" /><span>Back</span></button>
                    <button class="rm-key rm-nextkey" type="button" @click="send('next')"><span>Next</span><ChromeIcon name="next" /></button>
                </section>
            </main>
            <footer class="rm-foot">
                <button class="rm-key rm-small" type="button" :aria-pressed="showNotes" @click="showNotes = !showNotes"><ChromeIcon name="notes" /><span>{{ showNotes ? 'Controls' : 'Notes' }}</span></button>
                <button class="rm-key rm-small" type="button" :aria-pressed="covered === 'black'" @click="send('blackout')"><ChromeIcon name="black" /><span>Black</span></button>
                <button v-if="showNotes" class="rm-key rm-small" type="button" @click="send('prev')"><ChromeIcon name="prev" /><span>Back</span></button>
                <button v-if="showNotes" class="rm-key rm-small rm-nextkey" type="button" @click="send('next')"><ChromeIcon name="next" /><span>Next</span></button>
            </footer>
            <p class="rm-status" role="status">{{ message || (status === 'connecting' ? 'Connecting' : 'Connected. Updates arrive about once a second.') }}</p>
        </template>
    </div>
</template>

<style>
.rm { display: flex; flex-direction: column; padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left); touch-action: manipulation; }
.rm-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding: 16px 18px 12px; border-bottom: 1px solid var(--pp-line); }
.rm-top div { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.rm-top strong { font: 600 14px/1.2 var(--pp-font); color: var(--pp-ink-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rm-top span { font: 500 18px/1.25 var(--pp-font); color: var(--pp-ink); overflow-wrap: anywhere; }
.rm-count { font: 500 22px var(--pp-mono); color: var(--pp-accent); font-variant-numeric: tabular-nums; white-space: nowrap; }
.rm-main { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }
.rm-pad { flex: 1 1 auto; display: grid; grid-template-columns: 1fr 2fr; gap: 10px; padding: 14px 14px 8px; }
.rm-key { appearance: none; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; min-height: 56px; border-radius: 16px; border: 1px solid var(--pp-line-2); background: var(--pp-surface); color: var(--pp-ink); font: 600 16px var(--pp-font); cursor: pointer; transition: transform var(--pp-fast) var(--pp-ease), background var(--pp-fast) var(--pp-ease); user-select: none; -webkit-user-select: none; }
.rm-key svg { width: 28px; height: 28px; fill: none; stroke: currentColor; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; }
.rm-key:active { transform: scale(0.97); background: var(--pp-surface-2); }
.rm-key[aria-pressed='true'] { border-color: var(--pp-accent); color: var(--pp-accent); }
.rm-key:focus-visible { outline: 2px solid var(--pp-accent); outline-offset: 2px; }
.rm-nextkey { background: var(--pp-ink); color: var(--pp-bg); border-color: var(--pp-ink); }
.rm-nextkey:active { background: #fff; }
.rm-foot { display: flex; gap: 10px; padding: 8px 14px 6px; }
.rm-small { flex: 1 1 0; flex-direction: row; min-height: 56px; font-size: 14px; }
.rm-small svg { width: 22px; height: 22px; }
.rm-status { margin: 0; padding: 6px 18px 14px; font: 400 12px var(--pp-font); color: var(--pp-ink-3); text-align: center; }
.rm-notes { flex: 1 1 auto; overflow: auto; padding: 14px 18px; }
.rm-notes p { margin: 0 0 14px; line-height: 1.5; white-space: pre-wrap; overflow-wrap: anywhere; }
.rm-notes p.is-empty { color: var(--pp-ink-3); }
.rm-next { color: var(--pp-ink-2); font-size: 0.8em; border-top: 1px solid var(--pp-line); padding-top: 12px; }
.rm-notes-tools { display: flex; justify-content: flex-end; gap: 6px; margin-bottom: 8px; }
</style>
