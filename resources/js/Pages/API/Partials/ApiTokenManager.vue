<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ActionMessage from '@/Components/ActionMessage.vue';
import ActionSection from '@/Components/ActionSection.vue';
import Checkbox from '@/Components/Checkbox.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import DialogModal from '@/Components/DialogModal.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    tokens: Array,
    availablePermissions: Array,
    defaultPermissions: Array,
});

const createApiTokenForm = useForm({
    name: '',
    permissions: props.defaultPermissions,
});

const updateApiTokenForm = useForm({
    permissions: [],
});

const deleteApiTokenForm = useForm({});

const displayingToken = ref(false);
const managingPermissionsFor = ref(null);
const apiTokenBeingDeleted = ref(null);

const createApiToken = () => {
    createApiTokenForm.post(route('api-tokens.store'), {
        preserveScroll: true,
        onSuccess: () => {
            displayingToken.value = true;
            createApiTokenForm.reset();
        },
    });
};

const manageApiTokenPermissions = (token) => {
    updateApiTokenForm.permissions = token.abilities;
    managingPermissionsFor.value = token;
};

const updateApiToken = () => {
    updateApiTokenForm.put(route('api-tokens.update', managingPermissionsFor.value), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (managingPermissionsFor.value = null),
    });
};

const confirmApiTokenDeletion = (token) => {
    apiTokenBeingDeleted.value = token;
};

const deleteApiToken = () => {
    deleteApiTokenForm.delete(route('api-tokens.destroy', apiTokenBeingDeleted.value), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (apiTokenBeingDeleted.value = null),
    });
};
</script>

<template>
    <div>
        <FormSection @submitted="createApiToken">
            <template #title>
                Create an API token
            </template>

            <template #description>
                A token lets another program act as you. Give it only the permissions it needs.
            </template>

            <template #form>
                <div>
                    <InputLabel for="name" value="Name" />
                    <TextInput
                        id="name"
                        v-model="createApiTokenForm.name"
                        type="text"
                        name="name"
                        autofocus
                        autocomplete="off"
                        :aria-invalid="createApiTokenForm.errors.name ? 'true' : undefined"
                    />
                    <InputError :message="createApiTokenForm.errors.name" />
                </div>

                <fieldset v-if="availablePermissions.length > 0" style="border: 0; padding: 0; margin: 0; min-width: 0;">
                    <legend class="pr-label">Permissions</legend>

                    <div class="ac-perms">
                        <label v-for="permission in availablePermissions" :key="permission" class="pr-check">
                            <Checkbox v-model:checked="createApiTokenForm.permissions" :value="permission" />
                            <span>{{ permission }}</span>
                        </label>
                    </div>
                </fieldset>
            </template>

            <template #actions>
                <ActionMessage :on="createApiTokenForm.recentlySuccessful">
                    Created.
                </ActionMessage>

                <PrimaryButton :disabled="createApiTokenForm.processing">
                    Create token
                </PrimaryButton>
            </template>
        </FormSection>

        <ActionSection v-if="tokens.length > 0">
            <template #title>
                Your tokens
            </template>

            <template #description>
                Delete a token when the program that uses it is gone.
            </template>

            <template #content>
                <ul class="ac-list">
                    <li v-for="token in tokens" :key="token.id" class="ac-row">
                        <div>
                            <div class="ac-row-t">{{ token.name }}</div>
                            <div v-if="token.last_used_ago" class="ac-row-s">Last used {{ token.last_used_ago }}</div>
                        </div>

                        <div class="ac-row-end">
                            <button
                                v-if="availablePermissions.length > 0"
                                type="button"
                                class="pr-btn pr-btn--sm"
                                @click="manageApiTokenPermissions(token)"
                            >
                                Permissions
                            </button>

                            <button type="button" class="pr-btn pr-btn--ghost pr-btn--sm pr-btn--danger" @click="confirmApiTokenDeletion(token)">
                                Delete
                            </button>
                        </div>
                    </li>
                </ul>
            </template>
        </ActionSection>

        <DialogModal :show="displayingToken" @close="displayingToken = false">
            <template #title>
                Copy your token
            </template>

            <template #content>
                <p>It is shown once. Copy it now and keep it somewhere safe.</p>

                <div v-if="$page.props.jetstream.flash.token" class="ac-well mt-4">
                    {{ $page.props.jetstream.flash.token }}
                </div>
            </template>

            <template #footer>
                <SecondaryButton @click="displayingToken = false">
                    Done
                </SecondaryButton>
            </template>
        </DialogModal>

        <DialogModal :show="managingPermissionsFor != null" @close="managingPermissionsFor = null">
            <template #title>
                Token permissions
            </template>

            <template #content>
                <div class="ac-perms">
                    <label v-for="permission in availablePermissions" :key="permission" class="pr-check">
                        <Checkbox v-model:checked="updateApiTokenForm.permissions" :value="permission" />
                        <span>{{ permission }}</span>
                    </label>
                </div>
            </template>

            <template #footer>
                <SecondaryButton @click="managingPermissionsFor = null">
                    Cancel
                </SecondaryButton>

                <PrimaryButton
                    :disabled="updateApiTokenForm.processing"
                    @click="updateApiToken"
                >
                    Save permissions
                </PrimaryButton>
            </template>
        </DialogModal>

        <ConfirmationModal :show="apiTokenBeingDeleted != null" @close="apiTokenBeingDeleted = null">
            <template #title>
                Delete this token
            </template>

            <template #content>
                Anything that uses it stops working straight away.
            </template>

            <template #footer>
                <SecondaryButton @click="apiTokenBeingDeleted = null">
                    Cancel
                </SecondaryButton>

                <DangerButton
                    :disabled="deleteApiTokenForm.processing"
                    @click="deleteApiToken"
                >
                    Delete token
                </DangerButton>
            </template>
        </ConfirmationModal>
    </div>
</template>
