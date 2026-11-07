<script setup>
// Approval: where the deck stands (draft, in review, approved, changed since approval), who was asked, the reviewer's own
// decision, and the rule that an approval belongs to the exact content that was approved.
import { computed, onMounted, ref, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import { EmptyState, InlineNotice, PanelSection, ProgressBead, Segmented } from '../../../ui/controls';
import { useDeckStatus } from './useDeckStatus.js';
import { usePeople } from '../comments/usePeople.js';
import { timeAgo } from '../comments/commentsModel.js';
import {
    currentRound, decisionPayload, decisionProblem, myPendingReview, requestPayload, reviewState, reviewerChoices, roundSummary, statusMeta, statusSentence,
} from './statusModel.js';

const editor = useEditorContext();
const rootEl = ref(null);
/** The drawer keeps its scroll position between panels; a panel starts at its top. */
onMounted(() => rootEl.value?.closest('.ph')?.scrollTo({ top: 0 }));
const ds = useDeckStatus(editor);
const peopleStore = usePeople(editor);
const canManage = computed(() => editor.can.manage === true);
const meId = editor.me.id;

onMounted(() => { ds.refresh(); peopleStore.ensure(); });

const info = ds.info;
const status = ds.status;
const meta = computed(() => statusMeta(status.value));
const reviews = computed(() => info.value?.reviews || []);
const round = computed(() => currentRound(reviews.value));
const earlier = computed(() => { const ids = new Set(round.value.map((r) => r.id)); return reviews.value.filter((r) => !ids.has(r.id)); });
const summary = computed(() => roundSummary(reviews.value));
const sentence = computed(() => statusSentence(status.value, summary.value));
const mine = computed(() => myPendingReview(reviews.value, meId));
const approvedWhen = computed(() => (status.value === 'approved' && info.value?.approved_at ? `Approved ${timeAgo(info.value.approved_at)}` : ''));

// ---- errors, shared by the three forms ---------------------------------------------------------------------
const problem = ref('');
function fail(e) {
    problem.value = e?.status === 403 ? 'Your role cannot do that on this deck.'
        : e?.status === 429 ? (e.retryAfter ? `Too many requests. Try again in ${e.retryAfter} seconds.` : 'Too many requests. Try again in a moment.')
            : (e?.message || 'That did not work. Try again.');
}

// ---- my decision -------------------------------------------------------------------------------------------
const decision = ref('approved');
const decisionNote = ref('');
const decisionError = ref('');
const deciding = ref(false);
watch(mine, () => { decision.value = 'approved'; decisionNote.value = ''; decisionError.value = ''; });
async function submitDecision() {
    decisionError.value = decisionProblem(decision.value, decisionNote.value);
    if (decisionError.value || !mine.value) return;
    deciding.value = true;
    problem.value = '';
    try {
        const res = await editor.api.post(`/api/decks/${editor.deckId}/reviews/${mine.value.id}/decision`, decisionPayload(decision.value, decisionNote.value));
        ds.set(res);
        const word = decision.value === 'approved' ? 'You approved this version' : 'You asked for changes';
        editor.toast(word, { kind: 'ok' });
        editor.announce(word);
    } catch (e) { fail(e); } finally { deciding.value = false; }
}

// ---- asking for a review -----------------------------------------------------------------------------------
const chosen = ref(new Set());
const askNote = ref('');
const asking = ref(false);
const choices = computed(() => reviewerChoices(peopleStore.people.value, reviews.value, meId));
const noOne = computed(() => peopleStore.status.value === 'ready' && choices.value.length === 0);
function toggleChoice(id) {
    const next = new Set(chosen.value);
    if (next.has(id)) next.delete(id); else next.add(id);
    chosen.value = next;
}
async function ask() {
    if (!chosen.value.size) { problem.value = 'Choose at least one reviewer.'; return; }
    asking.value = true;
    problem.value = '';
    try {
        const res = await editor.api.post(`/api/decks/${editor.deckId}/reviews`, requestPayload([...chosen.value], askNote.value));
        ds.set(res);
        const n = chosen.value.size;
        editor.toast(n === 1 ? 'Review requested' : `${n} reviews requested`, { kind: 'ok' });
        editor.announce('Review requested');
        chosen.value = new Set();
        askNote.value = '';
    } catch (e) { fail(e); } finally { asking.value = false; }
}

// ---- withdraw ----------------------------------------------------------------------------------------------
const withdrawing = ref(null);
async function withdraw(r) {
    withdrawing.value = r.id;
    problem.value = '';
    try {
        await editor.api.delete(`/api/decks/${editor.deckId}/reviews/${r.id}`);
        await ds.refresh();
        editor.announce('Review request withdrawn');
    } catch (e) { fail(e); } finally { withdrawing.value = null; }
}

const initials = (n) => String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');
</script>

<template>
    <div ref="rootEl" class="apv">
        <header class="apv-head">
            <span class="apv-bead pr-bead" :data-state="meta.bead" :data-evidence="meta.evidence" aria-hidden="true"></span>
            <div class="apv-head-t">
                <p class="apv-label"><span class="apv-name">{{ meta.label }}</span><span class="pr-state apv-word">{{ meta.word }}</span></p>
                <p class="apv-sentence" role="status">{{ sentence }}</p>
                <p v-if="approvedWhen" class="pr-state apv-when">{{ approvedWhen }}</p>
            </div>
        </header>

        <div v-if="ds.loading.value && !info" class="apv-pad"><ProgressBead :value="null" label="Loading reviews" /></div>
        <div v-else-if="ds.error.value && !info" class="apv-pad">
            <InlineNotice kind="error" title="Reviews did not load">{{ ds.error.value }}</InlineNotice>
            <button type="button" class="pr-btn pr-btn--sm" @click="ds.refresh()">Try again</button>
        </div>

        <template v-else>
            <div v-if="problem" class="apv-pad apv-pad--tight"><InlineNotice kind="error">{{ problem }}</InlineNotice></div>

            <PanelSection v-if="mine" id="approval.decide" title="Your decision" state="asked of you" :collapsible="false">
                <p v-if="mine.note" class="apv-quote"><span class="pr-state">{{ mine.requested_by.name }} wrote</span>{{ mine.note }}</p>
                <Segmented v-model="decision" label="Decision" :options="[{ value: 'approved', label: 'Approve' }, { value: 'changes_requested', label: 'Request changes' }]" />
                <div class="apv-field">
                    <label class="c-label" for="ap-dnote">{{ decision === 'approved' ? 'Note (optional)' : 'What should change' }}</label>
                    <textarea id="ap-dnote" v-model="decisionNote" class="pr-field" rows="3" maxlength="2000" :aria-invalid="!!decisionError" :aria-describedby="decisionError ? 'ap-derr' : undefined"></textarea>
                    <p v-if="decisionError" id="ap-derr" class="c-error" role="alert"><span class="pr-bead pr-bead--bad pr-bead--sm" aria-hidden="true"></span>{{ decisionError }}</p>
                </div>
                <p class="c-hint">Your decision applies to this exact version. If the deck changes after you decide, it no longer counts.</p>
                <button type="button" class="pr-btn pr-btn--primary" :disabled="deciding" @click="submitDecision">{{ deciding ? 'Sending' : (decision === 'approved' ? 'Approve this version' : 'Send requested changes') }}</button>
            </PanelSection>

            <PanelSection id="approval.reviews" title="Reviews" :state="round.length ? `${summary.approved} of ${summary.total} approved` : ''" :collapsible="false">
                <EmptyState v-if="!round.length" title="No review has been asked for." :body="canManage ? 'Ask people below. They are told, and their decision is kept with this version.' : 'An owner or administrator can ask people to review.'" compact />
                <ul v-else class="apv-list">
                    <li v-for="r in round" :key="r.id" class="apv-rev">
                        <span class="pr-bead" :data-state="reviewState(r).bead" :data-evidence="reviewState(r).evidence" aria-hidden="true"></span>
                        <div class="apv-rev-b">
                            <p class="apv-rev-h"><span class="apv-rev-n">{{ r.reviewer.name }}<span v-if="r.reviewer.id === meId" class="apv-you"> (you)</span></span><span class="pr-state apv-rev-s">{{ reviewState(r).word }}</span></p>
                            <p class="apv-rev-p">{{ reviewState(r).phrase }}<template v-if="r.decided_at">, {{ timeAgo(r.decided_at) }}</template></p>
                            <p v-if="r.decision_note" class="apv-quote apv-quote--inline">{{ r.decision_note }}</p>
                            <p v-else-if="r.status === 'pending' && r.note" class="apv-quote apv-quote--inline"><span class="pr-state">{{ r.requested_by.name }} asked</span>{{ r.note }}</p>
                            <button v-if="canManage && r.status === 'pending'" type="button" class="apv-link" :disabled="withdrawing === r.id" @click="withdraw(r)">{{ withdrawing === r.id ? 'Withdrawing' : 'Withdraw request' }}</button>
                        </div>
                    </li>
                </ul>
            </PanelSection>

            <PanelSection v-if="canManage" id="approval.ask" title="Ask for a review" :default-open="!round.length || status !== 'in_review'">
                <p v-if="peopleStore.status.value === 'loading'" class="pr-small pr-muted">Loading people</p>
                <InlineNotice v-else-if="peopleStore.status.value === 'error'" kind="error">{{ peopleStore.error.value }} <button type="button" class="apv-link" @click="peopleStore.refresh()">Try again</button></InlineNotice>
                <template v-else-if="noOne">
                    <p class="pr-small">Nobody else can be asked yet. A reviewer must be able to comment on the deck, so share it with them first.</p>
                    <button v-if="editor.hasPanel('share')" type="button" class="pr-btn pr-btn--sm" @click="editor.openPanel('share')">Open sharing</button>
                </template>
                <template v-else>
                    <fieldset class="apv-who">
                        <legend class="c-label">Reviewers</legend>
                        <label v-for="c in choices" :key="c.value" class="apv-pick" :class="{ 'is-on': chosen.has(c.value) }">
                            <input type="checkbox" class="apv-cb" :checked="chosen.has(c.value)" @change="toggleChoice(c.value)" />
                            <span class="pr-avatar apv-av" aria-hidden="true">{{ initials(c.label) }}</span>
                            <span class="apv-pick-n">{{ c.label }}</span>
                            <span class="pr-state apv-pick-r">{{ c.hint }}</span>
                        </label>
                    </fieldset>
                    <div class="apv-field">
                        <label class="c-label" for="ap-note">Note for the reviewers (optional)</label>
                        <textarea id="ap-note" v-model="askNote" class="pr-field" rows="2" maxlength="2000" placeholder="What should they look at?"></textarea>
                    </div>
                    <button type="button" class="pr-btn pr-btn--primary" :disabled="asking || !chosen.size" @click="ask">{{ asking ? 'Sending' : (chosen.size > 1 ? `Ask ${chosen.size} people` : 'Ask for review') }}</button>
                </template>
            </PanelSection>
            <p v-else class="apv-pad apv-pad--tight pr-small pr-muted">Only an owner or an administrator can ask for a review.</p>

            <PanelSection v-if="earlier.length" id="approval.earlier" title="Earlier rounds" :state="String(earlier.length)" :default-open="false">
                <ul class="apv-list">
                    <li v-for="r in earlier" :key="r.id" class="apv-rev">
                        <span class="pr-bead" :data-state="reviewState(r).bead" :data-evidence="reviewState(r).evidence" aria-hidden="true"></span>
                        <div class="apv-rev-b">
                            <p class="apv-rev-h"><span class="apv-rev-n">{{ r.reviewer.name }}</span><span class="pr-state apv-rev-s">round {{ r.round }}</span></p>
                            <p class="apv-rev-p">{{ reviewState(r).phrase }}<template v-if="r.decided_at">, {{ timeAgo(r.decided_at) }}</template></p>
                            <p v-if="r.decision_note" class="apv-quote apv-quote--inline">{{ r.decision_note }}</p>
                        </div>
                    </li>
                </ul>
            </PanelSection>

            <PanelSection id="approval.how" title="How approval works" :default-open="status === 'changed_since_approval' || status === 'draft'">
                <p class="apv-how">An approval belongs to the exact content that was approved. Change anything afterwards, even one word, and the deck goes back to Draft and reads <strong>Changed since approval</strong> until it is reviewed again.</p>
                <p class="apv-how">Restoring an earlier version counts as a change, except the version named Approved: it is exactly the content that was approved, so restoring it brings the Approved status back. Approving the deck stores that version, so you can always return to it.</p>
            </PanelSection>
        </template>
    </div>
</template>

<style scoped>
.apv { display: flex; flex-direction: column; min-height: 100%; }
.apv-head { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 12px; align-items: start; padding: 18px 16px 16px; border-bottom: 1px solid var(--line); }
.apv-bead { --b: 16px; margin-top: 4px; }
.apv-label { margin: 0; display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; }
.apv-name { font-family: var(--f-display); font-weight: 600; font-size: var(--t-lg); letter-spacing: -0.02em; line-height: 1.2; }
.apv-word { color: var(--muted); }
.apv-sentence { margin: 4px 0 0; font-size: var(--t-sm); color: var(--ink-2); line-height: 1.4; }
.apv-when { margin: 6px 0 0; color: var(--muted); }
.apv-pad { padding: 16px; display: grid; gap: 12px; justify-items: start; }
.apv-pad--tight { padding: 12px 16px 0; justify-items: stretch; }
.apv-field { display: grid; gap: 6px; }
.apv-field textarea { width: 100%; font-size: var(--t-sm); }
.apv-quote { margin: 0; padding: 8px 10px; border-left: 2px solid var(--line-strong); background: var(--raised); font-size: var(--t-sm); line-height: 1.45; white-space: pre-wrap; overflow-wrap: anywhere; }
.apv-quote .pr-state { display: block; color: var(--muted); margin-bottom: 2px; }
.apv-quote--inline { margin-top: 2px; }
.apv-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 14px; position: relative; }
.apv-rev { position: relative; display: grid; grid-template-columns: 12px minmax(0, 1fr); gap: 10px; }
.apv-rev:not(:last-child)::before { content: ''; position: absolute; left: 5.25px; top: 18px; bottom: -14px; width: 1.5px; background: var(--line-strong); }
.apv-rev .pr-bead { margin-top: 3px; position: relative; z-index: 1; }
.apv-rev .pr-bead[data-state='hollow'] { background-color: var(--sheet); }
.apv-rev-b { display: grid; gap: 2px; min-width: 0; }
.apv-rev-h { margin: 0; display: flex; justify-content: space-between; gap: 8px; align-items: baseline; }
.apv-rev-n { font-size: var(--t-sm); font-weight: 600; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.apv-you { font-weight: 400; color: var(--muted); }
.apv-rev-s { color: var(--muted); flex: none; }
.apv-rev-p { margin: 0; font-size: var(--t-sm); color: var(--ink-2); line-height: 1.4; }
.apv-link { justify-self: start; min-height: 28px; padding: 0; border: 0; background: none; color: var(--ink-2); font-size: var(--t-sm); font-weight: 500; text-decoration: underline; text-decoration-color: var(--line-strong); text-underline-offset: 3px; }
.apv-link:hover:not(:disabled) { color: var(--ink); text-decoration-color: var(--ink); }
.apv-link:disabled { opacity: 0.5; }
.apv-who { margin: 0; padding: 0; border: 0; display: grid; gap: 4px; max-height: 240px; overflow-y: auto; }
.apv-who legend { padding: 0; margin-bottom: 4px; }
.apv-pick { display: grid; grid-template-columns: 18px 24px minmax(0, 1fr) auto; align-items: center; gap: 8px; min-height: 40px; padding: 4px 8px; border: 1px solid var(--line); border-radius: var(--r-2); background: var(--sheet); cursor: pointer; }
.apv-pick:hover { border-color: var(--line-strong); }
.apv-pick.is-on { border-color: var(--ink); background: var(--raised); }
.apv-pick:focus-within { outline: 2px solid var(--focus); outline-offset: 1px; }
.apv-cb { width: 16px; height: 16px; margin: 0; accent-color: var(--ink); }
.apv-cb:focus-visible { outline: none; }
.apv-av { --s: 24px; border: 0; font-size: var(--t-xs); color: var(--ink); }
.apv-pick-n { font-size: var(--t-sm); font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.apv-pick-r { color: var(--muted); }
.apv-how { margin: 0; font-size: var(--t-sm); line-height: 1.5; color: var(--ink-2); }
@media (pointer: coarse) { .apv-pick { min-height: 48px; } .apv-link { min-height: 44px; } }
</style>
