<script setup>
// One finding of the review: severity, what and where, why, the proposed fix as a before and after, and the choices.
import { computed, ref, useId } from 'vue';
import { fixPreview } from '../intel/opsText.js';
import { severityWord } from './reviewModel.js';
import SevMark from './SevMark.vue';

const props = defineProps({
    issue: { type: Object, required: true },
    doc: { type: Object, required: true },
    canEdit: { type: Boolean, default: true },
    ignored: { type: Boolean, default: false },
});
const emit = defineEmits(['jump', 'apply', 'ignore', 'ignore-kind', 'restore']);
const uid = useId();
const isClaim = computed(() => props.issue.category === 'claims');
const whyOpen = ref(isClaim.value);
const slideNo = computed(() => (props.issue.slide === null || props.issue.slide === undefined ? null : props.doc.slides.findIndex((s) => s.id === String(props.issue.slide)) + 1 || null));
const word = computed(() => (isClaim.value ? 'Question' : severityWord(props.issue.severity)));
const fixes = computed(() => {
    const out = [];
    if (props.issue.fix) out.push({ key: 'fix', fix: props.issue.fix, preview: fixPreview(props.issue.fix.ops, props.doc) });
    (props.issue.alternatives || []).forEach((alt, i) => out.push({ key: `alt${i}`, fix: alt, alt: true, preview: fixPreview(alt.ops, props.doc) }));
    return out;
});
const questions = computed(() => props.issue.claims || []);
</script>

<template>
    <li class="iq-item" :class="{ 'is-ignored': ignored }" :data-sev="issue.severity" :data-kind="issue.kind">
        <div class="iq-item-rail" aria-hidden="true"></div>
        <div class="iq-item-body">
            <div class="iq-item-top">
                <SevMark :severity="issue.severity" :word="word" />
                <button v-if="slideNo" type="button" class="iq-go pr-state" :aria-label="`Go to slide ${slideNo}${issue.element ? ' and select the element' : ''}`" @click="emit('jump', issue.slide, issue.element)">
                    Slide {{ slideNo }}
                </button>
                <span v-else class="iq-go is-static pr-state">Whole deck</span>
            </div>
            <p class="iq-msg">{{ issue.message }}</p>

            <ul v-if="isClaim && questions.length" class="iq-questions" aria-label="Questions to answer">
                <li v-for="(c, i) in questions.slice(0, 5)" :key="i">
                    <button type="button" class="iq-q" @click="emit('jump', issue.slide, c.element)"><span class="iq-q-t">"{{ c.text }}"</span><span class="iq-q-q">{{ c.question }}</span></button>
                </li>
                <li v-if="questions.length > 5" class="pr-small pr-muted">and {{ questions.length - 5 }} more on this slide</li>
            </ul>

            <button type="button" class="iq-why-t" :aria-expanded="whyOpen" :aria-controls="`why${uid}`" @click="whyOpen = !whyOpen">{{ whyOpen ? 'Hide why' : 'Why this matters' }}</button>
            <p v-show="whyOpen" :id="`why${uid}`" class="iq-why">{{ issue.why }}</p>

            <div v-for="f in fixes" :key="f.key" class="iq-fix">
                <p class="iq-fix-l"><span class="pr-state">{{ f.alt ? 'alternative' : 'proposed fix' }}</span>{{ f.fix.label }}</p>
                <ul v-if="f.preview.rows.length" class="iq-rows" aria-label="What the fix changes">
                    <li v-for="(r, i) in f.preview.rows" :key="i">
                        <span class="iq-rows-t">{{ r.target }}</span>
                        <span class="iq-rows-c"><span class="iq-rows-k">{{ r.label }}</span><del class="iq-del">{{ r.before }}</del><span class="pr-sr"> becomes </span><span aria-hidden="true" class="iq-arrow">to</span><ins class="iq-ins">{{ r.after }}</ins></span>
                    </li>
                </ul>
                <ul v-else class="iq-lines"><li v-for="(l, i) in f.preview.lines" :key="i">{{ l }}</li></ul>
                <button type="button" class="pr-btn pr-btn--sm" :class="{ 'pr-btn--primary': !f.alt }" :disabled="!canEdit" :title="canEdit ? '' : 'You can read this deck, not change it'" @click="emit('apply', issue, f.fix)">{{ f.alt ? 'Apply this instead' : 'Apply' }}</button>
            </div>

            <div class="iq-acts">
                <template v-if="ignored">
                    <span class="iq-ign pr-state">ignored</span>
                    <button type="button" class="pr-btn pr-btn--ghost pr-btn--sm" @click="emit('restore', issue)">Restore</button>
                </template>
                <template v-else>
                    <button type="button" class="pr-btn pr-btn--ghost pr-btn--sm" @click="emit('ignore', issue)">Ignore</button>
                    <button type="button" class="pr-btn pr-btn--ghost pr-btn--sm" @click="emit('ignore-kind', issue)">Ignore this kind</button>
                </template>
            </div>
        </div>
    </li>
</template>
