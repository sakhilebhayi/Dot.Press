<script setup>
// The custom theme editor: colour roles, chart colours, fonts, shape, default background, with a live mini slide and
// live contrast checks. The parent owns the draft and the API calls; this component only edits the draft.
import { computed, ref } from 'vue';
import {
    ColorField, Dialog, Field, FontPicker, IconButton, InlineNotice, NumberField, PanelSection, Segmented, Slider,
} from '../../../ui/controls';
import { SlideView } from '../../../render/index.js';
import { useEditorContext } from '../../context.js';
import BackgroundControls from '../background/BackgroundControls.vue';
import { CHART_COUNT, COLOR_ROLES, hexOf, setDraft } from './themeModel.js';
import { LEVEL_WORD, contrastChecks, worstLevel } from './contrast.js';
import { sampleSlide } from './sampleDoc.js';

const props = defineProps({
    draft: { type: Object, required: true },
    isNew: { type: Boolean, default: true },
    dirty: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    readOnly: { type: Boolean, default: false },
    inUse: { type: Boolean, default: false },
});
const emit = defineEmits(['update:draft', 'back', 'save', 'save-apply', 'delete']);
const editor = useEditorContext();
const confirmDelete = ref(false);

const view = computed(() => sampleSlide(props.draft));
const checks = computed(() => contrastChecks(props.draft));
const worst = computed(() => worstLevel(checks.value));
const issues = computed(() => checks.value.filter((c) => c.level !== 'ok').length);
const nameError = computed(() => (props.draft.name.trim() ? '' : 'Give the theme a name.'));
const weights = computed(() => {
    const base = [400, 500, 600, 700, 800];
    const hw = props.draft.t.hw;
    return (base.includes(hw) ? base : [...base, hw].sort((a, b) => a - b)).map((v) => ({ value: v, label: String(v) }));
});

const set = (path, value) => emit('update:draft', setDraft(props.draft, path, value));
const setColour = (path, v) => set(path, hexOf(v, props.draft));
const bead = { ok: 'ok', warn: 'warn', issue: 'bad' };
</script>

<template>
    <div class="dz-ed">
        <header class="dz-ed-top">
            <IconButton icon="chevronLeft" label="Back to themes" @click="emit('back')" />
            <h2 class="dz-ed-title pr-small">{{ isNew ? 'New theme' : 'Edit theme' }}</h2>
            <span v-if="dirty" class="pr-state pr-muted">unsaved changes</span>
        </header>

        <div class="dz-ed-live" role="group" aria-label="Live preview">
            <div class="dz-ed-slide" aria-hidden="true"><SlideView :slide="view.slide" :deck="view.deck" :assets="editor.assets" mode="thumb" /></div>
            <div class="dz-ed-sum" aria-live="polite">
                <span class="pr-bead pr-bead--sm" :class="`pr-bead--${bead[worst]}`" aria-hidden="true"></span>
                <span class="pr-small">{{ worst === 'ok' ? 'Contrast is good' : `${issues} contrast ${issues === 1 ? 'note' : 'notes'}` }}</span>
            </div>
        </div>

        <div class="dz-pad c-stack">
            <Field v-slot="{ id, describedby, invalid }" label="Name" :error="nameError">
                <input
                    :id="id" type="text" class="pr-field" maxlength="80" :value="draft.name" :disabled="readOnly" :aria-invalid="invalid || undefined" :aria-describedby="describedby"
                    @input="set('name', $event.target.value)"
                />
            </Field>
        </div>

        <PanelSection id="theme.colours" title="Colours" :state="String(COLOR_ROLES.length)">
            <div class="c-grid2">
                <ColorField
                    v-for="r in COLOR_ROLES" :key="r.key" :label="r.label" :model-value="draft.c[r.key]" :theme="draft" :disabled="readOnly"
                    @update:model-value="setColour(`c.${r.key}`, $event)"
                />
            </div>
        </PanelSection>

        <PanelSection id="theme.chart" title="Chart colours" :state="String(CHART_COUNT)" :default-open="false">
            <div class="c-grid2">
                <ColorField
                    v-for="(c, i) in draft.c.chart" :key="i" :label="`Chart ${i + 1}`" :model-value="c" :theme="draft" :disabled="readOnly"
                    @update:model-value="setColour(`c.chart.${i}`, $event)"
                />
            </div>
        </PanelSection>

        <PanelSection id="theme.type" title="Type">
            <FontPicker :model-value="draft.t.heading" label="Heading font" :roles="false" :theme="draft" :disabled="readOnly" @update:model-value="set('t.heading', $event)" />
            <FontPicker :model-value="draft.t.body" label="Body font" :roles="false" :theme="draft" :disabled="readOnly" @update:model-value="set('t.body', $event)" />
            <Segmented :model-value="draft.t.hw" :options="weights" label="Heading weight" :disabled="readOnly" @update:model-value="set('t.hw', $event)" />
            <Slider
                :model-value="draft.t.scale" label="Type scale" :min="0.7" :max="1.5" :step="0.05" :disabled="readOnly" hint="Multiplies the size of all text and tables in the deck." :format="(v) => `${Math.round(v * 100) / 100}x`"
                @update:model-value="set('t.scale', $event)"
            />
        </PanelSection>

        <PanelSection id="theme.shape" title="Shape" :default-open="false">
            <div class="c-grid2">
                <NumberField :model-value="draft.s.radius" label="Radius" :min="0" :max="200" :step="1" :disabled="readOnly" @update:model-value="set('s.radius', $event)" />
                <NumberField :model-value="draft.s.stroke" label="Stroke" :min="0" :max="40" :step="1" :disabled="readOnly" @update:model-value="set('s.stroke', $event)" />
            </div>
        </PanelSection>

        <PanelSection id="theme.bg" title="Default background" :default-open="false">
            <BackgroundControls
                :model-value="draft.bg" theme-label="None" label="Default slide background" :theme="draft" :disabled="readOnly"
                @update:model-value="set('bg', $event)"
            />
        </PanelSection>

        <PanelSection id="theme.contrast" title="Contrast" :state="worst === 'ok' ? 'all ok' : LEVEL_WORD[worst]">
            <ul class="dz-checks" aria-label="Contrast checks">
                <li v-for="c in checks" :key="c.id" class="dz-check">
                    <span class="pr-bead pr-bead--sm" :class="`pr-bead--${bead[c.level]}`" aria-hidden="true"></span>
                    <span class="dz-check-b">
                        <span class="pr-small dz-check-t">{{ c.label }}<span class="pr-state dz-check-w">{{ LEVEL_WORD[c.level] }}</span></span>
                        <span class="c-hint">{{ c.text }}</span>
                    </span>
                </li>
            </ul>
        </PanelSection>

        <div class="dz-bar" role="group" aria-label="Save theme">
            <InlineNotice v-if="error" kind="error">{{ error }}</InlineNotice>
            <p v-if="readOnly" class="c-hint">You can look at this theme but your role does not allow saving it.</p>
            <div class="dz-bar-a">
                <button type="button" class="pr-btn pr-btn--sm pr-btn--primary" :disabled="readOnly || busy || !!nameError || (!isNew && !dirty)" @click="emit('save')">{{ isNew ? 'Save theme' : 'Save changes' }}</button>
                <button type="button" class="pr-btn pr-btn--sm" :disabled="readOnly || busy || !!nameError || !editor.can.update" @click="emit('save-apply')">{{ inUse && !dirty ? 'Apply again' : 'Save and apply' }}</button>
                <button v-if="!isNew" type="button" class="pr-btn pr-btn--sm pr-btn--ghost pr-btn--danger" :disabled="readOnly || busy" @click="confirmDelete = true">Delete</button>
            </div>
        </div>

        <Dialog v-model:open="confirmDelete" title="Delete this theme?" size="sm">
            <p class="pr-small">"{{ draft.name }}" will be removed from your team's themes. Decks that already use it keep their copy of the theme and do not change.</p>
            <template #footer="{ close }">
                <button type="button" class="pr-btn" @click="close">Keep it</button>
                <button type="button" class="pr-btn pr-btn--primary pr-btn--danger" @click="emit('delete'); close()">Delete theme</button>
            </template>
        </Dialog>
    </div>
</template>
