<script setup>
// Speaker notes: a plain text editor for the current slide that saves as you type, speaking time at an editable pace,
// the deck's running total, the slides that still have no notes, and a large read only teleprompter view.
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import { Dialog, EmptyState, Field, NumberField, PanelSection } from '../../../ui/controls';
import { lsGet, lsSet } from '../../../ui/controls/persist.js';
import Ico from '../../ui/Ico.vue';
import { DEFAULT_WPM, MAX_NOTES, MAX_WPM, MIN_WPM, clampPace, deckNotes, formatClock, formatSpoken, notesValue, paragraphs, speakingSeconds, wordCount } from './notesModel.js';

const editor = useEditorContext();
const top = ref(null);
onMounted(() => top.value?.closest('.ph')?.scrollTo(0, 0));

const { store } = editor;
const slide = editor.slide;
const canEdit = computed(() => !editor.readOnly && editor.can?.update !== false);
const slides = computed(() => editor.state.value.doc.slides);
const number = computed(() => slides.value.findIndex((s) => s.id === slide.value?.id) + 1);

const PACE_KEY = 'press:notes:wpm';
const wpm = ref(clampPace(lsGet(PACE_KEY, DEFAULT_WPM)));
function setPace(v) {
    wpm.value = clampPace(v);
    lsSet(PACE_KEY, wpm.value);
}

// ---- the draft: typing is local, the store gets it after a short pause, on blur, on leaving the slide and on unmount ----
const draft = ref(slide.value?.notes || '');
let draftFor = slide.value?.id || null;
let dirty = false;
let timer = null;
const area = ref(null);

function flush() {
    clearTimeout(timer);
    timer = null;
    if (!dirty || !draftFor) return;
    dirty = false;
    const target = slides.value.find((s) => s.id === draftFor);
    const next = notesValue(draft.value);
    if (!target || !canEdit.value || (target.notes ?? null) === next) return;
    store.setSlideProps(draftFor, { notes: next }, { undoKey: `notes:${draftFor}`, label: 'Speaker notes' });
}
function onInput() {
    dirty = true;
    clearTimeout(timer);
    timer = setTimeout(flush, 350);
}
watch(() => slide.value?.id, (id) => {
    flush();
    draftFor = id || null;
    draft.value = slide.value?.notes || '';
    dirty = false;
});
// remote or undo changes to the notes of this slide show up unless the person is in the middle of typing
watch(() => slide.value?.notes, (n) => {
    if (dirty || document.activeElement === area.value) return;
    draft.value = n || '';
});
onBeforeUnmount(flush);

// ---- numbers ----
const words = computed(() => wordCount(draft.value));
const seconds = computed(() => speakingSeconds(words.value, wpm.value));
const totals = computed(() => {
    const list = slides.value.map((s) => (s.id === draftFor ? { ...s, notes: draft.value } : s));
    return deckNotes(list, wpm.value);
});
const missing = computed(() => totals.value.missing);
const over = computed(() => draft.value.length >= MAX_NOTES);
const blocks = computed(() => paragraphs(draft.value));

function jump(id) {
    flush();
    store.setSlide(id);
    editor.announce(`Slide ${slides.value.findIndex((s) => s.id === id) + 1}`);
}
const big = ref(false);
</script>

<template>
    <div ref="top" class="nt">
        <PanelSection id="notes.edit" title="Speaker notes" :state="`slide ${number}`" :collapsible="false">
            <Field v-slot="{ id, describedby }" label="Notes for this slide" hide-label>
                <textarea
                    :id="id" ref="area" v-model="draft" class="nt-area" rows="9" :maxlength="MAX_NOTES" :readonly="!canEdit" :aria-describedby="describedby" spellcheck="true"
                    placeholder="What you will say. Leave a blank line between paragraphs." @input="onInput" @blur="flush"
                ></textarea>
            </Field>
            <div class="nt-meter">
                <p class="nt-count pr-state" aria-live="off"><span>{{ words }} {{ words === 1 ? 'word' : 'words' }}</span><span>{{ formatSpoken(seconds) }}</span></p>
                <NumberField label="Pace" unit="wpm" :min="MIN_WPM" :max="MAX_WPM" :step="5" :model-value="wpm" @update:model-value="setPace" />
            </div>
            <p v-if="over" class="pr-small nt-limit">That is the longest a slide's notes can be ({{ MAX_NOTES }} characters).</p>
            <p v-if="!canEdit" class="pr-small c-muted">You can read these notes but not change them.</p>
            <p class="pr-small c-muted nt-private"><Ico name="eyeOff" size="sm" /> Notes never appear on the audience's screen. Only the presenter view shows them.</p>
        </PanelSection>

        <PanelSection id="notes.deck" title="Whole deck" :state="formatClock(totals.seconds)">
            <dl class="nt-sum">
                <div><dt class="pr-state">Words</dt><dd>{{ totals.words }}</dd></div>
                <div><dt class="pr-state">Speaking time</dt><dd>{{ formatSpoken(totals.seconds) }}</dd></div>
                <div><dt class="pr-state">Slides with notes</dt><dd>{{ totals.withNotes }} of {{ totals.total }}</dd></div>
            </dl>
            <p class="pr-small c-muted nt-foot">At {{ wpm }} words a minute. Hidden slides are skipped, as they are when presenting.</p>
        </PanelSection>

        <PanelSection id="notes.missing" title="Without notes" :state="String(missing.length)">
            <EmptyState v-if="!missing.length" compact title="Every slide has notes." />
            <ul v-else class="nt-miss" aria-label="Slides without notes">
                <li v-for="m in missing" :key="m.id">
                    <button type="button" class="nt-jump" :class="{ 'is-here': m.id === slide?.id }" :aria-current="m.id === slide?.id ? 'true' : undefined" @click="jump(m.id)">
                        <span class="nt-ring" aria-hidden="true"></span>
                        <span class="nt-no pr-state">{{ m.number }}</span>
                        <span class="nt-ti">{{ m.title }}</span>
                    </button>
                </li>
            </ul>
        </PanelSection>

        <PanelSection id="notes.prompt" title="Teleprompter" :default-open="false">
            <div class="nt-pr" tabindex="0" role="region" aria-label="Notes in teleprompter style">
                <template v-if="blocks.length"><p v-for="(b, i) in blocks" :key="i">{{ b }}</p></template>
                <p v-else class="nt-pr-empty">No notes on this slide yet.</p>
            </div>
            <button type="button" class="pr-btn" :disabled="!blocks.length" @click="big = true"><Ico name="present" size="sm" /> Read at full size</button>
        </PanelSection>

        <Dialog v-model:open="big" :title="`Slide ${number} notes`" size="lg">
            <div class="nt-big" tabindex="0">
                <p v-for="(b, i) in blocks" :key="i">{{ b }}</p>
            </div>
            <template #footer="{ close }"><span class="pr-state nt-bigmeta">{{ words }} words, {{ formatSpoken(seconds) }}</span><button type="button" class="pr-btn pr-btn--primary" data-autofocus @click="close">Close</button></template>
        </Dialog>
    </div>
</template>

<style>
.nt { padding-bottom: 24px; }
.nt .c-sec-b { grid-template-columns: minmax(0, 1fr); }
.nt-area { width: 100%; min-height: 168px; padding: 10px 12px; border: 1px solid var(--line-strong); border-radius: var(--r-2); background: var(--sheet); color: var(--ink); font: inherit; font-size: var(--t-md); line-height: 1.55; resize: vertical; box-sizing: border-box; }
.nt-area:hover { border-color: var(--muted); }
.nt-area:focus-visible { outline: 2px solid var(--focus); outline-offset: 0; border-color: var(--ink); }
.nt-area::placeholder { color: var(--muted); }
.nt-area[readonly] { background: var(--raised); }
.nt-meter { display: grid; grid-template-columns: minmax(0, 1fr) 132px; gap: 12px; align-items: end; }
.nt-count { margin: 0; display: grid; gap: 2px; color: var(--ink-2); font-size: var(--t-sm); padding-bottom: 6px; }
.nt-count span:last-child { color: var(--muted); }
.nt-limit { margin: 0; color: var(--warn); }
.nt-private { margin: 0; display: flex; gap: 8px; align-items: flex-start; }
.nt-private .pr-ico { flex: none; margin-top: 2px; }
.nt-sum { margin: 0; display: grid; gap: 0; }
.nt-sum > div { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; min-height: 36px; border-bottom: 1px solid var(--line); }
.nt-sum > div:last-child { border-bottom: 0; }
.nt-sum dt { color: var(--muted); }
.nt-sum dd { margin: 0; font-size: var(--t-md); font-weight: 600; font-variant-numeric: tabular-nums; }
.nt-foot { margin: 0; }
.nt-miss { list-style: none; margin: 0; padding: 0; display: grid; gap: 4px; max-height: 280px; overflow-y: auto; scrollbar-width: thin; }
.nt-jump { display: flex; align-items: center; gap: 10px; width: 100%; min-height: 40px; padding: 0 10px; border: 1px solid transparent; border-radius: var(--r-2); background: var(--raised); color: var(--ink); text-align: left; font-size: var(--t-sm); font-weight: 500; transition: border-color var(--d-1), background-color var(--d-1); }
.nt-jump:hover { border-color: var(--line-strong); background: var(--sheet); }
.nt-jump.is-here { border-color: var(--ink); background: var(--sheet); }
.nt-jump:focus-visible { outline: 2px solid var(--focus); outline-offset: 1px; }
.nt-ring { flex: none; width: 10px; height: 10px; border-radius: 50%; border: 1.5px solid var(--ink); box-sizing: border-box; }
.nt-jump.is-here .nt-ring { background: var(--amber); }
.nt-no { flex: none; min-width: 2ch; color: var(--muted); font-variant-numeric: tabular-nums; }
.nt-ti { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.nt-pr { max-height: 300px; overflow-y: auto; padding: 18px 16px; border-radius: var(--r-2); background: var(--ink); color: var(--paper); scrollbar-width: thin; }
.nt-pr p { margin: 0 0 0.8em; font-size: 22px; line-height: 1.45; font-weight: 500; letter-spacing: -0.005em; overflow-wrap: anywhere; }
.nt-pr p:last-child { margin-bottom: 0; }
.nt-pr:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
.nt-pr-empty { color: var(--faint); font-weight: 400 !important; }
.nt-big { padding: 8px 4px; max-height: 62vh; overflow-y: auto; }
.nt-big p { margin: 0 0 0.9em; font-size: clamp(26px, 4vw, 40px); line-height: 1.4; font-weight: 500; letter-spacing: -0.01em; overflow-wrap: anywhere; }
.nt-big:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
.nt-bigmeta { margin-right: auto; color: var(--muted); }
.nt-miss, .nt-sum { grid-template-columns: minmax(0, 1fr); }
@media (pointer: coarse) { .nt .pr-btn { --h: 44px; } .nt-jump { min-height: 44px; } .nt-area { font-size: 16px; } }
</style>
