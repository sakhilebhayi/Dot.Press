<script setup>
// A modal dialog: scrim, focus trap, Escape, focus returns to the element that opened it. v-model:open, `title`, default slot,
// `footer` slot for the buttons. size: sm | md | lg.
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import Ico from '../../editor/ui/Ico.vue';
import { focusables, trapTab } from './focus.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    size: { type: String, default: 'md' },
    dismissible: { type: Boolean, default: true }, // Escape and scrim click close it
});
const emit = defineEmits(['update:open', 'closed']);
const box = ref(null);
const uid = useId();
let opener = null;
function close() {
  emit('update:open', false);
  emit('closed');
  nextTick(() => { if (opener && document.contains(opener)) opener.focus?.(); });
}
function onKey(e) {
  if (e.key === 'Escape' && props.dismissible) { e.preventDefault(); e.stopPropagation(); close(); return; }
  trapTab(e, box.value);
}
watch(() => props.open, async (on) => {
  document.documentElement.style.overflow = on ? 'hidden' : '';
  if (!on) return;
  opener = document.activeElement;
  await nextTick();
  (box.value?.querySelector('[data-autofocus]') || focusables(box.value).find((el) => !el.matches('[data-dialog-close]')) || box.value)?.focus();
}, { immediate: true, flush: 'post' });
onBeforeUnmount(() => { document.documentElement.style.overflow = ''; });
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="pr c-dlg-back" @pointerdown.self="dismissible && close()">
            <div ref="box" class="c-dlg" :class="`c-dlg--${size}`" role="dialog" aria-modal="true" :aria-labelledby="`d${uid}`" tabindex="-1" data-no-shortcuts @keydown="onKey">
                <header class="c-dlg-h">
                    <h2 :id="`d${uid}`" class="pr-title">{{ title }}</h2>
                    <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon" aria-label="Close" data-dialog-close @click="close"><Ico name="close" /></button>
                </header>
                <div class="c-dlg-b pr-scroll"><slot :close="close" /></div>
                <footer v-if="$slots.footer" class="c-dlg-f"><slot name="footer" :close="close" /></footer>
            </div>
        </div>
    </Teleport>
</template>
