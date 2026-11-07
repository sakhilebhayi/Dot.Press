<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ActionSection from '@/Components/ActionSection.vue';
import ConfirmationModal from '@/Components/ConfirmationModal.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    team: Object,
});

const confirmingTeamDeletion = ref(false);
const form = useForm({});

const confirmTeamDeletion = () => {
    confirmingTeamDeletion.value = true;
};

const deleteTeam = () => {
    form.delete(route('teams.destroy', props.team), {
        errorBag: 'deleteTeam',
    });
};
</script>

<template>
    <ActionSection danger>
        <template #title>
            Delete team
        </template>

        <template #description>
            This cannot be undone.
        </template>

        <template #content>
            <p class="ac-text">
                Deleting a team deletes the projects and decks that belong to it. Download any deck you want to keep first, as PowerPoint or PDF.
            </p>

            <div class="ac-actions">
                <DangerButton @click="confirmTeamDeletion">
                    Delete this team
                </DangerButton>
            </div>

            <ConfirmationModal :show="confirmingTeamDeletion" @close="confirmingTeamDeletion = false">
                <template #title>
                    Delete this team
                </template>

                <template #content>
                    Everyone loses access and the team's projects and decks are deleted, for good.
                </template>

                <template #footer>
                    <SecondaryButton @click="confirmingTeamDeletion = false">
                        Keep the team
                    </SecondaryButton>

                    <DangerButton
                        :disabled="form.processing"
                        @click="deleteTeam"
                    >
                        Delete team
                    </DangerButton>
                </template>
            </ConfirmationModal>
        </template>
    </ActionSection>
</template>
