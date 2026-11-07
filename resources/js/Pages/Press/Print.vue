<script setup>
// Print view: every slide in order, one per page, each page exactly the slide size, drawn by the same renderer as the
// editor so the browser's "Save as PDF" gives selectable text and the exact fonts. The toolbar is for the screen only
// and disappears in print. Printing starts from a button, never by itself.
import '../../../css/press/tokens.css';
import '../../../css/press/fonts-ui.css';
import '../../../css/press/base.css';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import SlideView from '../../press/render/SlideView.vue';
import { ensureFonts } from '../../press/fonts.js';
import { fitScale, notesLayout, pageRule, plan, printHint } from '../../press/export/printLayout.js';

const props = defineProps({
    deck: { type: Object, required: true },
    slides: { type: Array, required: true },
    assets: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({ hidden: false, notes: false }) },
    backUrl: { type: String, default: '/dashboard' },
});

const W = computed(() => Math.max(1, Math.round(+props.deck?.w || 1920)));
const H = computed(() => Math.max(1, Math.round(+props.deck?.h || 1080)));
const pages = computed(() => plan(props.slides, { notes: !!props.options?.notes }).map((p, i, all) => ({
    ...p,
    key: `${p.kind}-${p.slide.id}`,
    last: i === all.length - 1,
    layout: p.kind === 'notes' ? notesLayout(p.slide.notes, W.value, H.value) : null,
})));
const slideCount = computed(() => props.slides.length);
const hint = computed(() => printHint(props.options));

// ---- the page size rule, which depends on the deck ----
let styleEl = null;
function applyPageRule() {
    if (!styleEl) {
        styleEl = document.createElement('style');
        styleEl.setAttribute('data-print-page', '');
        document.head.appendChild(styleEl);
    }
    styleEl.textContent = pageRule(W.value, H.value);
}

// ---- on screen scale ----
const stageEl = ref(null);
const scale = ref(1);
let ro = null;
function measure() {
    const el = stageEl.value;
    if (!el) return;
    const cs = getComputedStyle(el);
    scale.value = fitScale(el.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight), W.value);
}

// ---- ready: fonts and pictures loaded, so what prints is what is shown ----
const status = ref('loading'); // loading | ready | late
async function settle() {
    const started = Date.now();
    const pending = () => [...document.images].filter((i) => !i.complete).length;
    try { await document.fonts?.ready; } catch { /* no font API */ }
    while (pending() > 0 && Date.now() - started < 20000) await new Promise((r) => setTimeout(r, 150));
    await new Promise((r) => setTimeout(r, 250));
    try { await document.fonts?.ready; } catch { /* no font API */ }
    status.value = pending() > 0 ? 'late' : 'ready';
}

onMounted(() => {
    ensureFonts();
    applyPageRule();
    measure();
    if (typeof ResizeObserver !== 'undefined') {
        ro = new ResizeObserver(measure);
        ro.observe(stageEl.value);
    }
    settle();
});
onBeforeUnmount(() => {
    ro?.disconnect();
    styleEl?.remove();
    styleEl = null;
});

function print() {
    window.print();
}

const statusWord = computed(() => ({ loading: 'loading', ready: 'ready', late: 'ready, some pictures still loading' })[status.value]);
const statusText = computed(() => ({
    loading: 'Loading fonts and pictures so the print matches the screen.',
    ready: `${pages.value.length} ${pages.value.length === 1 ? 'page' : 'pages'}, ${W.value} by ${H.value}.`,
    late: 'Some pictures did not finish loading. You can still print; they may be missing.',
})[status.value]);
</script>

<template>
    <Head :title="`${deck.title || 'Presentation'} (print)`" />
    <div class="pr prt">
        <header class="prt-bar">
            <div class="prt-id">
                <p class="prt-title">{{ deck.title || 'Untitled presentation' }}</p>
                <p class="prt-meta">
                    <span class="pr-bead pr-bead--sm" :class="{ 'pr-bead--ok': status !== 'loading', 'pr-bead--busy': status === 'loading' }" aria-hidden="true"></span>
                    <span class="pr-state">{{ statusWord }}</span>
                    <span class="prt-meta-t" role="status">{{ statusText }}</span>
                </p>
            </div>
            <div class="prt-act">
                <a :href="backUrl" class="pr-btn">Back to editor</a>
                <button type="button" class="pr-btn pr-btn--primary" :disabled="slideCount === 0" @click="print">Print or save as PDF</button>
            </div>
            <p class="prt-hint">{{ hint }}</p>
        </header>

        <main ref="stageEl" class="prt-stage" aria-label="Pages to print" :style="{ '--s': scale, '--pw': W + 'px', '--ph': H + 'px' }">
            <p v-if="slideCount === 0" class="prt-empty">There are no slides to print. Hidden slides are left out unless you choose to include them.</p>
            <section
                v-for="p in pages" :key="p.key" class="prt-sheet" :class="{ 'is-last': p.last, 'is-notes': p.kind === 'notes' }"
                :style="{ '--sw': W * scale + 'px', '--sh': H * scale + 'px' }" :aria-label="p.kind === 'notes' ? `Notes for slide ${p.number}` : `Slide ${p.number}`"
            >
                <p class="prt-no pr-state"><span>{{ p.kind === 'notes' ? `notes ${p.number}` : p.number }}</span><span v-if="p.slide.hidden" class="prt-flag">hidden</span></p>
                <div class="prt-page">
                    <SlideView v-if="p.kind === 'slide'" :slide="p.slide" :deck="deck" :assets="assets" mode="view" eager :active="false" :allow-embeds="false" />
                    <div v-else class="prt-notes" :style="{ '--ns': p.layout.size + 'px', '--np': W * 0.07 + 'px', '--nt': H * 0.07 + 'px' }">
                        <p class="prt-notes-k">Speaker notes, slide {{ p.number }}</p>
                        <h2 class="prt-notes-h">{{ p.slide.title || 'Untitled slide' }}</h2>
                        <p class="prt-notes-b">{{ p.layout.text }}<template v-if="p.layout.truncated"> (The rest of these notes does not fit one page.)</template></p>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<style>
/* screen */
.prt { min-height: 100vh; background: var(--paper); color: var(--ink); font-family: var(--f-ui); }
.prt-bar { position: sticky; top: 0; z-index: 5; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px 16px; align-items: center; padding: 10px 16px; background: var(--sheet); border-bottom: 1px solid var(--line); }
.prt-id { min-width: 0; }
.prt-title { margin: 0; font-family: var(--f-display); font-weight: 600; font-size: var(--t-lg); letter-spacing: -0.02em; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.prt-meta { margin: 2px 0 0; display: flex; align-items: center; gap: 8px; min-width: 0; color: var(--muted); font-size: var(--t-sm); }
.prt-meta-t { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.prt-act { display: flex; gap: 8px; }
.prt-hint { grid-column: 1 / -1; margin: 0; font-size: var(--t-sm); color: var(--muted); max-width: 70ch; }
.prt-stage { padding: 24px 16px 64px; display: grid; justify-items: center; gap: 28px; }
.prt-empty { margin: 48px 0; color: var(--muted); max-width: 48ch; text-align: center; }
.prt-sheet { width: var(--sw); }
.prt-no { margin: 0 0 6px; display: flex; gap: 8px; align-items: baseline; color: var(--muted); }
.prt-flag { color: var(--warn); }
.prt-page { position: relative; width: var(--sw); height: var(--sh); box-shadow: var(--sheet-shadow); background: #fff; overflow: hidden; }
.prt-page > * { position: absolute; left: 0; top: 0; width: var(--pw); height: var(--ph); transform: scale(var(--s)); transform-origin: 0 0; }
.prt-page .ps-wrap { height: var(--ph); }
.prt-notes { background: #fff; color: #14171a; font-family: 'Public Sans', ui-sans-serif, system-ui, sans-serif; padding: calc(var(--nt) * 1.2) var(--np) var(--nt); box-sizing: border-box; overflow: hidden; }
.prt-notes-k { margin: 0 0 8px; font-size: calc(var(--ns) * 0.5); letter-spacing: 0.02em; color: #5b6269; }
.prt-notes-h { margin: 0 0 calc(var(--ns) * 0.8); font-size: calc(var(--ns) * 1.2); font-weight: 700; line-height: 1.15; }
.prt-notes-b { margin: 0; font-size: var(--ns); line-height: 1.45; white-space: pre-wrap; overflow-wrap: anywhere; }
@media (max-width: 640px) {
    .prt-bar { grid-template-columns: 1fr; }
    .prt-act { flex-wrap: wrap; }
    .prt-act .pr-btn { flex: 1 1 140px; }
}

/* print: the toolbar and labels go, each sheet is exactly one page of the slide's own size */
@media print {
    html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
    .prt { background: #fff; min-height: 0; }
    .prt-bar, .prt-no, .prt-empty { display: none !important; }
    .prt-stage { display: block; padding: 0; }
    .prt-sheet { width: var(--pw); height: var(--ph); break-after: page; page-break-after: always; break-inside: avoid; }
    .prt-sheet.is-last { break-after: auto; page-break-after: auto; }
    .prt-page { width: var(--pw); height: var(--ph); box-shadow: none; }
    .prt-page > * { transform: none; }
    .prt, .prt * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .prt video::-webkit-media-controls { display: none !important; }
}
</style>
