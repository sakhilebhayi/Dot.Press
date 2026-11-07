<script setup>
// One line, bottom left, a bead at its left that settles from hollow to solid. Presentational: the owner passes the toast.
import Ico from './Ico.vue';

defineProps({ toast: { type: Object, default: null } });
const emit = defineEmits(['dismiss', 'run']);
</script>

<template>
    <div class="tt-host" aria-live="off">
        <Transition name="tt">
            <div v-if="toast" :key="toast.id" class="tt" :class="`tt--${toast.kind}`" role="status">
                <span class="tt-bead" aria-hidden="true"></span>
                <span class="tt-msg">{{ toast.message }}</span>
                <button v-if="toast.action" type="button" class="tt-act" @click="emit('run', toast)">{{ toast.action.label }}</button>
                <button type="button" class="tt-x" aria-label="Dismiss" @click="emit('dismiss', toast.id)"><Ico name="close" size="sm" /></button>
            </div>
        </Transition>
    </div>
</template>

<style>
.tt-host { position: fixed; left: 16px; bottom: var(--toast-bottom, 16px); z-index: 250; pointer-events: none; max-width: calc(100vw - 32px); padding-bottom: env(safe-area-inset-bottom); }
.tt { pointer-events: auto; display: flex; align-items: center; gap: 12px; min-height: 44px; padding: 6px 8px 6px 16px; border-radius: var(--r-3); background: var(--ink); color: var(--sheet); box-shadow: var(--lift); font-size: var(--t-md); font-weight: 500; max-width: 520px; }
.tt-msg { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
/* impeccable-disable-next-line bounce-easing: DESIGN.md toast, the bead settles once */
.tt-bead { width: 10px; height: 10px; flex: none; border-radius: 50%; border: 1.5px solid var(--sheet); animation: tt-settle var(--d-4) var(--spring) forwards; }
.tt--error .tt-bead, .tt--warn .tt-bead { border-radius: var(--r-1); transform: rotate(45deg); background: var(--warn); border-color: var(--warn); animation: none; }
.tt--error .tt-bead { background: var(--bad); border-color: var(--bad); }
.tt-act { height: 30px; padding: 0 12px; border-radius: var(--r-2); border: 1px solid color-mix(in srgb, var(--sheet) 45%, transparent); background: transparent; color: var(--sheet); font-weight: 600; font-size: 0.8125rem; }
.tt-act:hover { background: color-mix(in srgb, var(--sheet) 16%, transparent); }
.tt-x { width: 30px; height: 30px; display: grid; place-items: center; border: 0; background: transparent; color: color-mix(in srgb, var(--sheet) 70%, transparent); border-radius: var(--r-2); }
.tt-x:hover { color: var(--sheet); background: color-mix(in srgb, var(--sheet) 14%, transparent); }
.tt :focus-visible { outline-color: var(--amber); }
@keyframes tt-settle { 0% { background: transparent; transform: translateX(-14px) scale(0.7); } 60% { transform: translateX(2px) scale(1.1); } 100% { background: var(--sheet); transform: none; } }
.tt-enter-active { transition: transform var(--d-3) var(--spring), opacity var(--d-2) var(--ease-settle); }
.tt-leave-active { transition: transform var(--d-2) var(--ease-snap), opacity var(--d-2) var(--ease-snap); }
.tt-enter-from { transform: translateX(-24px); opacity: 0; }
.tt-leave-to { transform: translateY(8px); opacity: 0; }
</style>
