/**
 * Formatting for the Text panel: the stage's own format helpers when it offers them (`editor.stage.format`), so that while a text
 * session is open (`ui.editingText`) bold, italic, underline, strike, colour, size, font and link act on the selected words inside
 * the editor, and otherwise on the whole element. If the stage does not offer them the panel falls back to whole element edits.
 *
 *   const fmt = useTextFormat(editor, elementsRef, pc)
 *   fmt.live            true while a text session is open on one of the selected elements
 *   fmt.get(name)       'bold' | 'italic' | 'underline' | 'strike' | 'link' | 'font' | 'size' | 'color' | 'align'
 *   fmt.set(name, v)    font, size, color, align, link
 *   fmt.toggle(name)    bold, italic, underline, strike
 */
import { computed } from 'vue';
import {
  docMarkOn, docSetMark, docSetTextStyle, normalizeFontSize,
} from '../../stage/lib/textdoc.js';
import { safeHref } from '../../../render/doc.js';
import { resolveWeight, normalizeTheme } from '../../../render/theme.js';

const MARKS = { bold: 'bold', italic: 'italic', underline: 'underline', strike: 'strike' };

export function useTextFormat(editor, els, pc) {
  const stage = () => editor.stage?.format || null;
  const live = computed(() => !!editor.ui.editingText && els.value.some((e) => e.id === editor.ui.editingText) && !!stage()?.session?.());
  const theme = () => normalizeTheme(editor.deck.value?.theme);

  function localGet(name) {
    const first = els.value[0];
    if (!first) return undefined;
    switch (name) {
      case 'bold': return els.value.every((e) => docMarkOn(e.doc, 'bold') || resolveWeight(e, theme()) >= 600);
      case 'italic': case 'underline': case 'strike': return els.value.every((e) => docMarkOn(e.doc, MARKS[name]));
      case 'link': return !!first.link;
      case 'font': return first.font || '@body';
      case 'size': return first.size || 48;
      case 'color': return first.color || '@ink';
      case 'align': return first.align || 'left';
      default: return undefined;
    }
  }
  function localSet(name, value) {
    const clear = (key) => (e) => (e.doc ? { doc: docSetTextStyle(e.doc, key, null) } : {});
    switch (name) {
      case 'font': pc.each((e) => ({ font: value, ...clear('fontFamily')(e) }), { undoKey: `fmt:font:${els.value.map((e) => e.id)}`, label: 'Font' }); break;
      case 'size': pc.each((e) => ({ size: Math.max(1, Math.round(+value * 100) / 100), ...clear('fontSize')(e) }), { undoKey: `fmt:size:${els.value.map((e) => e.id)}`, label: 'Font size' }); break;
      case 'color': pc.each((e) => ({ color: value, ...clear('color')(e) }), { undoKey: `fmt:color:${els.value.map((e) => e.id)}`, label: 'Text colour' }); break;
      case 'align': pc.set('align', value, { label: 'Align text' }); break;
      case 'link': {
        const href = value ? safeHref(value) : null;
        if (value && !href) return;
        pc.set('link', href, { label: 'Link' });
        break;
      }
      default: break;
    }
  }
  function localToggle(name) {
    const on = !localGet(name);
    pc.each((e) => {
      if (!e.doc) return {};
      const p = { doc: docSetMark(e.doc, MARKS[name], null, on) };
      if (name === 'bold' && !on && resolveWeight(e, theme()) >= 600) p.weight = 400;
      return p;
    }, { label: name[0].toUpperCase() + name.slice(1) });
  }

  return {
    live,
    editing: computed(() => !!editor.ui.editingText && els.value.some((e) => e.id === editor.ui.editingText)),
    get(name) {
      void editor.state.value;
      const f = stage();
      return f ? f.get(name, els.value) : localGet(name);
    },
    set(name, value) {
      if (!pc.canEdit.value) return;
      if (name === 'size') { const px = normalizeFontSize(value); if (!px) return; }
      const f = stage();
      if (f) f.set(name, value, els.value); else localSet(name, value);
    },
    toggle(name) {
      if (!pc.canEdit.value) return;
      const f = stage();
      if (f) f.toggle(name, els.value); else localToggle(name);
    },
  };
}
