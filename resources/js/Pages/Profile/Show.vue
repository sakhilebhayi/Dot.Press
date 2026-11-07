<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import DeleteUserForm from '@/Pages/Profile/Partials/DeleteUserForm.vue';
import LogoutOtherBrowserSessionsForm from '@/Pages/Profile/Partials/LogoutOtherBrowserSessionsForm.vue';
import TwoFactorAuthenticationForm from '@/Pages/Profile/Partials/TwoFactorAuthenticationForm.vue';
import UpdatePasswordForm from '@/Pages/Profile/Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from '@/Pages/Profile/Partials/UpdateProfileInformationForm.vue';

defineProps({
    confirmsTwoFactorAuthentication: Boolean,
    sessions: Array,
});
</script>

<template>
    <AppLayout title="Profile">
        <UpdateProfileInformationForm v-if="$page.props.jetstream.canUpdateProfileInformation" :user="$page.props.auth.user" />

        <UpdatePasswordForm v-if="$page.props.jetstream.canUpdatePassword" />

        <TwoFactorAuthenticationForm
            v-if="$page.props.jetstream.canManageTwoFactorAuthentication"
            :requires-confirmation="confirmsTwoFactorAuthentication"
        />

        <LogoutOtherBrowserSessionsForm :sessions="sessions" />

        <DeleteUserForm v-if="$page.props.jetstream.hasAccountDeletionFeatures" />
    </AppLayout>
</template>
