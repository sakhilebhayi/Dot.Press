<script setup>
// A panel anchored to an element (anchor: Element or a ref's value). Flips when there is no room, traps focus, closes on
// Escape and on a press outside, and gives focus back to what had it. Use v-model:open.
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { focusables, trapTab } from './focus.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    anchor: { type: Object, default: null },
    placement: { type: String, default: 'bottom-start' }, // bottom-start | bottom-end | top-start | top-end
    label: { type: String, default: 'Options' },
    width: { type: [Number, String], default: null }, // px, or 'anchor' to match the anchor
    trap: { type: Boolean, default: true },
    focusFirst: { type: Boolean, default: true },
});
const emit = defineEmits(['update:open', 'opened', 'closed']);
const box = ref(null);
const pos = ref({ left: 0, top: 0, width: undefined, maxHeight: undefined });
let opener = null;
let me = null;
const STACK = (globalThis.__pressPopStack ||= []);

function place() {
  const a = props.anchor?.getBoundingClientRect?.();
  const el = box.value;
  if (!a || !el) return;
  const r = el.getBoundingClientRect();
  const vw = window.innerWidth; const vh = window.innerHeight;
  const w = props.width === 'anchor' ? a.width : (Number(props.width) || r.width);
  let top = props.placement.startsWith('top') ? a.top - r.height - 6 : a.bottom + 6;
  if (!props.placement.startsWith('top') && top + r.height > vh - 8 && a.top - r.height - 6 > 8) top = a.top - r.height - 6;
  else if (props.placement.startsWith('top') && top < 8) top = a.bottom + 6;
  let left = props.placement.endsWith('end') ? a.right - w : a.left;
  left = Math.max(8, Math.min(left, vw - w - 8));
  pos.value = { left, top: Math.max(8, top), width: props.width ? `${w}px` : undefined, maxHeight: `${vh - 16}px` };
}
function close(restore = true) {
  if (!props.open) return;
  emit('update:open', false);
  emit('closed');
  if (restore && opener && document.contains(opener)) opener.focus?.();
}
function onKey(e) {
  if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
  if (props.trap) trapTab(e, box.value);
}
function onOutside(e) {
  if (box.value?.contains(e.target) || props.anchor?.contains?.(e.target)) return;
  const mine = STACK.indexOf(box.value);
  if (STACK.slice(mine + 1).some((el) => el.contains(e.target))) return; // a press inside a popover opened from this one
  close(false);
}
watch(() => props.open, async (on) => {
  document.removeEventListener('pointerdown', onOutside, true);
  window.removeEventListener('resize', place);
  if (!on) { const i = STACK.indexOf(me); if (i >= 0) STACK.splice(i, 1); me = null; return; }
  opener = document.activeElement;
  await nextTick();
  me = box.value;
  STACK.push(me);
  place();
  if (props.focusFirst) (focusables(box.value)[0] || box.value)?.focus();
  document.addEventListener('pointerdown', onOutside, true);
  window.addEventListener('resize', place);
  emit('opened');
  requestAnimationFrame(place);
}, { immediate: true, flush: 'post' });
onBeforeUnmount(() => { const i = STACK.indexOf(me); if (i >= 0) STACK.splice(i, 1); document.removeEventListener('pointerdown', onOutside, true); window.removeEventListener('resize', place); });
defineExpose({ place, close });
</script>

<template>
    <Teleport to="body">
        <div v-if="open" ref="box" class="pr c-pop" role="dialog" :aria-label="label" tabindex="-1" data-no-shortcuts :style="{ left: pos.left + 'px', top: pos.top + 'px', width: pos.width, maxHeight: pos.maxHeight }" @keydown="onKey">
            <slot :close="close" :place="place" />
        </div>
    </Teleport>
</template>
