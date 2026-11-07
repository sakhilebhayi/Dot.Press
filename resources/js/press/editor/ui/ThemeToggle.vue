<script setup>
// Light, dark or follow the system. Stored in localStorage (guarded) and applied as data-theme on <html>.
import { computed, ref } from 'vue';
import Ico from './Ico.vue';

const KEY = 'press:theme';
const mode = ref('auto');
function read() {
    try { const v = localStorage.getItem(KEY); return v === 'light' || v === 'dark' ? v : 'auto'; } catch { return 'auto'; }
}
function apply(m) {
    const root = document.documentElement;
    if (m === 'auto') root.removeAttribute('data-theme'); else root.setAttribute('data-theme', m);
}
mode.value = read();
apply(mode.value);
const isDark = computed(() => mode.value === 'dark' || (mode.value === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches));
function next() {
    mode.value = isDark.value ? 'light' : 'dark';
    apply(mode.value);
    try { localStorage.setItem(KEY, mode.value); } catch { /* the choice lasts for this page */ }
}
</script>

<template>
    <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon" :aria-label="isDark ? 'Use the light theme' : 'Use the dark theme'" :data-tip="isDark ? 'Light theme' : 'Dark theme'" @click="next">
        <Ico :name="isDark ? 'sun' : 'moon'" />
    </button>
</template>
