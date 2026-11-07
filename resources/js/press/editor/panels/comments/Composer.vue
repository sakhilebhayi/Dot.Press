<script setup>
// A comment text box with @mention suggestions. The box shows `@Name`; `modelValue` is the body with the
// `@[Name](id)` tokens the server parses (see commentsModel.toBody), so the parent never sees the difference.
//
// Keys: type @ and a letter for suggestions, Arrow keys and Enter or Tab pick one, Escape closes the list (a second
// Escape emits `cancel`), Ctrl or Cmd plus Enter sends.
import { computed, nextTick, onMounted, ref, useId, watch } from 'vue';
import { BODY_MAX, bodyLength, insertPick, mentionQuery, suggestPeople, toBody, toDisplay } from './commentsModel.js';

const props = defineProps({
    modelValue: { type: String, default: '' },
    people: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Write a comment. Type @ to mention someone.' },
    label: { type: String, default: 'Comment' },
    submitLabel: { type: String, default: 'Comment' },
    disabled: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    rows: { type: Number, default: 1 },
    meId: { type: Number, default: 0 },
    autofocus: { type: Boolean, default: false },
    cancellable: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue', 'submit', 'cancel']);

const uid = useId();
const box = ref(null);
const text = ref('');
const picks = ref([]);
const query = ref(null);
const active = ref(0);
let lastEmitted = null;

function adopt(body) {
    const d = toDisplay(body);
    text.value = d.text;
    picks.value = d.picks;
    lastEmitted = body;
    query.value = null;
    nextTick(grow);
}
adopt(props.modelValue);
watch(() => props.modelValue, (v) => { if (v !== lastEmitted) adopt(v); });

const suggestions = computed(() => (query.value ? suggestPeople(props.people, query.value.query, { exclude: [props.meId] }) : []));
const open = computed(() => suggestions.value.length > 0);
watch(suggestions, () => { active.value = 0; });

const body = computed(() => toBody(text.value, picks.value));
const length = computed(() => bodyLength(text.value, picks.value));
const tooLong = computed(() => length.value > BODY_MAX);
const empty = computed(() => text.value.trim() === '');
const canSend = computed(() => !props.disabled && !props.busy && !empty.value && !tooLong.value);

function push() {
    lastEmitted = body.value;
    emit('update:modelValue', body.value);
}
function grow() {
    const el = box.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight + 2, 168)}px`;
}
function onInput() {
    const el = box.value;
    text.value = el.value;
    query.value = mentionQuery(text.value, el.selectionStart);
    push();
    grow();
}
function onCaret() {
    const el = box.value;
    if (el) query.value = mentionQuery(text.value, el.selectionStart);
}
function choose(person) {
    if (!query.value) return;
    const r = insertPick(text.value, query.value, person);
    text.value = r.text;
    picks.value = [...picks.value.filter((p) => p.id !== r.pick.id || p.name !== r.pick.name), r.pick];
    query.value = null;
    push();
    nextTick(() => { box.value.focus(); box.value.setSelectionRange(r.caret, r.caret); grow(); });
}
function submit() { if (canSend.value) emit('submit', body.value); }
function onKey(e) {
    if (open.value) {
        const n = suggestions.value.length;
        if (e.key === 'ArrowDown') { e.preventDefault(); active.value = (active.value + 1) % n; return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); active.value = (active.value - 1 + n) % n; return; }
        if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); choose(suggestions.value[active.value]); return; }
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); query.value = null; return; }
    }
    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); submit(); return; }
    if (e.key === 'Escape' && props.cancellable) { e.preventDefault(); e.stopPropagation(); emit('cancel'); }
}
function focus() { box.value?.focus(); }
function clear() { adopt(''); emit('update:modelValue', ''); }
defineExpose({ focus, clear });
onMounted(() => { grow(); if (props.autofocus) focus(); });

const mac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || '');
const initials = (n) => String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');
const roleWord = { owner: 'owner', editor: 'editor', commenter: 'commenter', viewer: 'viewer' };
</script>

<template>
    <div class="cmx" :class="{ 'is-disabled': disabled }">
        <ul v-if="open" :id="`${uid}-list`" class="cmx-list" role="listbox" :aria-label="`People to mention`">
            <li
                v-for="(p, i) in suggestions" :id="`${uid}-o${i}`" :key="p.id" role="option" class="cmx-opt" :class="{ 'is-on': i === active }" :aria-selected="i === active"
                @mousedown.prevent="choose(p)" @mousemove="active = i"
            >
                <span class="pr-avatar cmx-av" aria-hidden="true">{{ initials(p.name) }}</span>
                <span class="cmx-name">{{ p.name }}</span>
                <span class="pr-state cmx-role">{{ roleWord[p.role] || p.role }}</span>
            </li>
        </ul>
        <textarea
            ref="box" :value="text" class="pr-field cmx-box" :rows="rows" :placeholder="placeholder" :disabled="disabled || busy" :aria-label="label" maxlength="6000"
            role="combobox" aria-autocomplete="list" aria-keyshortcuts="Control+Enter Meta+Enter" :aria-expanded="open" :aria-controls="open ? `${uid}-list` : undefined" :aria-activedescendant="open ? `${uid}-o${active}` : undefined"
            @input="onInput" @keydown="onKey" @keyup="onCaret" @click="onCaret" @blur="query = null"
        ></textarea>
        <div class="cmx-foot">
            <slot name="lead" />
            <span v-if="length > BODY_MAX - 500" class="cmx-count pr-state" :class="{ 'is-over': tooLong }" aria-live="polite">{{ length }} of {{ BODY_MAX }}</span>
            <span class="cmx-actions">
                <button v-if="cancellable" type="button" class="pr-btn pr-btn--sm pr-btn--ghost" @click="emit('cancel')">Cancel</button>
                <button type="button" class="pr-btn pr-btn--sm pr-btn--primary" :disabled="!canSend" :title="`${submitLabel} (${mac ? 'Cmd' : 'Ctrl'} Enter)`" @click="submit">{{ busy ? 'Sending' : submitLabel }}</button>
            </span>
        </div>
    </div>
</template>

<style scoped>
.cmx { position: relative; display: grid; gap: 8px; }
.pr textarea.cmx-box { min-height: 56px; }
.cmx-box { width: 100%; max-height: 168px; resize: none; overflow-y: auto; font-size: var(--t-sm); }
.cmx-foot { display: flex; align-items: center; justify-content: flex-end; gap: 8px; }
.cmx-foot > :first-child:not(.cmx-count):not(.cmx-actions) { flex: 1; min-width: 0; margin-right: auto; }
.cmx-count { color: var(--muted); display: inline-flex; align-items: center; gap: 4px; min-width: 0; }
.cmx-count.is-over { color: var(--bad); }
.cmx-actions { display: inline-flex; gap: 6px; flex: none; }
.cmx-list { position: absolute; z-index: 20; left: 0; right: 0; bottom: calc(100% + 4px); max-height: 232px; overflow: auto; margin: 0; padding: 4px; list-style: none; border: 1px solid var(--line-strong); border-radius: var(--r-3); background: var(--sheet); box-shadow: var(--lift); }
.cmx-opt { display: flex; align-items: center; gap: 8px; min-height: 36px; padding: 4px 8px; border-radius: var(--r-2); cursor: pointer; }
.cmx-opt.is-on { background: var(--raised); box-shadow: inset 2px 0 0 var(--amber); }
.cmx-av { --s: 24px; border: 0; font-size: var(--t-xs); color: var(--ink); }
.cmx-name { flex: 1; min-width: 0; font-size: var(--t-sm); font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cmx-role { color: var(--muted); }
@media (pointer: coarse) { .cmx-opt { min-height: 44px; } }
</style>
