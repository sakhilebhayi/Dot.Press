<script setup>
// Right click (or long press) menu. Rendered in the body so it can sit anywhere; keyboard: arrows, Enter, Escape, Left/Right for submenus.
import {
    computed, nextTick, onBeforeUnmount, onMounted, ref,
} from 'vue';
import { contextItems } from './lib/menu.js';

const props = defineProps({
    at: { type: Object, required: true },
    ctx: { type: Object, required: true },
});
const emit = defineEmits(['pick', 'close']);

const menu = ref(null);
const items = computed(() => contextItems(props.ctx));
const openSub = ref(null);
const pos = ref({ x: props.at.x, y: props.at.y });

function place() {
    const el = menu.value;
    if (!el) return;
    const w = el.offsetWidth;
    const h = el.offsetHeight;
    pos.value = {
        x: Math.max(8, Math.min(props.at.x, window.innerWidth - w - 8)),
        y: Math.max(8, Math.min(props.at.y, window.innerHeight - h - 8)),
    };
}

function onOutside(e) {
    if (menu.value && !menu.value.contains(e.target)) emit('close');
}
function focusables(scope) {
    return [...(scope || menu.value).querySelectorAll(':scope > ul > li > button:not([disabled])')];
}
function onKey(e) {
    const t = e.target;
    const scope = t.closest('.es-menu-sub') || menu.value;
    const list = focusables(scope);
    const i = list.indexOf(t);
    if (e.key === 'ArrowDown') { e.preventDefault(); list[(i + 1) % list.length]?.focus(); } else if (e.key === 'ArrowUp') { e.preventDefault(); list[(i - 1 + list.length) % list.length]?.focus(); } else if (e.key === 'ArrowRight' && t.dataset.sub) {
        e.preventDefault();
        openSub.value = t.dataset.sub;
        nextTick(() => focusables(t.parentElement.querySelector('.es-menu-sub'))[0]?.focus());
    } else if (e.key === 'ArrowLeft' && t.closest('.es-menu-sub')) {
        e.preventDefault();
        const parent = t.closest('li').querySelector(':scope > button');
        openSub.value = null;
        parent?.focus();
    } else if (e.key === 'Escape' || e.key === 'Tab') { e.preventDefault(); e.stopPropagation(); emit('close'); }
}

onMounted(async () => {
    await nextTick();
    place();
    focusables()[0]?.focus();
    document.addEventListener('pointerdown', onOutside, true);
    window.addEventListener('blur', () => emit('close'));
    window.addEventListener('resize', () => emit('close'));
});
onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutside, true));
</script>

<template>
    <div ref="menu" class="es-menu" data-es-ui role="menu" aria-label="Slide actions" :style="{ left: pos.x + 'px', top: pos.y + 'px' }" @keydown="onKey" @contextmenu.prevent>
        <ul>
            <template v-for="(it, i) in items" :key="it.id || 'sep' + i">
                <li v-if="it.sep" class="es-menu-sep" role="separator"></li>
                <li v-else style="position: relative">
                    <button
                        type="button"
                        class="es-menu-item"
                        role="menuitem"
                        :disabled="it.disabled"
                        :aria-haspopup="it.submenu ? 'menu' : undefined"
                        :aria-expanded="it.submenu ? openSub === it.id : undefined"
                        :data-sub="it.submenu ? it.id : undefined"
                        @click="it.submenu ? (openSub = it.id) : emit('pick', it.id)"
                        @mouseenter="openSub = it.submenu ? it.id : null"
                    >
                        <span>{{ it.label }}</span>
                        <span v-if="it.submenu" class="es-menu-key" aria-hidden="true">&#9656;</span>
                        <span v-else-if="it.shortcut" class="es-menu-key">{{ it.shortcut }}</span>
                    </button>
                    <div v-if="it.submenu && openSub === it.id" class="es-menu es-menu-sub" role="menu" :aria-label="it.label">
                        <ul>
                            <template v-for="(s, j) in it.submenu" :key="s.id || 'ss' + j">
                                <li v-if="s.sep" class="es-menu-sep" role="separator"></li>
                                <li v-else>
                                    <button type="button" class="es-menu-item" role="menuitem" :disabled="s.disabled" @click="emit('pick', s.id)">
                                        <span>{{ s.label }}</span>
                                        <span v-if="s.shortcut" class="es-menu-key">{{ s.shortcut }}</span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                </li>
            </template>
        </ul>
    </div>
</template>
