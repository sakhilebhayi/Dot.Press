<script setup>
// DotSearch: the Dot header search pill. Same markup and classes as blade/search.blade.php (.dsr-*), presentation only.
// It owns no search logic: it emits what the person typed and the host does the searching. Behaviour is the Blade
// script's (js/dot-search.js): opens on focus, closes on outside click or Escape (Escape also blurs), Ctrl/Cmd+K
// focuses the box when a `hint` is given.
// Props mirror the Blade component: name, placeholder, modelValue (value), action, method, label, hint, id, formAttributes
// (the Blade's `form-attributes`: an object of attributes for the <form>, the host's values win).
// Slot `results`: the host's dropdown rows, rendered inside .dsr-panel (when absent there is no panel and no combobox).
import { computed, onBeforeUnmount, onMounted, ref, useSlots } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    name: { type: String, default: 'search' },
    placeholder: { type: String, default: 'Search' },
    action: { type: String, default: null },
    method: { type: String, default: 'get' },
    label: { type: String, default: 'Search' },
    hint: { type: String, default: null },
    id: { type: String, default: null },
    formAttributes: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue', 'submit', 'focus', 'close']);

defineOptions({ inheritAttrs: false });

const slots = useSlots();
const root = ref(null);
const input = ref(null);
const open = ref(false);
const closed = ref(false); // Escape was pressed: the panel stays shut until the next focus
// "dsr-<name>", as in the Blade: the same on every render. Pass `id` when two searches with one name share a page.
const inputId = computed(() => props.id || `dsr-${String(props.name).replace(/[^A-Za-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || 'search'}`);
const listId = computed(() => `${inputId.value}-list`);
const hasResults = computed(() => !!slots.results);

function onFocus(e) {
    closed.value = false;
    open.value = true;
    emit('focus', e);
}
function close() {
    if (open.value) emit('close');
    open.value = false;
}
function onKeydown(e) {
    if (e.key === 'Escape') {
        closed.value = true;
        close();
        input.value?.blur();
    }
}
function onFocusout(e) {
    if (e.relatedTarget && root.value && !root.value.contains(e.relatedTarget)) close();
}
function onDocClick(e) {
    if (root.value && !root.value.contains(e.target)) close();
}
function onWindowKey(e) {
    if (props.hint && (e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        input.value?.focus();
    }
}
function onSubmit(e) {
    emit('submit', props.modelValue, e);
    // With an action the browser submits the GET form; without one the host handles it.
    if (!props.action) e.preventDefault();
}

onMounted(() => {
    document.addEventListener('click', onDocClick);
    window.addEventListener('keydown', onWindowKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', onDocClick);
    window.removeEventListener('keydown', onWindowKey);
});

defineExpose({ focus: () => input.value?.focus(), blur: () => input.value?.blur(), input });
</script>

<template>
    <div ref="root" class="dsr" :class="{ 'is-open': open, 'dsr--nohint': !hint }" data-dsr :data-dsr-shortcut="hint ? '' : null" :data-closed="closed ? '' : null" @keydown="onKeydown" @focusout="onFocusout">
        <label :for="inputId" class="dsr-sr">{{ label }}</label>
        <form role="search" :method="method" :action="action || null" v-bind="formAttributes" @submit="onSubmit">
            <input
                v-bind="$attrs"
                :id="inputId"
                ref="input"
                class="dsr-input"
                type="search"
                :name="name"
                :value="modelValue"
                :placeholder="placeholder"
                autocomplete="off"
                :role="hasResults ? 'combobox' : null"
                :aria-autocomplete="hasResults ? 'list' : null"
                :aria-controls="hasResults ? listId : null"
                :aria-expanded="hasResults ? String(open && !closed) : null"
                @input="emit('update:modelValue', $event.target.value)"
                @focus="onFocus"
            />
            <kbd v-if="hint" class="dsr-kbd" aria-hidden="true">{{ hint }}</kbd>
            <button type="submit" class="dsr-go" :aria-label="label"><span class="dsr-arrow" aria-hidden="true"></span></button>
        </form>
        <div v-if="hasResults" :id="listId" class="dsr-panel" role="listbox" aria-label="Suggestions" tabindex="-1"><slot name="results" /></div>
    </div>
</template>
