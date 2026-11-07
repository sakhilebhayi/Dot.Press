import test from 'node:test';
import assert from 'node:assert/strict';
import { KEYMAP, matchShortcut, formatCombo, shortcutsFor, helpGroups } from '../keys.js';

const ev = (key, mods = {}, extra = {}) => ({ key, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false, ...mods, ...extra });
const cmd = (e, scope = 'canvas', mac = false) => matchShortcut(e, scope, { mac })?.cmd ?? null;

test('every required command has a shortcut', () => {
  const need = ['edit.undo', 'edit.redo', 'edit.copy', 'edit.cut', 'edit.paste', 'edit.pastePlain', 'edit.duplicate', 'edit.delete', 'edit.selectAll',
    'arrange.group', 'arrange.ungroup', 'arrange.forward', 'arrange.backward', 'arrange.front', 'arrange.back', 'nudge.left', 'nudge.left10', 'slide.new',
    'slide.duplicate', 'slide.next', 'slide.prev', 'view.zoomIn', 'view.zoomOut', 'view.zoomFit', 'view.zoom100', 'view.grid', 'view.guides', 'edit.rename',
    'edit.find', 'present.start', 'text.bold', 'text.italic', 'text.underline', 'text.link', 'align.left', 'align.center', 'align.right', 'align.top',
    'align.middle', 'align.bottom', 'arrange.lock', 'arrange.hide'];
  for (const c of need) assert.ok(KEYMAP.some((e) => e.cmd === c), c);
  for (const e of KEYMAP) {
    assert.ok(e.label && e.group && e.scopes.length && e.keys.length, e.cmd);
  }
});

test('Mac uses Command, other platforms Control', () => {
  assert.equal(cmd(ev('z', { metaKey: true }), 'canvas', true), 'edit.undo');
  assert.equal(cmd(ev('z', { ctrlKey: true }), 'canvas', true), null, 'Control+Z is not undo on a Mac');
  assert.equal(cmd(ev('z', { ctrlKey: true })), 'edit.undo');
  assert.equal(cmd(ev('z', { metaKey: true })), null);
});

test('redo has a Mac form and two other forms', () => {
  assert.equal(cmd(ev('z', { metaKey: true, shiftKey: true }), 'canvas', true), 'edit.redo');
  assert.equal(cmd(ev('y', { ctrlKey: true })), 'edit.redo');
  assert.equal(cmd(ev('z', { ctrlKey: true, shiftKey: true })), 'edit.redo');
  assert.equal(cmd(ev('y', { metaKey: true }), 'canvas', true), null);
});

test('clipboard, duplicate, delete, select all, group, ungroup', () => {
  const m = (k, o = {}) => cmd(ev(k, { metaKey: true, ...o }), 'canvas', true);
  assert.equal(m('c'), 'edit.copy');
  assert.equal(m('x'), 'edit.cut');
  assert.equal(m('v'), 'edit.paste');
  assert.equal(m('v', { shiftKey: true }), 'edit.pastePlain');
  assert.equal(m('d'), 'edit.duplicate');
  assert.equal(m('a'), 'edit.selectAll');
  assert.equal(m('g'), 'arrange.group');
  assert.equal(m('g', { shiftKey: true }), 'arrange.ungroup');
  assert.equal(cmd(ev('Delete')), 'edit.delete');
  assert.equal(cmd(ev('Backspace')), 'edit.delete');
});

test('layer shortcuts: brackets, shift for the ends, also when Shift changes the typed character', () => {
  const m = (k, o = {}, code) => cmd(ev(k, { metaKey: true, ...o }, { code }), 'canvas', true);
  assert.equal(m(']'), 'arrange.forward');
  assert.equal(m('['), 'arrange.backward');
  assert.equal(m('}', { shiftKey: true }, 'BracketRight'), 'arrange.front');
  assert.equal(m('{', { shiftKey: true }, 'BracketLeft'), 'arrange.back');
});

test('nudge by 1 and by 10', () => {
  const r = matchShortcut(ev('ArrowLeft'), 'canvas');
  assert.deepEqual(r.args, { dx: -1, dy: 0 });
  assert.deepEqual(matchShortcut(ev('ArrowDown', { shiftKey: true }), 'canvas').args, { dx: 0, dy: 10 });
  assert.equal(matchShortcut(ev('ArrowUp'), 'text'), null, 'arrows belong to the text editor while typing');
});

test('slides: Enter on the spine, Cmd+M anywhere, duplicate, next and previous', () => {
  assert.equal(cmd(ev('Enter'), 'spine'), 'slide.new');
  assert.equal(cmd(ev('Enter'), 'canvas'), null);
  assert.equal(cmd(ev('m', { metaKey: true }), 'canvas', true), 'slide.newAnywhere');
  assert.equal(cmd(ev('m', { metaKey: true }), 'spine', true), 'slide.new');
  assert.equal(cmd(ev('d', { metaKey: true }), 'spine', true), 'slide.duplicate');
  assert.equal(cmd(ev('d', { metaKey: true }), 'canvas', true), 'edit.duplicate');
  assert.equal(cmd(ev('d', { metaKey: true, shiftKey: true }), 'canvas', true), 'slide.duplicateAnywhere');
  assert.equal(cmd(ev('PageDown'), 'canvas'), 'slide.next');
  assert.equal(cmd(ev('PageUp'), 'canvas'), 'slide.prev');
  assert.equal(cmd(ev('ArrowDown'), 'spine'), 'slide.nextSpine');
  assert.equal(cmd(ev('Delete'), 'spine'), 'slide.delete');
});

test('zoom, grid, guides, find, rename, present', () => {
  const m = (k, o = {}, code) => cmd(ev(k, { metaKey: true, ...o }, { code }), 'canvas', true);
  assert.equal(m('='), 'view.zoomIn');
  assert.equal(m('+', { shiftKey: true }), 'view.zoomIn');
  assert.equal(m('-'), 'view.zoomOut');
  assert.equal(m('0'), 'view.zoomFit');
  assert.equal(m('1'), 'view.zoom100');
  assert.equal(m("'"), 'view.grid');
  assert.equal(m(';'), 'view.guides');
  assert.equal(m('f'), 'edit.find');
  assert.equal(m('Enter'), 'present.start');
  assert.equal(m('Enter', { shiftKey: true }), 'present.fromStart');
  assert.equal(cmd(ev('F2')), 'edit.rename');
  assert.equal(cmd(ev('?', { shiftKey: true })), 'view.help');
});

test('alt letter shortcuts work on a Mac keyboard where Alt changes the character', () => {
  const e = ev('å', { altKey: true }, { code: 'KeyA' });
  assert.equal(cmd(e, 'canvas', true), 'align.left');
  assert.equal(cmd(ev('h', { altKey: true }, { code: 'KeyH' })), 'align.center');
  assert.equal(cmd(ev('l', { metaKey: true, shiftKey: true }, { code: 'KeyL' }), 'canvas', true), 'arrange.lock');
  assert.equal(cmd(ev('h', { metaKey: true, shiftKey: true }, { code: 'KeyH' }), 'canvas', true), 'arrange.hide');
});

test('text scope: formatting keys and nothing that steals text editing', () => {
  const m = (k, o = {}) => cmd(ev(k, { metaKey: true, ...o }), 'text', true);
  assert.equal(m('b'), 'text.bold');
  assert.equal(m('i'), 'text.italic');
  assert.equal(m('u'), 'text.underline');
  assert.equal(m('k'), 'text.link');
  assert.equal(m('z'), null);
  assert.equal(m('c'), null);
  assert.equal(m('l', { shiftKey: true }), 'text.alignLeft');
  assert.equal(cmd(ev('Escape'), 'text'), 'text.done');
  assert.equal(cmd(ev('Delete'), 'text'), null);
  assert.equal(cmd(ev('b', { metaKey: true }), 'canvas', true), 'text.bold');
});

test('presenting: Escape exits, editing shortcuts are off', () => {
  assert.equal(cmd(ev('Escape'), 'present'), 'present.exit');
  assert.equal(cmd(ev('Delete'), 'present'), null);
  assert.equal(cmd(ev('z', { metaKey: true }), 'present', true), null);
});

test('extra modifiers do not match: Cmd+Alt+C is not copy', () => {
  assert.equal(cmd(ev('c', { metaKey: true, altKey: true }), 'canvas', true), null);
  assert.equal(cmd(ev('Delete', { metaKey: true }), 'canvas', true), null);
});

test('display forms', () => {
  assert.equal(formatCombo('Mod+Shift+g', true), '⇧⌘G');
  assert.equal(formatCombo('Mod+Shift+g', false), 'Ctrl+Shift+G');
  assert.equal(formatCombo('Alt+a', true), '⌥A');
  assert.equal(formatCombo('ArrowLeft', false), '←');
  assert.deepEqual(shortcutsFor('edit.duplicate', true), ['⌘D']);
  assert.deepEqual(shortcutsFor('edit.redo', false), ['Ctrl+Y', 'Ctrl+Shift+Z']);
  assert.deepEqual(shortcutsFor('nope'), []);
});

test('help overlay data merges duplicate labels and covers every group', () => {
  const groups = helpGroups(true);
  assert.ok(groups.length >= 8);
  const slides = groups.find((g) => g.group === 'Slides');
  const news = slides.items.filter((i) => i.label === 'New slide');
  assert.equal(news.length, 1);
  assert.deepEqual(news[0].shortcuts.sort(), ['Enter', '⌘M'].sort());
  assert.ok(groups.every((g) => g.items.every((i) => i.shortcuts.length)));
});
