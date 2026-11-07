<script setup>
// Import a .pptx as a NEW deck (the current deck is never touched). A drop zone, the server's report grouped by slide,
// and a link to the new deck.
import { computed, ref } from 'vue';
import { useEditorContext } from '../../context.js';
import { InlineNotice, ProgressBead } from '../../../ui/controls/index.js';
import Ico from '../../ui/Ico.vue';
import { describeError } from './exportModel.js';
import { groupReport, importCounts, importHeadline } from './importReport.js';

const LIMIT_MB = 60;
const editor = useEditorContext();
const input = ref(null);
const over = ref(false);
const phase = ref('idle'); // idle | uploading | reading | done | failed
const sent = ref(0);
const error = ref('');
const result = ref(null);
const fileName = ref('');

const groups = computed(() => groupReport(result.value?.report));
const counts = computed(() => importCounts(result.value?.report));
const busy = computed(() => phase.value === 'uploading' || phase.value === 'reading');
const openUrl = computed(() => (result.value?.deck?.id ? `/decks/${result.value.deck.id}` : null));

function pick() { input.value?.click(); }
function onPick(e) {
    const f = e.target.files?.[0];
    e.target.value = '';
    if (f) start(f);
}
function onDrop(e) {
    over.value = false;
    const f = [...(e.dataTransfer?.files || [])][0];
    if (f) start(f);
}

async function start(file) {
    if (busy.value) return;
    result.value = null;
    error.value = '';
    fileName.value = file.name;
    if (!/\.pptx$/i.test(file.name)) {
        phase.value = 'failed';
        error.value = 'Choose a PowerPoint file ending in .pptx. Older .ppt files need to be saved as .pptx in PowerPoint first.';
        return;
    }
    if (file.size > LIMIT_MB * 1048576) {
        phase.value = 'failed';
        error.value = `That file is larger than ${LIMIT_MB} MB, which is the most that can be imported.`;
        return;
    }
    phase.value = 'uploading';
    sent.value = 0;
    const body = new FormData();
    body.append('file', file);
    try {
        const res = await editor.api.post('/api/imports/pptx', body, {
            timeout: 240000,
            onUploadProgress: (p) => {
                if (p.total) sent.value = p.loaded / p.total;
                if (p.total && p.loaded >= p.total) phase.value = 'reading';
            },
        });
        result.value = res;
        phase.value = 'done';
        editor.announce(importHeadline(res));
        editor.toast(importHeadline(res), { kind: 'ok' });
    } catch (e) {
        phase.value = 'failed';
        error.value = describeError(e, 'import this file');
    }
}
function again() { phase.value = 'idle'; result.value = null; error.value = ''; }
</script>

<template>
    <div class="xp-imp">
        <p class="xp-p">Make a new deck from a PowerPoint file. This deck stays as it is.</p>

        <div v-if="!busy && phase !== 'done'" class="xp-drop" :class="{ 'is-over': over }" @dragenter.prevent="over = true" @dragover.prevent="over = true" @dragleave.prevent="over = false" @drop.prevent="onDrop">
            <Ico name="upload" />
            <p class="xp-drop-t">Drop a .pptx file here</p>
            <button type="button" class="pr-btn pr-btn--sm" @click="pick">Choose a file</button>
            <input ref="input" class="pr-sr" type="file" accept=".pptx,application/vnd.openxmlformats-officedocument.presentationml.presentation" tabindex="-1" aria-label="PowerPoint file" @change="onPick" />
        </div>

        <div v-if="busy" class="xp-busy">
            <ProgressBead :value="phase === 'uploading' ? sent : null" :label="`Importing ${fileName}`" :word="phase === 'uploading' ? `sending ${Math.round(sent * 100)}%` : 'reading'" />
            <p class="xp-p pr-muted">{{ phase === 'uploading' ? `Sending ${fileName}.` : 'Reading the slides. Big files take a minute.' }}</p>
        </div>

        <InlineNotice v-if="phase === 'failed'" kind="error" title="The file was not imported">
            {{ error }}
            <template v-if="fileName"> <button type="button" class="xp-linkbtn" @click="again">Choose another file</button></template>
        </InlineNotice>

        <div v-if="phase === 'done' && result" class="xp-done">
            <InlineNotice kind="ok" :title="importHeadline(result)">
                <span v-if="counts.unsupported || counts.approximated">{{ counts.unsupported }} left out, {{ counts.approximated }} approximated.</span>
                <span v-else>Everything carried over.</span>
            </InlineNotice>
            <div class="xp-acts">
                <a :href="openUrl" class="pr-btn pr-btn--primary pr-btn--sm">Open the new deck</a>
                <button type="button" class="pr-btn pr-btn--sm" @click="again">Import another</button>
            </div>
            <div v-if="groups.length" class="xp-report">
                <h4 class="xp-h pr-small">What changed</h4>
                <ul class="xp-groups">
                    <li v-for="g in groups" :key="g.key" class="xp-g">
                        <p class="xp-g-h pr-state">{{ g.label }}</p>
                        <ul class="xp-g-l">
                            <li v-for="i in g.items" :key="i.kind + i.message">
                                <span class="xp-mark" :data-level="i.kind === 'unsupported' ? 'dropped' : 'approximated'" aria-hidden="true"></span>
                                <span class="xp-g-t"><span class="pr-state">{{ i.word }}</span> {{ i.message }}<span v-if="i.count > 1" class="xp-count pr-state"> x{{ i.count }}</span></span>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>

        <details class="xp-limits">
            <summary class="pr-small">What does not come across</summary>
            <ul>
                <li>Animations. Slide transitions are kept as a fade.</li>
                <li>SmartArt becomes plain shapes, so the layout is no longer a SmartArt layout.</li>
                <li>OLE objects (embedded Excel or Word), macros and comments are left out.</li>
                <li>Video and audio are left out; a video's poster picture is kept.</li>
                <li>Files over {{ LIMIT_MB }} MB are refused.</li>
            </ul>
        </details>
    </div>
</template>
