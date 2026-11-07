<script setup>
// Suggestions for the current slide or selected text, each one a proposal you accept or reject, plus slide generation.
// Local rules answer by default; a language model answers only when one is connected, and everything it writes stays
// marked as an AI draft until you review it.
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import { InlineNotice, PanelSection, ProgressBead } from '../../../ui/controls';
import { applyLayout } from '../../core/layouts.js';
import { createIntel, describeError } from '../intel/api.js';
import ModeChip from '../intel/ModeChip.vue';
import '../intel/intel.css';
import ProposalCard from './ProposalCard.vue';
import OutlineBuilder from './OutlineBuilder.vue';
import { ASKS, aiDraftSlides, aiDrafts, askLabel, degradedLine, isDeckAsk, modeLine, proposalCard, requestFor, reviewedOps, targetOf } from './assistantModel.js';

const editor = useEditorContext();
const { store, state } = editor;
const intel = createIntel(editor.api, editor.deckId);

const doc = computed(() => state.value.doc);
const slide = computed(() => editor.slide.value);
const selected = computed(() => editor.selected.value);
const canEdit = computed(() => !editor.readOnly && !!editor.can.update);
const selectionText = ref('');
const running = ref(null);
const result = shallowRef(null);
const cards = ref([]);
const error = ref(null);
const accepting = ref(false);

const ctx = computed(() => ({ slide: slide.value, selected: selected.value, selectionText: selectionText.value }));
const target = computed(() => targetOf(ctx.value));
const drafts = computed(() => aiDrafts(slide.value));
const otherDrafts = computed(() => aiDraftSlides(doc.value, state.value.slideId));
const hasCards = computed(() => cards.value.length > 0);

// Highlighted words inside a text box being edited: remember the last non-empty selection while editing.
function onSelection() {
    const sel = window.getSelection?.();
    const text = sel ? String(sel) : '';
    const node = sel?.anchorNode;
    const el = node instanceof Element ? node : node?.parentElement;
    if (text.trim() && el?.closest?.('.ProseMirror')) selectionText.value = text;
}
onMounted(() => document.addEventListener('selectionchange', onSelection));
onBeforeUnmount(() => document.removeEventListener('selectionchange', onSelection));
watch(() => editor.ui.editingText, (v) => { if (!v) selectionText.value = ''; });
watch(() => state.value.slideId, () => { selectionText.value = ''; });

async function ask(kind) {
    if (running.value) return;
    running.value = kind;
    error.value = null;
    result.value = null;
    cards.value = [];
    try {
        await editor.settle();
        if (!['saved', 'readonly'].includes(editor.saveInfo.value.key)) {
            error.value = { message: 'Your latest changes are still saving. Try again in a moment, so the suggestion matches your deck.' };
            return;
        }
        const res = await intel.suggest(requestFor(kind, ctx.value));
        result.value = { ...res, asked: kind };
        cards.value = (res.proposals || []).map((p) => proposalCard(p, doc.value, { aiMode: editor.aiMode }));
        editor.announce(cards.value.length ? `${cards.value.length} ${cards.value.length === 1 ? 'suggestion' : 'suggestions'} ready` : 'No suggestions');
    } catch (e) {
        error.value = describeError(e);
    } finally {
        running.value = null;
    }
}

function drop(card) { cards.value = cards.value.filter((c) => c.id !== card.id); }

async function accept(card) {
    if (!canEdit.value || accepting.value) return;
    accepting.value = true;
    try {
        let ops = card.ops;
        if (card.needsLayout) {
            let layouts = [];
            try { layouts = await intel.layouts(doc.value.deck.w, doc.value.deck.h); } catch { layouts = []; }
            const def = layouts.find((l) => l.id === card.layoutId);
            if (!def || !slide.value) { editor.toast('The layout library could not be read, so the layout was not changed.', { kind: 'warn' }); return; }
            ops = applyLayout(doc.value, slide.value.id, def).ops;
        }
        const r = store.dispatch(ops, { label: card.title });
        if (!r) { editor.toast('That suggestion no longer applies. The slide has changed since it was made.', { kind: 'warn' }); return; }
        const added = ops.find((o) => o.op === 'slide.add');
        if (added) store.setSlide(added.tid);
        drop(card);
        editor.toast(card.ai ? 'Accepted. It stays marked as an AI draft until you review it.' : 'Accepted', { kind: 'ok', action: { label: 'Undo', run: () => store.undo() } });
        editor.announce('Suggestion accepted');
    } finally {
        accepting.value = false;
    }
}
async function copy(card) {
    try { await navigator.clipboard.writeText(card.after); editor.toast('Copied. Paste it over your selection.', { kind: 'ok' }); } catch { editor.toast('Your browser would not let me copy. Select the text in the box above and copy it.', { kind: 'warn' }); }
}

// ---- AI drafts -------------------------------------------------------------------------------------
function review(ids, label) {
    if (!canEdit.value || !ids.length) return;
    store.dispatch(reviewedOps(slide.value.id, ids), { label, undoKey: `ai-review:${ids.join(',')}` });
    editor.announce(ids.length === 1 ? 'Marked as reviewed' : `${ids.length} marked as reviewed`);
}
const select = (id) => store.select([id]);
const jump = (id) => store.setSlide(String(id));
</script>

<template>
    <div class="iq iq-assistant">
        <header class="iq-head">
            <div class="iq-head-row">
                <h2 class="iq-title">Assistant</h2>
                <ModeChip :mode="editor.aiMode" />
            </div>
            <p class="iq-lead">{{ modeLine(editor.aiMode) }}</p>
        </header>

        <section class="iq-target" aria-label="What it applies to">
            <p class="pr-small"><span class="pr-state pr-muted">applies to</span> {{ target.words }}</p>
            <p v-if="selectionText" class="pr-small pr-muted">"{{ selectionText.trim().slice(0, 80) }}{{ selectionText.trim().length > 80 ? '...' : '' }}" <button type="button" class="iq-why-t" @click="selectionText = ''">Use the whole text box</button></p>
        </section>
        <div v-if="!canEdit" class="iq-pad"><InlineNotice kind="info">You can read this deck but not change it, so suggestions are off.</InlineNotice></div>

        <ul class="iq-asks" aria-label="What would you like?">
            <li v-for="a in ASKS" :key="a.id" class="iq-ask">
                <span class="iq-ask-l">{{ a.label }}</span>
                <p class="iq-ask-d">{{ a.id === 'professional_tone' && editor.aiMode !== 'ai' ? 'Needs a language model, which is not connected.' : a.hint }}</p>
                <button type="button" class="pr-btn pr-btn--sm" :disabled="!!running || !canEdit || (!slide && !isDeckAsk(a.id))" :aria-label="`${a.label}: ask`" @click="ask(a.id)">{{ running === a.id ? 'Asking' : 'Ask' }}</button>
            </li>
        </ul>

        <section v-if="running || error || result" class="iq-answer" aria-live="polite" aria-label="Answer">
            <div v-if="running" class="iq-pad"><ProgressBead :value="null" :label="`Working on: ${askLabel(running)}`" /></div>
            <div v-if="error" class="iq-pad"><InlineNotice kind="error" :title="error.kind === 'throttled' ? 'Slow down a moment' : 'No suggestion'">{{ error.message }}</InlineNotice></div>
            <template v-if="result && !running">
                <div class="iq-pad iq-stack">
                    <p class="iq-prop-h"><strong class="iq-prop-s">{{ askLabel(result.asked) }}</strong><ModeChip :mode="result.mode" :degraded="result.degraded" /></p>
                    <InlineNotice v-if="result.degraded" kind="warn" title="A language model was not used">{{ degradedLine(result) }}</InlineNotice>
                    <InlineNotice v-for="u in result.unavailable || []" :key="u.kind" kind="info" title="This needs a language model">{{ u.reason }}</InlineNotice>
                    <p v-for="n in result.notes || []" :key="n" class="pr-small pr-muted">{{ n }}</p>
                    <p v-if="!hasCards && !(result.unavailable || []).length" class="pr-small pr-muted">Nothing to suggest here.</p>
                    <p v-if="result.quota" class="pr-small pr-muted">{{ result.quota.remaining_today }} AI {{ result.quota.remaining_today === 1 ? 'request' : 'requests' }} left today.</p>
                </div>
                <ProposalCard v-for="c in cards" :key="c.id" :card="c" :doc="doc" :can-edit="canEdit" :busy="accepting" @accept="accept" @reject="drop" @copy="copy" />
            </template>
        </section>

        <PanelSection id="assistant.drafts" title="AI drafts" :state="drafts.length || ''" :default-open="drafts.length > 0">
            <p v-if="!drafts.length && !otherDrafts.length" class="pr-small pr-muted">Nothing here was written by a language model.</p>
            <template v-if="drafts.length">
                <p class="pr-small pr-muted">A language model wrote these. They stay marked until you read them and say they are fine.</p>
                <ul class="iq-ai-list">
                    <li v-for="d in drafts" :key="d.id">
                        <span class="iq-ai-t"><span class="iq-ai-badge">AI draft</span><span>{{ d.snippet || d.label }}</span></span>
                        <span class="iq-prop-acts">
                            <button type="button" class="pr-btn pr-btn--ghost pr-btn--sm" @click="select(d.id)">Select</button>
                            <button type="button" class="pr-btn pr-btn--sm" :disabled="!canEdit" @click="review([d.id], 'Mark as reviewed')">Mark as reviewed</button>
                        </span>
                    </li>
                </ul>
                <button v-if="drafts.length > 1" type="button" class="pr-btn pr-btn--sm" :disabled="!canEdit" @click="review(drafts.map((d) => d.id), 'Mark all as reviewed')">Mark all {{ drafts.length }} as reviewed</button>
            </template>
            <template v-if="otherDrafts.length">
                <p class="pr-small pr-muted">More AI drafts on other slides:</p>
                <div class="iq-chips"><button v-for="s in otherDrafts" :key="s.id" type="button" class="iq-slide-chip" @click="jump(s.id)">Slide {{ s.no }} ({{ s.count }})</button></div>
            </template>
        </PanelSection>

        <PanelSection id="assistant.generate" title="Generate slides from text" :default-open="false">
            <OutlineBuilder :intel="intel" />
        </PanelSection>
    </div>
</template>
