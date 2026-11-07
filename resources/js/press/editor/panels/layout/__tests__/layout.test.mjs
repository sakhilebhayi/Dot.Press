import test from 'node:test';
import assert from 'node:assert/strict';
import { applyOps } from '../../../core/ops.js';
import { saveLayoutFromSlide, SAMPLE_LAYOUTS } from '../../../core/layouts.js';
import { mkDoc, S, T, R, mulberry32 } from '../../../core/__tests__/helpers.mjs';
import { textDoc } from '../../../core/doc.js';
import {
  bulkOps, fromCustom, fromLibrary, planLayout, resultLines, sameLayoutSlides, summarise, toServerDefinition, wireframe,
} from '../layoutModel.js';

const { titleBody, twoColumns } = SAMPLE_LAYOUTS;

// ---- wireframe geometry ---------------------------------------------------------------------
test('a wireframe is the placeholder boxes in percent, tagged by what they hold', () => {
  const wf = wireframe([
    { role: 'title', type: 'text', x: 0.06, y: 0.08, w: 0.88, h: 0.16 },
    { role: 'body', type: 'text', x: 0.06, y: 0.3, w: 0.42, h: 0.6 },
    { role: 'media', type: 'media', x: 0.52, y: 0.3, w: 0.42, h: 0.6 },
    { role: 'kpi', type: 'text', x: 0, y: 0, w: 0.1, h: 0.1 },
  ]);
  assert.deepEqual(wf.map((b) => b.kind), ['title', 'body', 'media', 'number']);
  assert.deepEqual([wf[0].left, wf[0].top, wf[0].width, wf[0].height], [6, 8, 88, 16]);
  assert.equal(wf[1].height, 60);
  assert.equal(new Set(wf.map((b) => b.key)).size, 4);
});

test('wireframe boxes never leave the card, even for sloppy input', () => {
  const [b] = wireframe([{ role: 'body', type: 'text', x: 0.9, y: 0.95, w: 0.5, h: 0.5 }, { role: 'body', type: 'text', x: -1, y: 2, w: 0, h: 0 }]);
  assert.ok(b.left + b.width <= 100.0001 && b.top + b.height <= 100.0001);
  const wf = wireframe([{ role: 'body', type: 'text', x: -1, y: 2, w: 0, h: 0 }]);
  assert.ok(wf[0].width >= 1 && wf[0].left >= 0 && wf[0].top <= 100);
  assert.deepEqual(wireframe(undefined), []);
});

// ---- server and core layout formats ---------------------------------------------------------
test('a library entry becomes a core definition unchanged', () => {
  const def = fromLibrary({ id: 'two', name: 'Two columns', ph: twoColumns.ph, description: 'x' });
  assert.deepEqual(Object.keys(def).sort(), ['id', 'name', 'ph']);
  assert.equal(def.ph.length, 3);
});

test('a team layout from the API becomes placeholders; slide units are read against 1920 x 1080', () => {
  const def = fromCustom({
    id: 'custom:9', name: 'Site photo', definition: [
      { id: 'p1', type: 'text', role: 'title', units: 'fraction', x: 0.05, y: 0.05, w: 0.9, h: 0.15, size: 108, font: '@heading', weight: 700, hint: 'Headline' },
      { id: 'p2', type: 'image', units: 'slide', x: 960, y: 270, w: 864, h: 540 },
      { id: 'p3', type: 'audio', units: 'fraction', x: 0, y: 0, w: 0.1, h: 0.1 },
      { id: 'p4', type: 'text', units: 'fraction', x: 0.05, y: 0.3, w: 0.4, h: 0.5 },
    ],
  });
  assert.equal(def.id, 'custom:9');
  assert.equal(def.ph.length, 3); // audio is skipped
  assert.equal(def.ph[0].sizeF, 0.1);
  assert.equal(def.ph[0].text, 'Headline');
  assert.deepEqual(def.ph[0].style, { font: '@heading', weight: 700 });
  assert.deepEqual([def.ph[1].type, def.ph[1].role, def.ph[1].x, def.ph[1].w], ['media', 'media', 0.5, 0.45]);
  assert.equal(def.ph[2].role, 'body');
});

test('saving a slide produces a definition the server accepts: fractions inside the slide, known roles, valid styles', () => {
  const slide = S('s1', [
    T('t1', 100, 80, 1700, 200, { role: 'title', size: 100, font: '@heading', weight: 700, color: '@ink', align: 'left', valign: 'top', fit: 'none', lh: 1.2, doc: textDoc('Headline') }),
    T('t2', 100, 400, 800, 400, { size: 44, doc: textDoc('Body text') }),
    { id: 'i1', type: 'image', x: 1000, y: 400, w: 1200, h: 400, rot: 0, op: 1, lock: false, hide: false }, // runs past the right edge
    R('r1', 0, 0, 10, 10, {}), // no role: not a placeholder
  ]);
  const core = saveLayoutFromSlide(slide, { w: 1920, h: 1080 }, { name: 'My layout' });
  const def = toServerDefinition(core);
  assert.equal(def.length, 3);
  assert.deepEqual(def.map((d) => d.type), ['text', 'text', 'image']);
  assert.ok(def.every((d) => d.units === 'fraction' && d.x >= 0 && d.y >= 0 && d.x + d.w <= 1.0001 && d.y + d.h <= 1.0001 && d.w > 0 && d.h > 0));
  assert.deepEqual(def.map((d) => d.id), ['p1', 'p2', 'p3']);
  assert.equal(def[0].size, 100);
  assert.equal(def[0].hint, 'Headline');
  assert.equal(def[0].weight, 700);
  assert.equal(def[0].lh, 1.2);
  assert.ok(!('x' in { ...def[1], x: undefined }) || def[1].role === 'body');
  assert.ok(Math.abs(def[2].x + def[2].w - 1) < 0.0001); // clamped at the edge
});

test('invalid style values are dropped rather than sent', () => {
  const def = toServerDefinition({ ph: [{ role: 'body', type: 'text', x: 0, y: 0, w: 1, h: 1, style: { weight: 'bold', align: 'diagonal', fit: 'wrap', lh: 9, color: '@ink', valign: 'middle' } }] });
  assert.deepEqual(def[0].weight, undefined);
  assert.equal(def[0].align, undefined);
  assert.equal(def[0].fit, undefined);
  assert.equal(def[0].lh, undefined);
  assert.equal(def[0].color, '@ink');
  assert.equal(def[0].valign, 'middle');
});

test('an empty slide cannot be saved and the element cap is respected', () => {
  assert.deepEqual(toServerDefinition({ ph: [] }), []);
  const many = { ph: Array.from({ length: 60 }, () => ({ role: 'body', type: 'text', x: 0, y: 0, w: 0.1, h: 0.1 })) };
  assert.equal(toServerDefinition(many, 40).length, 40);
  assert.equal(toServerDefinition({ ph: [{ role: 'body', type: 'text', x: 0.5, y: 0.5, w: 0, h: 0.1 }] }).length, 0);
});

// ---- plan and report -------------------------------------------------------------------------
const doc = () => mkDoc([
  S('s1', [
    T('a', 10, 10, 300, 100, { role: 'title', doc: textDoc('Claim') }),
    T('b', 10, 300, 300, 100, { doc: textDoc('Some text') }),
    R('c', 500, 500, 50, 50, { name: 'Logo bar', lock: true }),
  ], { layout: 'bullets' }),
  S('s2', [T('d', 0, 0, 100, 50, { role: 'title' })], { layout: 'bullets' }),
  S('s3', [], { layout: 'title' }),
]);

test('the plan never removes anything and reports what would be matched, added and left', () => {
  const d = doc();
  const plan = planLayout(d, 's1', twoColumns, { rng: mulberry32(1) });
  assert.ok(plan.ops.every((o) => o.op !== 'el.remove'));
  const s = summarise(plan, d.slides[0]);
  assert.equal(s.matched, 2); // the title by role, the loose text by type
  assert.equal(s.moved, 2);
  assert.equal(s.added, 1); // the second column has nothing to take
  assert.equal(s.left, 1);
  assert.equal(s.locked, 1);
  assert.deepEqual(s.leftNames, ['Logo bar']);
  assert.deepEqual(s.addedRoles, ['text']);
  assert.equal(d.slides[0].els.length, 3); // untouched
});

test('elements already in place are counted as such, not as moved', () => {
  const d = mkDoc([S('s1', [
    T('t', 115.2, 86.4, 1689.6, 172.8, { role: 'title' }),
    T('b', 115.2, 324, 1689.6, 648, { role: 'body' }),
  ])]);
  const s = summarise(planLayout(d, 's1', titleBody), d.slides[0]);
  assert.deepEqual([s.matched, s.moved, s.inPlace, s.added, s.left], [2, 0, 2, 0, 0]);
});

test('the result is written in the future before applying and in the past after', () => {
  const d = doc();
  const s = summarise(planLayout(d, 's1', twoColumns, { rng: mulberry32(1) }), d.slides[0]);
  const plan = resultLines(s, 'plan');
  const done = resultLines(s, 'done');
  assert.match(plan[0], /^2 elements move into the layout's boxes\.$/);
  assert.match(done[0], /^2 elements moved into the layout's boxes\.$/);
  assert.ok(plan.includes('1 empty placeholder is added (text).'));
  assert.ok(done.includes('1 empty placeholder added (text).'));
  assert.equal(plan.at(-1), '1 element stays where it is (Logo bar). It is locked.');
  assert.equal(done.at(-1), '1 element was left where it was (Logo bar). It is locked.');
  const two = resultLines({ moved: 0, inPlace: 0, added: 0, left: 3, locked: 1, leftNames: ['a', 'b', 'c', 'd'].slice(0, 3) }, 'plan');
  assert.equal(two[0], '3 elements stay where they are (a, b, c). 1 is locked.');
  assert.deepEqual(resultLines({ moved: 0, inPlace: 0, added: 0, left: 0 }, 'plan'), ['Nothing on the slide needs to change.']);
  assert.deepEqual(resultLines({ moved: 0, inPlace: 0, added: 0, left: 0 }, 'done'), ['Nothing needed to change.']);
});

test('slides of the same kind are the ones with the same layout id', () => {
  const d = doc();
  assert.deepEqual(sameLayoutSlides(d, d.slides[0]).map((s) => s.id), ['s1', 's2']);
  assert.deepEqual(sameLayoutSlides(d, d.slides[2]).map((s) => s.id), ['s3']);
  assert.deepEqual(sameLayoutSlides(d, null), []);
});

test('applying to all slides of a kind makes one batch that sets the layout everywhere and deletes nothing', () => {
  const d = doc();
  const b = bulkOps(d, ['s1', 's2'], twoColumns, { rng: mulberry32(5) });
  assert.equal(b.slides, 2);
  assert.equal(b.touched, 2);
  assert.ok(b.ops.every((o) => o.op !== 'el.remove'));
  assert.equal(b.ops.filter((o) => o.op === 'slide.set').length, 2);
  const out = applyOps(d, b.ops).doc;
  assert.deepEqual(out.slides.map((s) => s.layout), ['two', 'two', 'title']);
  assert.ok(out.slides[0].els.length >= 3 && out.slides[1].els.length >= 1);
  for (const id of ['a', 'b', 'c']) assert.ok(out.slides[0].els.some((e) => e.id === id));
  assert.equal(b.summary.added, 3); // one on the first slide, two on the second
  assert.equal(b.summary.left, 1);
});

test('the built in library is fetched once per slide size and failures are not kept', async () => {
  const { cachedLibrary, clearLibraryCache } = await import('../libraryCache.js');
  clearLibraryCache();
  let calls = 0;
  const ok = () => { calls++; return Promise.resolve({ data: [1] }); };
  await cachedLibrary(1920, 1080, ok);
  await cachedLibrary(1920, 1080, ok);
  assert.equal(calls, 1);
  await cachedLibrary(1440, 1080, ok);
  assert.equal(calls, 2);
  clearLibraryCache();
  await assert.rejects(cachedLibrary(1, 2, () => Promise.reject(new Error('429'))));
  await cachedLibrary(1, 2, ok);
  assert.equal(calls, 3);
});
