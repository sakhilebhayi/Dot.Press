<script setup>
// A plain text diff: removed words are struck through, added words are underlined (never colour alone).
import { computed } from 'vue';
const props = defineProps({ diff: { type: Object, required: true }, label: { type: String, default: 'Proposed change' } });
const words = computed(() => (props.diff.kind === 'words' ? props.diff.segments : []));
const same = computed(() => props.diff.kind === 'words' && !props.diff.segments.some((s) => s.t !== 'same'));
</script>

<template>
    <div class="iq-diff" role="group" :aria-label="label" tabindex="0">
        <template v-if="diff.kind === 'lines'">
            <div v-for="(l, i) in diff.lines" :key="i" class="iq-diff-line"><span class="iq-diff-sign" aria-hidden="true">{{ l.sign === ' ' ? '' : l.sign }}</span><span><span v-if="l.sign === '+'" class="pr-sr">Added: </span>{{ l.text }}</span></div>
        </template>
        <template v-else-if="same"><span class="pr-muted">No wording changes.</span></template>
        <template v-else><template v-for="(s, i) in words" :key="i"><del v-if="s.t === 'del'"><span class="pr-sr">Removed: </span>{{ s.text }}</del><ins v-else-if="s.t === 'add'"><span class="pr-sr">Added: </span>{{ s.text }}</ins><span v-else>{{ s.text }}</span>{{ ' ' }}</template></template>
    </div>
</template>
