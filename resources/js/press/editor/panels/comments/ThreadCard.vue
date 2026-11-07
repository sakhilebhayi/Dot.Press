<script setup>
// One comment thread: the first comment, its replies on a rail, and the actions. Everything that changes data goes through
// `actions` (the panel's wrappers around useComments), which return a promise; this component only keeps UI state.
import { computed, nextTick, ref } from 'vue';
import Ico from '../../ui/Ico.vue';
import Composer from './Composer.vue';
import { parseSegments, plainText, timeAgo } from './commentsModel.js';

const props = defineProps({
    thread: { type: Object, required: true },
    scope: { type: String, default: '' },
    canGo: { type: Boolean, default: true },
    meId: { type: Number, default: 0 },
    canComment: { type: Boolean, default: true },
    unread: { type: Boolean, default: false },
    focused: { type: Boolean, default: false },
    people: { type: Array, default: () => [] },
    now: { type: Number, default: () => Date.now() },
    actions: { type: Object, required: true },
});
const emit = defineEmits(['go', 'seen']);

const replying = ref(false);
const replyText = ref('');
const replyBox = ref(null);
const editing = ref(null); // comment id
const editText = ref('');
const confirming = ref(null); // comment id
const busy = ref(new Set());
const rowError = ref({}); // id -> message

const replies = computed(() => props.thread.replies || []);
const canReply = computed(() => props.canComment && !props.thread.pending && !props.thread.failed);

function isBusy(id) { return busy.value.has(id); }
async function run(id, fn) {
    busy.value = new Set(busy.value).add(id);
    rowError.value = { ...rowError.value, [id]: '' };
    try { return await fn(); } catch (e) {
        rowError.value = { ...rowError.value, [id]: e?.message || 'That did not work. Try again.' };
        return null;
    } finally {
        const next = new Set(busy.value); next.delete(id); busy.value = next;
    }
}

async function startReply() {
    replying.value = true;
    emit('seen');
    await nextTick();
    replyBox.value?.focus();
}
async function sendReply(body) {
    const ok = await run(props.thread.id, () => props.actions.reply(props.thread, body));
    if (ok !== null) { replyText.value = ''; replying.value = false; }
}
function startEdit(c) {
    editing.value = c.id;
    editText.value = c.body;
    confirming.value = null;
}
async function saveEdit(c, body) {
    const ok = await run(c.id, () => props.actions.edit(c, body));
    if (ok !== null) editing.value = null;
}
async function doDelete(c) {
    const ok = await run(c.id, () => props.actions.remove(c));
    if (ok !== null) confirming.value = null;
}
function toggleResolved() { return run(props.thread.id, () => props.actions.resolve(props.thread, !props.thread.resolved)); }

const initials = (n) => String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');
const when = (c) => timeAgo(c.created_at, props.now);
const edited = (c) => c.updated_at && c.created_at && Date.parse(c.updated_at) - Date.parse(c.created_at) > 2000;
const headLabel = computed(() => `${props.thread.author.name}: ${plainText(props.thread.body).slice(0, 80)}`);
</script>

<template>
    <li class="cmt" :class="{ 'is-resolved': thread.resolved, 'is-focused': focused, 'is-pending': thread.pending, 'is-failed': thread.failed }" :data-thread="thread.id" :aria-label="headLabel">
        <span class="cmt-rail" aria-hidden="true"></span>
        <span class="cmt-bead pr-bead" :data-state="thread.resolved ? 'solid' : (focused ? 'current' : 'hollow')" :data-evidence="thread.resolved ? 'solid' : 'hollow'" aria-hidden="true"></span>

        <div class="cmt-main">
            <header class="cmt-head" data-thread-head tabindex="0" @focus="emit('seen')" @keydown.enter.self="canGo && emit('go', thread)">
                <span class="pr-avatar cmt-av" aria-hidden="true">{{ initials(thread.author.name) }}</span>
                <span class="cmt-who">
                    <span class="cmt-name">{{ thread.author.name }}<span v-if="thread.author.id === meId" class="cmt-you"> (you)</span></span>
                    <span class="cmt-when pr-state">{{ when(thread) }}<template v-if="edited(thread)">, edited</template></span>
                </span>
                <span v-if="unread" class="cmt-new pr-state" title="New since you last looked">new</span>
                <span v-if="thread.resolved" class="cmt-state pr-state">resolved</span>
            </header>

            <button v-if="scope" type="button" class="cmt-scope pr-chip" :disabled="!canGo" :title="canGo ? 'Go to this place on the slide' : undefined" @click="emit('go', thread)">
                <Ico name="pin" size="sm" /><span class="cmt-scope-t">{{ scope }}</span>
            </button>

            <!-- the first comment -->
            <div v-if="editing === thread.id" class="cmt-edit">
                <Composer v-model="editText" :people="people" :me-id="meId" label="Edit comment" submit-label="Save" :busy="isBusy(thread.id)" autofocus cancellable @submit="saveEdit(thread, $event)" @cancel="editing = null" />
            </div>
            <p v-else class="cmt-body"><template v-for="(seg, i) in parseSegments(thread.body)" :key="i"><span v-if="seg.type === 'mention'" class="cmt-men">@{{ seg.name }}</span><template v-else>{{ seg.text }}</template></template></p>

            <p v-if="rowError[thread.id]" class="cmt-err" role="alert"><span class="pr-bead pr-bead--sm pr-bead--bad" aria-hidden="true"></span>{{ rowError[thread.id] }}</p>
            <p v-if="thread.failed" class="cmt-err" role="alert">
                <span class="pr-bead pr-bead--sm pr-bead--bad" aria-hidden="true"></span>{{ thread.error }}
            </p>
            <div v-if="thread.failed" class="cmt-acts">
                <button type="button" class="cmt-act" @click="actions.retry(thread.id)">Retry</button>
                <button type="button" class="cmt-act" @click="actions.discard(thread.id)">Discard</button>
            </div>
            <p v-else-if="thread.pending" class="cmt-pending pr-state">sending</p>

            <div v-if="!thread.pending && !thread.failed && editing !== thread.id" class="cmt-acts">
                <button v-if="canReply" type="button" class="cmt-act" @click="startReply">Reply</button>
                <button v-if="canComment" type="button" class="cmt-act" :disabled="isBusy(thread.id)" @click="toggleResolved">{{ thread.resolved ? 'Reopen' : 'Resolve' }}</button>
                <button v-if="thread.can_edit" type="button" class="cmt-act" @click="startEdit(thread)">Edit</button>
                <button v-if="thread.can_delete" type="button" class="cmt-act cmt-act--danger" @click="confirming = thread.id">Delete</button>
            </div>
            <div v-if="confirming === thread.id" class="cmt-confirm" role="alertdialog" aria-label="Delete this thread">
                <p class="pr-small">Delete this comment{{ replies.length ? ` and its ${replies.length} ${replies.length === 1 ? 'reply' : 'replies'}` : '' }}? This cannot be undone.</p>
                <div class="cmt-acts">
                    <button type="button" class="pr-btn pr-btn--sm pr-btn--danger" :disabled="isBusy(thread.id)" data-autofocus @click="doDelete(thread)">Delete</button>
                    <button type="button" class="pr-btn pr-btn--sm pr-btn--ghost" @click="confirming = null">Keep</button>
                </div>
            </div>

            <!-- replies, on the rail -->
            <ul v-if="replies.length" class="cmt-replies">
                <li v-for="r in replies" :key="r.id" class="cmr" :class="{ 'is-failed': r.failed, 'is-pending': r.pending }">
                    <span class="cmr-bead pr-bead pr-bead--sm" aria-hidden="true"></span>
                    <header class="cmr-head">
                        <span class="cmr-name">{{ r.author.name }}<span v-if="r.author.id === meId" class="cmt-you"> (you)</span></span>
                        <span class="cmt-when pr-state">{{ when(r) }}<template v-if="edited(r)">, edited</template></span>
                    </header>
                    <div v-if="editing === r.id" class="cmt-edit">
                        <Composer v-model="editText" :people="people" :me-id="meId" label="Edit reply" submit-label="Save" :busy="isBusy(r.id)" autofocus cancellable @submit="saveEdit(r, $event)" @cancel="editing = null" />
                    </div>
                    <p v-else class="cmt-body"><template v-for="(seg, i) in parseSegments(r.body)" :key="i"><span v-if="seg.type === 'mention'" class="cmt-men">@{{ seg.name }}</span><template v-else>{{ seg.text }}</template></template></p>
                    <p v-if="rowError[r.id]" class="cmt-err" role="alert"><span class="pr-bead pr-bead--sm pr-bead--bad" aria-hidden="true"></span>{{ rowError[r.id] }}</p>
                    <template v-if="r.failed">
                        <p class="cmt-err" role="alert"><span class="pr-bead pr-bead--sm pr-bead--bad" aria-hidden="true"></span>{{ r.error }}</p>
                        <div class="cmt-acts"><button type="button" class="cmt-act" @click="actions.retry(r.id)">Retry</button><button type="button" class="cmt-act" @click="actions.discard(r.id)">Discard</button></div>
                    </template>
                    <p v-else-if="r.pending" class="cmt-pending pr-state">sending</p>
                    <div v-else-if="editing !== r.id && (r.can_edit || r.can_delete)" class="cmt-acts">
                        <button v-if="r.can_edit" type="button" class="cmt-act" @click="startEdit(r)">Edit</button>
                        <button v-if="r.can_delete" type="button" class="cmt-act cmt-act--danger" @click="confirming = r.id">Delete</button>
                    </div>
                    <div v-if="confirming === r.id" class="cmt-confirm" role="alertdialog" aria-label="Delete this reply">
                        <p class="pr-small">Delete this reply? This cannot be undone.</p>
                        <div class="cmt-acts">
                            <button type="button" class="pr-btn pr-btn--sm pr-btn--danger" :disabled="isBusy(r.id)" data-autofocus @click="doDelete(r)">Delete</button>
                            <button type="button" class="pr-btn pr-btn--sm pr-btn--ghost" @click="confirming = null">Keep</button>
                        </div>
                    </div>
                </li>
            </ul>

            <div v-if="replying" class="cmt-reply">
                <Composer ref="replyBox" v-model="replyText" :people="people" :me-id="meId" label="Reply" placeholder="Write a reply" submit-label="Reply" :busy="isBusy(thread.id)" cancellable @submit="sendReply" @cancel="replying = false" />
            </div>
        </div>
    </li>
</template>

<style scoped>
.cmt { position: relative; display: grid; grid-template-columns: 20px minmax(0, 1fr); column-gap: 8px; padding: 14px 16px 14px 12px; border-bottom: 1px solid var(--line); list-style: none; }
.cmt.is-focused { background: var(--raised); }
.cmt-rail { position: absolute; left: 21px; top: 30px; bottom: -14px; width: 1.5px; background: var(--line-strong); }
.cmt:last-child .cmt-rail { bottom: 14px; }
.cmt.is-resolved .cmt-rail { background: var(--line); }
.cmt-bead { position: relative; z-index: 1; margin-top: 3px; }
.cmt-bead[data-state='hollow'] { background-color: var(--sheet); }
.cmt-main { display: grid; grid-template-columns: minmax(0, 1fr); gap: 8px; min-width: 0; }
.cmt-head { display: flex; align-items: center; gap: 8px; min-height: 28px; border-radius: var(--r-2); outline-offset: 2px; }
.cmt-head:focus-visible { outline: 2px solid var(--focus); }
.cmt-av { --s: 24px; font-size: var(--t-xs); color: var(--ink); border: 0; }
.cmt-who { display: grid; min-width: 0; flex: 1; line-height: 1.25; }
.cmt-name, .cmr-name { font-size: var(--t-sm); font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cmt-you { font-weight: 400; color: var(--muted); }
.cmt-when { color: var(--muted); }
.cmt-new { flex: none; padding: 1px 6px; border-radius: var(--r-1); background: var(--ink); color: var(--sheet); }
.cmt-state { flex: none; color: var(--muted); }
.cmt-scope { max-width: 100%; min-width: 0; justify-self: start; cursor: pointer; font-family: var(--f-ui); font-weight: 500; font-size: var(--t-xs); }
.cmt-scope:hover:not(:disabled) { border-color: var(--ink); color: var(--ink); }
.cmt-scope:disabled { cursor: default; }
.cmt-scope-t { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cmt-body { margin: 0; font-size: var(--t-sm); line-height: 1.5; white-space: pre-wrap; overflow-wrap: anywhere; color: var(--ink); }
.cmt.is-resolved .cmt-body { color: var(--ink-2); }
.cmt-men { font-weight: 600; background: var(--raised); border-radius: var(--r-1); padding: 0 3px; box-shadow: inset 0 -1.5px 0 var(--line-strong); }
.cmt-acts { display: flex; flex-wrap: wrap; gap: 2px 14px; align-items: center; }
.cmt-act { min-height: 28px; padding: 0; border: 0; background: none; color: var(--ink-2); font-size: var(--t-sm); font-weight: 500; text-decoration: underline; text-decoration-color: var(--line-strong); text-underline-offset: 3px; }
.cmt-act:hover:not(:disabled) { color: var(--ink); text-decoration-color: var(--ink); }
.cmt-act:disabled { opacity: 0.45; }
.cmt-act--danger:hover:not(:disabled) { color: var(--bad); text-decoration-color: var(--bad); }
.cmt-err { margin: 0; display: flex; gap: 8px; align-items: flex-start; font-size: var(--t-sm); line-height: 1.4; }
.cmt-err .pr-bead { margin-top: 5px; flex: none; }
.cmt-pending { margin: 0; color: var(--muted); }
.cmt-confirm { display: grid; gap: 8px; padding: 10px 12px; border: 1px solid var(--line-strong); border-radius: var(--r-2); background: var(--raised); }
.cmt-confirm p { margin: 0; }
.cmt-replies { margin: 0; padding: 0; display: grid; gap: 12px; list-style: none; }
.cmr { position: relative; display: grid; gap: 4px; padding-left: 0; min-width: 0; }
.cmr-bead { position: absolute; left: -23px; top: 5px; background-color: var(--sheet); z-index: 1; }
.cmr-head { display: flex; align-items: baseline; gap: 8px; min-width: 0; }
.cmr.is-pending .cmt-body { color: var(--muted); }
.cmt-reply, .cmt-edit { padding-top: 2px; }
@media (pointer: coarse) { .cmt-act { min-height: 44px; display: inline-flex; align-items: center; } .cmt-head { min-height: 44px; } .cmt-scope { min-height: 36px; } }
</style>
