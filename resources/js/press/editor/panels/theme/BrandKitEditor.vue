<script setup>
// Create or edit one brand kit: name, palette (up to 12 named colours, optionally a theme role each), up to 3 fonts
// with roles and logos picked from the asset library. The parent saves; this component edits the draft.
import { computed, ref } from 'vue';
import {
    ColorField, Dialog, Field, FontPicker, IconButton, InlineNotice, PanelSection, SelectMenu, Toggle,
} from '../../../ui/controls';
import AssetPicker from '../background/AssetPicker.vue';
import {
    FONT_ROLES, PALETTE_ROLES, ROLE_WORDS, addColour, addFont, addLogo, hasProblems, kitProblems, removeAt, setAt,
} from './brandKitModel.js';
import { hexOf } from './themeModel.js';

const props = defineProps({
    draft: { type: Object, required: true },
    limits: { type: Object, required: true },
    isNew: { type: Boolean, default: true },
    dirty: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    readOnly: { type: Boolean, default: false },
});
const emit = defineEmits(['update:draft', 'back', 'save', 'delete']);
const picker = ref(false);
const confirmDelete = ref(false);

const problems = computed(() => kitProblems(props.draft, props.limits));
const invalid = computed(() => hasProblems(problems.value));
const roleOptions = computed(() => [{ value: '', label: 'No role' }, ...PALETTE_ROLES.map((r) => ({ value: r, label: ROLE_WORDS[r] }))]);
const fontRoleOptions = FONT_ROLES.map((r) => ({ value: r, label: r === 'accent' ? 'Accent' : ROLE_WORDS[r] }));

const up = (d) => emit('update:draft', d);
const touched = ref(false);
</script>

<template>
    <div class="dz-ed">
        <header class="dz-ed-top">
            <IconButton icon="chevronLeft" label="Back to brand kits" @click="emit('back')" />
            <h2 class="dz-ed-title pr-small">{{ isNew ? 'New brand kit' : 'Edit brand kit' }}</h2>
            <span v-if="dirty" class="pr-state pr-muted">unsaved changes</span>
        </header>

        <div class="dz-pad c-stack">
            <Field v-slot="{ id, describedby, invalid: inv }" label="Name" :error="touched ? problems.name || '' : ''">
                <input
                    :id="id" type="text" class="pr-field" maxlength="80" :value="draft.name" :disabled="readOnly" :aria-invalid="inv || undefined" :aria-describedby="describedby"
                    @input="up({ ...draft, name: $event.target.value })" @blur="touched = true"
                />
            </Field>
            <Toggle :model-value="draft.is_default" label="Team default" hint="New decks and the theme list offer the default kit first." :disabled="readOnly" @update:model-value="up({ ...draft, is_default: $event })" />
        </div>

        <PanelSection id="kit.palette" title="Palette" :state="`${draft.palette.length} of ${limits.palette}`">
            <ul class="dz-pal" aria-label="Palette colours">
                <li v-for="(c, i) in draft.palette" :key="i" class="dz-pal-i">
                    <div class="dz-pal-top">
                        <ColorField :model-value="c.hex" :label="`Colour ${i + 1}`" hide-label :disabled="readOnly" @update:model-value="(v) => up(setAt(draft, 'palette', i, { hex: hexOf(v) }))" />
                        <IconButton icon="trash" :label="`Remove ${c.name || 'colour ' + (i + 1)}`" size="sm" danger :disabled="readOnly" @click="up(removeAt(draft, 'palette', i))" />
                    </div>
                    <div class="c-grid2">
                        <Field v-slot="{ id, describedby, invalid: inv }" :label="`Name`" :error="problems.palette[i] || ''">
                            <input :id="id" type="text" class="pr-field" maxlength="40" :value="c.name" :disabled="readOnly" :aria-invalid="inv || undefined" :aria-describedby="describedby" @input="up(setAt(draft, 'palette', i, { name: $event.target.value }))" />
                        </Field>
                        <SelectMenu :model-value="c.role || ''" :options="roleOptions" label="Theme role" :disabled="readOnly" @update:model-value="up(setAt(draft, 'palette', i, { role: $event }))" />
                    </div>
                </li>
            </ul>
            <button type="button" class="pr-btn pr-btn--sm" :disabled="readOnly || draft.palette.length >= limits.palette" @click="up(addColour(draft, '#2563eb', limits.palette))">Add a colour</button>
            <p class="c-hint">A role puts the colour in that slot of the theme. Colours without a role become the accents and chart colours in order.</p>
        </PanelSection>

        <PanelSection id="kit.fonts" title="Fonts" :state="`${draft.fonts.length} of ${limits.fonts}`">
            <ul class="dz-pal" aria-label="Fonts">
                <li v-for="(f, i) in draft.fonts" :key="i" class="dz-pal-i">
                    <div class="dz-pal-top">
                        <FontPicker :model-value="f.family" :label="`Font ${i + 1}`" :roles="false" :disabled="readOnly" :error="problems.fonts[i] || ''" @update:model-value="up(setAt(draft, 'fonts', i, { family: $event }))" />
                        <IconButton icon="trash" :label="`Remove font ${f.family}`" size="sm" danger :disabled="readOnly" @click="up(removeAt(draft, 'fonts', i))" />
                    </div>
                    <SelectMenu :model-value="f.role" :options="fontRoleOptions" label="Used for" :disabled="readOnly" @update:model-value="up(setAt(draft, 'fonts', i, { role: $event }))" />
                </li>
            </ul>
            <button type="button" class="pr-btn pr-btn--sm" :disabled="readOnly || draft.fonts.length >= limits.fonts" @click="up(addFont(draft, 'Public Sans', limits.fonts))">Add a font</button>
        </PanelSection>

        <PanelSection id="kit.logos" title="Logos" :state="`${draft.logos.length} of ${limits.logos}`">
            <ul v-if="draft.logo_assets.length" class="dz-logos" aria-label="Logos">
                <li v-for="(l, i) in draft.logo_assets" :key="l.id" class="dz-logo">
                    <img :src="l.thumb || l.url" :alt="l.name" loading="lazy" decoding="async" />
                    <IconButton icon="close" :label="`Remove logo ${l.name}`" size="sm" :disabled="readOnly" @click="up(removeAt(draft, 'logos', i))" />
                </li>
            </ul>
            <button type="button" class="pr-btn pr-btn--sm" :disabled="readOnly || draft.logos.length >= limits.logos" @click="picker = true">Add a logo from the library</button>
        </PanelSection>

        <div class="dz-bar" role="group" aria-label="Save brand kit">
            <InlineNotice v-if="error" kind="error">{{ error }}</InlineNotice>
            <InlineNotice v-else-if="touched && invalid" kind="warn">{{ problems.name || Object.values(problems.palette)[0] || Object.values(problems.fonts)[0] || problems.general[0] }}</InlineNotice>
            <p v-if="readOnly" class="c-hint">You can look at this kit but your role does not allow saving it.</p>
            <div class="dz-bar-a">
                <button type="button" class="pr-btn pr-btn--sm pr-btn--primary" :disabled="readOnly || busy || (!isNew && !dirty)" @click="touched = true; if (!invalid) emit('save')">{{ isNew ? 'Save brand kit' : 'Save changes' }}</button>
                <button v-if="!isNew" type="button" class="pr-btn pr-btn--sm pr-btn--ghost pr-btn--danger" :disabled="readOnly || busy" @click="confirmDelete = true">Delete</button>
            </div>
        </div>

        <AssetPicker v-model:open="picker" title="Choose a logo" @pick="(a) => up(addLogo(draft, a, limits.logos))" />
        <Dialog v-model:open="confirmDelete" title="Delete this brand kit?" size="sm">
            <p class="pr-small">"{{ draft.name }}" will be removed. Decks that applied it keep the theme they were given.</p>
            <template #footer="{ close }">
                <button type="button" class="pr-btn" @click="close">Keep it</button>
                <button type="button" class="pr-btn pr-btn--primary pr-btn--danger" @click="emit('delete'); close()">Delete brand kit</button>
            </template>
        </Dialog>
    </div>
</template>
