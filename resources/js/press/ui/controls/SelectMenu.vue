<script setup>
// A select: a button that opens a listbox. Searchable, keyboard (arrows, Home, End, Enter, Escape), typeahead, and a
// preview slot. options: [{value, label, hint?, group?, disabled?}]. modelValue 'mixed' shows "Mixed".
//   slots: option ({option, selected, active}) the row content; value ({option}) the closed button content
import { computed, nextTick, ref, useId } from 'vue';
import Ico from '../../editor/ui/Ico.vue';
import Field from './Field.vue';
import Popover from './Popover.vue';
import { MIXED } from './mixed.js';

const props = defineProps({
    modelValue: { type: [String, Number, Boolean, Object], default: null },
    options: { type: Array, required: true },
    label: { type: String, default: '' },
    hideLabel: { type: Boolean, default: false },
    hint: { type: String, default: '' },
    error: { type: String, default: '' },
    placeholder: { type: String, default: 'Choose' },
    searchable: { type: Boolean, default: false },
    disabled: { type: Boolean, default: false },
    width: { type: [Number, String], default: 'anchor' },
});
const emit = defineEmits(['update:modelValue']);
const uid = useId();
const open = ref(false);
const btn = ref(null);
const list = ref(null);
const query = ref('');
const activeIndex = ref(0);
let typed = ''; let typedAt = 0;

const mixed = computed(() => props.modelValue === MIXED);
const selected = computed(() => props.options.find((o) => o.value === props.modelValue) || null);
const shown = computed(() => {
  const q = query.value.trim().toLowerCase();
  return q ? props.options.filter((o) => `${o.label} ${o.hint || ''}`.toLowerCase().includes(q)) : props.options;
});
const rows = computed(() => {
  const out = []; let group = null;
  shown.value.forEach((o, index) => { if (o.group && o.group !== group) { group = o.group; out.push({ heading: group }); } out.push({ o, index }); });
  return out;
});
const optId = (i) => `o${uid}-${i}`;

function show() {
  if (props.disabled) return;
  query.value = '';
  const i = shown.value.findIndex((o) => o.value === props.modelValue);
  activeIndex.value = i < 0 ? 0 : i;
  open.value = true;
  nextTick(scrollActive);
}
function choose(o) {
  if (o.disabled) return;
  emit('update:modelValue', o.value);
  open.value = false;
  nextTick(() => btn.value?.focus());
}
function scrollActive() { list.value?.querySelector(`#${CSS.escape(optId(activeIndex.value))}`)?.scrollIntoView({ block: 'nearest' }); }
function move(d) {
  const n = shown.value.length;
  if (!n) return;
  let i = activeIndex.value;
  for (let k = 0; k < n; k += 1) { i = (i + d + n) % n; if (!shown.value[i].disabled) break; }
  activeIndex.value = i;
  nextTick(scrollActive);
}
function typeahead(ch) {
  const now = Date.now();
  typed = now - typedAt > 700 ? ch : typed + ch;
  typedAt = now;
  const i = shown.value.findIndex((o) => String(o.label).toLowerCase().startsWith(typed.toLowerCase()) && !o.disabled);
  if (i >= 0) { activeIndex.value = i; nextTick(scrollActive); if (!open.value) emit('update:modelValue', shown.value[i].value); }
}
function onBtnKey(e) {
  if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(e.key)) { e.preventDefault(); show(); }
  else if (e.key.length === 1 && !e.metaKey && !e.ctrlKey && !e.altKey) { typeahead(e.key); }
}
function onListKey(e) {
  if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
  else if (e.key === 'Home') { e.preventDefault(); activeIndex.value = 0; nextTick(scrollActive); }
  else if (e.key === 'End') { e.preventDefault(); activeIndex.value = shown.value.length - 1; nextTick(scrollActive); }
  else if (e.key === 'Enter') { e.preventDefault(); const o = shown.value[activeIndex.value]; if (o) choose(o); }
  else if (!props.searchable && e.key.length === 1 && !e.metaKey && !e.ctrlKey && !e.altKey) { typeahead(e.key); }
}
</script>

<template>
    <Field v-slot="{ id, describedby, invalid }" :label="label" :hide-label="hideLabel" :hint="hint" :error="error">
        <button
            :id="id" ref="btn" type="button" class="c-select" :class="{ 'is-mixed': mixed, 'is-open': open }" aria-haspopup="listbox" :aria-expanded="open" :aria-describedby="describedby" :aria-invalid="invalid || undefined"
            :disabled="disabled" @click="open ? (open = false) : show()" @keydown="onBtnKey"
        >
            <span class="c-select-v"><slot name="value" :option="selected"><template v-if="mixed"><span class="c-muted">Mixed</span></template><template v-else-if="selected">{{ selected.label }}</template><template v-else><span class="c-muted">{{ placeholder }}</span></template></slot></span>
            <Ico name="chevronDown" size="sm" />
        </button>
        <Popover v-model:open="open" :anchor="btn" :width="width" :label="label || 'Options'" :trap="false" :focus-first="false" @opened="searchable ? $refs.search?.focus() : list?.focus()">
            <div class="c-sel-pop" @keydown="onListKey">
                <input v-if="searchable" ref="search" v-model="query" type="search" class="c-sel-search pr-field" placeholder="Search" aria-label="Search options" autocomplete="off" @input="activeIndex = 0" />
                <ul :id="`l${uid}`" ref="list" class="c-sel-list pr-scroll" role="listbox" tabindex="-1" :aria-label="label || 'Options'" :aria-activedescendant="shown.length ? optId(activeIndex) : undefined">
                    <template v-for="(r, k) in rows" :key="k">
                        <li v-if="r.heading" class="c-sel-g" role="presentation">{{ r.heading }}</li>
                        <li v-else :id="optId(r.index)" class="c-sel-o" :class="{ 'is-active': r.index === activeIndex, 'is-sel': r.o.value === modelValue, 'is-disabled': r.o.disabled }" role="option" :aria-selected="r.o.value === modelValue" :aria-disabled="r.o.disabled || undefined" @pointermove="activeIndex = r.index" @click="choose(r.o)">
                            <slot name="option" :option="r.o" :selected="r.o.value === modelValue" :active="r.index === activeIndex">
                                <span class="c-sel-ol">{{ r.o.label }}</span><span v-if="r.o.hint" class="c-sel-oh">{{ r.o.hint }}</span>
                            </slot>
                            <Ico v-if="r.o.value === modelValue" name="check" size="sm" class="c-sel-ck" />
                        </li>
                    </template>
                    <li v-if="!shown.length" class="c-sel-none" role="presentation">Nothing matches</li>
                </ul>
            </div>
        </Popover>
    </Field>
</template>
