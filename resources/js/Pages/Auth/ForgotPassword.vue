<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

defineProps({
    status: String,
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <Head title="Reset your password" />

    <AuthenticationCard>
        <template #logo>
            <AuthenticationCardLogo />
        </template>

        <h1 class="gs-title">Reset your password</h1>
        <p class="gs-lead">
            Give us the email address of your account and we will email you a link to choose a new password.
        </p>

        <div v-if="status" class="pr-note" role="status">
            <span class="pr-bead pr-bead--sm" data-state="solid" aria-hidden="true" />
            <p><b>sent</b>{{ status }}</p>
        </div>

        <form class="gs-form" @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput
                    id="email"
                    v-model="form.email"
                    type="email"
                    name="email"
                    required
                    autofocus
                    autocomplete="username"
                    inputmode="email"
                    :aria-invalid="form.errors.email ? 'true' : undefined"
                />
                <InputError :message="form.errors.email" />
            </div>

            <div class="gs-foot" style="margin-top: 0;">
                <PrimaryButton :disabled="form.processing">
                    Email me a reset link
                </PrimaryButton>
                <Link :href="route('login')" class="pr-textlink">Back to sign in</Link>
            </div>
        </form>
    </AuthenticationCard>
</template>
