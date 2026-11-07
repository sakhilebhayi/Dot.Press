/**
 * The inspector panel registry (pure, no Vue imports, node tested). Panels register themselves from
 * `panels/<name>/register.js` (see registry.js) with:
 *
 *   registerPanel({
 *     id,            unique string, used by editor.openPanel(id)
 *     label,         tab label ("Text", "Theme", "Review")
 *     icon,          an id from resources/js/press/icons.js (optional; the tab shows the label's first letter if absent)
 *     scope,         'selection' | 'slide' | 'deck'
 *     order,         number, lower first inside its scope (default 100)
 *     when(ctx),     optional predicate; the panel is offered only when it returns true
 *     component,     a Vue component (or defineAsyncComponent); receives prop `panelProps` (what openPanel passed)
 *   })
 *
 * ctx = { scope: current context ('selection' when elements are selected, else 'slide'), selected: [elements],
 *         slide, deck, can: {update, comment, manage, present, export}, state }.
 * Panels use `useEditorContext()` for everything else.
 *
 * A panel scoped `selection` is offered only while something is selected. `slide` and `deck` panels are always
 * offered (unless `when` says no). listPanels orders selection panels first, then slide, then deck.
 *
 * `var` and the lazy map are deliberate: register.js files import this module through registry.js in a cycle, and
 * must be able to register before this module body has run.
 */
// eslint-disable-next-line no-var
var panels;
function store() { return (panels ??= new Map()); }
function scopes() { return ['selection', 'slide', 'deck']; }

export function registerPanel(def) {
  if (!def || typeof def.id !== 'string' || !def.id) throw new Error('registerPanel: id is required');
  if (!scopes().includes(def.scope)) throw new Error(`registerPanel(${def.id}): scope must be one of ${scopes().join(', ')}`);
  if (!def.component) throw new Error(`registerPanel(${def.id}): component is required`);
  store().set(def.id, { order: 100, label: def.id, icon: null, when: null, ...def });
  return def.id;
}

export function unregisterPanel(id) { return store().delete(id); }
export function getPanel(id) { return store().get(id) || null; }
export function hasPanel(id) { return store().has(id); }
export function resetPanels() { store().clear(); }

export function listPanels(ctx = {}) {
  const hasSelection = (ctx.selected?.length || 0) > 0;
  return [...store().values()]
    .filter((p) => {
      if (p.scope === 'selection' && !hasSelection) return false;
      if (typeof p.when === 'function') {
        try { return !!p.when(ctx); } catch { return false; }
      }
      return true;
    })
    .sort((a, b) => scopes().indexOf(a.scope) - scopes().indexOf(b.scope) || a.order - b.order || a.label.localeCompare(b.label));
}

/** The panel to show: the wanted one if offered, else the first panel of the most specific scope. */
export function activePanel(list, wantedId) {
  return list.find((p) => p.id === wantedId) || list[0] || null;
}
