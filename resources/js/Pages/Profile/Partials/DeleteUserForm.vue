<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ActionSection from '@/Components/ActionSection.vue';
import DangerButton from '@/Components/DangerButton.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const confirmingUserDeletion = ref(false);
const passwordInput = ref(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    setTimeout(() => passwordInput.value.focus(), 250);
};

const deleteUser = () => {
    form.delete(route('current-user.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;

    form.reset();
};
</script>

<template>
    <ActionSection danger>
        <template #title>
            Delete account
        </template>

        <template #description>
            This cannot be undone.
        </template>

        <template #content>
            <p class="ac-text">
                Deleting your account also deletes the decks in the projects you created, even ones you shared with a team. Download any deck you want to keep first, as PowerPoint or PDF.
            </p>

            <div class="ac-actions">
                <DangerButton @click="confirmUserDeletion">
                    Delete my account
                </DangerButton>
            </div>

            <DialogModal :show="confirmingUserDeletion" @close="closeModal">
                <template #title>
                    <span class="pr-bead pr-bead--bad" aria-hidden="true" />
                    Delete account
                </template>

                <template #content>
                    This deletes your account and the decks in your projects, for good. Enter your password to confirm.

                    <div class="mt-4">
                        <TextInput
                            ref="passwordInput"
                            v-model="form.password"
                            type="password"
                            name="password"
                            aria-label="Password"
                            placeholder="Password"
                            autocomplete="current-password"
                            :aria-invalid="form.errors.password ? 'true' : undefined"
                            @keyup.enter="deleteUser"
                        />

                        <InputError :message="form.errors.password" />
                    </div>
                </template>

                <template #footer>
                    <SecondaryButton @click="closeModal">
                        Keep my account
                    </SecondaryButton>

                    <DangerButton
                        :disabled="form.processing"
                        @click="deleteUser"
                    >
                        Delete my account
                    </DangerButton>
                </template>
            </DialogModal>
        </template>
    </ActionSection>
</template>
