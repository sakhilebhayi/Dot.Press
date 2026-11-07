<script setup>
import { ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import ActionMessage from '@/Components/ActionMessage.vue';
import ActionSection from '@/Components/ActionSection.vue';
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
    team: Object,
    availableRoles: Array,
    userPermissions: Object,
});

const page = usePage();

const addTeamMemberForm = useForm({
    email: '',
    role: null,
});

const updateRoleForm = useForm({
    role: null,
});

const leaveTeamForm = useForm({});
const removeTeamMemberForm = useForm({});

const currentlyManagingRole = ref(false);
const managingRoleFor = ref(null);
const confirmingLeavingTeam = ref(false);
const teamMemberBeingRemoved = ref(null);

const addTeamMember = () => {
    addTeamMemberForm.post(route('team-members.store', props.team), {
        errorBag: 'addTeamMember',
        preserveScroll: true,
        onSuccess: () => addTeamMemberForm.reset(),
    });
};

const cancelTeamInvitation = (invitation) => {
    router.delete(route('team-invitations.destroy', invitation), {
        preserveScroll: true,
    });
};

const manageRole = (teamMember) => {
    managingRoleFor.value = teamMember;
    updateRoleForm.role = teamMember.membership.role;
    currentlyManagingRole.value = true;
};

const updateRole = () => {
    updateRoleForm.put(route('team-members.update', [props.team, managingRoleFor.value]), {
        preserveScroll: true,
        onSuccess: () => currentlyManagingRole.value = false,
    });
};

const confirmLeavingTeam = () => {
    confirmingLeavingTeam.value = true;
};

const leaveTeam = () => {
    leaveTeamForm.delete(route('team-members.destroy', [props.team, page.props.auth.user]));
};

const confirmTeamMemberRemoval = (teamMember) => {
    teamMemberBeingRemoved.value = teamMember;
};

const removeTeamMember = () => {
    removeTeamMemberForm.delete(route('team-members.destroy', [props.team, teamMemberBeingRemoved.value]), {
        errorBag: 'removeTeamMember',
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => teamMemberBeingRemoved.value = null,
    });
};

const displayableRole = (role) => {
    return props.availableRoles.find(r => r.key === role).name;
};
</script>

<template>
    <div>
        <!-- Add a member -->
        <FormSection v-if="userPermissions.canAddTeamMembers" @submitted="addTeamMember">
            <template #title>
                Add a member
            </template>

            <template #description>
                Invite someone by email. They get a link that adds them to this team.
            </template>

            <template #form>
                <div>
                    <InputLabel for="email" value="Email" />
                    <TextInput
                        id="email"
                        v-model="addTeamMemberForm.email"
                        type="email"
                        name="email"
                        autocomplete="off"
                        inputmode="email"
                        :aria-invalid="addTeamMemberForm.errors.email ? 'true' : undefined"
                    />
                    <InputError :message="addTeamMemberForm.errors.email" />
                </div>

                <div v-if="availableRoles.length > 0">
                    <InputLabel value="Role" />

                        <div class="ac-roles" role="radiogroup" aria-label="Role">
                            <button
                                v-for="role in availableRoles"
                                :key="role.key"
                                type="button"
                                class="ac-role"
                                role="radio"
                                :aria-checked="addTeamMemberForm.role === role.key ? 'true' : 'false'"
                                @click="addTeamMemberForm.role = role.key"
                            >
                                <span class="pr-bead" aria-hidden="true" />
                                <span>
                                    <span class="ac-role-n">{{ role.name }}</span>
                                    <span class="ac-role-d" style="display: block;">{{ role.description }}</span>
                                </span>
                            </button>
                        </div>

                    <InputError :message="addTeamMemberForm.errors.role" />
                </div>
            </template>

            <template #actions>
                <ActionMessage :on="addTeamMemberForm.recentlySuccessful">
                    Invited.
                </ActionMessage>

                <PrimaryButton :disabled="addTeamMemberForm.processing">
                    Send invitation
                </PrimaryButton>
            </template>
        </FormSection>

        <!-- Pending invitations -->
        <ActionSection v-if="team.team_invitations.length > 0 && userPermissions.canAddTeamMembers">
            <template #title>
                Waiting to join
            </template>

            <template #description>
                These people have an invitation and have not accepted it yet.
            </template>

            <template #content>
                <ul class="ac-list">
                    <li v-for="invitation in team.team_invitations" :key="invitation.id" class="ac-row">
                        <div class="ac-row-main">
                            <span class="pr-bead" data-state="hollow" aria-hidden="true" />
                            <span class="ac-row-t">{{ invitation.email }}</span>
                            <span class="ac-state">invited</span>
                        </div>

                        <div class="ac-row-end">
                            <button
                                v-if="userPermissions.canRemoveTeamMembers"
                                type="button"
                                class="pr-btn pr-btn--ghost pr-btn--sm pr-btn--danger"
                                @click="cancelTeamInvitation(invitation)"
                            >
                                Cancel invitation
                            </button>
                        </div>
                    </li>
                </ul>
            </template>
        </ActionSection>

        <!-- Members -->
        <ActionSection v-if="team.users.length > 0">
            <template #title>
                Members
            </template>

            <template #description>
                Everyone who belongs to this team, and the role each one has.
            </template>

            <template #content>
                <ul class="ac-list">
                    <li v-for="user in team.users" :key="user.id" class="ac-row">
                        <div class="ac-row-main">
                            <span class="ac-avatar" aria-hidden="true">{{ (user.name || '?').charAt(0).toUpperCase() }}</span>
                            <span>
                                <span class="ac-row-t">{{ user.name }}</span>
                                <span v-if="$page.props.auth.user.id === user.id" class="ac-state" style="margin-left: 8px;">you</span>
                            </span>
                        </div>

                        <div class="ac-row-end">
                            <button
                                v-if="userPermissions.canUpdateTeamMembers && availableRoles.length"
                                type="button"
                                class="pr-btn pr-btn--sm"
                                :aria-label="`Change the role of ${user.name}, now ${displayableRole(user.membership.role)}`"
                                @click="manageRole(user)"
                            >
                                {{ displayableRole(user.membership.role) }}
                            </button>

                            <span v-else-if="availableRoles.length" class="ac-state">{{ displayableRole(user.membership.role) }}</span>

                            <button
                                v-if="$page.props.auth.user.id === user.id"
                                type="button"
                                class="pr-btn pr-btn--ghost pr-btn--sm pr-btn--danger"
                                @click="confirmLeavingTeam"
                            >
                                Leave team
                            </button>

                            <button
                                v-else-if="userPermissions.canRemoveTeamMembers"
                                type="button"
                                class="pr-btn pr-btn--ghost pr-btn--sm pr-btn--danger"
                                :aria-label="`Remove ${user.name} from the team`"
                                @click="confirmTeamMemberRemoval(user)"
                            >
                                Remove
                            </button>
                        </div>
                    </li>
                </ul>
            </template>
        </ActionSection>

        <!-- Role dialog -->
        <DialogModal :show="currentlyManagingRole" @close="currentlyManagingRole = false">
            <template #title>
                Change role
            </template>

            <template #content>
                <div v-if="managingRoleFor">

                        <div class="ac-roles" role="radiogroup" aria-label="Role">
                            <button
                                v-for="role in availableRoles"
                                :key="role.key"
                                type="button"
                                class="ac-role"
                                role="radio"
                                :aria-checked="updateRoleForm.role === role.key ? 'true' : 'false'"
                                @click="updateRoleForm.role = role.key"
                            >
                                <span class="pr-bead" aria-hidden="true" />
                                <span>
                                    <span class="ac-role-n">{{ role.name }}</span>
                                    <span class="ac-role-d" style="display: block;">{{ role.description }}</span>
                                </span>
                            </button>
                        </div>

                </div>
            </template>

            <template #footer>
                <SecondaryButton @click="currentlyManagingRole = false">
                    Cancel
                </SecondaryButton>

                <PrimaryButton
                    :disabled="updateRoleForm.processing"
                    @click="updateRole"
                >
                    Save role
                </PrimaryButton>
            </template>
        </DialogModal>

        <!-- Leave -->
        <ConfirmationModal :show="confirmingLeavingTeam" @close="confirmingLeavingTeam = false">
            <template #title>
                Leave this team
            </template>

            <template #content>
                You lose access to the team's decks until someone invites you back.
            </template>

            <template #footer>
                <SecondaryButton @click="confirmingLeavingTeam = false">
                    Stay
                </SecondaryButton>

                <DangerButton
                    :disabled="leaveTeamForm.processing"
                    @click="leaveTeam"
                >
                    Leave team
                </DangerButton>
            </template>
        </ConfirmationModal>

        <!-- Remove -->
        <ConfirmationModal :show="teamMemberBeingRemoved" @close="teamMemberBeingRemoved = null">
            <template #title>
                Remove this person
            </template>

            <template #content>
                They lose access to the team's decks straight away.
            </template>

            <template #footer>
                <SecondaryButton @click="teamMemberBeingRemoved = null">
                    Cancel
                </SecondaryButton>

                <DangerButton
                    :disabled="removeTeamMemberForm.processing"
                    @click="removeTeamMember"
                >
                    Remove
                </DangerButton>
            </template>
        </ConfirmationModal>
    </div>
</template>
