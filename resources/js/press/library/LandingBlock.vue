<script setup>
// The arrival row: the Dot.Press logo, one sentence that states where the person is (the presentation they were last in,
// or that nothing is on the line yet), a welcome with their name and company, and the one primary action their role has.
// Every word and figure comes from the `landing` prop the server builds and the featured row it carries.
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Ico from '../editor/ui/Ico.vue';
import { landingCopy } from './libraryModel.js';

const props = defineProps({
    landing: { type: Object, required: true },
    row: { type: Object, default: null },
});
const emit = defineEmits(['new']);

const copy = computed(() => landingCopy(props.landing, props.row));
const primary = computed(() => props.landing.primary || null);
</script>

<template>
    <section class="ld" aria-labelledby="ld-h">
        <span class="ld-logo">
            <img class="ld-logo-i ld-logo-i--light" :src="landing.logo.light" alt="Dot.Press" width="88" height="88" decoding="async" />
            <img class="ld-logo-i ld-logo-i--dark" :src="landing.logo.dark" alt="Dot.Press" width="88" height="88" decoding="async" />
        </span>
        <div class="ld-say">
            <h2 id="ld-h" class="ld-h">{{ copy.headline }}</h2>
            <p v-if="row" class="ld-p"><strong class="ld-hello">{{ copy.welcome }}</strong> {{ copy.support }}</p>
            <p v-else class="ld-p"><strong class="ld-hello">{{ copy.welcome }}</strong> {{ primary ? copy.support : copy.nothing }}</p>
        </div>
        <div v-if="primary" class="ld-act">
            <a v-if="primary.kind === 'new'" :href="primary.href" class="pr-btn pr-btn--primary ld-go" data-primary-action aria-haspopup="dialog" @click.prevent="emit('new')"><Ico name="plus" size="sm" :stroke="2.2" /><span>{{ primary.label }}</span></a>
            <Link v-else :href="primary.href" class="pr-btn pr-btn--primary ld-go" data-primary-action><span>{{ primary.label }}</span><Ico name="arrow-right" size="sm" /></Link>
        </div>
    </section>
</template>
