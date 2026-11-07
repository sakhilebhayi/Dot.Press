<script setup>
import TextBox from './TextBox.vue';
defineProps({ el: { type: Object, required: true } });
</script>

<template>
    <TextBox :el="el" kind="text" />
</template>
