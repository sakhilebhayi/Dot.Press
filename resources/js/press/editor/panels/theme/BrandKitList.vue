<script setup>
// The team's brand kits: each row shows the palette as a strip, the name and the default marker. "Apply" converts the kit
// to a theme (the server does the conversion: kit.theme) and hands it to the same report as any other theme.
import { EmptyState, IconButton, ListRow, InlineNotice } from '../../../ui/controls';
import { resolveColor } from '../../core/theme.js';

defineProps({
    kits: { type: Array, required: true },
    limit: { type: Number, default: 20 },
    canWrite: { type: Boolean, default: true },
    canApply: { type: Boolean, default: true },
    activeId: { type: String, default: null }, // theme id the deck uses
    candidateId: { type: String, default: null },
    error: { type: String, default: '' },
});
const emit = defineEmits(['new', 'edit', 'apply', 'default', 'retry']);
const strip = (kit) => (kit.tokens?.palette || []).slice(0, 8).map((c) => resolveColor(c.hex, null) || c.hex);
</script>

<template>
    <div class="dz-pad c-stack">
        <InlineNotice v-if="error" kind="error">{{ error }} <button type="button" class="pr-btn pr-btn--sm" @click="emit('retry')">Try again</button></InlineNotice>
        <EmptyState
            v-if="!kits.length && !error" compact title="No brand kit yet."
            body="A brand kit keeps your colours, fonts and logos in one place and turns them into a theme in one step."
        >
            <button type="button" class="pr-btn pr-btn--primary" :disabled="!canWrite" @click="emit('new')">New brand kit</button>
        </EmptyState>
        <template v-else-if="kits.length">
            <ul class="dz-kits" aria-label="Brand kits">
                <ListRow v-for="k in kits" :key="k.id" :selected="candidateId === k.theme?.id || activeId === k.theme?.id">
                    <span class="dz-kit">
                        <span class="dz-strip" aria-hidden="true">
                            <span v-for="(c, i) in strip(k)" :key="i" class="dz-sw" :style="{ backgroundColor: c }"></span>
                            <span v-if="!strip(k).length" class="pr-state pr-muted">no colours</span>
                        </span>
                        <span class="dz-kit-n">
                            <span class="pr-small">{{ k.name }}</span>
                            <span class="pr-state pr-muted">{{ k.is_default ? 'team default' : `${k.tokens?.palette?.length || 0} colours` }}<template v-if="activeId === k.theme?.id">, in use</template></span>
                        </span>
                    </span>
                    <template #actions>
                        <button type="button" class="pr-btn pr-btn--sm" :disabled="!canApply" :aria-label="`Apply brand kit ${k.name}`" @click="emit('apply', k)">Apply</button>
                        <IconButton icon="pen" :label="`Edit ${k.name}`" size="sm" @click="emit('edit', k)" />
                    </template>
                </ListRow>
            </ul>
            <div class="dz-actions">
                <button type="button" class="pr-btn pr-btn--sm" :disabled="!canWrite || kits.length >= limit" @click="emit('new')">New brand kit</button>
                <span v-if="kits.length >= limit" class="c-hint">A team keeps up to {{ limit }} kits.</span>
            </div>
            <p v-if="!canWrite" class="c-hint">You can use the kits but your role does not allow changing them.</p>
        </template>
    </div>
</template>
