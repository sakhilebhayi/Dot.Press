<script setup>
// Says who produced an answer: "Local rules" (fixed rules over your own words) or "AI model" (a language model).
import Ico from '../../ui/Ico.vue';
defineProps({ mode: { type: String, default: 'local' }, degraded: { type: Boolean, default: false } });
</script>

<template>
    <span class="iq-mode pr-chip" :class="{ 'is-ai': mode === 'ai' }" :title="mode === 'ai' ? 'A language model wrote this' : 'Fixed rules over the text of your deck. No language model.'">
        <Ico v-if="mode === 'ai'" name="sparkle" size="sm" />
        <span v-else class="iq-mode-sq" aria-hidden="true"></span>
        {{ mode === 'ai' ? 'AI model' : 'Local rules' }}<span v-if="degraded" class="iq-mode-d"> (fallback)</span>
    </span>
</template>
