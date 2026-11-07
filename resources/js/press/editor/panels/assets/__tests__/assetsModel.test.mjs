import test from 'node:test';
import assert from 'node:assert/strict';
import {
  buildParams, mergePage, upsertAsset, usageWords, formatBytes, dimensions, kindOf, parseDeleteError, createRefetchGate, replaceableKind, assetPayload, SORTS, KINDS,
} from '../assetsModel.js';

test('query params leave out defaults and empty values', () => {
  assert.deepEqual(buildParams({}), {});
  assert.deepEqual(buildParams({ q: '  logo ', kind: 'image', sort: 'name', limit: 60, cursor: 'abc' }), { q: 'logo', kind: 'image', sort: 'name', limit: 60, cursor: 'abc' });
  assert.deepEqual(buildParams({ q: '', kind: 'all', sort: 'newest' }), {});
  assert.equal(buildParams({ limit: 500 }).limit, 100);
  assert.equal(buildParams({ q: 'x'.repeat(300) }).q.length, 100);
  assert.deepEqual(SORTS.map((s) => s.value), ['newest', 'oldest', 'name', 'size']);
  assert.deepEqual(KINDS.map((s) => s.value), ['all', 'image', 'video', 'audio']);
});

test('pages append without duplicates and keep their order', () => {
  const a = [{ id: 1 }, { id: 2 }];
  assert.deepEqual(mergePage(a, [{ id: 2 }, { id: 3 }]).map((x) => x.id), [1, 2, 3]);
  assert.deepEqual(mergePage(a, [{ id: 9 }], { replace: true }).map((x) => x.id), [9]);
  assert.deepEqual(mergePage(a, null).map((x) => x.id), [1, 2]);
  assert.equal(a.length, 2);
});

test('an updated asset replaces its entry in place', () => {
  const list = [{ id: 1, name: 'a', url: 'u1' }, { id: 2, name: 'b' }];
  const next = upsertAsset(list, { id: 1, name: 'renamed' });
  assert.deepEqual(next[0], { id: 1, name: 'renamed', url: 'u1' });
  assert.equal(next[1], list[1]);
});

test('usage words', () => {
  assert.deepEqual(usageWords({ decks: 0, slides: 0, brand_kits: 0 }), { word: 'Not used', short: 'Not used', used: false });
  assert.deepEqual(usageWords(undefined), { word: 'Not used', short: 'Not used', used: false });
  assert.equal(usageWords({ decks: 1 }).word, 'Used in 1 deck');
  assert.equal(usageWords({ decks: 3, brand_kits: 1 }).word, 'Used in 3 decks, 1 brand kit');
  assert.equal(usageWords({ decks: 3, brand_kits: 1 }).short, '3 decks, 1 kit');
  assert.equal(usageWords({ decks: 1 }).short, '1 deck');
  assert.equal(usageWords({ decks: 0, brand_kits: 2 }).word, 'Used in 2 brand kits');
});

test('sizes, dimensions and kinds', () => {
  assert.equal(formatBytes(512), '512 B');
  assert.equal(formatBytes(2048), '2 KB');
  assert.equal(formatBytes(5 * 1024 * 1024), '5.0 MB');
  assert.equal(formatBytes(50 * 1024 * 1024), '50 MB');
  assert.equal(formatBytes(-1), '');
  assert.equal(dimensions({ width: 800, height: 600 }), '800 by 600');
  assert.equal(dimensions({}), '');
  assert.equal(kindOf({ kind: 'video' }), 'video');
  assert.equal(kindOf({ mime: 'audio/mpeg' }), 'audio');
  assert.equal(kindOf({}), 'image');
});

test('the 409 answer becomes a list of decks, 403 and 404 become sentences', () => {
  const inUse = parseDeleteError({ status: 409, data: { message: 'Used in 2 decks.', decks: [{ id: 1, title: 'Q3', slide_count: 2 }], brand_kits: [{ id: 4, name: 'Mine' }] } });
  assert.equal(inUse.type, 'in_use');
  assert.equal(inUse.decks[0].title, 'Q3');
  assert.equal(inUse.brandKits[0].name, 'Mine');
  assert.equal(parseDeleteError({ status: 403, data: { message: 'Only a team admin can delete an asset that is still in use.' } }).type, 'forbidden');
  assert.equal(parseDeleteError({ status: 404 }).type, 'missing');
  assert.equal(parseDeleteError({ status: 500, message: 'Boom' }).message, 'Boom');
  assert.equal(parseDeleteError({ status: 409 }).decks.length, 0);
});

test('the refetch gate lets one refetch through per gap', () => {
  let t = 1000;
  const gate = createRefetchGate({ minGap: 5000, now: () => t });
  assert.equal(gate.attempt(), true);
  assert.equal(gate.attempt(), false);
  t += 4999;
  assert.equal(gate.attempt(), false);
  t += 1;
  assert.equal(gate.attempt(), true);
});

test('only one selected picture or video can be replaced', () => {
  assert.equal(replaceableKind([{ type: 'image' }]), 'image');
  assert.equal(replaceableKind([{ type: 'video' }]), 'video');
  assert.equal(replaceableKind([{ type: 'text' }]), null);
  assert.equal(replaceableKind([{ type: 'image' }, { type: 'image' }]), null);
  assert.equal(replaceableKind([]), null);
  assert.equal(replaceableKind(null), null);
});

test('the drag payload carries what the stage drop code reads', () => {
  const p = assetPayload({ id: 3, kind: 'image', width: 10, height: 20, alt: null, url: 'u', web: 'w', thumb: 't', mime_type: 'image/png', name: 'n' });
  assert.deepEqual(p, { id: 3, kind: 'image', width: 10, height: 20, alt: null, url: 'w', thumb: 't', mime: 'image/png', name: 'n' });
});

import { describeApiError, refetchLimit } from '../assetsModel.js';

test('library errors are plain sentences', () => {
  assert.match(describeApiError({ status: 403 }), /do not have access/);
  assert.match(describeApiError({ status: 429 }), /Too many/);
  assert.match(describeApiError({ network: true }), /Could not reach/);
  assert.match(describeApiError({ status: 500 }), /Something went wrong/);
  assert.equal(describeApiError({ status: 422, message: 'The name cannot be empty.' }), 'The name cannot be empty.');
  assert.match(describeApiError({ status: 404 }, 'this file'), /this file/);
});

test('a refetch brings back what is on screen, between 30 and 100', () => {
  assert.equal(refetchLimit(0), 30);
  assert.equal(refetchLimit(45), 45);
  assert.equal(refetchLimit(400), 100);
});
