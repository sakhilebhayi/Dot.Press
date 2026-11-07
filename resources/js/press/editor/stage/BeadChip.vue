<script setup>
// The slide's Spine state next to the slide: a small chip such as "hollow: Add a chart, table or figure". Editor furniture
// only, outside the slide's box, never in the document, never printed. The words come from lib/beadChip.js, which reads the
// same evidence function as the Spine.
import { computed } from 'vue';
import Bead from '../ui/Bead.vue';
import Ico from '../ui/Ico.vue';
import { chipModel } from './lib/beadChip.js';

const props = defineProps({
    slide: { type: Object, required: true },
    placement: { type: Object, required: true },
});
const emit = defineEmits(['dismiss']);
const model = computed(() => chipModel(props.slide));
const style = computed(() => ({ transform: `translate(${props.placement.left}px, ${props.placement.top}px)` }));
</script>

<template>
    <div class="es-beadchip" :class="`is-${model.state}`" :style="style" data-es-ui role="group" aria-label="This slide on the Spine">
        <Bead :state="model.state" :evidence="model.state" />
        <p class="es-beadchip-t"><span class="pr-state es-beadchip-w">{{ model.word }}</span><span class="es-beadchip-m">{{ model.message }}</span></p>
        <button type="button" class="es-beadchip-x" aria-label="Hide this hint for this slide" @click="emit('dismiss')"><Ico name="close" size="sm" /></button>
    </div>
</template>
