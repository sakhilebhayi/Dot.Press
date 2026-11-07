<script setup>
// The New flow, a sheet from the right: Blank, From an outline, Import a PowerPoint file, Templates (only when the
// templates route answers with items). Every tile and label here comes from the server or from the person.
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { createApi } from '../editor/ui/transport.js';
import { newId } from '../editor/core/doc.js';
import Ico from '../editor/ui/Ico.vue';
import LazyThumb from '../editor/ui/LazyThumb.vue';
import { SIZES } from './libraryModel.js';
import { buildOps, modeLabel, moveBeat, normalizeImport, normalizeOutline, outlineToDeck, titleFromBrief } from './outlineModel.js';

const props = defineProps({
    themes: { type: Array, default: () => [] },
    ai: { type: Object, default: () => ({ mode: 'local' }) },
});
const emit = defineEmits(['close']);

const api = createApi(axios.create({ withCredentials: true, headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } }));
const box = ref(null);
const tab = ref('blank');
const templates = ref([]);
const busy = ref(false);
const error = ref('');
let opener = null;

// ---- shared choices ----------------------------------------------------------------------------------
const size = reactive({ id: '16:9', w: 1920, h: 1080 });
const themeId = ref(props.themes[0]?.id || null);
const theme = computed(() => props.themes.find((t) => t.id === themeId.value) || null);
const customOk = computed(() => size.id !== 'custom' || (size.w >= 320 && size.w <= 7680 && size.h >= 320 && size.h <= 7680));
function deckBody(title) {
    const body = { title, size: size.id };
    if (size.id === 'custom') { body.w = Math.round(size.w); body.h = Math.round(size.h); }
    if (theme.value) body.theme = theme.value;
    return body;
}
function applySize(id) {
    const s = SIZES.find((x) => x.id === id);
    if (s && s.id !== 'custom') { size.w = s.w; size.h = s.h; }
}
const open = (id) => router.visit(route('press.editor', { deck: id }));
const swatch = (t) => [t.c?.bg, t.c?.surface, t.c?.accent, t.c?.accent2, t.c?.ink].filter(Boolean);
function fail(e, notFound) {
    busy.value = false;
    error.value = e?.status === 404 && notFound ? notFound : e?.network ? 'The server could not be reached. Check your connection and try again.' : e?.message || 'Something went wrong.';
}

// ---- blank -------------------------------------------------------------------------------------------
const blankTitle = ref('');
async function createBlank() {
    if (busy.value || !customOk.value) return;
    busy.value = true; error.value = '';
    try {
        const deck = await api.post('/api/decks', deckBody(blankTitle.value.trim() || 'Untitled presentation'));
        open(deck.id);
    } catch (e) { fail(e); }
}

// ---- outline -----------------------------------------------------------------------------------------
const FRAMEWORKS = [
    { id: 'auto', label: 'Let the writer choose' },
    { id: 'problem-solution-ask', label: 'Problem, solution, ask' },
    { id: 'situation-complication-resolution', label: 'Situation, complication, resolution' },
    { id: 'pitch', label: 'Pitch: hook, problem, solution, proof, ask' },
    { id: 'status-update', label: 'Status update: done, next, blocked' },
    { id: 'teach', label: 'Teach: context, idea, example, practice' },
];
const brief = reactive({ text: '', source: '', framework: 'auto' });
const step = ref('brief'); // brief | review
const outline = ref(null); // {mode, modeLabel, beats}
const regen = ref(null);
const briefOk = computed(() => brief.text.trim().length >= 12);
const slideCount = computed(() => outline.value?.beats.reduce((n, b) => n + b.slides.length, 0) || 0);
const announced = computed(() => modeLabel(outline.value?.mode || props.ai.mode));

async function fetchOutline(extra = {}) {
    const body = { brief: brief.text.trim(), source: brief.source.trim() || undefined, framework: brief.framework === 'auto' ? undefined : brief.framework, ...extra };
    const res = await api.post('/api/ai/outline', body);
    const out = normalizeOutline(res);
    if (!out) throw Object.assign(new Error('The outline writer answered with something that is not an outline.'), { status: 502 });
    return out;
}
async function draftOutline() {
    if (busy.value || !briefOk.value) return;
    busy.value = true; error.value = '';
    try {
        outline.value = await fetchOutline();
        step.value = 'review';
        busy.value = false;
        await nextTick();
        box.value?.querySelector('.ns-beat input')?.focus();
    } catch (e) { fail(e, 'The outline writer is not available in this build yet. Start from a blank presentation instead.'); }
}
async function regenerateBeat(beat) {
    if (regen.value) return;
    regen.value = beat.id; error.value = '';
    try {
        const out = await fetchOutline({ only: beat.beat });
        const fresh = out.beats.find((b) => b.beat === beat.beat) || out.beats[0];
        outline.value = { ...outline.value, beats: outline.value.beats.map((b) => (b.id === beat.id ? { ...fresh, id: beat.id, label: beat.label } : b)) };
    } catch (e) { fail(e, 'The outline writer is not available in this build yet.'); }
    regen.value = null;
}
const mutateBeats = (fn) => { outline.value = { ...outline.value, beats: fn(outline.value.beats) }; };
const renameBeat = (id, label) => mutateBeats((bs) => bs.map((b) => (b.id === id ? { ...b, label } : b)));
const removeBeat = (id) => mutateBeats((bs) => bs.filter((b) => b.id !== id));
const shiftBeat = (id, dir) => mutateBeats((bs) => moveBeat(bs, id, dir));
const setSlide = (bid, sid, title) => mutateBeats((bs) => bs.map((b) => (b.id === bid ? { ...b, slides: b.slides.map((s) => (s.id === sid ? { ...s, title } : s)) } : b)));
const removeSlide = (bid, sid) => mutateBeats((bs) => bs.map((b) => (b.id === bid ? { ...b, slides: b.slides.filter((s) => s.id !== sid) } : b)));
let extra = 0;
function addSlide(bid) { mutateBeats((bs) => bs.map((b) => (b.id === bid ? { ...b, slides: [...b.slides, { id: `n${++extra}`, title: '', points: [], notes: '' }] } : b))); }

const makeId = (taken, prefix) => newId([...taken], prefix);
async function generateDeck() {
    if (busy.value || !outline.value) return;
    const beats = outline.value.beats.map((b) => ({ ...b, slides: b.slides.filter((s) => s.title.trim()).map((s) => ({ ...s, title: s.title.trim() })) })).filter((b) => b.slides.length);
    if (!beats.length) { error.value = 'Keep at least one slide with a claim in it.'; return; }
    busy.value = true; error.value = '';
    try {
        const deck = await api.post('/api/decks', deckBody(titleFromBrief(brief.text)));
        const plan = outlineToDeck(beats, { w: deck.w, h: deck.h }, { makeId });
        for (const chunk of buildOps(deck.slides?.[0]?.id ?? null, plan.sections, plan.slides, { makeId })) {
            await api.post(`/api/decks/${deck.id}/ops`, { client: `outline-${deck.id}`, key: crypto.randomUUID?.() || `k${Date.now()}${Math.random()}`, ops: chunk });
        }
        open(deck.id);
    } catch (e) { fail(e); }
}

// ---- import ------------------------------------------------------------------------------------------
const file = ref(null);
const importTitle = ref('');
const imported = ref(null); // {deckId, notes}
const picker = ref(null);
const over = ref(false);
function pick(f) {
    error.value = '';
    if (!f) { file.value = null; return; }
    if (!/\.pptx$/i.test(f.name)) { error.value = 'Choose a .pptx file. Older .ppt files need to be saved as .pptx first.'; return; }
    if (f.size > 100 * 1024 * 1024) { error.value = 'That file is over 100 MB.'; return; }
    file.value = f;
}
async function importPptx() {
    if (busy.value || !file.value) return;
    busy.value = true; error.value = '';
    try {
        const form = new FormData();
        form.append('file', file.value);
        if (importTitle.value.trim()) form.append('title', importTitle.value.trim());
        const res = await api.post('/api/imports/pptx', form, { headers: { 'Content-Type': 'multipart/form-data' }, timeout: 120000 });
        const out = normalizeImport(res);
        busy.value = false;
        if (!out.deckId) { error.value = 'The import finished but did not say which presentation it made.'; return; }
        if (!out.notes.length) { open(out.deckId); return; }
        imported.value = out;
    } catch (e) { fail(e, 'PowerPoint import is not available in this build yet.'); }
}

// ---- templates ----------------------------------------------------------------------------------------
async function loadTemplates() {
    try {
        const res = await api.get('/api/templates');
        const list = Array.isArray(res) ? res : Array.isArray(res?.data) ? res.data : Array.isArray(res?.templates) ? res.templates : [];
        templates.value = list.filter((t) => t && (t.id != null) && (t.name || t.title));
    } catch { templates.value = []; }
}
async function useTemplate(t) {
    if (busy.value) return;
    busy.value = true; error.value = '';
    try {
        const body = { title: t.name || t.title, template: String(t.id) };
        const deck = await api.post('/api/decks', body);
        open(deck.id);
    } catch (e) { fail(e); }
}

const tabs = computed(() => [
    { id: 'blank', label: 'Blank' },
    { id: 'outline', label: 'From an outline' },
    { id: 'import', label: 'Import PowerPoint' },
    ...(templates.value.length ? [{ id: 'templates', label: 'Templates' }] : []),
]);
function go(id) { tab.value = id; error.value = ''; }
function onTabKey(e) {
    const btns = [...box.value.querySelectorAll('[role="tab"]')];
    const i = btns.indexOf(document.activeElement);
    const n = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: btns.length - 1 }[e.key];
    if (n === undefined) return;
    e.preventDefault();
    const t = btns[(n + btns.length) % btns.length];
    t.focus(); t.click();
}
function close() { emit('close'); nextTick(() => opener?.focus?.()); }
function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
    if (e.key === 'Tab') {
        const els = [...box.value.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex="0"]')].filter((el) => el.offsetParent !== null);
        if (!els.length) return;
        const first = els[0]; const last = els[els.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
}
onMounted(async () => {
    opener = document.activeElement;
    document.documentElement.style.overflow = 'hidden';
    await nextTick();
    box.value?.querySelector('input[type="text"]')?.focus();
    loadTemplates();
});
onBeforeUnmount(() => { document.documentElement.style.overflow = ''; });
</script>

<template>
    <Teleport to="body">
        <div class="pr ns-back" @pointerdown.self="close">
            <aside ref="box" class="ns" role="dialog" aria-modal="true" aria-labelledby="ns-title" data-no-shortcuts @keydown="onKey">
                <header class="ns-head">
                    <h2 id="ns-title" class="pr-title">New presentation</h2>
                    <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon" aria-label="Close" @click="close"><Ico name="close" /></button>
                </header>
                <div class="ns-tabs" role="tablist" aria-label="How to start" @keydown="onTabKey">
                    <button v-for="t in tabs" :key="t.id" type="button" role="tab" class="ns-tab" :class="{ 'is-on': tab === t.id }" :aria-selected="tab === t.id" :tabindex="tab === t.id ? 0 : -1" @click="go(t.id)">{{ t.label }}</button>
                </div>

                <div class="ns-body pr-scroll">
                    <p v-if="error" class="ns-error" role="alert"><span class="pr-bead pr-bead--bad pr-bead--sm" aria-hidden="true"></span>{{ error }}</p>

                    <!-- blank -->
                    <form v-if="tab === 'blank'" class="ns-form" role="tabpanel" @submit.prevent="createBlank">
                        <div>
                            <label class="pr-label" for="ns-title-in">Title</label>
                            <input id="ns-title-in" v-model="blankTitle" type="text" class="pr-field" maxlength="255" placeholder="Untitled presentation" autocomplete="off" />
                        </div>
                        <fieldset class="ns-set">
                            <legend class="pr-label">Slide size</legend>
                            <div class="ns-sizes">
                                <label v-for="s in SIZES" :key="s.id" class="ns-size" :class="{ 'is-on': size.id === s.id }">
                                    <input v-model="size.id" type="radio" name="size" :value="s.id" class="pr-sr" @change="applySize(s.id)" />
                                    <span class="ns-shape" :style="{ aspectRatio: `${s.w} / ${s.h}` }" aria-hidden="true"></span>
                                    <span class="ns-size-t">{{ s.label }}</span>
                                    <span class="ns-size-n pr-state">{{ s.note }}</span>
                                </label>
                            </div>
                            <div v-if="size.id === 'custom'" class="ns-custom">
                                <label class="ns-num"><span class="pr-label">Width</span><input v-model.number="size.w" type="number" min="320" max="7680" class="pr-field" /></label>
                                <label class="ns-num"><span class="pr-label">Height</span><input v-model.number="size.h" type="number" min="320" max="7680" class="pr-field" /></label>
                                <p class="pr-hint">Between 320 and 7680, in slide units.</p>
                            </div>
                        </fieldset>
                        <fieldset v-if="themes.length" class="ns-set">
                            <legend class="pr-label">Theme</legend>
                            <div class="ns-themes">
                                <label v-for="t in themes" :key="t.id" class="ns-theme" :class="{ 'is-on': themeId === t.id }">
                                    <input v-model="themeId" type="radio" name="theme" :value="t.id" class="pr-sr" />
                                    <span class="ns-swatch" aria-hidden="true"><i v-for="(c, i) in swatch(t)" :key="i" :style="{ background: c }"></i></span>
                                    <span class="ns-theme-n">{{ t.name }}</span>
                                </label>
                            </div>
                        </fieldset>
                        <button type="submit" class="pr-btn pr-btn--primary ns-go" :disabled="busy || !customOk">{{ busy ? 'Making it' : 'Create presentation' }}</button>
                    </form>

                    <!-- outline: brief -->
                    <form v-else-if="tab === 'outline' && step === 'brief'" class="ns-form" role="tabpanel" @submit.prevent="draftOutline">
                        <p class="ns-mode"><span class="pr-chip">{{ modeLabel(ai.mode) }}</span><span class="pr-hint">{{ ai.mode === 'ai' ? 'Your brief is sent to the AI model to draft the outline.' : 'The outline is drafted by rules on this server. Nothing is sent to an AI model.' }}</span></p>
                        <div>
                            <label class="pr-label" for="ns-brief">What is the presentation for?</label>
                            <textarea id="ns-brief" v-model="brief.text" class="pr-field" rows="4" maxlength="4000" placeholder="Explain to the board why churn doubled in Q3 and what we will do about it" />
                            <p class="pr-hint">A sentence or two: who is listening and what you want from them.</p>
                        </div>
                        <div>
                            <label class="pr-label" for="ns-source">Source text <span class="pr-muted">(optional)</span></label>
                            <textarea id="ns-source" v-model="brief.source" class="pr-field" rows="5" maxlength="20000" placeholder="Paste notes, a report or an article to draw the claims from" />
                        </div>
                        <div>
                            <label class="pr-label" for="ns-fw">Narrative</label>
                            <select id="ns-fw" v-model="brief.framework" class="pr-field"><option v-for="f in FRAMEWORKS" :key="f.id" :value="f.id">{{ f.label }}</option></select>
                        </div>
                        <details class="ns-more">
                            <summary>Size and theme</summary>
                            <div class="ns-inline">
                                <label class="ns-num"><span class="pr-label">Size</span><select v-model="size.id" class="pr-field" @change="applySize(size.id)"><option v-for="s in SIZES.filter((x) => x.id !== 'custom')" :key="s.id" :value="s.id">{{ s.label }} {{ s.note }}</option></select></label>
                                <label v-if="themes.length" class="ns-num"><span class="pr-label">Theme</span><select v-model="themeId" class="pr-field"><option v-for="t in themes" :key="t.id" :value="t.id">{{ t.name }}</option></select></label>
                            </div>
                        </details>
                        <button type="submit" class="pr-btn pr-btn--primary ns-go" :disabled="busy || !briefOk">{{ busy ? 'Drafting' : 'Draft the outline' }}</button>
                    </form>

                    <!-- outline: review -->
                    <div v-else-if="tab === 'outline'" class="ns-form" role="tabpanel">
                        <p class="ns-mode"><span class="pr-chip">{{ announced }}</span><span class="pr-hint">Edit the claims, then make the presentation. {{ slideCount }} {{ slideCount === 1 ? 'slide' : 'slides' }} in {{ outline.beats.length }} {{ outline.beats.length === 1 ? 'section' : 'sections' }}.</span></p>
                        <ol class="ns-beats">
                            <li v-for="(b, bi) in outline.beats" :key="b.id" class="ns-beat" :aria-busy="regen === b.id">
                                <div class="ns-beat-h">
                                    <span class="ns-beat-n pr-state">{{ bi + 1 }}</span>
                                    <input :value="b.label" type="text" class="pr-field ns-beat-in" maxlength="120" :aria-label="`Section ${bi + 1} name`" @input="renameBeat(b.id, $event.target.value)" />
                                    <div class="ns-beat-tools">
                                        <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon pr-btn--sm" :disabled="bi === 0" :aria-label="`Move ${b.label} up`" @click="shiftBeat(b.id, -1)"><Ico name="chevronUp" size="sm" /></button>
                                        <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon pr-btn--sm" :disabled="bi === outline.beats.length - 1" :aria-label="`Move ${b.label} down`" @click="shiftBeat(b.id, 1)"><Ico name="chevronDown" size="sm" /></button>
                                        <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon pr-btn--sm" :disabled="!!regen" :aria-label="`Write ${b.label} again`" data-tip="Write again" @click="regenerateBeat(b)"><Ico name="refresh" size="sm" /></button>
                                        <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon pr-btn--sm" :aria-label="`Delete ${b.label}`" @click="removeBeat(b.id)"><Ico name="trash" size="sm" /></button>
                                    </div>
                                </div>
                                <ul class="ns-claims">
                                    <li v-for="s in b.slides" :key="s.id" class="ns-claim">
                                        <span class="pr-bead pr-bead--sm" aria-hidden="true"></span>
                                        <input :value="s.title" type="text" class="pr-field" maxlength="200" aria-label="Claim" placeholder="Say what is true" @input="setSlide(b.id, s.id, $event.target.value)" />
                                        <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon pr-btn--sm" aria-label="Delete this slide" @click="removeSlide(b.id, s.id)"><Ico name="close" size="sm" /></button>
                                    </li>
                                </ul>
                                <button type="button" class="pr-btn pr-btn--ghost pr-btn--sm ns-add" @click="addSlide(b.id)"><Ico name="plus" size="sm" />Add a slide</button>
                            </li>
                        </ol>
                        <p v-if="!outline.beats.length" class="pr-muted">Nothing on this line yet. Go back and draft it again.</p>
                        <div class="ns-foot">
                            <button type="button" class="pr-btn" :disabled="busy" @click="step = 'brief'">Back</button>
                            <button type="button" class="pr-btn" :disabled="busy" @click="draftOutline"><Ico name="refresh" size="sm" />Write it all again</button>
                            <button type="button" class="pr-btn pr-btn--primary ns-grow" :disabled="busy || !slideCount" @click="generateDeck">{{ busy ? 'Making it' : `Make ${slideCount} ${slideCount === 1 ? 'slide' : 'slides'}` }}</button>
                        </div>
                    </div>

                    <!-- import -->
                    <div v-else-if="tab === 'import'" class="ns-form" role="tabpanel">
                        <template v-if="!imported">
                            <div class="ns-drop" :class="{ 'is-over': over, 'has-file': file }" @dragover.prevent="over = true" @dragleave="over = false" @drop.prevent="over = false; pick($event.dataTransfer.files[0])">
                                <input ref="picker" type="file" accept=".pptx,application/vnd.openxmlformats-officedocument.presentationml.presentation" class="pr-sr" aria-label="PowerPoint file" @change="pick($event.target.files[0])" />
                                <Ico name="upload" size="lg" />
                                <p v-if="!file" class="ns-drop-t">Drop a .pptx file here</p>
                                <p v-else class="ns-drop-t">{{ file.name }}</p>
                                <p class="pr-hint">{{ file ? `${(file.size / 1048576).toFixed(1)} MB` : 'or' }}</p>
                                <button type="button" class="pr-btn" @click="picker.click()">{{ file ? 'Choose another' : 'Choose a file' }}</button>
                            </div>
                            <div>
                                <label class="pr-label" for="ns-imp-title">Title <span class="pr-muted">(optional, defaults to the file name)</span></label>
                                <input id="ns-imp-title" v-model="importTitle" type="text" class="pr-field" maxlength="255" autocomplete="off" />
                            </div>
                            <p class="pr-hint">Text, pictures, shapes, tables and charts are converted. What could not be converted is listed when it finishes.</p>
                            <button type="button" class="pr-btn pr-btn--primary ns-go" :disabled="busy || !file" @click="importPptx">{{ busy ? 'Converting' : 'Import' }}</button>
                        </template>
                        <template v-else>
                            <h3 class="pr-title ns-rep-t">Imported, with notes</h3>
                            <p class="pr-hint">These things could not be converted exactly. The rest came across.</p>
                            <ul class="ns-report">
                                <li v-for="(n, i) in imported.notes" :key="i" class="ns-report-i"><span class="pr-bead pr-bead--sm" :class="n.kind === 'skipped' ? 'pr-bead--warn' : ''" aria-hidden="true"></span><span>{{ n.text }}</span></li>
                            </ul>
                            <button type="button" class="pr-btn pr-btn--primary ns-go" @click="open(imported.deckId)">Open the presentation</button>
                        </template>
                    </div>

                    <!-- templates -->
                    <div v-else-if="tab === 'templates'" class="ns-form" role="tabpanel">
                        <ul class="ns-tpls">
                            <li v-for="t in templates" :key="t.id">
                                <button type="button" class="ns-tpl" :disabled="busy" @click="useTemplate(t)">
                                    <span v-if="t.preview" class="ns-tpl-thumb"><LazyThumb :slide="{ id: 'p', bg: t.preview.bg ?? null, els: t.preview.els ?? [] }" :deck="{ w: t.w || 1920, h: t.h || 1080, theme: t.theme }" /></span>
                                    <span class="ns-tpl-n">{{ t.name || t.title }}</span>
                                    <span v-if="t.slides" class="pr-state pr-muted">{{ t.slides }} slides</span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
