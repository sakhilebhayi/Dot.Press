<script setup>
// The storytelling assistant: which narrative structure the deck resembles, which beats are present, weak or missing, a
// checklist a listener would apply, and the argument spine. Fixed local rules; everything it proposes waits for a click.
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import { InlineNotice, PanelSection, ProgressBead, SelectMenu } from '../../../ui/controls';
import { createIntel, describeError, frameworkOptions } from '../intel/api.js';
import { fixPreview, normalizeOps } from '../intel/opsText.js';
import { createSettler } from '../intel/settler.js';
import ModeChip from '../intel/ModeChip.vue';
import '../intel/intel.css';
import SpineEditor from './SpineEditor.vue';
import { beadRows, checkRows, checkSummary, coverageCount, detection, proposalTitle, transitionRows, BEAD_STATE } from './storyModel.js';

const editor = useEditorContext();
const { store, state } = editor;
const intel = createIntel(editor.api, editor.deckId);

const story = shallowRef(null);
const frameworks = ref([]);
const choice = ref('auto');
const status = ref('loading');
const error = ref(null);
const sync = ref({ stale: false, waiting: false, running: false });
const spineKey = ref(0);
const applied = ref(new Set());
let token = 0;
let alive = true;

const doc = computed(() => state.value.doc);
const canEdit = computed(() => !editor.readOnly && !!editor.can.update);
const options = computed(() => frameworkOptions(frameworks.value));
const det = computed(() => detection(story.value));
const beads = computed(() => beadRows(story.value, doc.value));
const checks = computed(() => checkRows(story.value, doc.value));
const transitions = computed(() => transitionRows(story.value, doc.value));
const found = computed(() => coverageCount(story.value));
const proposals = computed(() => (story.value?.proposals || []).filter((p) => !applied.value.has(p.id)).map((p) => ({ ...p, title: proposalTitle(p), preview: fixPreview(p.ops, doc.value) })));
const settled = () => ['saved', 'readonly'].includes(editor.saveInfo.value.key);

async function run() {
    const mine = ++token;
    try {
        const r = await intel.story(choice.value);
        if (!alive || mine !== token) return;
        story.value = r;
        applied.value = new Set();
        error.value = null;
        status.value = 'ready';
        spineKey.value += 1;
    } catch (e) {
        if (!alive || mine !== token) return;
        error.value = describeError(e);
        status.value = story.value ? 'ready' : 'error';
    }
}
const settler = createSettler({ run, delay: 4000, isSettled: settled, onState: (s) => { sync.value = s; } });

onMounted(() => {
    intel.frameworks().then((l) => { if (alive) frameworks.value = l; }).catch(() => {});
    if (settled()) settler.now(); else settler.touch();
});
watch(() => state.value.doc, () => settler.touch());
watch(() => editor.saveInfo.value.key, () => settler.settledChanged());
onBeforeUnmount(() => { alive = false; settler.stop(); });

function choose(v) {
    choice.value = v;
    status.value = story.value ? 'ready' : 'loading';
    settler.now();
}
const jump = (id) => store.setSlide(String(id));

function apply(p) {
    if (!canEdit.value) return;
    const r = store.dispatch(normalizeOps(p.ops), { label: p.title });
    if (!r) { editor.toast('That change no longer applies. The deck has changed since the check.', { kind: 'warn' }); settler.touch(); return; }
    applied.value = new Set([...applied.value, p.id]);
    editor.toast(`${p.title}: done`, { kind: 'ok', action: { label: 'Undo', run: () => store.undo() } });
    editor.announce(`${p.title}: done`);
}
</script>

<template>
    <div class="iq iq-story">
        <header class="iq-head">
            <div class="iq-head-row">
                <h2 class="iq-title">Story</h2>
                <ModeChip mode="local" />
            </div>
            <p class="iq-lead">Fixed rules compare your slides with a story structure. They suggest and never force a shape on your deck.</p>
            <SelectMenu :model-value="choice" label="Structure" :options="options" :disabled="status === 'loading'" @update:model-value="choose" />
            <p class="iq-status pr-small pr-muted" role="status">
                <ProgressBead v-if="sync.running || status === 'loading'" :value="null" label="Reading your story" />
                <span v-else-if="sync.waiting">Waiting for your changes to save, then checking again.</span>
                <span v-else-if="sync.stale">You changed the deck. Checking again in a moment.</span>
            </p>
        </header>

        <div v-if="status === 'error'" class="iq-pad">
            <InlineNotice kind="error" title="The story could not be read">{{ error?.message }} <button type="button" class="pr-btn pr-btn--sm iq-retry" @click="settler.now()">Try again</button></InlineNotice>
        </div>

        <template v-if="story">
            <div v-if="error" class="iq-pad"><InlineNotice kind="warn" title="Showing the last reading">{{ error.message }}</InlineNotice></div>

            <section class="iq-detect" aria-label="Detected structure">
                <p class="iq-detect-s">{{ det.headline }}</p>
                <p class="iq-why">{{ det.body }}</p>
                <div v-for="n in story.notes" :key="n" class="pr-small pr-muted">{{ n }}</div>
                <div v-if="det.offerFree && choice !== 'free'" class="iq-prop-acts"><button type="button" class="pr-btn pr-btn--sm" @click="choose('free')">Use the free structure</button></div>
                <div v-if="story.alternatives?.length > 1 && choice === 'auto'" class="iq-chips" aria-label="Other structures that fit">
                    <button v-for="a in story.alternatives.filter((x) => x.id !== story.framework.id)" :key="a.id" type="button" class="pr-btn pr-btn--sm iq-alt" :title="`${Math.round(a.coverage * 100)} percent of its beats found`" @click="choose(a.id)">{{ a.name }}</button>
                </div>
            </section>

            <PanelSection id="story.beats" title="Beats" :state="`${found.found} of ${found.total}`">
                <ol class="iq-beats iq-bleed">
                    <li v-for="b in beads" :key="b.id" class="iq-beat">
                        <span class="iq-beat-rail"><span class="pr-bead" :class="{ 'pr-bead--quiet': b.status === 'missing' }" :data-state="BEAD_STATE[b.status]" aria-hidden="true"></span></span>
                        <div class="iq-beat-body">
                            <div class="iq-beat-top"><span class="iq-beat-l">{{ b.label }}</span><span class="iq-beat-w pr-state">{{ b.word }}<template v-if="b.optional">, optional</template></span></div>
                            <p v-if="b.status !== 'present'" class="iq-beat-q">{{ b.question }}</p>
                            <div v-if="b.slides.length" class="iq-chips"><button v-for="s in b.slides" :key="s.id" type="button" class="iq-slide-chip" :aria-label="`Go to slide ${s.no}`" @click="jump(s.id)">{{ s.no }}</button></div>
                        </div>
                    </li>
                </ol>
            </PanelSection>

            <PanelSection id="story.checks" title="Checklist" :state="checkSummary(checks)">
                <ul class="iq-check iq-bleed">
                    <li v-for="c in checks" :key="c.id">
                        <div class="iq-check-top">
                            <span class="iq-check-l">{{ c.label }}</span>
                            <span class="iq-pass pr-state"><span :class="c.pass ? 'iq-pass-shape' : 'iq-gap-shape'" aria-hidden="true"></span>{{ c.word }}</span>
                        </div>
                        <p class="iq-check-d">{{ c.detail }}</p>
                        <div v-if="!c.pass && c.slides.length" class="iq-chips"><button v-for="s in c.slides" :key="s.id" type="button" class="iq-slide-chip" @click="jump(s.id)">Look at slide {{ s.no }}</button></div>
                    </li>
                </ul>
            </PanelSection>

            <PanelSection id="story.proposals" title="Proposals" :state="proposals.length">
                <p v-if="!proposals.length" class="pr-small pr-muted">Nothing to propose. Every beat the structure needs has a slide, and the order follows it.</p>
                <article v-for="p in proposals" :key="p.id" class="iq-prop is-preview iq-bleed">
                    <div class="iq-prop-h"><h4 class="iq-prop-t">{{ p.title }}</h4></div>
                    <p class="iq-prop-s">{{ p.summary }}</p>
                    <ul class="iq-pm"><li v-for="(l, i) in p.preview.lines" :key="i">{{ l }}</li></ul>
                    <div class="iq-prop-acts"><button type="button" class="pr-btn pr-btn--primary pr-btn--sm" :disabled="!canEdit" :title="canEdit ? '' : 'You can read this deck, not change it'" @click="apply(p)">{{ p.kind === 'reorder' ? 'Reorder slides' : 'Add the slide' }}</button></div>
                </article>
                <template v-if="transitions.length">
                    <h4 class="iq-sub">Slides that do not lead into each other</h4>
                    <ul class="iq-check iq-bleed">
                        <li v-for="t in transitions" :key="t.key">
                            <p class="iq-check-d"><strong>{{ t.question }}</strong></p>
                            <p class="iq-check-d">{{ t.reason }}</p>
                            <div class="iq-chips"><button type="button" class="iq-slide-chip" @click="jump(t.from.id)">Slide {{ t.from.no }}</button><button type="button" class="iq-slide-chip" @click="jump(t.to.id)">Slide {{ t.to.no }}</button></div>
                        </li>
                    </ul>
                </template>
            </PanelSection>

            <PanelSection id="story.spine" title="Argument spine" :state="canEdit ? 'editable' : 'read only'">
                <SpineEditor :intel="intel" :refresh-key="spineKey" @applied="settler.touch()" />
            </PanelSection>
        </template>
    </div>
</template>
