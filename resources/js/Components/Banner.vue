<script setup>
import { ref, watchEffect } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const show = ref(true);
const style = ref('success');
const message = ref('');

watchEffect(async () => {
    style.value = page.props.jetstream.flash?.bannerStyle || 'success';
    message.value = page.props.jetstream.flash?.banner || '';
    show.value = true;
});
</script>

<template>
    <div v-if="show && message" class="pr-banner" :class="{ 'pr-banner--danger': style == 'danger' }" :role="style == 'danger' ? 'alert' : 'status'">
        <span class="pr-bead" :class="style == 'danger' ? 'pr-bead--bad' : 'pr-bead--ok'" aria-hidden="true" />
        <p><b>{{ style == 'danger' ? 'problem' : 'done' }}</b>{{ message }}</p>
        <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon pr-btn--sm" aria-label="Dismiss" @click.prevent="show = false">
            <svg class="pr-ico pr-ico--sm" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
        </button>
    </div>
</template>
