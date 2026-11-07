<script setup>
// A menu that opens from a trigger. Teleported and fixed, so scrolling toolbars and drawers never clip it.
// items: [{label, icon?, shortcut?, run?, href?, download?, disabled?, checked?, danger?}] | {separator} | {heading}
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import Ico from './Ico.vue';

const props = defineProps({
    items: { type: Array, required: true },
    align: { type: String, default: 'start' }, // start | end
    up: { type: Boolean, default: false },
    label: { type: String, default: 'Menu' },
    minWidth: { type: Number, default: 200 },
});
const emit = defineEmits(['open', 'close']);
const open = ref(false);
const hasIcons = computed(() => props.items.some((i) => i.icon));
const root = ref(null);
const pop = ref(null);
const pos = ref({ left: 0, top: 0, minWidth: props.minWidth });
let opener = null;

function place() {
    const r = root.value.getBoundingClientRect();
    const p = pop.value?.getBoundingClientRect();
    const w = p?.width || props.minWidth;
    const h = p?.height || 0;
    let left = props.align === 'end' ? r.right - w : r.left;
    left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
    let top = props.up ? r.top - h - 6 : r.bottom + 6;
    if (!props.up && top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
    if (props.up && top < 8) top = r.bottom + 6;
    pos.value = { left, top, minWidth: Math.max(props.minWidth, r.width) };
}
async function show() {
    opener = document.activeElement;
    open.value = true;
    emit('open');
    await nextTick();
    place();
    pop.value?.querySelector('[role^="menuitem"]:not([disabled]):not([aria-disabled="true"])')?.focus();
    document.addEventListener('pointerdown', onOutside, true);
    window.addEventListener('resize', hide);
    window.addEventListener('scroll', hide, true);
}
function hide(restore = false) {
    if (!open.value) return;
    open.value = false;
    emit('close');
    document.removeEventListener('pointerdown', onOutside, true);
    window.removeEventListener('resize', hide);
    window.removeEventListener('scroll', hide, true);
    if (restore === true && opener && document.contains(opener)) opener.focus?.();
}
function toggle() { if (open.value) hide(true); else show(); }
function onOutside(e) {
    if (pop.value?.contains(e.target) || root.value?.contains(e.target)) return;
    hide();
}
function onKey(e) {
    const btns = [...pop.value.querySelectorAll('[role^="menuitem"]:not([disabled]):not([aria-disabled="true"])')];
    const i = btns.indexOf(document.activeElement);
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); hide(true); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); btns[(i + 1) % btns.length]?.focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); btns[(i - 1 + btns.length) % btns.length]?.focus(); }
    else if (e.key === 'Home') { e.preventDefault(); btns[0]?.focus(); }
    else if (e.key === 'End') { e.preventDefault(); btns[btns.length - 1]?.focus(); }
    else if (e.key === 'Tab') hide(true);
}
function choose(it) {
    hide(true);
    it.run?.();
}
onBeforeUnmount(() => hide());
defineExpose({ toggle, show, hide, open });
</script>

<template>
    <span ref="root" class="dm">
        <slot name="trigger" :toggle="toggle" :open="open" />
        <Teleport to="body">
            <div v-if="open" ref="pop" class="pr pr-menu dm-pop" role="menu" :aria-label="label" data-no-shortcuts :style="{ left: pos.left + 'px', top: pos.top + 'px', minWidth: pos.minWidth + 'px' }" @keydown="onKey">
                <template v-for="(it, i) in items" :key="i">
                    <hr v-if="it.separator" class="pr-menu-sep" />
                    <p v-else-if="it.heading" class="pr-menu-label">{{ it.heading }}</p>
                    <a v-else-if="it.href" :href="it.href" :download="it.download" role="menuitem" class="pr-menu-item" @click="hide()">
                        <Ico v-if="it.icon" :name="it.icon" size="sm" /><span v-else-if="hasIcons" class="pr-menu-ico" aria-hidden="true"></span><span>{{ it.label }}</span>
                    </a>
                    <button v-else type="button" role="menuitem" class="pr-menu-item" :class="{ 'pr-menu-item--danger': it.danger }" :disabled="it.disabled" @click="choose(it)">
                        <Ico v-if="it.icon" :name="it.icon" size="sm" /><span v-else-if="hasIcons" class="pr-menu-ico" aria-hidden="true"></span>
                        <span>{{ it.label }}</span>
                        <Ico v-if="it.checked" name="check" size="sm" class="dm-check" />
                        <span v-if="it.shortcut" class="pr-state">{{ it.shortcut }}</span>
                    </button>
                </template>
            </div>
        </Teleport>
    </span>
</template>

<style>
.dm { display: inline-flex; }
.dm-pop { position: fixed; z-index: 300; max-height: calc(100dvh - 16px); overflow: auto; }
.dm-check { margin-left: auto; color: var(--ink); }
.pr-menu-item .dm-check + .pr-state { margin-left: 8px; }
</style>
