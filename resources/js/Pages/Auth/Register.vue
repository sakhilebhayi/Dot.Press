<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    terms: false,
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <Head title="Create an account" />

    <AuthenticationCard>
        <template #logo>
            <AuthenticationCardLogo />
        </template>

        <h1 class="gs-title">Create an account</h1>
        <p class="gs-lead">
            Most accounts are created by signing in through InfoDot. Use this form only if you were told to.
        </p>

        <form class="gs-form" @submit.prevent="submit">
            <div>
                <InputLabel for="name" value="Name" />
                <TextInput id="name" v-model="form.name" type="text" name="name" required autofocus autocomplete="name" :aria-invalid="form.errors.name ? 'true' : undefined" />
                <InputError :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" v-model="form.email" type="email" name="email" required autocomplete="username" inputmode="email" :aria-invalid="form.errors.email ? 'true' : undefined" />
                <InputError :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="Password" />
                <TextInput id="password" v-model="form.password" type="password" name="password" required autocomplete="new-password" :aria-invalid="form.errors.password ? 'true' : undefined" />
                <InputError :message="form.errors.password" />
            </div>

            <div>
                <InputLabel for="password_confirmation" value="Confirm password" />
                <TextInput id="password_confirmation" v-model="form.password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" :aria-invalid="form.errors.password_confirmation ? 'true' : undefined" />
                <InputError :message="form.errors.password_confirmation" />
            </div>

            <div v-if="$page.props.jetstream.hasTermsAndPrivacyPolicyFeature">
                <label class="pr-check">
                    <Checkbox id="terms" v-model:checked="form.terms" name="terms" required />
                    <span>
                        I agree to the
                        <a target="_blank" :href="route('terms.show')" class="pr-textlink">Terms</a>
                        and the
                        <a target="_blank" :href="route('policy.show')" class="pr-textlink">Privacy policy</a>
                    </span>
                </label>
                <InputError :message="form.errors.terms" />
            </div>

            <div class="gs-foot" style="margin-top: 0;">
                <PrimaryButton :disabled="form.processing">
                    Create account
                </PrimaryButton>

                <Link :href="route('login')" class="pr-textlink">Already have an account?</Link>
            </div>
        </form>
    </AuthenticationCard>
</template>
