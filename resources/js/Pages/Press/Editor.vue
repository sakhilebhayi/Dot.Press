<script setup>
// The editor: top bar, Spine, stage, dock, inspector. All state lives in the `editor` object built by useEditor().
import '../../../css/press/tokens.css';
import '../../../css/press/fonts-ui.css';
import '../../../css/press/base.css';
import '../../../css/press/shell.css';
import '../../../css/press/spine.css';
import '../../../css/press/controls.css';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import { useEditor } from '@/press/editor/ui/useEditor.js';
import TopBar from '@/press/editor/ui/TopBar.vue';
import Spine from '@/press/editor/ui/Spine.vue';
import Dock from '@/press/editor/ui/Dock.vue';
import Inspector from '@/press/editor/ui/Inspector.vue';
import ToastHost from '@/press/editor/ui/ToastHost.vue';
import ContextMenuHost from '@/press/editor/ui/ContextMenuHost.vue';
import HelpOverlay from '@/press/editor/ui/HelpOverlay.vue';
import EditorStage from '@/press/editor/stage/EditorStage.vue';

const props = defineProps({
    document: { type: Object, required: true },
    role: { type: String, default: null },
    can: { type: Object, default: () => ({}) },
    me: { type: Object, required: true },
    ai: { type: Object, default: () => ({ mode: 'local' }) },
    startSlide: { type: [String, Number], default: null },
});

const editor = useEditor(props);
const { ui } = editor;

const narrowQuery = typeof window !== 'undefined' ? window.matchMedia('(max-width: 1023px)') : null;
const overlaySpine = () => !!narrowQuery?.matches;
if (overlaySpine()) ui.spine.open = false;
const onNarrow = (e) => { ui.spine.open = !e.matches; };
onMounted(() => narrowQuery?.addEventListener('change', onNarrow));
onBeforeUnmount(() => narrowQuery?.removeEventListener('change', onNarrow));
watch(() => editor.state.value.slideId, () => { if (overlaySpine()) ui.spine.open = false; });

function toggleSpine() { ui.spine.open = !ui.spine.open; }
const spineState = computed(() => (ui.spine.open ? 'open' : 'closed'));
const title = computed(() => editor.deck.value.title || 'Presentation');
</script>

<template>
    <Head :title="title" />
    <div class="pr ed" :data-spine="spineState" :data-inspector="ui.inspector.open ? 'open' : 'closed'" :data-readonly="editor.readOnly ? 'true' : null">
        <a class="pr-skip" href="#ed-stage">Skip to the slide</a>
        <TopBar @toggle-spine="toggleSpine" />

        <div v-if="editor.recovery.value" class="ed-banner" role="alert">
            <p><span class="pr-bead pr-bead--warn" aria-hidden="true"></span>You have unsent changes from an earlier session. Restore or discard.</p>
            <button type="button" class="pr-btn pr-btn--primary pr-btn--sm" @click="editor.restoreRecovery()">Restore</button>
            <button type="button" class="pr-btn pr-btn--sm" @click="editor.discardRecovery()">Discard</button>
        </div>

        <div id="ed-spine" class="ed-spine"><Spine v-show="ui.spine.open" /></div>
        <div v-if="ui.spine.open && overlaySpine()" class="ed-scrim" aria-hidden="true" @click="ui.spine.open = false"></div>

        <main id="ed-stage" class="ed-main" tabindex="-1" aria-label="Slide editor">
            <h1 class="pr-sr">{{ title }}</h1>
            <div class="ed-stage"><EditorStage /></div>
            <Dock />
        </main>

        <div v-if="ui.inspector.open" id="ed-insp" class="ed-insp"><Inspector /></div>

        <ToastHost />
        <ContextMenuHost />
        <HelpOverlay v-if="ui.help" />
        <div class="pr-sr" role="status" aria-live="polite">{{ editor.live.value }}</div>
    </div>
</template>
