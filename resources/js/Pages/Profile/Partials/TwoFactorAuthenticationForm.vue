<script setup>
import { ref, computed, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import ActionSection from '@/Components/ActionSection.vue';
import ConfirmsPassword from '@/Components/ConfirmsPassword.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    requiresConfirmation: Boolean,
});

const page = usePage();
const enabling = ref(false);
const confirming = ref(false);
const disabling = ref(false);
const qrCode = ref(null);
const setupKey = ref(null);
const recoveryCodes = ref([]);

const confirmationForm = useForm({
    code: '',
});

const twoFactorEnabled = computed(
    () => ! enabling.value && page.props.auth.user?.two_factor_enabled,
);

watch(twoFactorEnabled, () => {
    if (! twoFactorEnabled.value) {
        confirmationForm.reset();
        confirmationForm.clearErrors();
    }
});

const enableTwoFactorAuthentication = () => {
    enabling.value = true;

    router.post(route('two-factor.enable'), {}, {
        preserveScroll: true,
        onSuccess: () => Promise.all([
            showQrCode(),
            showSetupKey(),
            showRecoveryCodes(),
        ]),
        onFinish: () => {
            enabling.value = false;
            confirming.value = props.requiresConfirmation;
        },
    });
};

const showQrCode = () => {
    return axios.get(route('two-factor.qr-code')).then(response => {
        qrCode.value = response.data.svg;
    });
};

const showSetupKey = () => {
    return axios.get(route('two-factor.secret-key')).then(response => {
        setupKey.value = response.data.secretKey;
    });
}

const showRecoveryCodes = () => {
    return axios.get(route('two-factor.recovery-codes')).then(response => {
        recoveryCodes.value = response.data;
    });
};

const confirmTwoFactorAuthentication = () => {
    confirmationForm.post(route('two-factor.confirm'), {
        errorBag: "confirmTwoFactorAuthentication",
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            confirming.value = false;
            qrCode.value = null;
            setupKey.value = null;
        },
    });
};

const regenerateRecoveryCodes = () => {
    axios
        .post(route('two-factor.recovery-codes'))
        .then(() => showRecoveryCodes());
};

const disableTwoFactorAuthentication = () => {
    disabling.value = true;

    router.delete(route('two-factor.disable'), {
        preserveScroll: true,
        onSuccess: () => {
            disabling.value = false;
            confirming.value = false;
        },
    });
};
</script>

<template>
    <ActionSection>
        <template #title>
            Two-factor sign-in
        </template>

        <template #description>
            Ask for a code from an authenticator app each time you sign in.
        </template>

        <template #content>
            <p class="ac-state">
                <span class="pr-bead" :data-state="twoFactorEnabled && ! confirming ? 'solid' : 'hollow'" aria-hidden="true" />
                <template v-if="twoFactorEnabled && ! confirming">on</template>
                <template v-else-if="twoFactorEnabled && confirming">finish setup</template>
                <template v-else>off</template>
            </p>

            <h3 v-if="twoFactorEnabled && ! confirming" class="ac-subhead mt-3">
                Two-factor sign-in is on.
            </h3>

            <h3 v-else-if="twoFactorEnabled && confirming" class="ac-subhead mt-3">
                Finish turning on two-factor sign-in.
            </h3>

            <h3 v-else class="ac-subhead mt-3">
                Two-factor sign-in is off.
            </h3>

            <p class="ac-text">
                When it is on, sign-in asks for a six digit code from an authenticator app on your phone, as well as your password.
            </p>

            <div v-if="twoFactorEnabled">
                <div v-if="qrCode">
                    <p v-if="confirming" class="ac-text mt-4">
                        Scan this QR code with your authenticator app, or type the setup key into it, then enter the code it shows.
                    </p>

                    <p v-else class="ac-text mt-4">
                        Two-factor sign-in is on. Scan this QR code with your authenticator app, or type the setup key into it.
                    </p>

                    <div class="ac-qr mt-4" v-html="qrCode" />

                    <p v-if="setupKey" class="ac-text mt-4">
                        Setup key: <code class="ac-well" style="display: inline-block; padding: 2px 8px;" v-html="setupKey" />
                    </p>

                    <div v-if="confirming" class="mt-4 ac-grid">
                        <div>
                            <InputLabel for="code" value="Code from the app" />

                            <TextInput
                                id="code"
                                v-model="confirmationForm.code"
                                type="text"
                                name="code"
                                inputmode="numeric"
                                autofocus
                                autocomplete="one-time-code"
                                :aria-invalid="confirmationForm.errors.code ? 'true' : undefined"
                                @keyup.enter="confirmTwoFactorAuthentication"
                            />

                            <InputError :message="confirmationForm.errors.code" />
                        </div>
                    </div>
                </div>

                <div v-if="recoveryCodes.length > 0 && ! confirming">
                    <p class="ac-text mt-4">
                        <strong class="ac-ink">Keep these recovery codes in a password manager.</strong>
                        Each one works once, if you lose the phone.
                    </p>

                    <div class="ac-well mt-3" style="max-width: 24rem;">
                        <div v-for="code in recoveryCodes" :key="code">
                            {{ code }}
                        </div>
                    </div>
                </div>
            </div>

            <div class="ac-actions">
                <template v-if="! twoFactorEnabled">
                    <ConfirmsPassword @confirmed="enableTwoFactorAuthentication">
                        <PrimaryButton type="button" :disabled="enabling">
                            Turn on
                        </PrimaryButton>
                    </ConfirmsPassword>
                </template>

                <template v-else>
                    <ConfirmsPassword @confirmed="confirmTwoFactorAuthentication">
                        <PrimaryButton
                            v-if="confirming"
                            type="button"
                            :disabled="enabling || confirmationForm.processing"
                        >
                            Confirm
                        </PrimaryButton>
                    </ConfirmsPassword>

                    <ConfirmsPassword @confirmed="regenerateRecoveryCodes">
                        <SecondaryButton v-if="recoveryCodes.length > 0 && ! confirming">
                            Make new recovery codes
                        </SecondaryButton>
                    </ConfirmsPassword>

                    <ConfirmsPassword @confirmed="showRecoveryCodes">
                        <SecondaryButton v-if="recoveryCodes.length === 0 && ! confirming">
                            Show recovery codes
                        </SecondaryButton>
                    </ConfirmsPassword>

                    <ConfirmsPassword @confirmed="disableTwoFactorAuthentication">
                        <SecondaryButton
                            v-if="confirming"
                            :disabled="disabling"
                        >
                            Cancel
                        </SecondaryButton>
                    </ConfirmsPassword>

                    <ConfirmsPassword @confirmed="disableTwoFactorAuthentication">
                        <SecondaryButton
                            v-if="! confirming"
                            class="pr-btn--danger-quiet"
                            :disabled="disabling"
                        >
                            Turn off
                        </SecondaryButton>
                    </ConfirmsPassword>
                </template>
            </div>
        </template>
    </ActionSection>
</template>
