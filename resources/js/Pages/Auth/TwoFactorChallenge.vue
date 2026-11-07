<script setup>
import { nextTick, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticationCard from '@/Components/AuthenticationCard.vue';
import AuthenticationCardLogo from '@/Components/AuthenticationCardLogo.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const recovery = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

const recoveryCodeInput = ref(null);
const codeInput = ref(null);

const toggleRecovery = async () => {
    recovery.value ^= true;

    await nextTick();

    if (recovery.value) {
        recoveryCodeInput.value.focus();
        form.code = '';
    } else {
        codeInput.value.focus();
        form.recovery_code = '';
    }
};

const submit = () => {
    form.post(route('two-factor.login'));
};
</script>

<template>
    <Head title="Two-factor sign-in" />

    <AuthenticationCard>
        <template #logo>
            <AuthenticationCardLogo />
        </template>

        <h1 class="gs-title">Two-factor sign-in</h1>
        <p class="gs-lead">
            <template v-if="! recovery">
                Enter the code from your authenticator app.
            </template>

            <template v-else>
                Enter one of your recovery codes. Each one works once.
            </template>
        </p>

        <form class="gs-form" @submit.prevent="submit">
            <div v-if="! recovery">
                <InputLabel for="code" value="Code" />
                <TextInput
                    id="code"
                    ref="codeInput"
                    v-model="form.code"
                    type="text"
                    name="code"
                    inputmode="numeric"
                    autofocus
                    autocomplete="one-time-code"
                    :aria-invalid="form.errors.code ? 'true' : undefined"
                />
                <InputError :message="form.errors.code" />
            </div>

            <div v-else>
                <InputLabel for="recovery_code" value="Recovery code" />
                <TextInput
                    id="recovery_code"
                    ref="recoveryCodeInput"
                    v-model="form.recovery_code"
                    type="text"
                    name="recovery_code"
                    autocomplete="one-time-code"
                    :aria-invalid="form.errors.recovery_code ? 'true' : undefined"
                />
                <InputError :message="form.errors.recovery_code" />
            </div>

            <div class="gs-foot" style="margin-top: 0;">
                <PrimaryButton :disabled="form.processing">
                    Sign in
                </PrimaryButton>

                <button type="button" class="pr-textlink" @click.prevent="toggleRecovery">
                    <template v-if="! recovery">
                        Use a recovery code
                    </template>

                    <template v-else>
                        Use an authenticator code
                    </template>
                </button>
            </div>
        </form>
    </AuthenticationCard>
</template>
