<script setup>
// DotMenuItem: one row of the account menu. Same markup as blade/menu-item.blade.php. `item` is one of
//   { rule: true } | { heading } | { label, href, badge, current, attrs }  (a link)
//   { label, current, form: { action, method: 'PUT', fields: {...}, csrf: true } }   (a one-button form; the method is spoofed with _method)
//   { label, button: true, attrs }   (a plain button for the host's own handler: attrs can hold onClick)
// `csrf` is the token string (an Inertia host passes the page's).
import { computed } from 'vue';

const props = defineProps({
    item: { type: Object, default: () => ({}) },
    csrf: { type: String, default: null },
});

const badge = computed(() => (props.item.badge !== undefined && props.item.badge !== null && props.item.badge !== '' ? props.item.badge : null));
const form = computed(() => (props.item.form && typeof props.item.form === 'object' ? props.item.form : null));
const verb = computed(() => (form.value ? String(form.value.method || 'POST').toUpperCase() : null));
const spoof = computed(() => !!verb.value && verb.value !== 'GET' && verb.value !== 'POST');
const fields = computed(() => Object.entries((form.value && form.value.fields) || {}));
const useToken = computed(() => !!props.csrf && verb.value !== 'GET' && (!form.value || form.value.csrf !== false));
</script>

<template>
    <div v-if="item.rule" class="dh-menu__rule" role="separator"></div>
    <p v-else-if="item.heading && !item.href && !form && !item.button" class="dh-menu__head">{{ item.heading }}</p>
    <form v-else-if="form" class="dh-menu__form" :method="verb === 'GET' ? 'get' : 'post'" :action="form.action || ''">
        <input v-if="useToken" type="hidden" name="_token" :value="csrf" />
        <input v-if="spoof" type="hidden" name="_method" :value="verb" />
        <input v-for="([n, v], i) in fields" :key="`${n}-${i}`" type="hidden" :name="n" :value="v" />
        <button type="submit" class="dh-menu__item" :aria-current="item.current ? 'true' : null" v-bind="item.attrs || {}"><span class="dh-menu__label">{{ item.label }}</span><svg v-if="item.current" class="dh-menu__mark" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg><span v-if="badge !== null" class="dh-count">{{ badge }}</span></button>
    </form>
    <button v-else-if="item.button" type="button" class="dh-menu__item" :aria-current="item.current ? 'true' : null" v-bind="item.attrs || {}"><span class="dh-menu__label">{{ item.label }}</span><svg v-if="item.current" class="dh-menu__mark" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5" /></svg><span v-if="badge !== null" class="dh-count">{{ badge }}</span></button>
    <a v-else class="dh-menu__item" :href="item.href || '#'" :aria-current="item.current ? 'page' : null" v-bind="item.attrs || {}"><span class="dh-menu__label">{{ item.label }}</span><span v-if="badge !== null" class="dh-count">{{ badge }}</span></a>
</template>
