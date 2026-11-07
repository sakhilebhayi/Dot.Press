/**
 * The shared shape of the editor, so the shell, the stage and every panel can be written separately.
 *
 * The shell (Pages/Press/Editor.vue, resources/js/press/editor/ui/useEditor.js) builds one `editor` object per page
 * and `provide`s it under EDITOR_KEY. Everything else calls `useEditorContext()`.
 *
 * editor = {
 *   store,          // createStore() from ./core (immutable state, dispatch, undo, selection, clipboard, handleKey ...)
 *   state,          // shallowRef(store.getState()), replaced on every store change
 *   sync,           // createSync() (autosave); state.value.sync holds status
 *   ui,             // reactive, see createEditorUi() below
 *   assets,         // reactive map {assetId: {url, thumb, w, h, mime, name, kind}} (the document `assets` map)
 *   deck,           // computed: state.value.doc.deck  (w, h, theme, title, sections, status, ...)
 *   slide,          // computed: the current slide object (or null)
 *   selected,       // computed: array of selected element objects on the current slide
 *   me,             // {id, name}
 *   can,            // {update, comment, manage, present, export}
 *   aiMode,         // 'local' | 'ai'
 *   api,            // thin axios wrappers: get/post/put/patch/delete returning response data (CSRF handled)
 *   presence,       // shallowRef([{client, user_id, name, color, slide_id, selection, cursor}])
 *   toast(message, {kind: 'info'|'ok'|'warn'|'error', action?: {label, run}}),
 *   openPanel(id, props?), closePanel(),   // inspector drawer
 *   registerPanel(def),                    // see panels/registry.js
 * }
 */
import { inject, reactive } from 'vue';

export const EDITOR_KEY = Symbol('dot-press-editor');

export function useEditorContext() {
    const editor = inject(EDITOR_KEY, null);
    if (!editor) {
        throw new Error('useEditorContext() was called outside the editor shell');
    }
    return editor;
}

/** Tools: select, hand, text, shape:<kind>, line, arrow, image, chart, table, icon, video, audio. */
export function createEditorUi() {
    return reactive({
        tool: 'select',
        zoom: 'fit', // 'fit' or a number (1 = 100 percent of slide units to CSS pixels)
        panX: 0,
        panY: 0,
        grid: false,
        guides: true,
        rulers: false,
        margins: false,
        snap: true,
        cropping: null, // element id while a picture is in crop mode
        tableSel: null, // {id, r1, c1, r2, c2}: the selected cells while a table is being edited (set by the stage)
        editingText: null, // element id while its text is being edited
        contextMenu: null, // {x, y} in client pixels, or null
        spine: { open: true, view: 'spine' }, // view: 'spine' (claims) | 'sheets' (thumbnail grid)
        inspector: { open: false, panel: null, props: null },
        dragging: null, // {kind: 'asset'|'slide'|'file', ...} while something is being dragged over the editor
        help: false, // shortcut overlay
        presenting: false,
    });
}
