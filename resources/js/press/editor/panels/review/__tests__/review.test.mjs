import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildView, summaryLine, fixAllPlan, categoryLabel, truncationNotes } from '../reviewModel.js';
import { loadIgnores, saveIgnores, isIgnored, ignoreIssue, ignoreKind, restoreIssue, restoreKind, emptyIgnores } from '../ignoreStore.js';

const issue = (id, o = {}) => ({ id, kind: 'min-type-size', category: 'readability', severity: 'warn', slide: '1', slide_index: 1, message: id, why: 'w', ...o });
const memory = () => { const m = new Map(); return { get: (k) => m.get(k) ?? null, set: (k, v) => m.set(k, v), m }; };

test('issues are grouped by category in the documented order, worst first within the server order', () => {
  const v = buildView([
    issue('a', { category: 'claims', kind: 'unsupported-claim' }),
    issue('b', { category: 'consistency', kind: 'spacing', severity: 'issue' }),
    issue('c', { category: 'readability' }),
    issue('d', { category: 'readability', severity: 'info', kind: 'long-lines' }),
  ]);
  assert.deepEqual(v.groups.map((g) => g.id), ['consistency', 'readability', 'claims']);
  assert.deepEqual(v.groups[1].issues.map((i) => i.id), ['c', 'd']);
  assert.deepEqual(v.groups[1].counts, { issue: 0, warn: 1, info: 1 });
  assert.equal(v.open, 4);
  assert.deepEqual(v.totals, { issue: 1, warn: 2, info: 1 });
});

test('an unknown category still appears, after the known ones, with a readable label', () => {
  const v = buildView([issue('a', { category: 'brand-voice' }), issue('b', { category: 'density', kind: 'density' })]);
  assert.deepEqual(v.groups.map((g) => g.id), ['density', 'brand-voice']);
  assert.equal(categoryLabel('brand-voice'), 'Brand-voice');
});

test('filters narrow the groups but never the open counts that feed the Spine', () => {
  const issues = [issue('a', { slide: '1' }), issue('b', { slide: '2', severity: 'issue' }), issue('c', { slide: '2', category: 'density', kind: 'density' })];
  const v = buildView(issues, { severity: 'issue' });
  assert.equal(v.shown, 1);
  assert.deepEqual(v.bySlide, { 1: 1, 2: 2 });
  assert.equal(buildView(issues, { slide: '2' }).shown, 2);
  assert.equal(buildView(issues, { category: 'density' }).shown, 1);
  assert.equal(buildView(issues, { slide: '2', category: 'density', severity: 'warn' }).shown, 1);
});

test('ignored issues and kinds leave the open counts and the Spine map; showIgnored lists them again', () => {
  const issues = [issue('a'), issue('b', { slide: '2' }), issue('c', { slide: '2', kind: 'overlap', category: 'consistency' })];
  let ig = ignoreIssue(emptyIgnores(), issues[0]);
  ig = ignoreKind(ig, issues[2]);
  const v = buildView(issues, { ignores: ig });
  assert.equal(v.open, 1);
  assert.equal(v.ignored, 2);
  assert.deepEqual(v.bySlide, { 2: 1 });
  assert.equal(v.shown, 1);
  assert.equal(buildView(issues, { ignores: ig, showIgnored: true }).shown, 3);
});

test('issues applied here disappear at once, before the next check', () => {
  const v = buildView([issue('a'), issue('b')], { applied: new Set(['a']) });
  assert.equal(v.open, 1);
});

test('deck level issues (no slide) are counted but belong to no bead', () => {
  const v = buildView([issue('a', { slide: null })]);
  assert.equal(v.open, 1);
  assert.deepEqual(v.bySlide, {});
});

test('the header sentence', () => {
  const v = buildView([issue('a'), issue('b', { slide: '2', severity: 'issue' })]);
  assert.equal(summaryLine(v, 14), '2 open: 1 issue, 1 warning, on 2 of 14 slides');
  assert.equal(summaryLine(buildView([]), 3), 'Nothing open.');
});

test('truncation is reported in words', () => {
  const notes = truncationNotes({ truncated: { 'long-lines': 20 }, by_kind: { 'long-lines': 80 } });
  assert.match(notes[0], /20 more "long lines" findings are not listed \(the first 60 are\)/);
});

test('ignore store: round trip, per deck, and survives corrupt or failing storage', () => {
  const s = memory();
  const ig = ignoreKind(ignoreIssue(emptyIgnores(), issue('a')), issue('x', { kind: 'overlap' }));
  assert.equal(saveIgnores(s, 7, ig), true);
  assert.deepEqual(loadIgnores(s, 7), ig);
  assert.deepEqual(loadIgnores(s, 8), emptyIgnores());
  s.set('press:review:ignore:9', '{not json');
  assert.deepEqual(loadIgnores(s, 9), emptyIgnores());
  s.set('press:review:ignore:9', JSON.stringify({ ids: [1, 'ok'], kinds: 'nope' }));
  assert.deepEqual(loadIgnores(s, 9), { ids: ['ok'], kinds: [] });
  const broken = { get() { throw new Error('blocked'); }, set() { throw new Error('full'); } };
  assert.deepEqual(loadIgnores(broken, 1), emptyIgnores());
  assert.equal(saveIgnores(broken, 1, ig), false);
});

test('ignore store: restore one issue, a kind, or everything; ids are capped', () => {
  const a = issue('a');
  let ig = ignoreKind(ignoreIssue(emptyIgnores(), a), a);
  assert.equal(isIgnored(ig, a), true);
  assert.equal(isIgnored(restoreIssue(ig, a), a), false);
  assert.equal(isIgnored(restoreKind(ig, 'min-type-size'), issue('zz')), false);
  let many = emptyIgnores();
  for (let i = 0; i < 500; i++) many = ignoreIssue(many, issue(`i${i}`));
  assert.equal(many.ids.length, 400);
  assert.equal(many.ids.at(-1), 'i499');
});

const doc = {
  slides: [
    { id: '1', els: [{ id: 'e1', type: 'text', size: 12, name: 'Body' }, { id: 'e2', type: 'text', size: 10 }] },
    { id: '2', els: [{ id: 'e3', type: 'text', size: 12 }] },
  ],
};

test('fix all: only safe fixes, merged in order, conflicts and content rewrites left out with a reason', () => {
  const fix = (slide, id, p) => ({ label: 'f', ops: [{ op: 'el.set', slide, id, p }] });
  const issues = [
    issue('a', { fix: fix(1, 'e1', { size: 24 }) }),
    issue('b', { fix: fix('1', 'e1', { size: 28 }) }),
    issue('c', { fix: fix('1', 'e2', { size: 20, lh: 1.2 }) }),
    issue('d', { fix: fix('2', 'e3', { doc: { type: 'doc' } }) }),
    issue('e', { fix: { label: 'f', ops: [{ op: 'el.remove', slide: '1', id: 'e2' }] } }),
    issue('f'),
  ];
  const plan = fixAllPlan(issues, doc);
  assert.deepEqual(plan.entries.map((e) => e.issue.id), ['a', 'c']);
  assert.equal(plan.ops.length, 2);
  assert.equal(plan.ops[0].slide, '1');
  assert.deepEqual(plan.left.map((l) => l.issue.id), ['b', 'd', 'e']);
  assert.match(plan.left[0].reason, /same property/);
  assert.match(plan.entries[0].lines[0], /Slide 1: change the font size of "Body"/);
});
