<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

defineProps({
    canResetPassword: Boolean,
    status: String,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.transform(data => ({
        ...data,
        remember: form.remember ? 'on' : '',
    })).post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Sign in" />

    <AuthenticationCard>
        <template #logo>
            <AuthenticationCardLogo />
        </template>

        <h1 class="gs-title">Sign in</h1>
        <p class="gs-lead">
            Dot.Press accounts come from InfoDot. An account is created the first time you sign in through InfoDot, so there is no separate sign-up here.
        </p>

        <div v-if="status" class="pr-note" role="status">
            <span class="pr-bead pr-bead--sm" data-state="solid" aria-hidden="true" />
            <p><b>note</b>{{ status }}</p>
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

            <div>
                <InputLabel for="password" value="Password" />
                <TextInput
                    id="password"
                    v-model="form.password"
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    :aria-invalid="form.errors.password ? 'true' : undefined"
                />
                <InputError :message="form.errors.password" />
            </div>

            <label class="pr-check">
                <Checkbox v-model:checked="form.remember" name="remember" />
                <span>Keep me signed in on this device</span>
            </label>

            <div class="gs-foot" style="margin-top: 0;">
                <PrimaryButton :disabled="form.processing">
                    Sign in
                </PrimaryButton>

                <Link v-if="canResetPassword" :href="route('password.request')" class="pr-textlink">
                    Forgot your password?
                </Link>
            </div>
        </form>

        <p class="gs-aside">
            Came from InfoDot and still landed here? Open Dot.Press again from
            <a class="pr-textlink" href="https://infodot.co.za" rel="noopener">InfoDot</a>.
        </p>
    </AuthenticationCard>
</template>
