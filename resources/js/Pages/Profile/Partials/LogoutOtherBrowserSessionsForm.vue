<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ActionMessage from '@/Components/ActionMessage.vue';
import ActionSection from '@/Components/ActionSection.vue';
import DialogModal from '@/Components/DialogModal.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

defineProps({
    sessions: Array,
});

const confirmingLogout = ref(false);
const passwordInput = ref(null);

const form = useForm({
    password: '',
});

const confirmLogout = () => {
    confirmingLogout.value = true;

    setTimeout(() => passwordInput.value.focus(), 250);
};

const logoutOtherBrowserSessions = () => {
    form.delete(route('other-browser-sessions.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    confirmingLogout.value = false;

    form.reset();
};
</script>

<template>
    <ActionSection>
        <template #title>
            Browser sessions
        </template>

        <template #description>
            Where you are signed in to Dot.Press right now.
        </template>

        <template #content>
            <p class="ac-text">
                You can sign out of every other browser and device. The list shows recent sessions and may not be complete. If you think someone else has your account, also change your password.
            </p>

            <ul v-if="sessions.length > 0" class="ac-list mt-5">
                <li v-for="(session, i) in sessions" :key="i" class="ac-row">
                    <div class="ac-row-main">
                        <svg v-if="session.agent.is_desktop" class="pr-ico pr-ico--lg ac-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" />
                        </svg>

                        <svg v-else class="pr-ico pr-ico--lg ac-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />
                        </svg>

                        <div>
                            <div class="ac-row-t">
                                {{ session.agent.platform ? session.agent.platform : 'Unknown system' }}, {{ session.agent.browser ? session.agent.browser : 'unknown browser' }}
                            </div>

                            <div class="ac-row-s">
                                {{ session.ip_address }}

                                <span v-if="session.is_current_device" class="ac-state" style="margin-left: 8px;"><span class="pr-bead pr-bead--sm" data-state="current" aria-hidden="true" />this device</span>
                                <span v-else>, last active {{ session.last_active }}</span>
                            </div>
                        </div>
                    </div>
                </li>
            </ul>

            <div class="ac-actions">
                <SecondaryButton @click="confirmLogout">
                    Sign out other sessions
                </SecondaryButton>

                <ActionMessage :on="form.recentlySuccessful">
                    Done.
                </ActionMessage>
            </div>

            <DialogModal :show="confirmingLogout" @close="closeModal">
                <template #title>
                    Sign out other sessions
                </template>

                <template #content>
                    Enter your password to confirm that you want to sign out of every other browser and device.

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
                            @keyup.enter="logoutOtherBrowserSessions"
                        />

                        <InputError :message="form.errors.password" />
                    </div>
                </template>

                <template #footer>
                    <SecondaryButton @click="closeModal">
                        Cancel
                    </SecondaryButton>

                    <PrimaryButton
                        :disabled="form.processing"
                        @click="logoutOtherBrowserSessions"
                    >
                        Sign out other sessions
                    </PrimaryButton>
                </template>
            </DialogModal>
        </template>
    </ActionSection>
</template>
