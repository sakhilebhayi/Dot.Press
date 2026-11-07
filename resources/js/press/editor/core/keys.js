/**
 * The keyboard map as data, used for both handling keys and drawing the help overlay.
 *
 * Entry: { cmd, label, group, scopes, keys, mac?, other?, args? }
 *   cmd     command id the shell maps to a store method ('edit.undo', 'arrange.group', ...)
 *   scopes  where it is active: 'canvas' (slide canvas focused, not editing text), 'spine' (slide list),
 *           'text' (editing text), 'present' (presenting), 'any' (all of those except 'text')
 *   keys    combos used on every platform; mac / other replace `keys` on that platform
 *   args    extra data handed to the command (nudge: {dx, dy})
 *
 * Combo syntax: modifiers joined by '+': Mod (Command on Mac, Control elsewhere), Shift, Alt, then a key:
 * a letter or digit (case insensitive), a punctuation character, or a name (Enter, Escape, Delete,
 * Backspace, Tab, ArrowUp, ArrowDown, ArrowLeft, ArrowRight, PageUp, PageDown, Plus, F2).
 *
 * API
 *   KEYMAP                                        the data
 *   matchShortcut(event, scope, {mac}) -> {cmd, entry, args} | null
 *        event is event-like: {key, code?, metaKey, ctrlKey, shiftKey, altKey}. Letters are read from
 *        `code` when present, so Alt+letter works on a Mac keyboard.
 *   formatCombo(combo, mac) -> '⌘⇧G' on Mac, 'Ctrl+Shift+G' elsewhere
 *   shortcutsFor(cmd, mac) -> ['⌘D']
 *   helpGroups(mac) -> [{group, items: [{cmd, label, shortcuts: [...], scopes}]}]
 */

const E = (cmd, label, group, scopes, keys, extra = {}) => ({ cmd, label, group, scopes, keys, ...extra });

export const KEYMAP = [
  // edit
  E('edit.undo', 'Undo', 'Edit', ['any'], ['Mod+z']),
  E('edit.redo', 'Redo', 'Edit', ['any'], ['Mod+Shift+z'], { other: ['Ctrl+y', 'Ctrl+Shift+z'] }),
  E('edit.copy', 'Copy', 'Edit', ['canvas', 'spine'], ['Mod+c']),
  E('edit.cut', 'Cut', 'Edit', ['canvas', 'spine'], ['Mod+x']),
  E('edit.paste', 'Paste', 'Edit', ['canvas', 'spine'], ['Mod+v']),
  E('edit.pastePlain', 'Paste without formatting', 'Edit', ['canvas'], ['Mod+Shift+v']),
  E('edit.duplicate', 'Duplicate', 'Edit', ['canvas'], ['Mod+d']),
  E('edit.delete', 'Delete', 'Edit', ['canvas'], ['Delete', 'Backspace']),
  E('edit.selectAll', 'Select all', 'Edit', ['canvas'], ['Mod+a']),
  E('edit.deselect', 'Clear selection', 'Edit', ['canvas'], ['Escape']),
  E('edit.find', 'Find', 'Edit', ['any'], ['Mod+f']),
  E('edit.rename', 'Rename', 'Edit', ['canvas', 'spine'], ['F2']),
  E('select.next', 'Select next element', 'Edit', ['canvas'], ['Tab']),
  E('select.prev', 'Select previous element', 'Edit', ['canvas'], ['Shift+Tab']),
  // arrange
  E('arrange.group', 'Group', 'Arrange', ['canvas'], ['Mod+g']),
  E('arrange.ungroup', 'Ungroup', 'Arrange', ['canvas'], ['Mod+Shift+g']),
  E('arrange.forward', 'Bring forward', 'Arrange', ['canvas'], ['Mod+]']),
  E('arrange.backward', 'Send backward', 'Arrange', ['canvas'], ['Mod+[']),
  E('arrange.front', 'Bring to front', 'Arrange', ['canvas'], ['Mod+Shift+]']),
  E('arrange.back', 'Send to back', 'Arrange', ['canvas'], ['Mod+Shift+[']),
  E('arrange.lock', 'Lock or unlock', 'Arrange', ['canvas'], ['Mod+Shift+l']),
  E('arrange.hide', 'Hide or show', 'Arrange', ['canvas'], ['Mod+Shift+h']),
  E('arrange.flipH', 'Flip horizontally', 'Arrange', ['canvas'], ['Shift+h']),
  E('arrange.flipV', 'Flip vertically', 'Arrange', ['canvas'], ['Shift+v']),
  E('align.left', 'Align left', 'Align', ['canvas'], ['Alt+a']),
  E('align.center', 'Align horizontal centres', 'Align', ['canvas'], ['Alt+h']),
  E('align.right', 'Align right', 'Align', ['canvas'], ['Alt+d']),
  E('align.top', 'Align top', 'Align', ['canvas'], ['Alt+w']),
  E('align.middle', 'Align vertical centres', 'Align', ['canvas'], ['Alt+v']),
  E('align.bottom', 'Align bottom', 'Align', ['canvas'], ['Alt+s']),
  // nudge
  E('nudge.left', 'Nudge left by 1', 'Move', ['canvas'], ['ArrowLeft'], { args: { dx: -1, dy: 0 } }),
  E('nudge.right', 'Nudge right by 1', 'Move', ['canvas'], ['ArrowRight'], { args: { dx: 1, dy: 0 } }),
  E('nudge.up', 'Nudge up by 1', 'Move', ['canvas'], ['ArrowUp'], { args: { dx: 0, dy: -1 } }),
  E('nudge.down', 'Nudge down by 1', 'Move', ['canvas'], ['ArrowDown'], { args: { dx: 0, dy: 1 } }),
  E('nudge.left10', 'Nudge left by 10', 'Move', ['canvas'], ['Shift+ArrowLeft'], { args: { dx: -10, dy: 0 } }),
  E('nudge.right10', 'Nudge right by 10', 'Move', ['canvas'], ['Shift+ArrowRight'], { args: { dx: 10, dy: 0 } }),
  E('nudge.up10', 'Nudge up by 10', 'Move', ['canvas'], ['Shift+ArrowUp'], { args: { dx: 0, dy: -10 } }),
  E('nudge.down10', 'Nudge down by 10', 'Move', ['canvas'], ['Shift+ArrowDown'], { args: { dx: 0, dy: 10 } }),
  // slides
  E('slide.new', 'New slide', 'Slides', ['spine'], ['Enter', 'Mod+m']),
  E('slide.newAnywhere', 'New slide', 'Slides', ['canvas'], ['Mod+m']),
  E('slide.duplicate', 'Duplicate slide', 'Slides', ['spine'], ['Mod+d']),
  E('slide.duplicateAnywhere', 'Duplicate slide', 'Slides', ['canvas'], ['Mod+Shift+d']),
  E('slide.delete', 'Delete slide', 'Slides', ['spine'], ['Delete', 'Backspace']),
  E('slide.next', 'Next slide', 'Slides', ['canvas', 'spine'], ['PageDown']),
  E('slide.prev', 'Previous slide', 'Slides', ['canvas', 'spine'], ['PageUp']),
  E('slide.nextSpine', 'Next slide', 'Slides', ['spine'], ['ArrowDown']),
  E('slide.prevSpine', 'Previous slide', 'Slides', ['spine'], ['ArrowUp']),
  // view
  E('view.zoomIn', 'Zoom in', 'View', ['any'], ['Mod+=', 'Mod+Plus']),
  E('view.zoomOut', 'Zoom out', 'View', ['any'], ['Mod+-']),
  E('view.zoomFit', 'Zoom to fit', 'View', ['any'], ['Mod+0']),
  E('view.zoom100', 'Zoom to 100 percent', 'View', ['any'], ['Mod+1']),
  E('view.grid', 'Toggle grid', 'View', ['any'], ["Mod+'"]),
  E('view.guides', 'Toggle guides', 'View', ['any'], ['Mod+;']),
  E('view.help', 'Keyboard shortcuts', 'View', ['canvas', 'spine'], ['?']),
  E('present.start', 'Present', 'Present', ['any'], ['Mod+Enter']),
  E('present.fromStart', 'Present from the first slide', 'Present', ['any'], ['Mod+Shift+Enter']),
  E('present.exit', 'Exit presenting', 'Present', ['present'], ['Escape']),
  // text
  E('text.bold', 'Bold', 'Text', ['text', 'canvas'], ['Mod+b']),
  E('text.italic', 'Italic', 'Text', ['text', 'canvas'], ['Mod+i']),
  E('text.underline', 'Underline', 'Text', ['text', 'canvas'], ['Mod+u']),
  E('text.link', 'Link', 'Text', ['text', 'canvas'], ['Mod+k']),
  E('text.alignLeft', 'Align text left', 'Text', ['text'], ['Mod+Shift+l']),
  E('text.alignCenter', 'Centre text', 'Text', ['text'], ['Mod+Shift+e']),
  E('text.alignRight', 'Align text right', 'Text', ['text'], ['Mod+Shift+r']),
  E('text.done', 'Finish editing text', 'Text', ['text'], ['Escape']),
];

// ---- parsing ----------------------------------------------------------------------------------

const NAMES = {
  enter: 'Enter', escape: 'Escape', esc: 'Escape', delete: 'Delete', backspace: 'Backspace', tab: 'Tab',
  arrowup: 'ArrowUp', arrowdown: 'ArrowDown', arrowleft: 'ArrowLeft', arrowright: 'ArrowRight',
  pageup: 'PageUp', pagedown: 'PageDown', plus: 'Plus', f2: 'F2', ' ': 'Space',
};
const CODE_CHARS = {
  BracketLeft: '[', BracketRight: ']', Equal: '=', Minus: '-', Quote: "'", Semicolon: ';',
};

function parseCombo(combo) {
  const parts = combo.split('+');
  // a trailing '+' key is spelled Plus, so a plain split is safe; '=' and '-' are real keys
  const key = parts.pop();
  const mods = new Set(parts.map((p) => p.toLowerCase()));
  const k = key.length === 1 ? key.toLowerCase() : (NAMES[key.toLowerCase()] || key);
  return {
    mod: mods.has('mod'), ctrl: mods.has('ctrl'), shift: mods.has('shift'), alt: mods.has('alt'), key: k,
  };
}

function eventKey(e) {
  const code = e.code || '';
  if (/^Key[A-Z]$/.test(code)) return code.slice(3).toLowerCase();
  if (/^Digit[0-9]$/.test(code)) return code.slice(5);
  if (CODE_CHARS[code] && (e.altKey || e.shiftKey || !e.key || e.key.length !== 1)) return CODE_CHARS[code];
  const k = e.key;
  if (k == null) return '';
  if (k === '+') return 'Plus';
  if (k === ' ') return 'Space';
  return k.length === 1 ? k.toLowerCase() : (NAMES[k.toLowerCase()] || k);
}

const SHIFT_FREE = new Set(['?', 'Plus', '=']);

function combosOf(entry, mac) {
  const list = mac ? entry.mac || entry.keys : entry.other || entry.keys;
  return list;
}

const PARSED = new Map();
const parsed = (c) => { if (!PARSED.has(c)) PARSED.set(c, parseCombo(c)); return PARSED.get(c); };

function inScope(entry, scope) {
  if (entry.scopes.includes(scope)) return true;
  return scope !== 'text' && scope !== 'present' && entry.scopes.includes('any');
}

export function matchShortcut(event, scope = 'canvas', { mac = false } = {}) {
  const key = eventKey(event);
  for (const entry of KEYMAP) {
    if (!inScope(entry, scope)) continue;
    for (const combo of combosOf(entry, mac)) {
      const c = parsed(combo);
      if (c.key !== key) continue;
      // Mac: Mod is Command and Ctrl is the Control key. Elsewhere Mod and Ctrl are both Control.
      const wantMeta = mac ? c.mod : false;
      const wantCtrl = mac ? c.ctrl : c.mod || c.ctrl;
      if (wantMeta !== !!event.metaKey || wantCtrl !== !!event.ctrlKey) continue;
      if (c.alt !== !!event.altKey) continue;
      // shift is part of how these keys are typed, so it never counts against them
      if (!SHIFT_FREE.has(key) && c.shift !== !!event.shiftKey) continue;
      return { cmd: entry.cmd, entry, args: entry.args || null };
    }
  }
  return null;
}

// ---- display ----------------------------------------------------------------------------------

const MAC_SYMBOL = { mod: '⌘', ctrl: '⌃', shift: '⇧', alt: '⌥' };
const KEY_LABEL = {
  Enter: 'Enter', Escape: 'Esc', Delete: 'Del', Backspace: 'Backspace', Tab: 'Tab', ArrowUp: '↑', ArrowDown: '↓',
  ArrowLeft: '←', ArrowRight: '→', PageUp: 'Page Up', PageDown: 'Page Down', Plus: '+', F2: 'F2',
};

export function formatCombo(combo, mac = false) {
  const c = parsed(combo);
  const k = KEY_LABEL[c.key] || (c.key.length === 1 ? c.key.toUpperCase() : c.key);
  if (mac) {
    return `${c.ctrl ? MAC_SYMBOL.ctrl : ''}${c.alt ? MAC_SYMBOL.alt : ''}${c.shift ? MAC_SYMBOL.shift : ''}${c.mod ? MAC_SYMBOL.mod : ''}${k}`;
  }
  const parts = [];
  if (c.mod || c.ctrl) parts.push('Ctrl');
  if (c.alt) parts.push('Alt');
  if (c.shift) parts.push('Shift');
  parts.push(k);
  return parts.join('+');
}

export function shortcutsFor(cmd, mac = false) {
  const entry = KEYMAP.find((e) => e.cmd === cmd);
  return entry ? combosOf(entry, mac).map((c) => formatCombo(c, mac)) : [];
}

export function helpGroups(mac = false) {
  const groups = new Map();
  const seen = new Set();
  for (const e of KEYMAP) {
    const label = `${e.group}:${e.label}`;
    if (seen.has(label)) continue; // 'New slide' on canvas and on spine is one row
    seen.add(label);
    if (!groups.has(e.group)) groups.set(e.group, []);
    const merged = KEYMAP.filter((o) => o.group === e.group && o.label === e.label);
    const shortcuts = [...new Set(merged.flatMap((o) => combosOf(o, mac).map((c) => formatCombo(c, mac))))];
    groups.get(e.group).push({ cmd: e.cmd, label: e.label, shortcuts, scopes: [...new Set(merged.flatMap((o) => o.scopes))] });
  }
  return [...groups.entries()].map(([group, items]) => ({ group, items }));
}
