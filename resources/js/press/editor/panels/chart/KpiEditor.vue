<script setup>
// Key figures as a reorderable list of small forms: label, value, change, unit, note and which direction is good news.
// Reports edits as cell(r, c, text) with the column order of grid.js (label, value, change, unit, note, good).
import { computed } from 'vue';
import Field from '../../../ui/controls/Field.vue';
import IconButton from '../../../ui/controls/IconButton.vue';
import ReorderList from '../../../ui/controls/ReorderList.vue';
import Segmented from '../../../ui/controls/Segmented.vue';

const props = defineProps({
    view: { type: Object, required: true },
    canEdit: { type: Boolean, default: true },
});
const emit = defineEmits(['cell', 'remove-row', 'move']);
const items = computed(() => props.view.rows.map((row, i) => ({ id: `k${i}`, i, row })));
const GOOD = [{ value: 'up', label: 'Up is good' }, { value: 'down', label: 'Down is good' }];
const commit = (e, r, c, now) => { if (e.target.value !== now) emit('cell', r, c, e.target.value); };
</script>

<template>
    <div class="cpk">
        <ReorderList :items="items" item-key="id" label="Key figures" :handle-label="(it) => `Move figure ${it.i + 1}`" @reorder="(e) => emit('move', e.from, e.to)">
            <template #default="{ item }">
                <div class="cpk-f">
                    <Field label="Label" v-slot="{ id, describedby }">
                        <input :id="id" :aria-describedby="describedby" class="pr-field" :value="item.row[0]" :disabled="!canEdit" maxlength="60" autocomplete="off" @change="commit($event, item.i, 0, item.row[0])" />
                    </Field>
                    <div class="c-grid2">
                        <Field label="Value" v-slot="{ id, describedby }">
                            <input :id="id" :aria-describedby="describedby" class="pr-field cpk-num" :value="item.row[1]" :disabled="!canEdit" inputmode="decimal" autocomplete="off" @change="commit($event, item.i, 1, item.row[1])" />
                        </Field>
                        <Field label="Change" v-slot="{ id, describedby }">
                            <input :id="id" :aria-describedby="describedby" class="pr-field cpk-num" :value="item.row[2]" :disabled="!canEdit" inputmode="decimal" autocomplete="off" placeholder="4.2 or text" @change="commit($event, item.i, 2, item.row[2])" />
                        </Field>
                        <Field label="Unit" v-slot="{ id }">
                            <input :id="id" class="pr-field" :value="item.row[3]" :disabled="!canEdit" maxlength="12" autocomplete="off" @change="commit($event, item.i, 3, item.row[3])" />
                        </Field>
                        <Field label="Note" v-slot="{ id }">
                            <input :id="id" class="pr-field" :value="item.row[4]" :disabled="!canEdit" maxlength="80" autocomplete="off" @change="commit($event, item.i, 4, item.row[4])" />
                        </Field>
                    </div>
                    <Segmented :model-value="item.row[5]" :options="GOOD" :disabled="!canEdit" :label="`Which direction is good for ${item.row[0] || 'figure ' + (item.i + 1)}`" hide-label @update:model-value="(v) => emit('cell', item.i, 5, v)" />
                </div>
            </template>
            <template #actions="{ item }">
                <IconButton icon="trash" :label="`Delete figure ${item.i + 1}`" size="sm" danger :disabled="!canEdit" @click="emit('remove-row', item.i)" />
            </template>
        </ReorderList>
    </div>
</template>

<style>
.cpk .c-row { align-items: flex-start; padding-top: 6px; padding-bottom: 8px; }
.cpk .c-row-h { margin-top: 4px; }
.cpk .c-row-a { margin-top: 4px; }
.cpk-f { display: grid; gap: 8px; }
.cpk-num { font-family: var(--f-mono); font-size: var(--t-xs); text-align: right; }
.cpk .c-hint { font-size: var(--t-xs); }
</style>
