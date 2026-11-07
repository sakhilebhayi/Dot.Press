<script setup>
// What each export format does with each kind of content. Every row is a case of ExportPreflight (see COMPAT in
// landingModel.js). Level is shown as a shape and a word, never colour alone. On a phone each row becomes a short list.
// The four rows where a format gives something up come first; the five that are kept in both sit behind a control,
// so the table is short for a reader who only wants to know what changes.
import { ref } from 'vue';
import { COMPAT, LEVEL_WORD, splitCompat } from './landingModel.js';

const { lead, rest } = splitCompat(COMPAT);
const open = ref(false);
</script>

<template>
    <div class="lp-compat-wrap">
        <table class="lp-compat">
            <caption class="pr-sr">What PowerPoint and PDF exports do with each kind of content</caption>
            <thead>
                <tr>
                    <th scope="col">Content</th>
                    <th scope="col">PowerPoint (.pptx)</th>
                    <th scope="col">PDF</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="row in lead" :key="row.what">
                    <th scope="row">{{ row.what }}</th>
                    <td v-for="col in ['pptx', 'pdf']" :key="col" :data-col="col === 'pptx' ? 'PowerPoint' : 'PDF'">
                        <span class="lp-lv" :data-l="row[col].level"><i aria-hidden="true" />{{ LEVEL_WORD[row[col].level] }}</span>
                        <span v-if="row[col].note" class="lp-compat-note">{{ row[col].note }}</span>
                    </td>
                </tr>
            </tbody>
            <tbody id="lp-compat-rest" :hidden="!open">
                <tr v-for="row in rest" :key="row.what">
                    <th scope="row">{{ row.what }}</th>
                    <td v-for="col in ['pptx', 'pdf']" :key="col" :data-col="col === 'pptx' ? 'PowerPoint' : 'PDF'">
                        <span class="lp-lv" :data-l="row[col].level"><i aria-hidden="true" />{{ LEVEL_WORD[row[col].level] }}</span>
                        <span v-if="row[col].note" class="lp-compat-note">{{ row[col].note }}</span>
                    </td>
                </tr>
            </tbody>
        </table>
        <button type="button" class="lp-compat-more" :aria-expanded="open" aria-controls="lp-compat-rest" @click="open = !open">
            {{ open ? 'Show fewer rows' : `Show the other ${rest.length} rows: ${rest.map((r) => r.what.toLowerCase()).join(', ')}` }}
        </button>
    </div>
</template>
