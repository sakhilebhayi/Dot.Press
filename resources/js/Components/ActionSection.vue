<script setup>
import SectionTitle from './SectionTitle.vue';

// danger: the section holds a destructive action. It gets its own rule in the warning colour and a square bead.
defineProps({ danger: { type: Boolean, default: false } });
</script>

<template>
    <section class="ac-sec" :class="{ 'ac-sec--danger': danger }">
        <SectionTitle>
            <template #title>
                <span v-if="danger" class="pr-bead pr-bead--bad" aria-hidden="true" />
                <slot name="title" />
            </template>
            <template #description>
                <slot name="description" />
            </template>
        </SectionTitle>

        <div class="ac-sec-b">
            <slot name="content" />
        </div>
    </section>
</template>
