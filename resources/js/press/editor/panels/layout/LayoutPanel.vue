<script setup>
// Slide layouts: the built in library (GET /api/layout-library) drawn as wireframes, the team's saved layouts
// (GET /api/layouts), applying one to this slide or to every slide of the same kind, and saving this slide as a layout.
// Applying never deletes content: matched elements move into the boxes, missing placeholders are added, the rest stays.
import {
    computed, nextTick, ref, shallowRef, watch,
} from 'vue';
import { useEditorContext } from '../../context.js';
import {
    Dialog, EmptyState, Field, IconButton, InlineNotice, PanelSection, ProgressBead,
} from '../../../ui/controls';
import { saveLayoutFromSlide } from '../../core/layouts.js';
import { apiErrorText } from '../theme/themeModel.js';
import { columnsOf, gridTarget } from '../theme/gridNav.js';
import { cachedLibrary } from './libraryCache.js';
import {
    bulkOps, fromCustom, fromLibrary, planLayout, resultLines, sameLayoutSlides, summarise, toServerDefinition, wireframe,
} from './layoutModel.js';
import '../theme/design.css';

const editor = useEditorContext();
const { store } = editor;

const library = shallowRef([]);
const custom = shallowRef([]);
const status = ref('loading');
const error = ref('');
const errorStatus = ref(0);
const customError = ref('');
const limits = ref({ layouts: 60, elements: 40 });
const chosenId = ref(null);
const result = ref(null); // {name, lines}
const confirmAll = ref(false);
const saveName = ref('');
const saveError = ref('');
const saving = ref(false);
const renaming = ref(false);
const renameText = ref('');
const confirmDelete = ref(false);
const busy = ref(false);

const slide = computed(() => editor.slide.value);
const deck = computed(() => editor.deck.value);
const doc = computed(() => editor.state.value.doc);
const canEdit = computed(() => !editor.readOnly && !!editor.can.update);
const aspect = computed(() => `${deck.value.w} / ${deck.value.h}`);

async function load() {
    status.value = 'loading';
    error.value = '';
    customError.value = '';
    const { w, h } = deck.value;
    const [lib, own] = await Promise.allSettled([cachedLibrary(w, h, () => editor.api.get('/api/layout-library', { w, h })), editor.api.get('/api/layouts')]);
    if (lib.status === 'rejected') {
        status.value = 'error';
        errorStatus.value = lib.reason?.status || 0;
        error.value = apiErrorText(lib.reason, 'layouts');
        return;
    }
    library.value = (lib.value.data || []).map((e) => ({ ...fromLibrary(e), description: e.description, evidence: e.evidence, custom: false }));
    if (own.status === 'fulfilled') {
        custom.value = (own.value.data || []).map((p) => ({ ...fromCustom(p), custom: true, dbId: p.db_id, kind: p.kind }));
        if (own.value.limits) limits.value = own.value.limits;
    } else {
        custom.value = [];
        customError.value = apiErrorText(own.reason, 'your team layouts');
    }
    status.value = 'ready';
}
load();
watch(() => [deck.value.w, deck.value.h], () => load());

const all = computed(() => [...library.value, ...custom.value]);
const current = computed(() => all.value.find((l) => l.id === slide.value?.layout) || null);
const chosen = computed(() => all.value.find((l) => l.id === chosenId.value) || null);
const plan = computed(() => (chosen.value && slide.value ? planLayout(doc.value, slide.value.id, chosen.value) : null));
const planSummary = computed(() => (plan.value ? summarise(plan.value, slide.value) : null));
const planLines = computed(() => (planSummary.value ? resultLines(planSummary.value, 'plan') : []));
const sameKind = computed(() => sameLayoutSlides(doc.value, slide.value));
const isCurrentChoice = computed(() => !!chosen.value && slide.value?.layout === chosen.value.id);

function choose(l) { chosenId.value = l.id; result.value = null; renaming.value = false; }
function cancel() { chosenId.value = null; renaming.value = false; }

function applyHere() {
    const def = chosen.value;
    if (!def || !canEdit.value) return;
    const before = planLayout(doc.value, slide.value.id, def);
    const sum = summarise(before, slide.value);
    store.applyLayout(def);
    result.value = { name: def.name, lines: resultLines(sum, 'done') };
    chosenId.value = null;
    editor.toast(`Layout changed to ${def.name}`, { kind: 'ok', action: { label: 'Undo', run: () => { store.undo(); result.value = null; } } });
    editor.announce(`Layout changed to ${def.name}. ${resultLines(sum, 'done').join(' ')}`);
}

function applyAll(close) {
    const def = chosen.value;
    if (!def || !canEdit.value) { close(); return; }
    const ids = sameKind.value.map((s) => s.id);
    const b = bulkOps(doc.value, ids, def);
    if (b.ops.length) store.dispatch(b.ops, { label: `Apply layout to ${ids.length} slides` });
    result.value = { name: def.name, lines: [`${ids.length} ${ids.length === 1 ? 'slide uses' : 'slides use'} ${def.name} now.`, ...resultLines(b.summary, 'done')] };
    chosenId.value = null;
    close();
    editor.toast(`Layout changed on ${ids.length} slides`, { kind: 'ok', action: { label: 'Undo', run: () => { store.undo(); result.value = null; } } });
    editor.announce(`Layout ${def.name} applied to ${ids.length} slides`);
}

// ---- saved layouts ------------------------------------------------------------------------------
const savedDef = computed(() => (slide.value ? saveLayoutFromSlide(slide.value, deck.value, { name: saveName.value.trim() || 'Custom layout' }) : null));
const placeable = computed(() => savedDef.value?.ph?.length || 0);

async function saveAsLayout() {
    saveError.value = '';
    const name = saveName.value.trim();
    if (!name) { saveError.value = 'Give the layout a name.'; return; }
    if (!placeable.value) { saveError.value = 'This slide has nothing to save yet. Add a title, some text or a picture first.'; return; }
    saving.value = true;
    try {
        const definition = toServerDefinition(savedDef.value, limits.value.elements);
        const res = await editor.api.post('/api/layouts', { name, kind: 'custom', definition });
        custom.value = [...custom.value, { ...fromCustom(res.layout), custom: true, dbId: res.layout.db_id, kind: res.layout.kind }];
        saveName.value = '';
        editor.toast(`Saved the layout ${name}`, { kind: 'ok' });
        editor.announce(`Layout ${name} saved to your team layouts`);
    } catch (e) {
        saveError.value = apiErrorText(e, 'layouts');
    } finally {
        saving.value = false;
    }
}

function startRename() { renameText.value = chosen.value.name; renaming.value = true; }
async function doRename() {
    const l = chosen.value;
    const name = renameText.value.trim();
    if (!l || !name) return;
    busy.value = true;
    try {
        const res = await editor.api.put(`/api/layouts/${l.dbId}`, { name });
        custom.value = custom.value.map((c) => (c.dbId === l.dbId ? { ...c, name: res.layout.name } : c));
        renaming.value = false;
        editor.toast(`Renamed to ${res.layout.name}`, { kind: 'ok' });
    } catch (e) {
        editor.toast(apiErrorText(e, 'layouts'), { kind: 'error' });
    } finally { busy.value = false; }
}
async function doDelete() {
    const l = chosen.value;
    if (!l) return;
    busy.value = true;
    try {
        await editor.api.delete(`/api/layouts/${l.dbId}`);
        custom.value = custom.value.filter((c) => c.dbId !== l.dbId);
        chosenId.value = null;
        editor.toast(`Deleted the layout ${l.name}`, { kind: 'info' });
        editor.announce('Layout deleted. Slides that used it keep their content.');
    } catch (e) {
        editor.toast(apiErrorText(e, 'layouts'), { kind: 'error' });
    } finally { busy.value = false; }
}

// ---- keyboard in the grids ----------------------------------------------------------------------
async function onKey(e, list) {
    const cards = [...e.currentTarget.querySelectorAll('[role="radio"]')];
    const i = cards.indexOf(document.activeElement);
    if (i < 0) return;
    const j = gridTarget(e.key, i, cards.length, columnsOf(cards.map((c) => c.getBoundingClientRect().top)));
    if (j === null) return;
    e.preventDefault();
    choose(list[j]);
    await nextTick();
    cards[j].focus();
}
const tabbableId = (list) => (list.some((l) => l.id === chosenId.value) ? chosenId.value : list.some((l) => l.id === slide.value?.layout) ? slide.value.layout : list[0]?.id);
</script>

<template>
    <div class="dz dz-layout">
        <EmptyState v-if="!slide" compact title="No slide selected." body="Pick a slide on the left to change its layout." />
        <template v-else>
            <div v-if="status === 'loading'" class="dz-pad" role="status"><ProgressBead :value="null" label="Loading layouts" /></div>
            <div v-else-if="status === 'error'" class="dz-pad">
                <InlineNotice kind="error" :title="errorStatus === 403 ? 'Layouts are not available to you' : 'Layouts could not be loaded'">
                    {{ error }} <button v-if="errorStatus !== 403" type="button" class="pr-btn pr-btn--sm" @click="load">Try again</button>
                </InlineNotice>
            </div>
            <template v-else>
                <div class="dz-pad c-stack">
                    <p class="pr-small">
                        This slide uses <strong>{{ current?.name || slide.layout || 'no layout' }}</strong>.
                        <span v-if="!current && slide.layout" class="c-hint">That layout is not in the library any more; the slide keeps its content.</span>
                    </p>
                    <InlineNotice v-if="result" kind="ok" :title="`Layout: ${result.name}`">
                        <ul class="dz-result"><li v-for="l in result.lines" :key="l">{{ l }}</li></ul>
                    </InlineNotice>
                    <InlineNotice v-if="customError" kind="warn">{{ customError }}</InlineNotice>
                </div>

                <PanelSection id="layout.library" title="Layouts" :state="String(library.length)" :collapsible="false">
                    <div class="dz-lay-wrap" role="radiogroup" aria-label="Layouts" @keydown="onKey($event, library)">
                        <ul class="dz-lay-grid dz-lay-grid--flush">
                            <li v-for="l in library" :key="l.id">
                                <button
                                    type="button" role="radio" class="dz-lay" :class="{ 'is-candidate': chosenId === l.id }" :aria-checked="chosenId === l.id" :tabindex="tabbableId(library) === l.id ? 0 : -1"
                                    :aria-label="`${l.name}${slide.layout === l.id ? ', used by this slide' : ''}`" :title="l.description" @click="choose(l)"
                                >
                                    <span class="dz-wf" :style="{ aspectRatio: aspect }" aria-hidden="true">
                                        <span v-for="b in wireframe(l.ph)" :key="b.key" class="dz-wf-b" :data-kind="b.kind" :style="{ left: b.left + '%', top: b.top + '%', width: b.width + '%', height: b.height + '%' }"></span>
                                    </span>
                                    <span class="dz-lay-meta">
                                        <span v-if="slide.layout === l.id" class="pr-bead pr-bead--sm" data-state="current" aria-hidden="true"></span>
                                        <span class="dz-lay-name">{{ l.name }}</span>
                                        <span v-if="slide.layout === l.id" class="pr-state">in use</span>
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </PanelSection>

                <PanelSection id="layout.team" title="Your team's layouts" :state="String(custom.length)" :collapsible="false">
                    <EmptyState v-if="!custom.length" compact title="No saved layouts yet." body="Arrange a slide the way you like, then save it below to reuse it on other slides." />
                    <div v-else class="dz-lay-wrap" role="radiogroup" aria-label="Your team's layouts" @keydown="onKey($event, custom)">
                        <ul class="dz-lay-grid dz-lay-grid--flush">
                            <li v-for="l in custom" :key="l.id">
                                <button
                                    type="button" role="radio" class="dz-lay" :class="{ 'is-candidate': chosenId === l.id }" :aria-checked="chosenId === l.id" :tabindex="tabbableId(custom) === l.id ? 0 : -1"
                                    :aria-label="`${l.name}${slide.layout === l.id ? ', used by this slide' : ''}`" @click="choose(l)"
                                >
                                    <span class="dz-wf" :style="{ aspectRatio: aspect }" aria-hidden="true">
                                        <span v-for="b in wireframe(l.ph)" :key="b.key" class="dz-wf-b" :data-kind="b.kind" :style="{ left: b.left + '%', top: b.top + '%', width: b.width + '%', height: b.height + '%' }"></span>
                                    </span>
                                    <span class="dz-lay-meta">
                                        <span v-if="slide.layout === l.id" class="pr-bead pr-bead--sm" data-state="current" aria-hidden="true"></span>
                                        <span class="dz-lay-name">{{ l.name }}</span>
                                        <span v-if="slide.layout === l.id" class="pr-state">in use</span>
                                    </span>
                                </button>
                            </li>
                        </ul>
                    </div>
                </PanelSection>

                <PanelSection id="layout.save" title="Save this slide as a layout" :default-open="false">
                    <p class="c-hint">Keeps the position and style of the title, text and pictures on this slide as boxes. The words stay on this slide.</p>
                    <Field v-slot="{ id, describedby, invalid }" label="Layout name" :error="saveError">
                        <input
                            :id="id" v-model="saveName" type="text" class="pr-field" maxlength="80" placeholder="For example, Site photo and notes" :disabled="!canEdit"
                            :aria-invalid="invalid || undefined" :aria-describedby="describedby" @keydown.enter.prevent="saveAsLayout"
                        />
                    </Field>
                    <div class="dz-actions">
                        <button type="button" class="pr-btn pr-btn--sm" :disabled="!canEdit || saving || custom.length >= limits.layouts" @click="saveAsLayout">{{ saving ? 'Saving' : 'Save layout' }}</button>
                        <span class="c-hint">{{ placeable }} {{ placeable === 1 ? 'box' : 'boxes' }} from this slide</span>
                    </div>
                    <p v-if="custom.length >= limits.layouts" class="c-hint">A team keeps up to {{ limits.layouts }} saved layouts. Delete one first.</p>
                </PanelSection>

                <section v-if="chosen && plan && planSummary" class="dz-bar" :aria-label="`Apply the layout ${chosen.name}`">
                    <p class="dz-bar-h">
                        <span class="dz-bar-n">{{ chosen.name }}</span>
                        <span class="dz-bar-k"><span class="pr-state pr-muted">{{ chosen.custom ? 'team layout' : 'built in' }}</span><IconButton icon="close" label="Cancel" size="sm" @click="cancel" /></span>
                    </p>
                    <p v-if="chosen.description" class="c-hint">{{ chosen.description }}</p>
                    <p v-if="isCurrentChoice && !plan.ops.length" class="pr-small">This slide already follows this layout.</p>
                    <template v-else>
                        <p class="pr-small dz-bar-s">If you apply it to this slide:</p>
                        <ul class="dz-bar-l"><li v-for="l in planLines" :key="l">{{ l }}</li></ul>
                        <p class="c-hint">Nothing is deleted. Text and styling stay as they are.</p>
                    </template>
                    <div v-if="renaming" class="dz-actions">
                        <input v-model="renameText" class="pr-field" maxlength="80" aria-label="New layout name" @keydown.enter.prevent="doRename" @keydown.esc.stop="renaming = false" />
                        <button type="button" class="pr-btn pr-btn--sm pr-btn--primary" :disabled="busy || !renameText.trim()" @click="doRename">Rename</button>
                        <button type="button" class="pr-btn pr-btn--sm pr-btn--ghost" @click="renaming = false">Cancel</button>
                    </div>
                    <div v-else class="dz-bar-a">
                        <button type="button" class="pr-btn pr-btn--sm pr-btn--primary" :disabled="!canEdit || (isCurrentChoice && !plan.ops.length)" @click="applyHere">Apply to this slide</button>
                        <button
                            v-if="sameKind.length > 1" type="button" class="pr-btn pr-btn--sm" :disabled="!canEdit" :aria-label="`Apply to all ${sameKind.length} slides that use ${current?.name || slide.layout}`"
                            :title="`Apply to all ${sameKind.length} slides that use ${current?.name || slide.layout}`" @click="confirmAll = true"
                        >
                            Apply to all {{ sameKind.length }}
                        </button>
                        <template v-if="chosen.custom">
                            <button type="button" class="pr-btn pr-btn--sm pr-btn--ghost" :disabled="!canEdit" @click="startRename">Rename</button>
                            <button type="button" class="pr-btn pr-btn--sm pr-btn--ghost pr-btn--danger" :disabled="!canEdit" @click="confirmDelete = true">Delete</button>
                        </template>
                    </div>
                </section>
            </template>
        </template>

        <Dialog v-model:open="confirmAll" title="Apply to all slides of this kind?" size="sm">
            <p class="pr-small">
                {{ sameKind.length }} slides use <strong>{{ current?.name || slide?.layout }}</strong> right now. Each one gets <strong>{{ chosen?.name }}</strong>:
                matching elements move into place and missing placeholders are added. Nothing is deleted, and you can undo it in one step.
            </p>
            <template #footer="{ close }">
                <button type="button" class="pr-btn" @click="close">Cancel</button>
                <button type="button" class="pr-btn pr-btn--primary" @click="applyAll(close)">Apply to {{ sameKind.length }} slides</button>
            </template>
        </Dialog>
        <Dialog v-model:open="confirmDelete" title="Delete this layout?" size="sm">
            <p class="pr-small">"{{ chosen?.name }}" will be removed from your team's layouts. Slides that use it keep their content and their positions.</p>
            <template #footer="{ close }">
                <button type="button" class="pr-btn" @click="close">Keep it</button>
                <button type="button" class="pr-btn pr-btn--primary pr-btn--danger" @click="doDelete(); close()">Delete layout</button>
            </template>
        </Dialog>
    </div>
</template>
