<script setup>
// A small confirm dialog for the one action that cannot be undone.
import { nextTick, onMounted, ref } from 'vue';

const props = defineProps({
    title: { type: String, required: true },
    body: { type: String, default: '' },
    confirm: { type: String, default: 'Delete' },
    busy: { type: Boolean, default: false },
});
const emit = defineEmits(['confirm', 'cancel']);
const box = ref(null);
let opener = null;
onMounted(async () => { opener = document.activeElement; await nextTick(); box.value?.querySelector('[data-cancel]')?.focus(); });
function cancel() { emit('cancel'); nextTick(() => opener?.focus?.()); }
function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cancel(); return; }
    if (e.key === 'Tab') {
        const els = [...box.value.querySelectorAll('button:not([disabled])')];
        const i = els.indexOf(document.activeElement);
        e.preventDefault();
        els[(i + (e.shiftKey ? -1 : 1) + els.length) % els.length]?.focus();
    }
}
</script>

<template>
    <Teleport to="body">
        <div class="pr cf-back" @pointerdown.self="cancel">
            <div ref="box" class="cf" role="alertdialog" aria-modal="true" aria-labelledby="cf-t" aria-describedby="cf-b" data-no-shortcuts @keydown="onKey">
                <h2 id="cf-t" class="pr-title">{{ title }}</h2>
                <p id="cf-b" class="cf-body">{{ body }}</p>
                <div class="cf-actions">
                    <button type="button" class="pr-btn" data-cancel @click="cancel">Keep it</button>
                    <button type="button" class="pr-btn pr-btn--primary cf-danger" :disabled="busy" @click="emit('confirm')">{{ confirm }}</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style>
.cf-back { position: fixed; inset: 0; z-index: 360; display: grid; place-items: center; padding: 16px; background: color-mix(in srgb, var(--ink) 40%, transparent); animation: hp-fade var(--d-2) var(--ease-settle); }
.cf { width: min(440px, 100%); padding: 24px; border-radius: var(--r-3); background: var(--paper); border: 1px solid var(--line); box-shadow: var(--lift); animation: pr-pop var(--d-3) var(--ease-settle); }
.cf-body { margin-top: 10px; color: var(--ink-2); }
.cf-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 22px; }
.cf-danger { background: var(--bad); border-color: var(--bad); color: #fff; }
.cf-danger:hover { background: color-mix(in srgb, var(--bad) 84%, var(--ink)); border-color: transparent; }
:root[data-theme='dark'] .cf-danger { color: #14171a; }
</style>
