<script setup>
// DotGuest: the signed-out state of the header (0.6.0): "Log in" and "Create account" instead of the account menu. Same markup as
// blade/guest.blade.php. DotHeader prints it for you (bar from 861px, top of the drawer on a phone, `inline`).
// Props: login { label, href, attrs }, register { label, href, attrs } (the primary button), inline, label.
import { computed } from 'vue';

const props = defineProps({
    login: { type: Object, default: null },
    register: { type: Object, default: null },
    inline: { type: Boolean, default: false },
    label: { type: String, default: 'Account' },
});

const one = (item, text) => (item && typeof item === 'object' ? { label: text, href: '#', attrs: {}, ...item } : null);
const inItem = computed(() => one(props.login, 'Log in'));
const upItem = computed(() => one(props.register, 'Create account'));
</script>

<template>
    <div v-if="inItem || upItem" :class="inline ? 'dh-dguest' : 'dh-guest'" role="group" :aria-label="label">
        <a v-if="inItem" class="dh-guest__link" :href="inItem.href" v-bind="inItem.attrs || {}">{{ inItem.label }}</a>
        <a v-if="upItem" class="dh-guest__link dh-guest__link--primary" :href="upItem.href" v-bind="upItem.attrs || {}">{{ upItem.label }}</a>
    </div>
</template>
