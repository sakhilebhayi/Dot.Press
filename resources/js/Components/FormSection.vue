<script setup>
import { computed, useSlots } from 'vue';
import SectionTitle from './SectionTitle.vue';

defineEmits(['submitted']);

const hasActions = computed(() => !! useSlots().actions);
</script>

<template>
    <section class="ac-sec">
        <SectionTitle>
            <template #title>
                <slot name="title" />
            </template>
            <template #description>
                <slot name="description" />
            </template>
        </SectionTitle>

        <div class="ac-sec-b">
            <form @submit.prevent="$emit('submitted')">
                <div class="ac-grid">
                    <slot name="form" />
                </div>

                <div v-if="hasActions" class="ac-actions" style="flex-direction: row-reverse; justify-content: flex-end;">
                    <slot name="actions" />
                </div>
            </form>
        </div>
    </section>
</template>
