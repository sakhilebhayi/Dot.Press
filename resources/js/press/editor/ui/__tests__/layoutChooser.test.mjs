import test from 'node:test';
import assert from 'node:assert/strict';
import { BUILTIN_LAYOUTS, BUILTIN_IDS, mergeLibrary, libraryList, glyphFromDefinition, GLYPH_W, GLYPH_H } from '../layoutChooser.js';

const SPEC_IDS = ['title', 'section', 'bullets', 'two', 'image-left', 'image-right', 'image-full', 'compare', 'timeline', 'quote', 'data', 'kpi', 'closing', 'blank'];

test('the chooser lists exactly the layout ids of the document spec, once each', () => {
  assert.deepEqual(BUILTIN_IDS, SPEC_IDS);
  assert.equal(new Set(BUILTIN_IDS).size, BUILTIN_IDS.length);
});

test('every glyph stays inside its box', () => {
  for (const l of BUILTIN_LAYOUTS) {
    for (const r of l.glyph) {
      assert.ok(r.x >= 0 && r.y >= 0 && r.x + r.w <= GLYPH_W + 1e-9 && r.y + r.h <= GLYPH_H + 1e-9, `${l.id} ${JSON.stringify(r)}`);
    }
  }
  assert.deepEqual(BUILTIN_LAYOUTS.find((l) => l.id === 'blank').glyph, []);
});

test('without a library the built in list is returned with no definitions', () => {
  for (const payload of [null, undefined, {}, [], { layouts: 'x' }]) {
    const items = mergeLibrary(payload);
    assert.deepEqual(items.map((i) => i.id), SPEC_IDS);
    assert.ok(items.every((i) => i.def === null && !i.custom));
  }
});

test('a library replaces glyphs and names, and appends custom layouts', () => {
  const def = { id: 'title', name: 'Cover', ph: [{ role: 'title', type: 'text', x: 0.1, y: 0.3, w: 0.8, h: 0.2 }, { role: 'media', type: 'media', x: 0.5, y: 0.6, w: 0.4, h: 0.3 }] };
  const custom = { id: 'custom:a1', name: 'Mine', ph: [{ role: 'body', type: 'text', x: 0, y: 0, w: 1, h: 1 }] };
  for (const payload of [[def, custom], { layouts: [def, custom] }, { data: [def, custom] }]) {
    const items = mergeLibrary(payload);
    assert.equal(items.length, SPEC_IDS.length + 1);
    assert.equal(items[0].name, 'Cover');
    assert.equal(items[0].def, def);
    assert.deepEqual(items[0].glyph.map((g) => g.k), ['t', 'm']);
    assert.equal(items.at(-1).id, 'custom:a1');
    assert.equal(items.at(-1).custom, true);
  }
});

test('malformed library entries are ignored', () => {
  assert.deepEqual(libraryList([{ id: 3, ph: [] }, { id: 'x' }, null, { id: 'ok', ph: [] }]).map((d) => d.id), ['ok']);
  const g = glyphFromDefinition({ ph: [{ role: 'title', type: 'text', x: -1, y: 2, w: 5, h: 0 }] });
  assert.ok(g[0].x >= 0 && g[0].y <= GLYPH_H && g[0].w <= GLYPH_W && g[0].h > 0);
});
