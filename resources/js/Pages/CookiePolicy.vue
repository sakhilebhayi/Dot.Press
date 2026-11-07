<script setup>
// A legal page. The text lives in resources/markdown and is rendered on the server; this page only sets it in the
// guest shell. The first line of each document ("Last updated") is shown under the title.
import { Head } from '@inertiajs/vue3';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';

defineProps({
    cookies: String,
});
</script>

<template>
    <Head title="Cookie policy">
        <meta name="robots" content="index, follow" />
    </Head>

    <AuthenticationCard wide>
        <template #logo>
            <AuthenticationCardLogo />
        </template>

        <h1 class="gs-title">Cookie policy</h1>
        <div class="gs-doc" v-html="cookies" />
    </AuthenticationCard>
</template>
