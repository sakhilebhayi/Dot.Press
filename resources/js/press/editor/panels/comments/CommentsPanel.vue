<script setup>
// Comments: every thread on the deck, newest first, with a composer that can aim at the deck, the slide, a selected element
// or a point on the stage. The stage draws the pins (editor.commentPins, filled by useComments); a pin or the stage's
// "Comment here" menu item opens this panel with {pin|focus: threadId} or {anchor: {slide, element?, x, y}}.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import { EmptyState, InlineNotice, ProgressBead, Segmented, SelectMenu } from '../../../ui/controls';
import ThreadCard from './ThreadCard.vue';
import Composer from './Composer.vue';
import { useComments } from './useComments.js';
import { usePeople } from './usePeople.js';
import { countThreads, elementLabel, filterThreads, resolveTarget, scopeLabel, unreadIds } from './commentsModel.js';

const props = defineProps({ panelProps: { type: Object, default: null } });
const editor = useEditorContext();
const comments = useComments(editor);
const peopleStore = usePeople(editor);
const { store } = editor;

const canComment = computed(() => editor.can.comment !== false);
const meId = editor.me.id;
const slides = computed(() => editor.state.value.doc.slides);
const slideIndex = computed(() => slides.value.findIndex((s) => s.id === editor.state.value.slideId));
const one = computed(() => (editor.selected.value.length === 1 ? editor.selected.value[0] : null));

// ---- filters -------------------------------------------------------------------------------------------
const stateFilter = ref('open');
const thisSlide = ref(false);
const counts = computed(() => countThreads(comments.threads.value, editor.state.value.slideId));
const visible = computed(() => filterThreads(comments.threads.value, { state: stateFilter.value, slideId: thisSlide.value ? editor.state.value.slideId : null }));
const stateOptions = computed(() => [
    { value: 'open', label: `Open ${counts.value.open}` },
    { value: 'resolved', label: `Resolved ${counts.value.resolved}` },
    { value: 'all', label: 'All' },
]);

// ---- unread --------------------------------------------------------------------------------------------
const baseline = ref(comments.seenAt.value);
const readNow = ref(new Set());
const unread = computed(() => new Set(unreadIds(comments.threads.value, baseline.value, meId).filter((id) => !readNow.value.has(id))));
function seen(id) { if (unread.value.has(id)) readNow.value = new Set(readNow.value).add(id); }
const now = ref(Date.now());
let clock = null;
function leave() { comments.markSeen(); }
onMounted(() => {
    comments.setPanelOpen(true);
    peopleStore.ensure();
    clock = window.setInterval(() => { now.value = Date.now(); }, 30000);
    window.addEventListener('pagehide', leave);
});
onBeforeUnmount(() => {
    comments.setPanelOpen(false);
    leave();
    window.clearInterval(clock);
    window.removeEventListener('pagehide', leave);
});

// ---- composer ------------------------------------------------------------------------------------------
const draft = ref('');
const composer = ref(null);
const kind = ref('slide');
const pendingAnchor = ref(null); // {slide, element, x, y} from the stage menu
const targetNote = ref('');

const kindOptions = computed(() => {
    const out = [{ value: 'deck', label: 'Whole deck' }];
    if (slideIndex.value >= 0) out.push({ value: 'slide', label: `Slide ${slideIndex.value + 1}`, hint: 'The slide as a whole' });
    if (one.value) out.push({ value: 'element', label: `On ${elementLabel(one.value)}`, hint: 'Selected element' });
    if (pendingAnchor.value) out.push({ value: 'point', label: `Pin at ${pendingAnchor.value.x}, ${pendingAnchor.value.y}`, hint: `On slide ${slideIndex.value + 1}` });
    return out;
});
watch(kindOptions, (opts) => { if (!opts.some((o) => o.value === kind.value)) kind.value = opts.some((o) => o.value === 'slide') ? 'slide' : 'deck'; });
watch(() => editor.state.value.slideId, (id) => { if (pendingAnchor.value && String(pendingAnchor.value.slide) !== String(id)) { pendingAnchor.value = null; } });

async function submitRoot(body) {
    const target = resolveTarget(kind.value, { slide: editor.slide.value, element: one.value, anchor: pendingAnchor.value });
    if (!target) {
        targetNote.value = 'This slide is still being saved. Wait a moment, or comment on the whole deck.';
        return;
    }
    targetNote.value = '';
    draft.value = '';
    pendingAnchor.value = null;
    if (kind.value === 'point') kind.value = 'slide';
    const res = await comments.post({ body, target });
    if (res) { thisSlide.value = false; stateFilter.value = stateFilter.value === 'resolved' ? 'open' : stateFilter.value; }
}

// ---- opening from the stage ------------------------------------------------------------------------
const focusId = ref(null);
/** Scroll the panel only (never the page: the drawer is still sliding in when a pin opens it). */
function scrollToThread(id) {
    const el = document.querySelector(`[data-thread="${id}"]`);
    const host = el?.closest('.ph');
    if (!el || !host) return;
    const top = el.getBoundingClientRect().top - host.getBoundingClientRect().top + host.scrollTop;
    host.scrollTo({ top: Math.max(0, top - host.clientHeight / 4), behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
}
async function applyProps(p) {
    if (!p) return;
    const id = p.pin ?? p.focus;
    if (id != null) {
        focusId.value = Number(id);
        await nextTick();
        const t = comments.threads.value.find((x) => x.id === focusId.value);
        if (t && !visible.value.some((x) => x.id === t.id)) { stateFilter.value = 'all'; thisSlide.value = false; }
        await nextTick();
        scrollToThread(focusId.value);
        seen(focusId.value);
    }
    if (p.anchor && canComment.value) {
        const a = p.anchor;
        pendingAnchor.value = { slide: String(a.slide ?? editor.state.value.slideId), element: a.element || null, x: Number(a.x), y: Number(a.y) };
        kind.value = 'point';
        await nextTick();
        composer.value?.focus();
    }
}
watch(() => props.panelProps, applyProps, { immediate: true });
// the thread list may arrive after the pin was clicked
watch(() => comments.status.value, (s) => { if (s === 'ready' && focusId.value != null) applyProps({ focus: focusId.value }); });

// ---- thread actions --------------------------------------------------------------------------------
function go(thread) {
    seen(thread.id);
    focusId.value = thread.id;
    if (thread.slide_id == null) { editor.announce('This comment is about the whole deck'); return; }
    const sid = String(thread.slide_id);
    const slide = slides.value.find((s) => s.id === sid);
    if (!slide) { editor.toast('That slide has been deleted', { kind: 'warn' }); return; }
    store.setSlide(sid);
    if (thread.element_id && slide.els.some((e) => e.id === thread.element_id)) store.select([thread.element_id], 'replace');
    editor.emit?.('stage:focus');
}
const actions = {
    async reply(thread, body) { await comments.post({ body, parent: thread }); return true; },
    async edit(c, body) { return comments.edit(c.id, body); },
    async remove(c) { await comments.remove(c); editor.announce('Comment deleted'); return true; },
    async resolve(thread, value) {
        await comments.setResolved(thread, value);
        editor.announce(value ? 'Thread resolved' : 'Thread reopened');
        if (value) editor.toast('Thread resolved', { kind: 'ok', action: { label: 'Undo', run: () => comments.setResolved(thread, false).catch(() => editor.toast('Could not reopen the thread', { kind: 'error' })) } });
        return true;
    },
    retry: (tmp) => comments.retry(tmp),
    discard: (tmp) => comments.discard(tmp),
};

function onListKey(e) {
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) return;
    const heads = [...e.currentTarget.querySelectorAll('[data-thread-head]')];
    const i = heads.indexOf(document.activeElement);
    if (i < 0) return;
    e.preventDefault();
    const next = { ArrowDown: i + 1, ArrowUp: i - 1, Home: 0, End: heads.length - 1 }[e.key];
    heads[Math.max(0, Math.min(heads.length - 1, next))]?.focus();
}

const summary = computed(() => {
    const parts = [`${counts.value.open} open`, `${counts.value.resolved} resolved`];
    return parts.join(', ');
});
const people = computed(() => peopleStore.people.value);
const emptyBody = computed(() => {
    if (thisSlide.value && counts.value.onSlide === 0) return 'Nobody has commented on this slide yet.';
    if (stateFilter.value === 'open' && counts.value.resolved) return 'Every thread is resolved. Switch to Resolved to read them again.';
    if (stateFilter.value === 'resolved') return 'Resolved threads appear here.';
    return canComment.value ? 'Write the first one below. Point at a place on the slide with the right-click menu.' : 'Comments from the team will appear here.';
});
</script>

<template>
    <div class="cmp">
        <div class="cmp-top">
            <div class="cmp-row">
                <span class="cmp-sum pr-state" role="status">{{ summary }}<template v-if="unread.size">, <strong>{{ unread.size }} new</strong></template></span>
                <button type="button" class="pr-btn pr-btn--sm" :aria-pressed="thisSlide" :title="slideIndex >= 0 ? `Only comments on slide ${slideIndex + 1}` : undefined" @click="thisSlide = !thisSlide">This slide <span class="pr-state">{{ counts.onSlide }}</span></button>
            </div>
            <Segmented v-model="stateFilter" :options="stateOptions" label="Show comments" hide-label />
        </div>

        <div class="cmp-list-wrap">
            <div v-if="comments.status.value === 'loading' && !comments.threads.value.length" class="cmp-state"><ProgressBead :value="null" label="Loading comments" /><p class="pr-small pr-muted">Loading comments</p></div>
            <div v-else-if="comments.status.value === 'error' && !comments.threads.value.length" class="cmp-state">
                <InlineNotice kind="error" title="Comments did not load">{{ comments.error.value }}</InlineNotice>
                <button type="button" class="pr-btn pr-btn--sm" @click="comments.load()">Try again</button>
            </div>
            <template v-else>
                <InlineNotice v-if="comments.error.value" kind="warn" class="cmp-note">{{ comments.error.value }}</InlineNotice>
                <ul v-if="visible.length" class="cmp-list" aria-label="Comment threads" @keydown="onListKey">
                    <ThreadCard
                        v-for="t in visible" :key="t.id" :thread="t" :scope="scopeLabel(t, slides)" :can-go="t.slide_id != null" :me-id="meId" :can-comment="canComment"
                        :unread="unread.has(t.id)" :focused="focusId === t.id" :people="people" :now="now" :actions="actions" @go="go" @seen="seen(t.id)"
                    />
                </ul>
                <EmptyState v-else :title="comments.threads.value.length ? 'Nothing here.' : 'No comments yet.'" :body="emptyBody" compact />
            </template>
        </div>

        <form v-if="canComment" class="cmp-compose" @submit.prevent>
            <p v-if="targetNote" class="cmp-warn pr-small" role="alert">{{ targetNote }}</p>
            <Composer ref="composer" v-model="draft" :people="people" :me-id="meId" :label="`Comment on ${kindOptions.find((o) => o.value === kind)?.label || 'the deck'}`" @submit="submitRoot">
                <template #lead>
                    <SelectMenu v-model="kind" :options="kindOptions" label="Comment on" hide-label />
                    <button v-if="pendingAnchor" type="button" class="pr-btn pr-btn--sm pr-btn--ghost" @click="pendingAnchor = null; kind = 'slide'">Remove pin</button>
                </template>
            </Composer>
        </form>
        <div v-else class="cmp-compose">
            <InlineNotice kind="info">You can read comments here. Your role on this deck cannot add them.</InlineNotice>
        </div>
    </div>
</template>

<style scoped>
.cmp { display: flex; flex-direction: column; min-height: 100%; }
.cmp-top { display: grid; gap: 10px; padding: 12px 16px; border-bottom: 1px solid var(--line); background: var(--sheet); }
@media (min-width: 768px) { .cmp-top { position: sticky; top: 0; z-index: 3; } }
.cmp-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.cmp-sum { color: var(--muted); }
.cmp-sum strong { color: var(--ink); font-weight: 600; }
.cmp-list-wrap { flex: 1; min-height: 160px; }
.cmp-list { margin: 0; padding: 0; list-style: none; }
.cmp-state { display: grid; gap: 12px; justify-items: start; padding: 24px 16px; }
.cmp-note { margin: 12px 16px 0; }
.cmp-compose { position: sticky; bottom: 0; z-index: 3; display: grid; gap: 8px; padding: 10px 16px 12px; border-top: 1px solid var(--line-strong); background: var(--sheet); }
.cmp-warn { margin: 0; color: var(--ink); }
</style>
