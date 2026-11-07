<script setup>
import ApiTokenManager from '@/Pages/API/Partials/ApiTokenManager.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    tokens: Array,
    availablePermissions: Array,
    defaultPermissions: Array,
});
</script>

<template>
    <AppLayout title="API tokens">
        <ApiTokenManager
            :tokens="tokens"
            :available-permissions="availablePermissions"
            :default-permissions="defaultPermissions"
        />
    </AppLayout>
</template>
