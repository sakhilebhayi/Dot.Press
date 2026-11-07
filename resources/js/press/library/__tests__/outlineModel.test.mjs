import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeOutline, modeLabel, moveBeat, outlineToDeck, buildOps, titleFromBrief, normalizeImport } from '../outlineModel.js';
import { newId } from '../../editor/core/doc.js';

const makeId = (taken, prefix) => { let i = 1; while (taken.has(`${prefix}${i}`)) i += 1; return `${prefix}${i}`; };

test('mode labels are always one of the two honest names', () => {
  assert.equal(modeLabel('ai'), 'AI model');
  assert.equal(modeLabel('local'), 'Local rules');
  assert.equal(modeLabel(undefined), 'Local rules');
});

test('normalizeOutline accepts strings and objects and drops junk', () => {
  const out = normalizeOutline({
    mode: 'ai',
    outline: [
      { beat: 'problem', label: 'The problem', slides: ['Churn doubled in Q3', { claim: 'Support is the cause', points: ['Tickets rose 40%', ' ', 'Wait time 3 days'], notes: 'Say it slowly' }, 7, null, { title: '' }] },
      { beat: 'ask', slides: [] },
      'nonsense',
      { slides: [] },
    ],
  });
  assert.equal(out.mode, 'ai');
  assert.equal(out.modeLabel, 'AI model');
  assert.equal(out.beats.length, 2);
  assert.deepEqual(out.beats[0].slides.map((s) => s.title), ['Churn doubled in Q3', 'Support is the cause']);
  assert.deepEqual(out.beats[0].slides[1].points, ['Tickets rose 40%', 'Wait time 3 days']);
  assert.equal(out.beats[0].slides[1].notes, 'Say it slowly');
  assert.equal(out.beats[1].label, 'ask');
  assert.equal(new Set(out.beats.flatMap((b) => [b.id, ...b.slides.map((s) => s.id)])).size, 4);
});

test('normalizeOutline rejects answers that are not an outline', () => {
  assert.equal(normalizeOutline(null), null);
  assert.equal(normalizeOutline({}), null);
  assert.equal(normalizeOutline({ outline: [] }), null);
  assert.equal(normalizeOutline({ outline: 'x' }), null);
  assert.equal(normalizeOutline({ mode: 'weird', outline: [{ label: 'A', slides: ['One claim here'] }] }).mode, 'local');
});

test('moveBeat swaps neighbours and ignores the ends', () => {
  const beats = [{ id: 'a' }, { id: 'b' }, { id: 'c' }];
  assert.deepEqual(moveBeat(beats, 'b', -1).map((b) => b.id), ['b', 'a', 'c']);
  assert.deepEqual(moveBeat(beats, 'b', 1).map((b) => b.id), ['a', 'c', 'b']);
  assert.equal(moveBeat(beats, 'a', -1), beats);
  assert.equal(moveBeat(beats, 'c', 1), beats);
  assert.equal(moveBeat(beats, 'zz', 1), beats);
});

test('outlineToDeck makes a section per beat and sensible slides', () => {
  const beats = normalizeOutline({ outline: [
    { label: 'Open', slides: ['Revenue grew 12 percent', { title: 'Three drivers', points: ['Price', 'Mix', 'Volume'] }] },
    { label: 'Ask', slides: ['We need two more engineers'] },
  ] }).beats;
  const r = outlineToDeck(beats, { w: 1920, h: 1080 }, { makeId });
  assert.equal(r.count, 3);
  assert.deepEqual(r.sections.map((s) => s.title), ['Open', 'Ask']);
  assert.deepEqual(r.slides.map((s) => s.layout), ['title', 'bullets', 'blank']);
  assert.equal(r.slides[0].section_id, r.sections[0].id);
  assert.equal(r.slides[2].section_id, r.sections[1].id);
  assert.equal(r.slides[1].els.length, 2);
  assert.equal(r.slides[1].els[1].doc.content[0].type, 'bulletList');
  for (const s of r.slides) for (const e of s.els) { assert.ok(e.x >= 0 && e.x + e.w <= 1920 && e.y + e.h <= 1080); assert.match(e.id, /^[A-Za-z0-9_-]{1,40}$/); }
});

test('outlineToDeck scales to other slide sizes', () => {
  const beats = [{ id: 'b', label: 'One', beat: 'one', slides: [{ id: 's', title: 'A claim for a tall slide', points: [], notes: '' }] }];
  const r = outlineToDeck(beats, { w: 1080, h: 1920 }, { makeId });
  const t = r.slides[0].els[0];
  assert.ok(t.x + t.w <= 1080 && t.y + t.h <= 1920);
  assert.ok(t.size < 96);
});

test('buildOps adds sections, chains slides and removes the blank slide last, in chunks of 150', () => {
  const slides = Array.from({ length: 200 }, (_, i) => ({ title: `Claim ${i} stands here`, layout: 'blank', section_id: 'sec1', els: [] }));
  const chunks = buildOps(77, [{ id: 'sec1', title: 'Only' }], slides, { makeId });
  const flat = chunks.flat();
  assert.ok(chunks.every((c) => c.length <= 150));
  assert.deepEqual(flat[0], { op: 'section.add', id: 'sec1', title: 'Only' });
  assert.equal(flat[1].after, null);
  assert.equal(flat[2].after, flat[1].tid);
  assert.deepEqual(flat.at(-1), { op: 'slide.remove', id: '77' });
  assert.equal(new Set(flat.filter((o) => o.op === 'slide.add').map((o) => o.tid)).size, 200);
  assert.equal(flat.length, 202);
  assert.equal(buildOps(null, [], slides.slice(0, 1), { makeId }).flat().length, 1);
});

test('titleFromBrief takes the first line and trims it', () => {
  assert.equal(titleFromBrief(''), 'Untitled presentation');
  assert.equal(titleFromBrief('Quarterly review for the board.\nMore detail follows'), 'Quarterly review for the board');
  const long = `${'word '.repeat(40)}end`;
  assert.ok(titleFromBrief(long).length <= 70);
  assert.ok(!titleFromBrief(long).endsWith(' '));
});

test('normalizeImport finds the deck and flattens the report', () => {
  assert.deepEqual(normalizeImport({ deck: { id: 12 }, report: ['Fonts replaced', { slide: 3, message: 'SmartArt became a picture' }, 5] }), {
    deckId: '12', notes: [{ kind: 'note', text: 'Fonts replaced' }, { kind: 'note', text: 'Slide 3: SmartArt became a picture' }],
  });
  const r = normalizeImport({ id: 9, report: { unsupported: ['Macros'], notes: ['Two fonts replaced'], skipped: [{ reason: 'OLE object' }] } });
  assert.equal(r.deckId, '9');
  assert.deepEqual(r.notes.map((n) => n.kind), ['skipped', 'note', 'skipped']);
  assert.deepEqual(normalizeImport(null), { deckId: null, notes: [] });
});

test('document ids from core newId are valid for outline ops', () => {
  assert.match(newId(['s1'], 's'), /^[A-Za-z0-9_-]{1,40}$/);
});

test('normalizeOutline reads the real POST /api/ai/outline shape: beats with slots and needs', () => {
  const out = normalizeOutline({
    mode: 'local',
    beats: [
      { id: 'title', label: 'Title', slides: [{ title: 'Why churn doubled', claim: '', kind: 'title', slots: { bullets: [] }, notes: '', needs: [] }] },
      { id: 'opening', label: 'Opening and purpose', slides: [{ title: 'Churn rose to 4.4 percent', claim: 'Churn rose to 4.4 percent.', kind: 'bullets', slots: { bullets: ['Legacy plan', 'Support doubled'] }, notes: 'n', needs: [] }] },
      { id: 'points', label: 'Key points', slides: [{ title: 'Key points', claim: '', kind: 'bullets', slots: { bullets: [] }, needs: [{ slot: 'bullets', label: 'Needs your evidence', question: 'What are the main things you want people to remember?' }] }] },
    ],
  });
  assert.equal(out.beats.length, 3);
  assert.equal(out.beats[1].beat, 'opening');
  assert.deepEqual(out.beats[1].slides[0].points, ['Legacy plan', 'Support doubled']);
  assert.equal(out.beats[2].slides[0].need, 'What are the main things you want people to remember?');
  assert.equal(out.beats[0].slides[0].need, '');
});
