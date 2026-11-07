<script setup>
// The 52 px top bar: way back, the deck title, the save bead, who else is here, and the deck level actions.
import { computed, nextTick, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useEditorContext } from '../context.js';
import DropMenu from './DropMenu.vue';
import Ico from './Ico.vue';
import ThemeToggle from './ThemeToggle.vue';
import ApprovalChip from '../panels/approval/ApprovalChip.vue';
import { shortcutsFor } from '../core/keys.js';

const editor = useEditorContext();
const { ui, store } = editor;
const emit = defineEmits(['toggle-spine']);

const editing = ref(false);
const draft = ref('');
const input = ref(null);
const title = computed(() => editor.deck.value.title || 'Untitled presentation');
async function startRename() {
    if (editor.readOnly) return;
    draft.value = editor.deck.value.title;
    editing.value = true;
    await nextTick();
    input.value?.focus();
    input.value?.select();
}
function commit() {
    if (!editing.value) return;
    editing.value = false;
    if (editor.renameDeck(draft.value)) editor.announce('Deck renamed');
}
function cancel() { editing.value = false; }

const save = editor.saveInfo;
function onSaveAction() {
    const a = save.value.action;
    if (a === 'retry') editor.retrySave();
    else if (a === 'discard') editor.discardUnsent();
    else if (a === 'reload') window.location.reload();
}
const actionWord = computed(() => ({ retry: 'Retry', discard: 'Discard it', reload: 'Reload' })[save.value.action] || '');

// people here: me first, then the others, one avatar per person
const people = computed(() => {
    const seen = new Set([editor.me.id]);
    const list = [{ id: editor.me.id, name: editor.me.name, color: 'var(--amber)', me: true }];
    for (const p of editor.presence.value) {
        if (seen.has(p.user_id)) continue;
        seen.add(p.user_id);
        list.push({ id: p.user_id, name: p.name, color: p.color, me: false });
    }
    return list;
});
const shown = computed(() => people.value.slice(0, 4));
const more = computed(() => Math.max(0, people.value.length - 4));
const initials = (n) => String(n || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('');
const peopleLabel = computed(() => (people.value.length === 1 ? 'Only you are here' : `${people.value.length} people here: ${people.value.map((p) => (p.me ? 'you' : p.name)).join(', ')}`));

const mod = editor.mac ? '⌘' : 'Ctrl+';
const presentItems = computed(() => [
    { label: 'Present here', icon: 'present', shortcut: shortcutsFor('present.start', editor.mac)[0], run: () => editor.present('here') },
    { label: 'Present from the first slide', icon: 'present', shortcut: shortcutsFor('present.fromStart', editor.mac)[0], run: () => editor.present('start') },
    { label: 'Presenter view', icon: 'user', run: () => editor.present('presenter') },
    { separator: true },
    { label: 'Copy audience link', icon: 'link', run: () => editor.copyAudienceLink() },
]);
const openExport = (format, section = null) => editor.openPanel('export', { format, section });
const exportItems = computed(() => [
    { label: 'PDF', icon: 'document', run: () => openExport('pdf') },
    { label: 'PowerPoint', icon: 'document', run: () => openExport('pptx') },
    { label: 'Present package (offline)', icon: 'download', run: () => openExport('package') },
    { label: 'Images (PNG)', icon: 'image', run: () => openExport('images') },
    { label: 'Print view', icon: 'folder', run: () => openExport('print') },
    { separator: true },
    { label: 'Import PowerPoint', icon: 'upload', run: () => openExport('pdf', 'import') },
]);
const overflowItems = computed(() => [
    { label: ui.inspector.open ? 'Close the inspector' : 'Inspector', icon: 'inspector', run: () => editor.toggleInspector() },
    ...(editor.hasPanel('share') ? [{ label: 'Share', icon: 'share', run: () => editor.openPanel('share') }] : []),
    ...(editor.hasPanel('review') ? [{ label: 'Review', icon: 'review', run: () => editor.openPanel('review') }] : []),
    ...(canExport.value ? [{ label: 'Export', icon: 'export', run: () => openExport('pdf') }] : []),
    ...(editor.hasPanel('approval') ? [{ label: 'Approval', icon: 'review', run: () => editor.openPanel('approval') }] : []),
    { separator: true },
    { label: 'Keyboard shortcuts', icon: 'help', run: () => { ui.help = true; } },
]);
const canExport = computed(() => editor.can.export !== false);
const canPresent = computed(() => editor.can.present !== false);
</script>

<template>
    <header class="tb">
        <div class="tb-left">
            <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon tb-menu" :aria-expanded="ui.spine.open" aria-controls="ed-spine" aria-label="Show slides" @click="emit('toggle-spine')"><Ico name="menu" /></button>
            <Link :href="route('dashboard')" class="tb-mark" aria-label="Back to the library" data-tip="Library">
                <svg class="tb-dot" viewBox="0 0 32 12" aria-hidden="true"><line x1="0" y1="6" x2="32" y2="6" stroke="currentColor" stroke-width="1.5" /><circle cx="9" cy="6" r="4.25" fill="var(--amber)" stroke="currentColor" stroke-width="1.5" /></svg>
                <span class="tb-word">Press</span>
            </Link>
        </div>

        <div class="tb-title">
            <input v-if="editing" ref="input" v-model="draft" class="tb-input" maxlength="255" aria-label="Presentation title" @keydown.enter.prevent="commit" @keydown.esc.prevent="cancel" @blur="commit" />
            <button v-else type="button" class="tb-name" :disabled="editor.readOnly" :aria-label="editor.readOnly ? `Title: ${title}` : `Title: ${title}. Click to rename`" @click="startRename">{{ title }}</button>
            <span class="tb-save" :class="`is-${save.key}`" role="status" :title="save.detail || undefined">
                <span class="pr-bead pr-bead--sm" :class="{ 'pr-bead--ok': save.tone === 'ok', 'pr-bead--busy': save.tone === 'busy', 'pr-bead--warn': save.tone === 'warn', 'pr-bead--bad': save.tone === 'bad', 'pr-bead--quiet': save.tone === 'quiet' }" aria-hidden="true"></span>
                <span class="tb-save-w pr-state">{{ save.label }}</span>
                <button v-if="save.action" type="button" class="tb-retry" @click="onSaveAction">{{ actionWord }}</button>
            </span>
        </div>

        <div class="tb-right">
            <ul class="tb-people" :aria-label="peopleLabel">
                <li v-for="p in shown" :key="p.id" class="pr-avatar" :style="{ '--c': p.color }" :title="p.me ? `${p.name} (you)` : p.name"><span aria-hidden="true">{{ initials(p.name) }}</span><span class="pr-sr">{{ p.me ? `${p.name}, you` : p.name }}</span></li>
                <li v-if="more" class="pr-avatar tb-more" :title="`${more} more`">+{{ more }}</li>
            </ul>
            <ApprovalChip />
            <button type="button" class="pr-btn tb-act tb-insp" :aria-pressed="ui.inspector.open" aria-controls="ed-insp" aria-label="Inspector" data-tip="Inspector" @click="editor.toggleInspector()"><Ico name="inspector" size="sm" /><span class="tb-lbl">Inspector</span></button>
            <button v-if="editor.hasPanel('share')" type="button" class="pr-btn tb-act" @click="editor.openPanel('share')"><Ico name="share" size="sm" /><span class="tb-lbl">Share</span></button>
            <button v-if="editor.hasPanel('review')" type="button" class="pr-btn tb-act" @click="editor.openPanel('review')"><Ico name="review" size="sm" /><span class="tb-lbl">Review</span></button>
            <DropMenu v-if="canExport" :items="exportItems" align="end" label="Export">
                <template #trigger="{ toggle, open }">
                    <button type="button" class="pr-btn tb-act" aria-haspopup="menu" :aria-expanded="open" @click="toggle"><Ico name="export" size="sm" /><span class="tb-lbl">Export</span></button>
                </template>
            </DropMenu>
            <span v-if="canPresent" class="tb-present">
                <button type="button" class="pr-btn pr-btn--primary tb-go" @click="editor.present('here')"><Ico name="present" size="sm" /><span>Present</span></button>
                <DropMenu :items="presentItems" align="end" label="Present options">
                    <template #trigger="{ toggle, open }">
                        <button type="button" class="pr-btn pr-btn--primary tb-more-btn" aria-haspopup="menu" :aria-expanded="open" aria-label="More ways to present" @click="toggle"><Ico name="chevronDown" size="sm" /></button>
                    </template>
                </DropMenu>
            </span>
            <DropMenu :items="overflowItems" align="end" label="More">
                <template #trigger="{ toggle, open }">
                    <button type="button" class="pr-btn pr-btn--ghost pr-btn--icon tb-overflow" aria-haspopup="menu" :aria-expanded="open" aria-label="More actions" @click="toggle"><Ico name="more" /></button>
                </template>
            </DropMenu>
            <ThemeToggle class="tb-theme" />
        </div>
    </header>
</template>
