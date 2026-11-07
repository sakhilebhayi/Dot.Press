<script setup>
// Renders ui.contextMenu = {x, y, items?}. The stage and the Spine fill it; with no items it shows the element menu.
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useEditorContext } from '../context.js';
import Ico from './Ico.vue';
import { elementMenu } from './contextItems.js';

const editor = useEditorContext();
const { ui, store } = editor;
const box = ref(null);
const pos = ref({ left: 0, top: 0 });
let opener = null;

const items = computed(() => {
    const m = ui.contextMenu;
    if (!m) return [];
    if (m.items) return m.items;
    const sel = editor.selected.value;
    return elementMenu({
        count: sel.length, locked: sel.some((e) => e.lock), hidden: sel.some((e) => e.hide), grouped: sel.some((e) => e.group),
        canEdit: !editor.readOnly, mac: editor.mac,
    });
});

const hasIcons = computed(() => items.value.some((i) => i.icon));

async function run(item) {
    close(false);
    if (item.run) { item.run(); return; }
    if (item.cmd === 'edit.paste') {
        try { store.paste({ text: await navigator.clipboard.readText() }); } catch { editor.toast(`Use ${editor.mac ? 'Cmd' : 'Ctrl'}+V to paste here`, { kind: 'info' }); }
        return;
    }
    if (item.cmd === 'edit.copy' || item.cmd === 'edit.cut') {
        const r = item.cmd === 'edit.cut' ? store.cut() : store.copy();
        if (r?.text) navigator.clipboard?.writeText(r.text).catch(() => {});
        return;
    }
    if (item.cmd) store.runCommand(item.cmd, item.args);
}
function close(restore = true) {
    ui.contextMenu = null;
    if (restore && opener && document.contains(opener)) opener.focus?.();
    opener = null;
}
function onKey(e) {
    const btns = [...box.value.querySelectorAll('button:not([disabled])')];
    const i = btns.indexOf(document.activeElement);
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); btns[(i + 1) % btns.length]?.focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); btns[(i - 1 + btns.length) % btns.length]?.focus(); }
    else if (e.key === 'Tab') { e.preventDefault(); close(); }
}
function onOutside(e) { if (box.value && !box.value.contains(e.target)) close(false); }

watch(() => ui.contextMenu, async (m) => {
    document.removeEventListener('pointerdown', onOutside, true);
    window.removeEventListener('blur', onBlur);
    if (!m) return;
    opener = document.activeElement;
    pos.value = { left: m.x, top: m.y };
    await nextTick();
    const r = box.value?.getBoundingClientRect();
    if (r) {
        pos.value = { left: Math.max(8, Math.min(m.x, window.innerWidth - r.width - 8)), top: Math.max(8, Math.min(m.y, window.innerHeight - r.height - 8)) };
    }
    box.value?.querySelector('button:not([disabled])')?.focus();
    document.addEventListener('pointerdown', onOutside, true);
    window.addEventListener('blur', onBlur);
});
function onBlur() { close(false); }
onBeforeUnmount(() => { document.removeEventListener('pointerdown', onOutside, true); window.removeEventListener('blur', onBlur); });
</script>

<template>
    <Teleport to="body">
        <div v-if="ui.contextMenu" ref="box" class="pr pr-menu cm" role="menu" aria-label="Actions" data-no-shortcuts :style="{ left: pos.left + 'px', top: pos.top + 'px' }" @keydown="onKey" @contextmenu.prevent>
            <template v-for="(it, i) in items" :key="i">
                <hr v-if="it.separator" class="pr-menu-sep" />
                <p v-else-if="it.heading" class="pr-menu-label">{{ it.heading }}</p>
                <button v-else type="button" role="menuitem" class="pr-menu-item" :class="{ 'pr-menu-item--danger': it.danger }" :disabled="it.disabled" @click="run(it)">
                    <Ico v-if="it.icon" :name="it.icon" size="sm" /><span v-else-if="hasIcons" class="pr-menu-ico" aria-hidden="true"></span>
                    <span>{{ it.label }}</span>
                    <span v-if="it.shortcut" class="pr-state">{{ it.shortcut }}</span>
                </button>
            </template>
        </div>
    </Teleport>
</template>

<style>
.cm { position: fixed; z-index: 300; max-height: calc(100dvh - 16px); overflow: auto; }
</style>
