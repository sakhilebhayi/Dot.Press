<script setup>
// Export: PDF, PowerPoint, the offline present package, PNG images and the print view, plus importing a PowerPoint file.
//
// PDF and PowerPoint are server jobs: options, then a PREFLIGHT that says what becomes of every element, then the
// export, then progress until the file really exists. Nothing says "ready" before the download link does.
import './export.css';
import { computed, onBeforeUnmount, reactive, ref, shallowRef, watch } from 'vue';
import { useEditorContext } from '../../context.js';
import { docText } from '../../core/index.js';
import { EmptyState, InlineNotice, NumberField, PanelSection, ProgressBead, Segmented, Toggle } from '../../../ui/controls/index.js';
import { lsGet, lsSet } from '../../../ui/controls/persist.js';
import { downloadUrl, formatBytes, saveBlob } from '../../../export/download.js';
import { exportImages, pickSlides } from '../../../export/exportImages.js';
import { safeFileName } from '../../../export/zip.js';
import { clampScale } from '../../../export/svgSlide.js';
import FormatList from './FormatList.vue';
import PreflightView from './PreflightView.vue';
import ImportBox from './ImportBox.vue';
import { FORMATS, defaultForm, describeError, elementName, expiryWord, preflightOptions, preflightView, printUrl, rangeToIds, requestOptions } from './exportModel.js';
import { initialState, reduce, runExport, workingMessage, workingWord } from './exportJob.js';

const props = defineProps({ panelProps: { type: Object, default: null } });
const editor = useEditorContext();
const deckId = editor.deckId;

const ICONS = { pdf: 'document', pptx: 'present', package: 'download', images: 'image', print: 'folder' };
const formats = FORMATS.map((f) => ({ ...f, icon: ICONS[f.id] }));
const format = ref('pdf');
const importKey = ref(0);
// The top bar's Export menu opens this panel on a format (and, for "Import PowerPoint", with that section open).
function applyProps(p) {
    if (p?.format && FORMATS.some((f) => f.id === p.format)) format.value = p.format;
    if (p?.section === 'import') {
        lsSet('press:panel:sections', { ...lsGet('press:panel:sections', {}), 'export.import': true });
        importKey.value++;
    }
}
applyProps(props.panelProps);
watch(() => props.panelProps, applyProps);
const current = computed(() => FORMATS.find((f) => f.id === format.value));
const isJob = computed(() => !!current.value?.job);
const canExport = computed(() => editor.can.export !== false);

const slides = computed(() => editor.state.value.doc.slides);
const form = reactive(defaultForm(editor.state.value.doc.slides.length));
const count = computed(() => slides.value.length);
const hiddenCount = computed(() => slides.value.filter((s) => s.hidden).length);
watch(count, (n) => { if (form.to > n || form.to < form.from) form.to = n; if (form.from > n) form.from = 1; });
const rangeIds = computed(() => (form.range === 'range' ? rangeToIds(slides.value, form.from, form.to) : null));
const rangeWord = computed(() => {
    const ids = rangeIds.value;
    if (!ids) return `all ${count.value} ${count.value === 1 ? 'slide' : 'slides'}`;
    return `${ids.length} of ${count.value} slides`;
});

// ---- PDF and PowerPoint jobs ----------------------------------------------------------------------------------
const job = shallowRef(initialState());
const jobFormat = ref(null); // the format the current job state belongs to
let abort = null;
const jobBusy = computed(() => job.value.phase === 'starting' || job.value.phase === 'working');
const showJob = computed(() => jobFormat.value === format.value);
const view = computed(() => {
    const pf = job.value.preflight;
    if (!pf || jobFormat.value !== format.value) return null;
    return preflightView(pf, { format: format.value, range: format.value === 'pdf' ? rangeIds.value : null, lookup });
});
const formatLabel = computed(() => (format.value === 'pptx' ? 'PowerPoint file' : 'PDF'));

function lookup(slideId, elId) {
    const s = slides.value.find((x) => x.id === slideId);
    const el = s?.els?.find((x) => x.id === elId);
    return elementName(el, (e) => (e.doc ? docText(e.doc) : e.chart?.opt?.title || ''));
}

watch(() => [format.value, form.includeHidden, form.notes, form.range, form.from, form.to], () => {
    if (jobFormat.value && !jobBusy.value) job.value = reduce(job.value, { type: 'stale' });
});
watch(format, () => { pkg.phase = 'idle'; });

async function check() {
    if (jobBusy.value) return;
    jobFormat.value = format.value;
    job.value = reduce(initialState(), { type: 'check' });
    try {
        await editor.settle();
        const pf = await editor.api.post(`/api/decks/${deckId}/exports/preflight`, { format: format.value, options: preflightOptions(format.value, form) });
        job.value = reduce(job.value, { type: 'checked', preflight: pf });
        editor.announce('Check finished');
    } catch (e) {
        job.value = reduce(job.value, { type: 'checkFailed', error: describeError(e, 'check this export') });
    }
}

async function start() {
    if (jobBusy.value) return;
    const fmt = format.value;
    const keep = job.value.preflight;
    jobFormat.value = fmt;
    abort = new AbortController();
    await editor.settle();
    const end = await runExport({
        api: editor.api,
        deckId,
        format: fmt,
        options: requestOptions(fmt, form, slides.value),
        sleep: (ms) => new Promise((r) => window.setTimeout(r, ms)),
        signal: abort.signal,
        describe: (e) => describeError(e, 'export this deck'),
        onState: (s) => { job.value = { ...s, preflight: keep }; },
    });
    job.value = { ...end, preflight: keep };
    if (end.phase === 'done') {
        editor.announce(`Your ${formatLabel.value} is ready to download`);
        editor.toast(`${fmt === 'pptx' ? 'PowerPoint file' : 'PDF'} ready`, { kind: 'ok' });
    } else if (end.phase === 'failed') {
        editor.announce(end.error);
    }
    loadRecent();
}

async function download(url) {
    try {
        const r = await downloadUrl(url);
        editor.toast(`Saved ${r.name} (${formatBytes(r.size)})`, { kind: 'ok' });
    } catch (e) {
        editor.toast(describeError(e, 'download this file'), { kind: 'error' });
        loadRecent();
    }
}

// ---- recent exports -------------------------------------------------------------------------------------------
const recent = shallowRef([]);
const recentState = ref('idle'); // idle | loading | error
const recentError = ref('');
async function loadRecent() {
    recentState.value = 'loading';
    try {
        const res = await editor.api.get(`/api/decks/${deckId}/exports`);
        recent.value = res.data || [];
        recentState.value = 'idle';
    } catch (e) {
        recentState.value = 'error';
        recentError.value = describeError(e, 'list recent exports');
    }
}
if (canExport.value) loadRecent();
const FORMAT_NAME = { pdf: 'PDF', pptx: 'PowerPoint' };
const STATUS_WORD = { queued: 'queued', running: 'building', done: 'ready', failed: 'failed', expired: 'expired' };
const when = (iso) => (iso ? new Date(iso).toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '');

// ---- present package ------------------------------------------------------------------------------------------
const pkg = reactive({ phase: 'idle', error: '', result: null, notes: true });
async function makePackage() {
    if (pkg.phase === 'working') return;
    pkg.phase = 'working';
    pkg.error = '';
    try {
        await editor.settle();
        const r = await downloadUrl(`/api/decks/${deckId}/present-package?notes=${pkg.notes ? 1 : 0}`, { fallbackName: `${safeFileName(editor.deck.value.title, 'presentation')}.html` });
        pkg.result = r;
        pkg.phase = 'done';
        editor.announce(`Saved ${r.name}`);
    } catch (e) {
        pkg.phase = 'failed';
        pkg.error = e.status === 503
            ? 'The offline player is not built on this server yet, so a package cannot be made. Whoever runs this server needs to run the front-end build.'
            : describeError(e, 'make the package');
    }
}

// ---- images (made in this browser) ----------------------------------------------------------------------------
const img = reactive({ phase: 'idle', done: 0, total: 0, number: null, error: '', warnings: [], failed: [], blob: null, name: '', count: 0 });
let imgAbort = null;
const imgBusy = computed(() => img.phase === 'working');
const sizeNote = computed(() => {
    const d = editor.deck.value;
    const { scale, reduced } = clampScale(d.w, d.h, form.scale);
    return `${Math.round(d.w * scale)} by ${Math.round(d.h * scale)} pixels${reduced ? ' (reduced to fit browser limits)' : ''}`;
});
async function makeImages() {
    if (imgBusy.value) return;
    img.phase = 'working';
    img.error = '';
    img.blob = null;
    img.warnings = [];
    img.failed = [];
    img.done = 0;
    imgAbort = new AbortController();
    try {
        await editor.settle();
        const picked = pickSlides(slides.value, { range: rangeIds.value, includeHidden: form.includeHidden });
        if (!picked.length) throw new Error('There are no slides to export. Hidden slides are left out unless you include them.');
        img.total = picked.length;
        const { createSlideRenderer } = await import('../../../export/slideRaster.js');
        const renderer = createSlideRenderer({ deck: editor.deck.value, assets: { ...editor.assets }, signal: imgAbort.signal });
        const out = await exportImages({
            slides: picked,
            scale: form.scale,
            renderSlide: (slide, o) => renderer.render(slide, o),
            signal: imgAbort.signal,
            onProgress: (p) => { img.done = p.done; img.total = p.total; img.number = p.number; },
        });
        img.blob = new Blob(out.parts, { type: 'application/zip' });
        img.name = `${safeFileName(editor.deck.value.title, 'deck')}-slides.zip`;
        img.count = out.count;
        img.warnings = out.warnings;
        img.failed = out.failed;
        img.phase = 'done';
        editor.announce(`${out.count} ${out.count === 1 ? 'picture' : 'pictures'} ready`);
    } catch (e) {
        if (e?.name === 'AbortError') {
            img.phase = 'cancelled';
            editor.announce('Image export cancelled');
        } else {
            img.phase = 'failed';
            img.error = e?.message || 'The pictures could not be made.';
        }
    }
}
function cancelImages() { imgAbort?.abort(); }
function saveImages() {
    if (img.blob) saveBlob(img.blob, img.name);
}
const imgProgress = computed(() => (img.total ? img.done / img.total : null));
const warningGroups = computed(() => {
    const m = new Map();
    for (const w of img.warnings) {
        if (!m.has(w.text)) m.set(w.text, []);
        m.get(w.text).push(w.number);
    }
    return [...m].map(([text, nums]) => ({ text, slides: nums.length > 3 ? `${nums.slice(0, 3).join(', ')} and ${nums.length - 3} more` : nums.join(', ') }));
});

// ---- print view -----------------------------------------------------------------------------------------------
const prn = reactive({ hidden: false, notes: false, blocked: false });
const prnUrl = computed(() => printUrl(deckId, prn));
async function openPrint() {
    prn.blocked = false;
    const w = window.open('about:blank', '_blank');
    await editor.settle();
    if (w) {
        try { w.opener = null; } catch { /* cross origin guard */ }
        w.location.href = prnUrl.value;
    } else {
        prn.blocked = true;
        editor.toast('Your browser blocked the new tab. Use the link below the button.', { kind: 'warn' });
    }
}

onBeforeUnmount(() => { abort?.abort(); imgAbort?.abort(); });
</script>

<template>
    <div class="xp">
        <EmptyState v-if="!canExport" title="Exporting is off for you." body="You can view this deck, but the owner has not given you export rights." compact />
        <template v-else>
            <PanelSection id="export.format" title="Export as" :collapsible="false">
                <FormatList v-model="format" :options="formats" />
            </PanelSection>

            <PanelSection :id="`export.${format}`" :title="current.label" :collapsible="false">
                <!-- PDF and PowerPoint -->
                <template v-if="isJob">
                    <div class="c-stack xp-opts">
                        <template v-if="format === 'pdf'">
                            <Segmented v-model="form.range" label="Slides" :options="[{ value: 'all', label: 'All slides' }, { value: 'range', label: 'A range' }]" :disabled="jobBusy" />
                            <div v-if="form.range === 'range'" class="c-grid2">
                                <NumberField v-model="form.from" label="From" :min="1" :max="count" :disabled="jobBusy" />
                                <NumberField v-model="form.to" label="To" :min="1" :max="count" :disabled="jobBusy" />
                            </div>
                            <Toggle v-model="form.includeHidden" label="Include hidden slides" :hint="hiddenCount ? `${hiddenCount} hidden in this deck.` : 'No slide is hidden in this deck.'" :disabled="jobBusy" />
                            <Toggle v-model="form.notes" label="Speaker notes pages" hint="A notes page follows each slide that has notes." :disabled="jobBusy" />
                        </template>
                        <p v-else class="xp-p pr-muted">PowerPoint always gets every slide. Hidden slides stay hidden in the file, and speaker notes come with them.</p>
                    </div>

                    <div class="xp-acts">
                        <button type="button" class="pr-btn" :class="{ 'pr-btn--primary': !view }" :disabled="jobBusy || (showJob && job.phase === 'checking')" @click="check">{{ view ? 'Check again' : 'Check before exporting' }}</button>
                    </div>
                    <p v-if="!view && !(showJob && job.phase !== 'idle')" class="xp-p pr-muted">The check lists what becomes of every element in {{ format === 'pptx' ? 'PowerPoint' : 'the PDF' }}, with the reason, before anything is written.</p>

                    <ProgressBead v-if="showJob && job.phase === 'checking'" label="Checking the deck" word="checking" />
                    <InlineNotice v-if="showJob && job.phase === 'idle' && job.error" kind="error" title="The check did not finish">{{ job.error }}</InlineNotice>

                    <template v-if="view">
                        <InlineNotice :kind="format === 'pptx' && view.animated > 0 ? 'warn' : 'info'" :title="view.topNote" />
                        <PreflightView :view="view" />
                        <p v-if="view.fontsNote" class="xp-p">{{ view.fontsNote }}</p>
                        <ul v-if="view.notes.length" class="xp-gen"><li v-for="n in view.notes" :key="n">{{ n }}</li></ul>
                        <p v-if="view.total === 0" class="xp-p pr-muted">There is nothing to export with these options.</p>
                    </template>

                    <!-- export and progress -->
                    <div v-if="view && !(showJob && (job.phase === 'working' || job.phase === 'starting' || job.phase === 'done'))" class="xp-acts">
                        <button type="button" class="pr-btn pr-btn--primary" :disabled="jobBusy || view.total === 0" @click="start">Export {{ format === 'pdf' ? 'PDF' : 'PowerPoint' }}<template v-if="format === 'pdf'">, {{ rangeWord }}</template></button>
                    </div>

                    <div v-if="showJob && (job.phase === 'working' || job.phase === 'starting')" class="xp-busy">
                        <ProgressBead :label="`Exporting ${formatLabel}`" :word="workingWord(job)" />
                        <p class="xp-p" role="status">{{ workingMessage(job, formatLabel) }}</p>
                    </div>

                    <div v-if="showJob && job.phase === 'done' && job.job" class="xp-done">
                        <InlineNotice kind="ok" :title="`Your ${formatLabel} is ready`">
                            {{ formatBytes(job.job.size) }}. {{ expiryWord(job.job.expires_at) }}.
                        </InlineNotice>
                        <div class="xp-acts">
                            <button type="button" class="pr-btn pr-btn--primary" @click="download(job.job.download_url)">Download</button>
                            <button type="button" class="pr-btn" @click="start">Export again</button>
                        </div>
                    </div>

                    <div v-if="showJob && job.phase === 'failed'" class="xp-fail">
                        <InlineNotice kind="error" title="The export did not finish">{{ job.error }}</InlineNotice>
                        <div class="xp-acts"><button v-if="job.retryable" type="button" class="pr-btn pr-btn--primary" @click="start">Retry</button></div>
                    </div>
                </template>

                <!-- Present package -->
                <template v-else-if="format === 'package'">
                    <p class="xp-p">One HTML file that plays your deck with no internet: the slides, the pictures and the fonts are all inside it. Open it in any browser, on any machine. It plays in the same player as Present.</p>
                    <Toggle v-model="pkg.notes" label="Include speaker notes" hint="Notes can be shown over the slide while presenting. Leave this off before you send the file to the audience." :disabled="pkg.phase === 'working'" />
                    <div class="xp-acts"><button type="button" class="pr-btn pr-btn--primary" :disabled="pkg.phase === 'working'" @click="makePackage">{{ pkg.phase === 'working' ? 'Building the package' : 'Download the package' }}</button></div>
                    <ProgressBead v-if="pkg.phase === 'working'" label="Building the present package" word="building" />
                    <InlineNotice v-if="pkg.phase === 'done' && pkg.result" kind="ok" title="Saved">{{ pkg.result.name }}, {{ formatBytes(pkg.result.size) }}.<template v-if="pkg.result.warnings"> {{ pkg.result.warnings }} {{ pkg.result.warnings === 1 ? 'item was' : 'items were' }} replaced by a link or left out because they need the internet or were too large.</template></InlineNotice>
                    <InlineNotice v-if="pkg.phase === 'failed'" kind="error" title="No package was made">{{ pkg.error }}</InlineNotice>
                </template>

                <!-- Images -->
                <template v-else-if="format === 'images'">
                    <p class="xp-p">One PNG per slide, drawn in this browser with the same renderer as the editor, bundled into a ZIP file.</p>
                    <div class="c-stack xp-opts">
                        <Segmented v-model="form.scale" label="Size" :options="[{ value: 1, label: '1x' }, { value: 2, label: '2x' }]" :hint="sizeNote" :disabled="imgBusy" />
                        <Segmented v-model="form.range" label="Slides" :options="[{ value: 'all', label: 'All slides' }, { value: 'range', label: 'A range' }]" :disabled="imgBusy" />
                        <div v-if="form.range === 'range'" class="c-grid2">
                            <NumberField v-model="form.from" label="From" :min="1" :max="count" :disabled="imgBusy" />
                            <NumberField v-model="form.to" label="To" :min="1" :max="count" :disabled="imgBusy" />
                        </div>
                        <Toggle v-model="form.includeHidden" label="Include hidden slides" :hint="hiddenCount ? `${hiddenCount} hidden in this deck.` : 'No slide is hidden in this deck.'" :disabled="imgBusy" />
                    </div>
                    <div class="xp-acts">
                        <button v-if="!imgBusy" type="button" class="pr-btn pr-btn--primary" @click="makeImages">Make pictures, {{ rangeWord }}</button>
                        <button v-else type="button" class="pr-btn" @click="cancelImages">Cancel</button>
                    </div>
                    <div v-if="imgBusy" class="xp-busy">
                        <ProgressBead :value="imgProgress" label="Drawing slides" :word="`${img.done} of ${img.total}`" />
                        <p class="xp-p" role="status">Drawing slide {{ img.number || '' }}. This browser tab has to stay open.</p>
                    </div>
                    <InlineNotice v-if="img.phase === 'cancelled'" kind="info" title="Cancelled">Nothing was saved.</InlineNotice>
                    <InlineNotice v-if="img.phase === 'failed'" kind="error" title="No pictures were made">{{ img.error }}</InlineNotice>
                    <div v-if="img.phase === 'done' && img.blob" class="xp-done">
                        <InlineNotice kind="ok" :title="`${img.count} ${img.count === 1 ? 'picture' : 'pictures'} ready`">{{ img.name }}, {{ formatBytes(img.blob.size) }}.</InlineNotice>
                        <div class="xp-acts"><button type="button" class="pr-btn pr-btn--primary" @click="saveImages">Download ZIP</button></div>
                        <InlineNotice v-if="img.failed.length" kind="warn" :title="`${img.failed.length} ${img.failed.length === 1 ? 'slide' : 'slides'} could not be drawn`">
                            <span v-for="f in img.failed" :key="f.number" class="xp-blk">Slide {{ f.number }}: {{ f.reason }}</span>
                        </InlineNotice>
                        <InlineNotice v-if="warningGroups.length" kind="info" title="Not drawn exactly">
                            <span v-for="g in warningGroups" :key="g.text" class="xp-blk">Slide {{ g.slides }}: {{ g.text }}</span>
                        </InlineNotice>
                    </div>
                    <details class="xp-limits">
                        <summary class="pr-small">What images cannot show</summary>
                        <ul>
                            <li>Video is drawn as its poster frame. Embedded videos and audio are not drawn.</li>
                            <li>Pictures from other websites that do not allow it are left out.</li>
                            <li>Animations show their final state.</li>
                            <li>The browser's picture renderer may differ from the screen on rare effects. Use Print view when it has to be exact.</li>
                        </ul>
                    </details>
                </template>

                <!-- Print view -->
                <template v-else-if="format === 'print'">
                    <p class="xp-p">Opens every slide on its own page, at the slide's own size. Press <strong>Print or save as PDF</strong>, then choose <strong>Save as PDF</strong> in the print dialog. That gives selectable text and the exact fonts, and it is the closest copy of what you see here.</p>
                    <div class="c-stack xp-opts">
                        <Toggle v-model="prn.hidden" label="Include hidden slides" :hint="hiddenCount ? `${hiddenCount} hidden in this deck.` : 'No slide is hidden in this deck.'" />
                        <Toggle v-model="prn.notes" label="Speaker notes pages" hint="A notes page follows each slide that has notes." />
                    </div>
                    <div class="xp-acts"><button type="button" class="pr-btn pr-btn--primary" @click="openPrint">Open print view</button></div>
                    <p v-if="prn.blocked" class="xp-p">Open it yourself: <a class="pr-link" :href="prnUrl" target="_blank" rel="noopener">print view</a>.</p>
                    <p class="xp-p pr-muted">The print view opens in a new tab. Printing never starts by itself.</p>
                </template>
            </PanelSection>

            <PanelSection id="export.import" :key="importKey" title="Import PowerPoint" :default-open="false">
                <ImportBox />
            </PanelSection>

            <PanelSection id="export.recent" title="Recent exports" :state="recent.length ? recent.length : ''" :default-open="false">
                <p v-if="recentState === 'loading' && !recent.length" class="xp-p pr-muted">Loading.</p>
                <InlineNotice v-else-if="recentState === 'error'" kind="error" title="Could not load the list">{{ recentError }} <button type="button" class="xp-linkbtn" @click="loadRecent">Retry</button></InlineNotice>
                <EmptyState v-else-if="!recent.length" title="No exports yet." body="PDF and PowerPoint files you make show here for a day." compact />
                <ul v-else class="xp-recent">
                    <li v-for="r in recent" :key="r.id" class="xp-rc">
                        <span class="xp-rc-t"><span class="xp-rc-f">{{ FORMAT_NAME[r.format] || r.format }}</span><span class="xp-rc-w pr-state">{{ STATUS_WORD[r.status] || r.status }}</span></span>
                        <span class="xp-rc-m pr-muted">{{ when(r.created_at) }}<template v-if="r.status === 'done'">, {{ formatBytes(r.size) }}</template></span>
                        <span v-if="r.status === 'done'" class="xp-rc-m pr-muted">{{ expiryWord(r.expires_at) }}</span>
                        <span v-if="r.status === 'failed' && r.error" class="xp-rc-m">{{ r.error }}</span>
                        <button v-if="r.download_url" type="button" class="pr-btn pr-btn--sm xp-rc-b" @click="download(r.download_url)">Download</button>
                    </li>
                </ul>
                <div class="xp-acts"><button type="button" class="pr-btn pr-btn--ghost pr-btn--sm" :disabled="recentState === 'loading'" @click="loadRecent">Refresh</button></div>
            </PanelSection>
        </template>
    </div>
</template>
