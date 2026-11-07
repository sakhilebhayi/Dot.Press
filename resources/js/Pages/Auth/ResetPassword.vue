<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    email: String,
    token: String,
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Choose a new password" />

    <AuthenticationCard>
        <template #logo>
            <AuthenticationCardLogo />
        </template>

        <h1 class="gs-title">Choose a new password</h1>
        <p class="gs-lead">Use a long one that you do not use anywhere else.</p>

        <form class="gs-form" @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput
                    id="email"
                    v-model="form.email"
                    type="email"
                    name="email"
                    required
                    autocomplete="username"
                    inputmode="email"
                    :aria-invalid="form.errors.email ? 'true' : undefined"
                />
                <InputError :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="New password" />
                <TextInput
                    id="password"
                    v-model="form.password"
                    type="password"
                    name="password"
                    required
                    autofocus
                    autocomplete="new-password"
                    :aria-invalid="form.errors.password ? 'true' : undefined"
                />
                <InputError :message="form.errors.password" />
            </div>

            <div>
                <InputLabel for="password_confirmation" value="Confirm new password" />
                <TextInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                    :aria-invalid="form.errors.password_confirmation ? 'true' : undefined"
                />
                <InputError :message="form.errors.password_confirmation" />
            </div>

            <div class="gs-foot" style="margin-top: 0;">
                <PrimaryButton :disabled="form.processing">
                    Save new password
                </PrimaryButton>
            </div>
        </form>
    </AuthenticationCard>
</template>
