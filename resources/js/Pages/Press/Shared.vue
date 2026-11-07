<script setup>
// Public viewer for a shared deck: read only, no editing affordances, and no speaker notes. When the link is
// protected the page asks for the password first; the deck content is not even sent until it is unlocked.
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import '../../../css/press-player.css';
import PresentPlayer from '../../press/player/PresentPlayer.vue';
import ChromeIcon from '../../press/player/ChromeIcon.vue';

const props = defineProps({
    /** The deck as a guest may read it: { deck, slides, assets }. Null while the link is still locked. */
    doc: { type: Object, default: null },
    startIndex: { type: Number, default: 0 },
    needsPassword: { type: Boolean, default: false },
    /** where the password is posted. Defaults to the current address. */
    unlockUrl: { type: String, default: null },
    title: { type: String, default: 'Shared presentation' },
    /** set when the link cannot be used: 'expired' | 'revoked' | 'unavailable' */
    problem: { type: String, default: null },
    /** the owner allowed a print view (save as PDF) for people with the link */
    allowDownload: { type: Boolean, default: false },
    token: { type: String, default: '' },
    /** the plain sentence from the last failed unlock (wrong password, too many tries) */
    error: { type: String, default: null },
});

const deck = computed(() => props.doc?.deck || null);
const slides = computed(() => props.doc?.slides || []);
const assets = computed(() => props.doc?.assets || {});
const message = computed(() => form.errors.password || props.error || '');
const printUrl = computed(() => (props.allowDownload && props.token ? `/s/${props.token}/print` : null));

const form = useForm({ password: '' });
function unlock() {
    form.post(props.unlockUrl || `/s/${props.token}/unlock`, {
        preserveScroll: true,
        onFinish: () => form.reset('password'),
    });
}
const PROBLEMS = {
    expired: 'This link has expired. Ask the owner for a new one.',
    revoked: 'This link was turned off by the owner.',
    unavailable: 'This presentation is not available.',
};
</script>

<template>
    <Head :title="deck?.title || title" />
    <div v-if="problem" class="pp-root"><div class="pp-gate"><div class="pp-card"><h1>{{ title }}</h1><p>{{ PROBLEMS[problem] || PROBLEMS.unavailable }}</p></div></div></div>
    <div v-else-if="needsPassword || !deck" class="pp-root">
        <div class="pp-gate">
            <form class="pp-card" @submit.prevent="unlock">
                <h1>{{ title }}</h1>
                <p>This presentation is protected. Enter the password you were given.</p>
                <label class="pp-sr" for="share-pass">Password</label>
                <input id="share-pass" v-model="form.password" class="pp-field" type="password" autocomplete="off" autofocus required placeholder="Password" :aria-invalid="!!message" aria-describedby="share-err" />
                <p id="share-err" class="pp-error" role="alert">{{ message }}</p>
                <button class="pp-primary" type="submit" :disabled="form.processing || !form.password">Open presentation</button>
            </form>
        </div>
    </div>
    <PresentPlayer v-else :deck="deck" :slides="slides" :assets="assets" :start-index="startIndex" mode="shared">
        <template v-if="printUrl" #actions>
            <a class="pp-btn pp-link" :href="printUrl" aria-label="Print or save as PDF" title="Print or save as PDF"><ChromeIcon name="download" /></a>
        </template>
    </PresentPlayer>
</template>
