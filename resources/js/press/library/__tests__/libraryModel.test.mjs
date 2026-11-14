import test from 'node:test';
import assert from 'node:assert/strict';
import { FILTERS, SORTS, SIZES, relativeTime, sizeLabel, statusBead, emptyCopy, rowMove, pickSelection, footCounts, previewDeck, previewSlide, normalizeTemplates, templateCategories, landingCopy } from '../libraryModel.js';

const NOW = Date.parse('2026-11-07T12:00:00');

test('filters match the API filters and sorts match the API sorts', () => {
  assert.deepEqual(FILTERS.map((f) => f.id), [null, 'mine', 'team', 'shared', 'favourites', 'recent', 'archived']);
  assert.deepEqual(SORTS.map((s) => s.id), ['updated', 'title', 'created']);
  assert.deepEqual(SIZES.map((s) => s.id), ['16:9', '4:3', 'portrait', 'custom']);
});

test('relativeTime reads like a person', () => {
  const at = (s) => relativeTime(s, NOW);
  assert.equal(at('2026-11-07T11:59:40'), 'just now');
  assert.equal(at('2026-11-07T11:55:00'), '5 min ago');
  assert.equal(at('2026-11-07T11:00:00'), '1 hour ago');
  assert.equal(at('2026-11-07T08:00:00'), '4 hours ago');
  assert.equal(at('2026-11-06T09:00:00'), 'yesterday');
  assert.equal(at('2026-11-04T09:00:00'), '3 days ago');
  assert.equal(at('2026-10-20T09:00:00'), '20 Oct');
  assert.equal(at('2025-03-02T09:00:00'), '2 Mar 2025');
  assert.equal(at(null), '');
  assert.equal(at('not a date'), '');
});

test('sizeLabel names the common shapes', () => {
  assert.equal(sizeLabel(1920, 1080), '16:9');
  assert.equal(sizeLabel(1280, 720), '16:9');
  assert.equal(sizeLabel(1440, 1080), '4:3');
  assert.equal(sizeLabel(1080, 1920), 'Portrait');
  assert.equal(sizeLabel(1000, 1000), '1000 x 1000');
});

test('status beads follow the Spine language', () => {
  assert.deepEqual(statusBead('draft'), { state: 'hollow', word: 'draft' });
  assert.deepEqual(statusBead('in_review'), { state: 'issue', word: 'in review' });
  assert.deepEqual(statusBead('approved'), { state: 'solid', word: 'approved' });
  assert.deepEqual(statusBead(undefined), { state: 'hollow', word: 'draft' });
});

test('empty copy is specific to the filter and to a search', () => {
  assert.match(emptyCopy('archived'), /Archived/);
  assert.match(emptyCopy(null, 'budget'), /"budget"/);
  assert.notEqual(emptyCopy('mine'), emptyCopy('team'));
  assert.ok(emptyCopy('unknown').length > 0);
});

test('rowMove: arrows step down the ledger and stop at its ends', () => {
  assert.equal(rowMove(5, 1, 'ArrowDown'), 2);
  assert.equal(rowMove(5, 4, 'ArrowDown'), 4);
  assert.equal(rowMove(5, 0, 'ArrowUp'), 0);
  assert.equal(rowMove(5, 3, 'ArrowUp'), 2);
  assert.equal(rowMove(5, 3, 'Home'), 0);
  assert.equal(rowMove(5, 1, 'End'), 4);
  assert.equal(rowMove(5, 2, 'ArrowLeft'), 2);
  assert.equal(rowMove(5, -1, 'ArrowDown'), 0);
  assert.equal(rowMove(5, 9, 'ArrowUp'), 0);
  assert.equal(rowMove(0, 0, 'ArrowDown'), -1);
});

test('pickSelection only selects rows that are loaded: the address cannot name anything else', () => {
  const rows = [{ id: 7 }, { id: 9 }, { id: 12 }];
  assert.equal(pickSelection(rows, '9', 12), 9);
  assert.equal(pickSelection(rows, 12, null), 12);
  assert.equal(pickSelection(rows, '404', 12), 12); // not loaded: the featured one
  assert.equal(pickSelection(rows, '404', 55), 7); // neither loaded: the first row
  assert.equal(pickSelection(rows, '9abc', null), 7);
  assert.equal(pickSelection(rows, null, null), 7);
  assert.equal(pickSelection([], '9', 9), null);
});

test('footCounts counts the rows loaded, says shown, and never claims a total', () => {
  assert.deepEqual(footCounts([]), { lead: 'Nothing shown yet.', detail: '' });
  const rows = [{ status: 'approved' }, { status: 'draft' }, { status: 'in_review' }, { status: 'draft' }, {}];
  assert.deepEqual(footCounts(rows), { lead: '5 shown', detail: '1 approved, 1 in review, 3 draft' });
  assert.deepEqual(footCounts(rows, true), { lead: '5 shown, more to load', detail: '1 approved, 1 in review, 3 draft' });
  assert.deepEqual(footCounts([{ status: 'draft' }]), { lead: '1 shown', detail: '1 draft' });
});

test('preview helpers fall back sensibly', () => {
  assert.deepEqual(previewDeck({ w: 1280, h: 720 }), { w: 1280, h: 720, theme: undefined });
  assert.equal(previewDeck({}).w, 1920);
  assert.deepEqual(previewSlide({ id: 4, first_slide: 9, preview: { bg: null, els: [{ id: 'a' }] } }), { id: '9', bg: null, els: [{ id: 'a' }] });
  assert.deepEqual(previewSlide({ id: 4 }), { id: '4', bg: null, els: [] });
});

test('normalizeTemplates reads the shape GET /api/templates sends (the id is the key)', () => {
  const res = {
    data: [
      { key: 'executive-summary', name: 'Executive summary', category: 'business', category_name: 'Business and executive', blurb: 'Answer first', narrative: 'Pyramid Principle', slides: 9, theme: 'boardroom', aspect: '16:9' },
      { key: 'lesson', name: 'Lesson', category: 'education', category_name: 'Education and training', slides: 7 },
      { name: 'no key' },
    ],
    categories: [{ id: 'business', name: 'Business and executive', count: 1 }, { id: 'sales', name: 'Sales', count: 0 }, { id: 'education', name: 'Education and training', count: 1 }],
  };
  const list = normalizeTemplates(res);
  assert.equal(list.length, 2);
  assert.equal(list[0].id, 'executive-summary');
  assert.equal(list[0].categoryName, 'Business and executive');
  assert.equal(list[1].blurb, '');
  assert.deepEqual(templateCategories(list, res.categories).map((c) => [c.id, c.count]), [['business', 1], ['education', 1]]);
  assert.deepEqual(normalizeTemplates(null), []);
});

test('landingCopy states where the person was last, welcomes them by name and names their company', () => {
  const landing = { firstName: 'Thandi', company: 'Acme Mining', primary: { kind: 'new' } };
  const c = landingCopy(landing, { title: 'Q3 review', last_opened_at: '2026-11-01T10:00:00Z' });
  assert.equal(c.headline, 'You were last in Q3 review.');
  assert.equal(c.welcome, 'Welcome, Thandi.');
  assert.equal(c.support, 'These are the presentations Acme Mining makes.');
  assert.equal(landingCopy(landing, { title: 'Fresh', last_opened_at: null }).headline, 'Newest in the library: Fresh.');
  assert.equal(landingCopy(landing, null).headline, 'Nothing on this line yet.');
  assert.doesNotMatch(c.welcome + c.support + c.nothing, /\d/, 'the fixed words carry no figures');
});

test('landingCopy survives a missing name or company', () => {
  const bare = landingCopy({ firstName: '  ', company: null, primary: null });
  assert.equal(bare.welcome, 'Welcome.');
  assert.match(bare.support, /your team/);
  assert.match(bare.nothing, /^Nothing to open yet\./);
  assert.equal(landingCopy().welcome, 'Welcome.');
  assert.equal(landingCopy({}, { title: '  ', last_opened_at: 'x' }).headline, 'You were last in an untitled presentation.');
});
