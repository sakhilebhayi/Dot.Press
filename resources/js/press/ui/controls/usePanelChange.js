/**
 * Turns inspector control changes into store.patch calls, for the elements selected on the current slide.
 *
 *   const pc = usePanelChange(editor);          // editor = useEditorContext()
 *   pc.value('size')                            current value over the selection: the common value, 'mixed' when they differ,
 *                                               undefined when nothing is selected. Dotted paths read nested keys ('shadow.blur').
 *   pc.value('size', {fallback: 48})            a missing key counts as 48
 *   pc.set('size', 56)                          patch every selected element; one undo step per control (the undo key is
 *                                               'panel:<path>:<ids>', so a scrub or a slider drag within one second is one step)
 *   pc.set('size', 56, {label: 'Font size'})    the undo label
 *   pc.set('size', el => el.size + 2)           a function gets each element and returns its value (relative changes)
 *   pc.reset('shadow')                          sets null: the key is deleted from the element
 *   pc.setMany({fill: '#fff', stroke: null})    several keys, one undo step
 *   pc.each(el => ({...patch}), {label, undoKey})   free form: return a shallow patch per element
 *   pc.elements                                 computed array of the selected elements
 *   pc.canEdit                                  false for viewers: controls should be disabled
 * Nothing is sent when the value equals what the element already has.
 */
import { computed } from 'vue';
import { buildPatches, panelUndoKey, readProp } from './mixed.js';

export function usePanelChange(editor) {
  const elements = computed(() => editor.selected.value);
  const canEdit = computed(() => !editor.readOnly && editor.can?.update !== false);

  function value(path, opts = {}) {
    void editor.state.value;
    return readProp(elements.value, path, opts);
  }
  function set(path, val, { label, undoKey } = {}) {
    if (!canEdit.value) return false;
    const patches = buildPatches(elements.value, path, val);
    if (!patches.length) return false;
    editor.store.patch(patches, { undoKey: undoKey || panelUndoKey(path, elements.value), label: label || 'Edit' });
    return true;
  }
  function setMany(obj, { label, undoKey } = {}) {
    if (!canEdit.value) return false;
    const merged = new Map();
    for (const [path, val] of Object.entries(obj)) {
      for (const p of buildPatches(elements.value, path, val)) merged.set(p.id, { id: p.id, p: { ...(merged.get(p.id)?.p || {}), ...p.p } });
    }
    const patches = [...merged.values()];
    if (!patches.length) return false;
    editor.store.patch(patches, { undoKey: undoKey || panelUndoKey(Object.keys(obj).join('+'), elements.value), label: label || 'Edit' });
    return true;
  }
  const reset = (path, opts) => set(path, null, { label: 'Reset', ...opts });
  function each(fn, { label, undoKey } = {}) {
    if (!canEdit.value) return false;
    const patches = elements.value.map((el) => ({ id: el.id, p: fn(el) || {} })).filter((p) => Object.keys(p.p).length);
    if (!patches.length) return false;
    editor.store.patch(patches, { undoKey: undoKey || null, label: label || 'Edit' });
    return true;
  }
  return { elements, canEdit, value, set, setMany, reset, each };
}
