<script setup>
// Public viewer for a shared deck: read only, no editing affordances, and no speaker notes. When the link is
// protected the page asks for the password first; the deck content is not even sent until it is unlocked.
import { Head, useForm } from '@inertiajs/vue3';
import '../../../css/press-player.css';
import PresentPlayer from '../../press/player/PresentPlayer.vue';

const props = defineProps({
    deck: { type: Object, default: null },
    slides: { type: Array, default: () => [] },
    assets: { type: Object, default: () => ({}) },
    startIndex: { type: Number, default: 0 },
    needsPassword: { type: Boolean, default: false },
    /** where the password is posted. Defaults to the current address. */
    unlockUrl: { type: String, default: null },
    title: { type: String, default: 'Shared presentation' },
    /** set when the link cannot be used: 'expired' | 'revoked' | 'unavailable' */
    problem: { type: String, default: null },
});

const form = useForm({ password: '' });
function unlock() {
    form.post(props.unlockUrl || window.location.pathname + window.location.search, {
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
                <input id="share-pass" v-model="form.password" class="pp-field" type="password" autocomplete="off" autofocus required placeholder="Password" :aria-invalid="!!form.errors.password" aria-describedby="share-err" />
                <p id="share-err" class="pp-error" role="alert">{{ form.errors.password }}</p>
                <button class="pp-primary" type="submit" :disabled="form.processing || !form.password">Open presentation</button>
            </form>
        </div>
    </div>
    <PresentPlayer v-else :deck="deck" :slides="slides" :assets="assets" :start-index="startIndex" mode="shared" />
</template>
