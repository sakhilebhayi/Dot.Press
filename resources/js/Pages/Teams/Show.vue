<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import DeleteTeamForm from '@/Pages/Teams/Partials/DeleteTeamForm.vue';
import TeamMemberManager from '@/Pages/Teams/Partials/TeamMemberManager.vue';
import UpdateTeamNameForm from '@/Pages/Teams/Partials/UpdateTeamNameForm.vue';

defineProps({
    team: Object,
    availableRoles: Array,
    permissions: Object,
});
</script>

<template>
    <AppLayout title="Team settings">
        <UpdateTeamNameForm :team="team" :permissions="permissions" />

        <TeamMemberManager
            :team="team"
            :available-roles="availableRoles"
            :user-permissions="permissions"
        />

        <DeleteTeamForm v-if="permissions.canDeleteTeam && ! team.personal_team" :team="team" />
    </AppLayout>
</template>
