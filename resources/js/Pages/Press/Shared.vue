<script setup>
// Public viewer for a shared deck: read only, no editing affordances, and no speaker notes. The server sends the
// document (spec section 9) as `doc`, or, when the link is protected and not yet unlocked, no document at all and
// `needsPassword`: the deck content is not even sent until the password is right (SharedDeckController).
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import '../../../css/press-player.css';
import PresentPlayer from '../../press/player/PresentPlayer.vue';

const props = defineProps({
    /** the document the guest may read: {deck, slides, assets} */
    doc: { type: Object, default: null },
    needsPassword: { type: Boolean, default: false },
    /** the reason the last password was refused (flashed by the server) */
    error: { type: String, default: null },
    /** the link's own token: the password is posted to /s/{token}/unlock */
    token: { type: String, required: true },
    title: { type: String, default: 'Shared presentation' },
});

const form = useForm({ password: '' });
const problem = computed(() => props.error || form.errors.password || '');
function unlock() {
    form.post(`/s/${encodeURIComponent(props.token)}/unlock`, {
        preserveScroll: true,
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head :title="doc?.deck?.title || title" />
    <div v-if="needsPassword || !doc" class="pp-root">
        <div class="pp-gate">
            <form class="pp-card" @submit.prevent="unlock">
                <h1>{{ title }}</h1>
                <p>This presentation is protected. Enter the password you were given.</p>
                <label class="pp-sr" for="share-pass">Password</label>
                <input id="share-pass" v-model="form.password" class="pp-field" type="password" autocomplete="off" autofocus required placeholder="Password" :aria-invalid="!!problem" aria-describedby="share-err" />
                <p id="share-err" class="pp-error" role="alert">{{ problem }}</p>
                <button class="pp-primary" type="submit" :disabled="form.processing || !form.password">Open presentation</button>
            </form>
        </div>
    </div>
    <PresentPlayer v-else :deck="doc.deck" :slides="doc.slides" :assets="doc.assets" :title="doc.deck.title" mode="shared" />
</template>
