<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    status: String,
});

const form = useForm({});

const submit = () => {
    form.post(route('verification.send'));
};

const verificationLinkSent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <Head title="Verify your email" />

    <AuthenticationCard>
        <template #logo>
            <AuthenticationCardLogo />
        </template>

        <h1 class="gs-title">Verify your email</h1>
        <p class="gs-lead">
            We emailed you a link. Open it to confirm your address. If it did not arrive, we can send another.
        </p>

        <div v-if="verificationLinkSent" class="pr-note" role="status">
            <span class="pr-bead pr-bead--sm" data-state="solid" aria-hidden="true" />
            <p><b>sent</b>A new verification link is on its way to the address in your profile.</p>
        </div>

        <form class="gs-form" @submit.prevent="submit">
            <div class="gs-foot" style="margin-top: 0;">
                <PrimaryButton :disabled="form.processing">
                    Send the link again
                </PrimaryButton>

                <span style="display: inline-flex; gap: 16px;">
                    <Link :href="route('profile.show')" class="pr-textlink">Edit profile</Link>
                    <Link :href="route('logout')" method="post" as="button" class="pr-textlink">Sign out</Link>
                </span>
            </div>
        </form>
    </AuthenticationCard>
</template>
