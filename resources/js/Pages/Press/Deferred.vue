<script setup>
// A page for a deck too big to write into the HTML (see App\Services\Press\DeferredPage). The server sends the name of
// the real page, its own props and a streaming URL; this fetches the data while the real page's code loads, then mounts it.
import '../../../css/press/tokens.css';
import '../../../css/press/fonts-ui.css';
import '../../../css/press/base.css';
import { computed, defineAsyncComponent, ref, shallowRef } from 'vue';

const props = defineProps({
    target: { type: String, required: true },
    targetProps: { type: Object, default: () => ({}) },
    payloadUrl: { type: String, required: true },
    payloadKey: { type: String, default: null },
});

// Only pages of this folder can be named, whatever the server sends.
const pages = import.meta.glob(['./*.vue', '!./Deferred.vue']);
const Target = defineAsyncComponent(pages[`./${props.target}.vue`] || (() => Promise.reject(new Error('Unknown page'))));

const payload = shallowRef(null);
const failed = ref(null);
const loading = ref(true);

async function load() {
    loading.value = true;
    failed.value = null;
    try {
        const res = await fetch(props.payloadUrl, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!res.ok) throw new Error(res.status === 403 || res.status === 404 ? 'You can no longer open this presentation.' : `The server answered ${res.status}.`);
        payload.value = await res.json();
    } catch (e) {
        failed.value = e?.message && !/fetch|network|load failed/i.test(e.message) ? e.message : 'The connection dropped before the presentation arrived.';
    } finally {
        loading.value = false;
    }
}
load();

const merged = computed(() => (payload.value === null ? null : { ...props.targetProps, ...(props.payloadKey ? { [props.payloadKey]: payload.value } : payload.value) }));
</script>

<template>
    <component :is="Target" v-if="merged" v-bind="merged" />
    <main v-else class="pr dfr" :aria-busy="loading ? 'true' : 'false'">
        <div v-if="!failed" class="dfr-box" role="status">
            <span class="pr-bead" data-state="current" data-evidence="hollow" aria-hidden="true"></span>
            <p class="pr-title">Opening the presentation</p>
            <p class="pr-small pr-muted">A large deck is sent in one piece. This takes a moment.</p>
        </div>
        <div v-else class="dfr-box" role="alert">
            <span class="pr-bead pr-bead--warn" aria-hidden="true"></span>
            <p class="pr-title">The presentation did not open</p>
            <p class="pr-small pr-muted">{{ failed }}</p>
            <button type="button" class="pr-btn pr-btn--primary" @click="load">Try again</button>
        </div>
    </main>
</template>

<style>
.dfr { min-height: 100vh; display: grid; place-items: center; padding: var(--s-5); }
.dfr-box { display: grid; justify-items: start; gap: var(--s-3); max-width: 28rem; }
.dfr-box .pr-bead { margin-bottom: var(--s-2); }
.dfr-box .pr-btn { margin-top: var(--s-2); }
.dfr[aria-busy='true'] .pr-bead[data-state='current'] { animation: dfr-beat 1.4s var(--ease-settle) infinite; }
@keyframes dfr-beat { 50% { transform: scale(1.25); } }
@media (prefers-reduced-motion: reduce) { .dfr[aria-busy='true'] .pr-bead[data-state='current'] { animation: none; } }
</style>
