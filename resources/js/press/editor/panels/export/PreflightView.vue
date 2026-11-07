<script setup>
// What the preflight found, per slide, with the server's reasons. A real table: one row per slide, a button in the first
// cell opens the elements of that slide. Level is shown by shape and by a word, never by colour alone.
import { computed, ref } from 'vue';
import { LEVELS, LEVEL_HELP } from './exportModel.js';

const props = defineProps({ view: { type: Object, required: true } });
const open = ref(new Set());
const onlyChanged = ref(props.view.changed > 0);
const rows = computed(() => props.view.slides.filter((s) => !onlyChanged.value || s.changed || s.skipped));
const hiddenRows = computed(() => props.view.slides.length - rows.value.length);
function toggle(id) {
    const next = new Set(open.value);
    if (next.has(id)) next.delete(id); else next.add(id);
    open.value = next;
}
const countsText = (c) => LEVELS.filter((k) => c[k]).map((k) => `${c[k]} ${k}`).join(', ');
</script>

<template>
    <div class="xp-pf">
        <p class="xp-line pr-state" data-testid="summary-line">{{ view.line }}</p>
        <details class="xp-legend-box">
            <summary>What the words mean</summary>
            <ul class="xp-legend">
                <li v-for="k in LEVELS" :key="k"><span class="xp-mark" :data-level="k" aria-hidden="true"></span><span class="pr-state">{{ k }}</span><span class="xp-legend-d">{{ LEVEL_HELP[k] }}</span></li>
            </ul>
        </details>

        <ul v-if="view.warnings.length" class="xp-warn" aria-label="Changes by reason">
            <li v-for="w in view.warnings" :key="w.level + w.text">
                <span class="xp-mark" :data-level="w.level" aria-hidden="true"></span>
                <span class="xp-warn-t"><span class="pr-state">{{ w.level }}</span> {{ w.text }}<span v-if="w.count > 1" class="xp-count pr-state"> x{{ w.count }}</span></span>
            </li>
        </ul>

        <label v-if="view.slides.length > 1 && view.changed > 0" class="xp-only">
            <input v-model="onlyChanged" type="checkbox" /> <span>Only slides that change</span>
        </label>

        <table class="xp-tbl">
            <caption class="pr-sr">Export result per slide</caption>
            <thead><tr><th scope="col">Slide</th><th scope="col">Result</th></tr></thead>
            <tbody>
                <template v-for="s in rows" :key="s.key">
                    <tr class="xp-row" :class="{ 'is-open': open.has(s.id), 'is-skipped': s.skipped }">
                        <th scope="row">
                            <button type="button" class="xp-tgl" :aria-expanded="open.has(s.id)" :aria-controls="`pf-${s.id}`" :disabled="s.skipped" @click="toggle(s.id)">
                                <span class="xp-n pr-state">{{ s.index }}</span>
                                <span class="xp-ti">{{ s.title || 'Untitled slide' }}</span>
                            </button>
                        </th>
                        <td>
                            <span v-if="s.skipped" class="xp-res"><span class="xp-mark" data-level="skipped" aria-hidden="true"></span><span class="pr-state">left out</span></span>
                            <span v-else class="xp-res"><span class="xp-mark" :data-level="s.worst" aria-hidden="true"></span><span class="pr-state">{{ s.changed ? s.worst : 'native' }}</span><span v-if="s.changed" class="xp-res-c">{{ countsText({ approximated: s.counts.approximated, flattened: s.counts.flattened, dropped: s.counts.dropped }) }}</span></span>
                            <span v-if="s.hidden && !s.skipped" class="xp-hid pr-state">hidden slide</span>
                        </td>
                    </tr>
                    <tr v-if="open.has(s.id)" :id="`pf-${s.id}`" class="xp-detail">
                        <td colspan="2">
                            <ul class="xp-els">
                                <li v-for="r in s.rows" :key="r.key" :class="{ 'is-ok': r.level === 'native' }">
                                    <span class="xp-mark" :data-level="r.level" aria-hidden="true"></span>
                                    <span class="xp-el">
                                        <span class="xp-el-h"><span class="xp-el-k">{{ r.kind }}</span><span v-if="r.name" class="xp-el-n">{{ r.name }}</span><span class="pr-state xp-el-w">{{ r.word }}</span></span>
                                        <span v-if="r.reason" class="xp-el-r">{{ r.reason }}</span>
                                    </span>
                                </li>
                                <li v-for="n in s.notes" :key="n" class="xp-note"><span class="xp-mark" data-level="note" aria-hidden="true"></span><span class="xp-el"><span class="xp-el-r">{{ n }}</span></span></li>
                                <li v-if="!s.rows.length && !s.notes.length" class="xp-note"><span class="xp-el"><span class="xp-el-r">Nothing on this slide to carry over.</span></span></li>
                            </ul>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <p v-if="hiddenRows > 0" class="xp-more pr-small pr-muted">{{ hiddenRows }} {{ hiddenRows === 1 ? 'slide' : 'slides' }} with nothing to flag {{ hiddenRows === 1 ? 'is' : 'are' }} not listed.</p>
    </div>
</template>
