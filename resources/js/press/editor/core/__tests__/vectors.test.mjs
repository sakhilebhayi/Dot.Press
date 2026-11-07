// Runs every shared vector in tests/fixtures/press-ops against applyOps.
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { applyOps } from '../ops.js';
import { counterIds } from './helpers.mjs';

const dir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../../../../tests/fixtures/press-ops');
const files = fs.readdirSync(dir).filter((f) => f.endsWith('.json')).sort();

/** The comparison form documented in the fixtures README. */
export function comparable(doc, normalize = []) {
  const slides = doc.slides.map((s) => {
    const { rev: _r, ...rest } = s;
    if (!normalize.includes(s.id)) return rest;
    const ids = new Map();
    const groups = new Map();
    const els = s.els.map((e, i) => {
      ids.set(e.id, `e${i + 1}`);
      const copy = { ...e, id: `e${i + 1}` };
      if (e.group) {
        if (!groups.has(e.group)) groups.set(e.group, `g${groups.size + 1}`);
        copy.group = groups.get(e.group);
      }
      return copy;
    });
    return { ...rest, els };
  });
  const { rev: _dr, updated_at: _u, ...deck } = doc.deck;
  return { deck, slides };
}

test('there are at least 40 shared vectors', () => {
  assert.ok(files.length >= 40, `found ${files.length}`);
});

for (const f of files) {
  const v = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'));
  test(`vector ${v.name}`, () => {
    assert.equal(f, `${v.name}.json`);
    const input = JSON.stringify(v.doc);
    const r = applyOps(v.doc, v.ops, { makeId: counterIds(), strictTid: true });
    assert.equal(JSON.stringify(v.doc), input, 'input document must not be mutated');
    assert.deepEqual(comparable(r.doc, v.normalize), comparable(v.expected.doc, v.normalize));
    assert.deepEqual(r.skipped.map((s) => ({ index: s.index, reason: s.reason })), v.expected.skipped);
    // the exact inverse brings the original back (ids of duplicated slides are removed with the slide)
    const back = applyOps(r.doc, r.inverse, { makeId: counterIds() });
    assert.deepEqual(back.skipped, [], 'inverse must apply cleanly');
    assert.deepEqual(comparable(back.doc), comparable(v.doc));
  });
}
