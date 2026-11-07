import test from 'node:test';
import assert from 'node:assert/strict';
import { listParams, apiProblem, assetKind, uploadProblem, humanSize, registerAsset, PAGE } from '../lib/library.js';

test('listParams leaves out empty values and trims the search', () => {
  assert.deepEqual(listParams({ kind: 'image' }), { limit: PAGE, kind: 'image' });
  assert.deepEqual(listParams({ kind: 'video', q: '  sea  ', cursor: 'abc', projectId: 7 }), { limit: PAGE, kind: 'video', q: 'sea', cursor: 'abc', project_id: 7 });
  assert.deepEqual(listParams({ q: '   ', projectId: '' }), { limit: PAGE });
  assert.equal(listParams({ q: 'x'.repeat(300) }).q.length, 100);
});

test('apiProblem speaks plainly for each failure', () => {
  assert.equal(apiProblem({ status: 403 }).kind, 'forbidden');
  const l = apiProblem({ status: 429, retryAfter: 12 });
  assert.equal(l.kind, 'limited');
  assert.match(l.message, /12 seconds/);
  assert.equal(apiProblem({ status: 429 }).retryAfter, null);
  assert.equal(apiProblem({ network: true, message: 'The network is not reachable' }).kind, 'offline');
  assert.match(apiProblem({ status: 422, message: 'The file must be an image.' }).message, /must be an image/);
  assert.equal(apiProblem({ status: 500, message: 'The server answered 500' }).kind, 'server');
  assert.ok(apiProblem({}).message.length > 5);
  for (const e of [{ status: 403 }, { status: 429 }, { network: true }, { status: 404 }, { status: 413 }, {}]) assert.doesNotMatch(apiProblem(e).message, /[\u2013\u2014]/);
});

test('assetKind goes by mime then by extension', () => {
  assert.equal(assetKind({ type: 'image/png', name: 'x' }), 'image');
  assert.equal(assetKind({ type: '', name: 'clip.MP4' }), 'video');
  assert.equal(assetKind({ type: '', name: 'tune.m4a' }), 'audio');
  assert.equal(assetKind({ type: 'application/pdf', name: 'a.pdf' }), null);
  assert.equal(assetKind(null), null);
});

test('uploadProblem names the wrong kind', () => {
  assert.equal(uploadProblem({ type: 'audio/mpeg', name: 'a.mp3', size: 10 }, 'audio'), null);
  assert.match(uploadProblem({ type: 'image/png', name: 'a.png', size: 10 }, 'video'), /not a video file/);
  assert.match(uploadProblem({ type: 'video/mp4', name: 'a.mp4', size: 10 }, 'image'), /not a picture/);
  assert.match(uploadProblem({ type: 'image/png', name: 'a.png', size: 10 }, 'audio'), /not an audio file/);
  assert.match(uploadProblem({ type: 'image/png', name: 'a.png', size: 0 }, 'image'), /empty/);
});

test('humanSize', () => {
  assert.equal(humanSize(900), '900 B');
  assert.equal(humanSize(2048), '2 KB');
  assert.equal(humanSize(2.5 * 1048576), '2.5 MB');
  assert.equal(humanSize(30 * 1048576), '30 MB');
  assert.equal(humanSize(-1), '');
});

test('registerAsset writes the map, with the poster, using relative urls on this origin', () => {
  const editor = { assets: {} };
  const id = registerAsset(editor, { id: 5, name: 'Clip', kind: 'video', url: '/a/5', thumb: null, width: 1280, height: 720, mime: 'video/mp4', poster: { id: 9, url: '/a/9', thumb: '/a/9t' } });
  assert.equal(id, 5);
  assert.equal(editor.assets[5].w, 1280);
  assert.equal(editor.assets[9].url, '/a/9');
  const calls = [];
  registerAsset({ registerAsset: (i, e) => calls.push([i, e.kind]) }, { id: 1, kind: 'image', url: '/x' });
  assert.deepEqual(calls, [[1, 'image']]);
  assert.equal(registerAsset({ assets: {} }, null), null);
});
