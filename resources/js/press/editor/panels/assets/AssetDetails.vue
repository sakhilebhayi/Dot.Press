<script setup>
// Everything about one file: preview, name and alt text (PATCH), where it is used, add or replace, a poster frame for video, delete.
import { computed, ref, watch } from 'vue';
import { Dialog, Field, InlineNotice, ProgressBead } from '../../../ui/controls/index.js';
import Ico from '../../ui/Ico.vue';
import { dimensions, formatBytes, kindOf, usageWords, describeApiError } from './assetsModel.js';

const props = defineProps({
    open: { type: Boolean, default: false },
    asset: { type: Object, default: null },
    canEdit: { type: Boolean, default: false }, // may rename and delete
    canAdd: { type: Boolean, default: false }, // may change the deck
    replaceKind: { type: String, default: null },
    save: { type: Function, required: true },
    loadUsage: { type: Function, required: true },
    setPoster: { type: Function, required: true },
});
const emit = defineEmits(['update:open', 'add', 'replace', 'delete']);
const name = ref('');
const alt = ref('');
const error = ref('');
const saving = ref(false);
const savedWord = ref('');
const usage = ref(null);
const usageError = ref('');
const video = ref(null);
const posterBusy = ref(false);
const posterError = ref('');

const kind = computed(() => (props.asset ? kindOf(props.asset) : 'image'));
const dirty = computed(() => props.asset && (name.value.trim() !== props.asset.name || (alt.value.trim() || '') !== (props.asset.alt || '')));
const facts = computed(() => (props.asset ? [kind.value, formatBytes(props.asset.size), dimensions(props.asset), props.asset.mime].filter(Boolean) : []));
const canReplace = computed(() => props.canAdd && props.replaceKind && props.replaceKind === kind.value);

watch(() => [props.open, props.asset?.id], async ([o]) => {
    if (!o || !props.asset) return;
    name.value = props.asset.name; alt.value = props.asset.alt || ''; error.value = ''; savedWord.value = ''; posterError.value = '';
    usage.value = null; usageError.value = '';
    try { usage.value = await props.loadUsage(props.asset); } catch (e) { usageError.value = describeApiError(e, 'the usage of this file'); }
}, { immediate: true });

async function submit() {
    if (!dirty.value || saving.value) return;
    if (!name.value.trim()) { error.value = 'The name cannot be empty.'; return; }
    saving.value = true; error.value = '';
    try { await props.save(props.asset, { name: name.value.trim(), alt: alt.value.trim() }); savedWord.value = 'saved'; } catch (e) { error.value = e?.status === 422 ? e.message : describeApiError(e, 'this file'); } finally { saving.value = false; }
}

async function capturePoster() {
    const v = video.value;
    if (!v) return;
    posterBusy.value = true; posterError.value = '';
    try {
        const c = document.createElement('canvas');
        c.width = v.videoWidth; c.height = v.videoHeight;
        if (!c.width) throw new Error('The video has not loaded a frame yet. Press play, then pause on the frame you want.');
        c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
        const blob = await new Promise((res, rej) => c.toBlob((b) => (b ? res(b) : rej(new Error('That frame could not be captured.'))), 'image/jpeg', 0.88));
        await props.setPoster(props.asset, blob);
    } catch (e) {
        posterError.value = e?.status ? describeApiError(e, 'the poster') : String(e?.message || 'That frame could not be captured.');
    } finally { posterBusy.value = false; }
}
</script>

<template>
    <Dialog :open="open" :title="asset ? asset.name : 'File'" size="md" @update:open="emit('update:open', $event)">
        <div v-if="asset" class="al-dlg">
            <div class="al-prev">
                <img v-if="kind === 'image'" :src="asset.web || asset.url" :alt="asset.alt || ''" />
                <video v-else-if="kind === 'video'" ref="video" :src="asset.url" :poster="asset.poster?.url || undefined" controls preload="metadata" playsinline></video>
                <audio v-else :src="asset.url" controls preload="none"></audio>
            </div>
            <p class="al-facts pr-state">
                <span v-for="f in facts" :key="f">{{ f }}</span>
            </p>

            <InlineNotice v-if="kind === 'video' && !asset.poster" kind="info" title="No poster frame yet.">
                A poster is the still picture shown before a video plays. Pause the video above on a good frame, then use it.
                <div v-if="canEdit" style="margin-top: 8px"><button type="button" class="pr-btn pr-btn--sm" :disabled="posterBusy" @click="capturePoster">Use this frame as the poster</button></div>
            </InlineNotice>
            <div v-else-if="kind === 'video' && canEdit" class="al-acts">
                <button type="button" class="pr-btn pr-btn--sm" :disabled="posterBusy" @click="capturePoster">Use the shown frame as the new poster</button>
            </div>
            <p v-if="posterError" class="c-error" role="alert"><span class="pr-bead pr-bead--bad pr-bead--sm" aria-hidden="true"></span>{{ posterError }}</p>

            <form class="c-stack" novalidate @submit.prevent="submit">
                <Field label="Name" :error="error">
                    <template #default="{ id, describedby, invalid }"><input :id="id" v-model="name" class="pr-field" maxlength="200" :disabled="!canEdit" :aria-invalid="invalid" :aria-describedby="describedby" @input="savedWord = ''" /></template>
                </Field>
                <Field v-if="kind !== 'audio'" label="Alt text" hint="Read aloud for people who cannot see the picture. Pictures you add start with this text.">
                    <template #default="{ id, describedby }"><textarea :id="id" v-model="alt" class="pr-field al-textarea" maxlength="240" rows="2" :disabled="!canEdit" :aria-describedby="describedby" @input="savedWord = ''"></textarea></template>
                </Field>
                <div v-if="canEdit" class="al-acts">
                    <button type="submit" class="pr-btn pr-btn--primary" :disabled="!dirty || saving">Save changes</button>
                    <span v-if="savedWord" class="pr-state c-muted" role="status">{{ savedWord }}</span>
                </div>
            </form>

            <section aria-label="Where it is used" class="c-stack" style="gap: 6px">
                <p class="ip-h" style="margin: 0">Used in <span class="pr-state">{{ usage ? usageWords({ decks: usage.deck_count, brand_kits: usage.brand_kit_count }).word.replace('Used in ', '') : '' }}</span></p>
                <ProgressBead v-if="!usage && !usageError" label="Looking up where this file is used" />
                <p v-if="usageError" class="c-error" role="alert">{{ usageError }}</p>
                <ul v-else-if="usage && (usage.decks.length || usage.brand_kits.length)" class="al-lines">
                    <li v-for="d in usage.decks" :key="d.id">{{ d.title }} <span class="pr-state c-muted">{{ d.slide_count }} {{ d.slide_count === 1 ? 'slide' : 'slides' }}</span></li>
                    <li v-for="k in usage.brand_kits" :key="`k${k.id}`">Brand kit: {{ k.name }}</li>
                </ul>
                <p v-else-if="usage" class="c-hint">No deck or brand kit uses this file.</p>
            </section>
        </div>
        <template #footer="{ close }">
            <button v-if="canEdit" type="button" class="pr-btn pr-btn--ghost pr-btn--danger" style="margin-right: auto" @click="emit('delete', asset)"><Ico name="trash" size="sm" />Delete</button>
            <button type="button" class="pr-btn" @click="close">Close</button>
            <button v-if="canReplace" type="button" class="pr-btn" @click="emit('replace', asset); close()">Replace selected {{ replaceKind === 'video' ? 'video' : 'picture' }}</button>
            <button v-if="canAdd" type="button" class="pr-btn pr-btn--primary" @click="emit('add', asset); close()">Add to slide</button>
        </template>
    </Dialog>
</template>
