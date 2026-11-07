/**
 * Which chart the panel edits and how it writes (Vue composable).
 *
 *   const t = useChartTarget(editor, () => props.panelProps);
 *   t.el            the chart element ({id, type: 'chart', chart, alt, ...}) or null
 *   t.chart         el.chart (never mutated; every change builds a new chart)
 *   t.multi         true when several charts are selected (the panel edits the first and says so)
 *   t.canEdit       false for viewers
 *   t.theme         the deck theme (for previews and colour swatches)
 *   t.commit(next, {key, label})      replace el.chart; key makes edits of one control within a second one undo step
 *   t.commitAlt(text)                 set or clear el.alt
 *   t.undo()                          editor.store.undo()
 *
 * The element is `panelProps.id` when given (and on the current slide), else the first selected chart. A chart is written
 * as a whole (`el.set` is a shallow patch); the undo key is `chart:<id>:<key>`.
 */
import { computed } from 'vue';

export function useChartTarget(editor, getProps) {
  const sel = computed(() => { void editor.state.value; return editor.selected.value.filter((e) => e.type === 'chart'); });
  const el = computed(() => {
    void editor.state.value;
    const want = getProps()?.id;
    if (want) {
      const found = editor.slide.value?.els?.find((e) => e.id === want && e.type === 'chart');
      if (found) return found;
    }
    return sel.value[0] || null;
  });
  const chart = computed(() => el.value?.chart || null);
  const multi = computed(() => !getProps()?.id && sel.value.length > 1);
  const canEdit = computed(() => !editor.readOnly && editor.can?.update !== false);
  const theme = computed(() => editor.deck.value?.theme || undefined);

  function commit(next, { key = 'data', label = 'Edit chart' } = {}) {
    if (!el.value || !canEdit.value) return false;
    if (JSON.stringify(next) === JSON.stringify(el.value.chart)) return false;
    editor.store.patch([{ id: el.value.id, p: { chart: next } }], { undoKey: `chart:${el.value.id}:${key}`, label });
    return true;
  }
  function commitAlt(text) {
    if (!el.value || !canEdit.value) return false;
    const v = String(text ?? '').slice(0, 500);
    if ((el.value.alt || '') === v) return false;
    editor.store.patch([{ id: el.value.id, p: { alt: v === '' ? null : v } }], { undoKey: `chart:${el.value.id}:alt`, label: 'Alt text' });
    return true;
  }
  const undo = () => editor.store.undo();
  return { el, chart, multi, canEdit, theme, commit, commitAlt, undo };
}
