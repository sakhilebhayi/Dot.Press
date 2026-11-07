<script setup>
// The shortcut sheet, drawn from helpGroups() in keys.js.
import { nextTick, onMounted, ref } from 'vue';
import { helpGroups } from '../core/keys.js';
import { useEditorContext } from '../context.js';
import Ico from './Ico.vue';

const editor = useEditorContext();
const groups = helpGroups(editor.mac);
const panel = ref(null);
let opener = null;
onMounted(async () => {
    opener = document.activeElement;
    await nextTick();
    panel.value?.focus();
});
function close() {
    editor.ui.help = false;
    if (opener && document.contains(opener)) opener.focus?.();
}
function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
    if (e.key === 'Tab') { e.preventDefault(); panel.value?.focus(); }
}
</script>

<template>
    <Teleport to="body">
        <div class="pr hp-back" @pointerdown.self="close">
            <div ref="panel" class="hp" role="dialog" aria-modal="true" aria-labelledby="hp-title" tabindex="-1" data-no-shortcuts @keydown="onKey">
                <header class="hp-head">
                    <h2 id="hp-title" class="pr-title">Keyboard shortcuts</h2>
                    <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon" aria-label="Close shortcuts" @click="close"><Ico name="close" /></button>
                </header>
                <div class="hp-body pr-scroll">
                    <section v-for="g in groups" :key="g.group" class="hp-group">
                        <h3 class="hp-g">{{ g.group }}</h3>
                        <dl class="hp-list">
                            <template v-for="item in g.items" :key="item.cmd">
                                <dt>{{ item.label }}</dt>
                                <dd><kbd v-for="s in item.shortcuts.slice(0, 2)" :key="s" class="pr-kbd">{{ s }}</kbd></dd>
                            </template>
                        </dl>
                    </section>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style>
.hp-back { position: fixed; inset: 0; z-index: 350; display: grid; place-items: center; padding: 16px; background: color-mix(in srgb, var(--ink) 38%, transparent); animation: hp-fade var(--d-2) var(--ease-settle); }
.hp { width: min(860px, 100%); max-height: min(720px, 100%); display: flex; flex-direction: column; border-radius: var(--r-3); background: var(--paper); box-shadow: var(--lift); border: 1px solid var(--line); outline: none; animation: pr-pop var(--d-3) var(--ease-settle); }
.hp:focus-visible { outline: none; }
.hp-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 14px 12px 22px; border-bottom: 1px solid var(--line); }
.hp-body { overflow: auto; padding: 8px 22px 22px; columns: 3 240px; column-gap: 32px; }
.hp-group { break-inside: avoid; margin-top: 18px; }
.hp-g { font-size: 0.8125rem; font-weight: 600; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid var(--line); }
.hp-list { display: grid; grid-template-columns: 1fr auto; gap: 7px 12px; margin: 0; font-size: 0.8125rem; align-items: center; }
.hp-list dt { color: var(--ink-2); }
.hp-list dd { margin: 0; display: flex; gap: 4px; justify-content: flex-end; }
</style>
