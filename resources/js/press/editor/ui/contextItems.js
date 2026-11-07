/**
 * The default right click menu for the stage, as data (pure). The stage can pass its own `items` in
 * `ui.contextMenu = {x, y, items}`; when it passes only {x, y} the host builds this list from the selection.
 *
 *   elementMenu({ count, locked, hidden, grouped, canEdit, mac }) -> items
 * Item: {label, shortcut?, cmd?, run?, disabled?, danger?} | {separator: true} | {heading}
 * `cmd` is a store command id (store.runCommand); `run` is a function. Disabled when the person may not edit.
 */
export function elementMenu({ count = 0, locked = false, hidden = false, grouped = false, canEdit = true, mac = false } = {}) {
  const k = (win, macKey) => (mac ? macKey : win);
  const edit = (item) => ({ ...item, disabled: item.disabled || !canEdit });
  if (!count) {
    return [
      edit({ label: 'Paste', cmd: 'edit.paste', shortcut: k('Ctrl+V', '⌘V') }),
      { label: 'Select all', cmd: 'edit.selectAll', shortcut: k('Ctrl+A', '⌘A') },
      { separator: true },
      { label: 'Toggle grid', cmd: 'view.grid', shortcut: k("Ctrl+'", "⌘'") },
      { label: 'Zoom to fit', cmd: 'view.zoomFit', shortcut: k('Ctrl+0', '⌘0') },
    ];
  }
  return [
    edit({ label: 'Cut', cmd: 'edit.cut', shortcut: k('Ctrl+X', '⌘X') }),
    { label: 'Copy', cmd: 'edit.copy', shortcut: k('Ctrl+C', '⌘C') },
    edit({ label: 'Paste', cmd: 'edit.paste', shortcut: k('Ctrl+V', '⌘V') }),
    edit({ label: 'Duplicate', cmd: 'edit.duplicate', shortcut: k('Ctrl+D', '⌘D') }),
    edit({ label: 'Delete', cmd: 'edit.delete', shortcut: 'Del', danger: true }),
    { separator: true },
    { heading: 'Arrange' },
    edit({ label: 'Bring to front', cmd: 'arrange.front', shortcut: k('Ctrl+Shift+]', '⇧⌘]') }),
    edit({ label: 'Bring forward', cmd: 'arrange.forward', shortcut: k('Ctrl+]', '⌘]') }),
    edit({ label: 'Send backward', cmd: 'arrange.backward', shortcut: k('Ctrl+[', '⌘[') }),
    edit({ label: 'Send to back', cmd: 'arrange.back', shortcut: k('Ctrl+Shift+[', '⇧⌘[') }),
    { separator: true },
    edit(count > 1 ? { label: 'Group', cmd: 'arrange.group', shortcut: k('Ctrl+G', '⌘G') } : { label: 'Group', cmd: 'arrange.group', disabled: true }),
    edit({ label: 'Ungroup', cmd: 'arrange.ungroup', shortcut: k('Ctrl+Shift+G', '⇧⌘G'), disabled: !grouped }),
    edit({ label: locked ? 'Unlock' : 'Lock', cmd: 'arrange.lock', shortcut: k('Ctrl+Shift+L', '⇧⌘L') }),
    edit({ label: hidden ? 'Show' : 'Hide', cmd: 'arrange.hide', shortcut: k('Ctrl+Shift+H', '⇧⌘H') }),
  ];
}
