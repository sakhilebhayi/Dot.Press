<script setup>
import Modal from './Modal.vue';

const emit = defineEmits(['close']);

defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const close = () => {
    emit('close');
};
</script>

<template>
    <Modal
        :show="show"
        :max-width="maxWidth"
        :closeable="closeable"
        @close="close"
    >
        <div class="pr-modal-b">
            <h2 class="pr-modal-t">
                <span class="pr-bead pr-bead--bad" aria-hidden="true" />
                <slot name="title" />
            </h2>

            <div class="pr-modal-c">
                <slot name="content" />
            </div>
        </div>

        <div class="pr-modal-f">
            <slot name="footer" />
        </div>
    </Modal>
</template>
