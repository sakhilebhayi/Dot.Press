<script setup>
// What each export format does with each kind of content. Every row is a case of ExportPreflight (see COMPAT in
// landingModel.js). Level is shown as a shape and a word, never colour alone. On a phone each row becomes a short list.
import { COMPAT, LEVEL_WORD } from './landingModel.js';
</script>

<template>
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
            <tr v-for="row in COMPAT" :key="row.what">
                <th scope="row">{{ row.what }}</th>
                <td v-for="col in ['pptx', 'pdf']" :key="col" :data-col="col === 'pptx' ? 'PowerPoint' : 'PDF'">
                    <span class="lp-lv" :data-l="row[col].level"><i aria-hidden="true" />{{ LEVEL_WORD[row[col].level] }}</span>
                    <span v-if="row[col].note" class="lp-compat-note">{{ row[col].note }}</span>
                </td>
            </tr>
        </tbody>
    </table>
</template>
