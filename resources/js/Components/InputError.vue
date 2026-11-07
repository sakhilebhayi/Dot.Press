<script setup>
// A field error: an icon and the words, announced when it appears. Colour is never the only signal.
defineProps({
    message: String,
});
</script>

<template>
    <div v-if="message" class="pr-err" role="alert">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="9" /><path d="M12 7.5v5.5" /><path d="M12 16.5h.01" />
        </svg>
        <span>{{ message }}</span>
    </div>
</template>
