<script setup>
// The editor's toast host: shows the toast on top of the queue in editor.toastState.
import { useEditorContext } from '../context.js';
import ToastView from './ToastView.vue';

const editor = useEditorContext();
function run(t) { t.action.run(); editor.dismissToast(t.id); }
</script>

<template>
    <ToastView :toast="editor.toastState.value" @dismiss="editor.dismissToast($event)" @run="run" />
</template>
