/**
 * The context menu as data. Pure. The component runs `item.run` names against the editor.
 *
 *   contextItems({count, groupCount, hasGroup, locked, hidden, canEdit, canComment, hasPanel, hasStyle, onElement, shortcuts})
 *      -> [{id, label, shortcut?, disabled?, submenu?: [...] } | {sep: true}]
 *   shortcuts(cmd) -> string   formats the key for a command ('' when none)
 */
export function contextItems(o) {
  const sc = (cmd) => (o.shortcuts ? o.shortcuts(cmd) : '');
  const it = (id, label, cmd, extra = {}) => ({ id, label, shortcut: cmd ? sc(cmd) : '', ...extra });
  const sep = { sep: true };
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
    items.push(it('delete', 'Delete', 'edit.delete'));
    items.push(sep);
    items.push(o.hasGroup
      ? it('ungroup', 'Ungroup', 'arrange.ungroup')
      : it('group', 'Group', 'arrange.group', { disabled: o.count < 2 }));
    items.push({
      id: 'arrange', label: 'Arrange', submenu: [
        it('front', 'Bring to front', 'arrange.front'),
        it('forward', 'Bring forward', 'arrange.forward'),
        it('backward', 'Send backward', 'arrange.backward'),
        it('back', 'Send to back', 'arrange.back'),
      ],
    });
    items.push({
      id: 'align', label: 'Align', submenu: [
        it('align.left', 'Left', 'align.left'), it('align.center', 'Centre', 'align.center'), it('align.right', 'Right', 'align.right'),
        it('align.top', 'Top', 'align.top'), it('align.middle', 'Middle', 'align.middle'), it('align.bottom', 'Bottom', 'align.bottom'),
        ...(o.count >= 3 ? [sep, it('distribute.h', 'Distribute horizontally'), it('distribute.v', 'Distribute vertically')] : []),
      ],
    });
    items.push({ id: 'flip', label: 'Flip', submenu: [it('flipH', 'Horizontal', 'arrange.flipH'), it('flipV', 'Vertical', 'arrange.flipV')] });
    items.push(it('lock', o.locked ? 'Unlock' : 'Lock', 'arrange.lock'));
    items.push(it('hide', o.hidden ? 'Show' : 'Hide', 'arrange.hide'));
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
    if (i.sep && (!out.length || out[out.length - 1].sep)) continue;
    out.push(i);
  }
  while (out.length && out[out.length - 1].sep) out.pop();
  return out;
}
