/**
 * The stage's right click menu as data, in the shape the shell's context menu host renders:
 *   {id, label, shortcut?, disabled?, danger?}   an item (the stage attaches `run` when it opens the menu)
 *   {separator: true}   {heading}
 * `ui.contextMenu = {x, y, items}` shows it. Pure.
 *
 *   contextItems({count, hasGroup, locked, hidden, canEdit, canComment, hasPanel, hasStyle, shortcuts}) -> items
 *   shortcuts(cmd) -> string   formats the key for a keymap command ('' when none)
 */
export function contextItems(o) {
  const sc = (cmd) => (o.shortcuts && cmd ? o.shortcuts(cmd) : '');
  const it = (id, label, cmd, extra = {}) => ({
    id, label, shortcut: sc(cmd), ...extra,
  });
  const sep = { separator: true };
  const has = o.count > 0;
  const edit = !!o.canEdit;
  const items = [];
  if (has) {
    if (edit) items.push(it('cut', 'Cut', 'edit.cut'));
    items.push(it('copy', 'Copy', 'edit.copy'));
  }
  if (edit) {
    items.push(it('paste', 'Paste', 'edit.paste'));
    items.push(it('pastePlain', 'Paste without formatting', 'edit.pastePlain'));
  }
  if (has && edit) {
    items.push(it('duplicate', 'Duplicate', 'edit.duplicate'));
    items.push(it('delete', 'Delete', 'edit.delete', { danger: true }));
    items.push(sep);
    items.push({ heading: 'Arrange' });
    items.push(it('front', 'Bring to front', 'arrange.front'));
    items.push(it('forward', 'Bring forward', 'arrange.forward'));
    items.push(it('backward', 'Send backward', 'arrange.backward'));
    items.push(it('back', 'Send to back', 'arrange.back'));
    items.push(o.hasGroup
      ? it('ungroup', 'Ungroup', 'arrange.ungroup')
      : it('group', 'Group', 'arrange.group', { disabled: o.count < 2 }));
    items.push(it('lock', o.locked ? 'Unlock' : 'Lock', 'arrange.lock'));
    items.push(it('hide', o.hidden ? 'Show' : 'Hide', 'arrange.hide'));
    items.push(sep);
    items.push({ heading: o.count > 1 ? 'Align and flip' : 'Flip' });
    if (o.count > 1) {
      items.push(it('align.left', 'Align left', 'align.left'));
      items.push(it('align.center', 'Align centres', 'align.center'));
      items.push(it('align.right', 'Align right', 'align.right'));
      items.push(it('align.top', 'Align top', 'align.top'));
      items.push(it('align.middle', 'Align middles', 'align.middle'));
      items.push(it('align.bottom', 'Align bottom', 'align.bottom'));
      if (o.count >= 3) {
        items.push(it('distribute.h', 'Distribute horizontally'));
        items.push(it('distribute.v', 'Distribute vertically'));
      }
    }
    items.push(it('flipH', 'Flip horizontally', 'arrange.flipH'));
    items.push(it('flipV', 'Flip vertically', 'arrange.flipV'));
    items.push(sep);
    items.push(it('copyStyle', 'Copy style', null, { disabled: o.count !== 1 }));
    items.push(it('pasteStyle', 'Paste style', null, { disabled: !o.hasStyle }));
  }
  if (!has && edit) {
    items.push(sep);
    items.push(it('selectAll', 'Select all', 'edit.selectAll'));
  }
  if (o.canComment && (!o.hasPanel || o.hasPanel('comments'))) {
    items.push(sep);
    items.push(it('comment', 'Add comment here'));
  }
  // trim leading, trailing and doubled separators
  const out = [];
  for (const i of items) {
    if (i.separator && (!out.length || out[out.length - 1].separator)) continue;
    out.push(i);
  }
  while (out.length && out[out.length - 1].separator) out.pop();
  return out;
}
