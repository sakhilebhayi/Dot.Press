import test from 'node:test';
import assert from 'node:assert/strict';
import { createUploadQueue, explainUploadError, isFinished } from '../uploadQueue.js';

const file = (name, size = 10) => ({ name, size, type: 'image/png' });
const defer = () => { let resolve; let reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const tick = () => new Promise((r) => setImmediate(r));

function harness(opts = {}) {
  const calls = [];
  const upload = (f, ctx) => { const d = defer(); calls.push({ file: f, ctx, ...d }); return d.promise; };
  const done = [];
  const q = createUploadQueue({ upload, onDone: (i) => done.push(i), ...opts });
  return { q, calls, done };
}

test('files start in order and only `concurrency` run at a time', async () => {
  const { q, calls } = harness({ concurrency: 2 });
  q.add([file('a'), file('b'), file('c')]);
  assert.deepEqual(q.items().map((i) => i.state), ['uploading', 'uploading', 'queued']);
  assert.equal(calls.length, 2);
  calls[0].resolve({ asset: { id: 1 } });
  await tick();
  assert.deepEqual(q.items().map((i) => i.state), ['done', 'uploading', 'uploading']);
  assert.equal(calls.length, 3);
});

test('progress only moves forward and stays below one until the answer arrives', async () => {
  const { q, calls } = harness();
  const [id] = q.add([file('a')]);
  const seen = [];
  q.subscribe((items) => seen.push(items.find((i) => i.id === id).progress));
  calls[0].ctx.onProgress(0.4);
  calls[0].ctx.onProgress(0.2);
  calls[0].ctx.onProgress(1);
  assert.equal(q.items()[0].progress, 0.99);
  assert.ok(seen.every((v, i) => i === 0 || v >= seen[i - 1]));
  calls[0].resolve({ asset: { id: 5 } });
  await tick();
  assert.equal(q.items()[0].progress, 1);
});

test('a finished upload carries its asset, the dedupe flag, and calls onDone once', async () => {
  const { q, calls, done } = harness();
  q.add([file('a'), file('b')]);
  calls[0].resolve({ asset: { id: 1 }, deduplicated: false });
  calls[1].resolve({ asset: { id: 2 }, deduplicated: true });
  await tick();
  const [a, b] = q.items();
  assert.deepEqual([a.state, a.asset.id, a.deduplicated], ['done', 1, false]);
  assert.deepEqual([b.state, b.asset.id, b.deduplicated], ['done', 2, true]);
  assert.equal(done.length, 2);
  assert.equal(q.active(), 0);
});

test('a 422 shows the server words, other failures say what to do, and the queue moves on', async () => {
  const { q, calls } = harness({ concurrency: 1 });
  q.add([file('bad'), file('good')]);
  const err = Object.assign(new Error('The file must be a picture, video or audio file.'), { status: 422 });
  calls[0].reject(err);
  await tick();
  assert.equal(q.items()[0].state, 'failed');
  assert.equal(q.items()[0].error, 'The file must be a picture, video or audio file.');
  assert.equal(q.items()[1].state, 'uploading');
  calls[1].reject(Object.assign(new Error('x'), { status: 413 }));
  await tick();
  assert.match(q.items()[1].error, /too large/);
});

test('cancel aborts a running upload and a queued one without a request', async () => {
  const { q, calls } = harness({ concurrency: 1 });
  const [a, b] = q.add([file('a'), file('b')]);
  assert.equal(q.cancel(b), true);
  assert.equal(q.items()[1].state, 'cancelled');
  assert.equal(q.cancel(a), true);
  assert.equal(calls[0].ctx.signal.aborted, true);
  calls[0].reject(Object.assign(new Error('canceled'), { code: 'ERR_CANCELED' }));
  await tick();
  assert.deepEqual(q.items().map((i) => i.state), ['cancelled', 'cancelled']);
  assert.equal(calls.length, 1);
  assert.equal(q.cancel(a), false);
});

test('an answer that arrives after a cancel is ignored', async () => {
  const { q, calls, done } = harness();
  const [a] = q.add([file('a')]);
  q.cancel(a);
  calls[0].resolve({ asset: { id: 1 } });
  await tick();
  assert.equal(q.items()[0].state, 'cancelled');
  assert.equal(done.length, 0);
});

test('retry puts a failed or cancelled item back in the line', async () => {
  const { q, calls } = harness();
  const [a] = q.add([file('a')]);
  calls[0].reject(Object.assign(new Error('x'), { network: true }));
  await tick();
  assert.match(q.items()[0].error, /could not reach the server/);
  assert.equal(q.retry(a), true);
  assert.equal(q.items()[0].state, 'uploading');
  assert.equal(q.items()[0].error, null);
  assert.equal(calls.length, 2);
  assert.equal(q.retry(a), false);
});

test('validate stops a file before any request and keeps the plain reason', () => {
  const { q, calls } = harness({ validate: (f) => (f.name.endsWith('.exe') ? 'virus.exe is not a picture, video or audio file.' : null) });
  const ids = q.add([file('virus.exe'), file('ok.png')]);
  assert.equal(q.items()[0].state, 'failed');
  assert.match(q.items()[0].error, /not a picture/);
  assert.equal(calls.length, 1);
  assert.equal(q.retry(ids[0]), false);
});

test('remove and clearFinished', async () => {
  const { q, calls } = harness({ concurrency: 1 });
  const [a, b] = q.add([file('a'), file('b')]);
  calls[0].resolve({ asset: { id: 1 } });
  await tick();
  q.clearFinished();
  assert.deepEqual(q.items().map((i) => i.id), [b]);
  assert.equal(q.remove(b), true);
  assert.equal(q.items().length, 0);
  assert.equal(q.remove(a), false);
  assert.equal(isFinished({ state: 'done' }) && !isFinished({ state: 'uploading' }), true);
});

test('a throwing onDone does not stall the queue', async () => {
  const calls = [];
  const q = createUploadQueue({ concurrency: 1, upload: (f) => { const d = defer(); calls.push(d); return d.promise; }, onDone: () => { throw new Error('boom'); } });
  q.add([file('a'), file('b')]);
  calls[0].resolve({ asset: { id: 1 } });
  await tick();
  assert.equal(q.items()[0].state, 'done');
  assert.equal(q.items()[1].state, 'uploading');
});

test('upload errors are explained in plain words', () => {
  assert.equal(explainUploadError({ status: 422, data: { errors: { file: ['Too wide.'] } } }), 'Too wide.');
  assert.equal(explainUploadError({ status: 422, message: 'Bad file.' }), 'Bad file.');
  assert.match(explainUploadError({ status: 403 }), /view this library/);
  assert.match(explainUploadError({ status: 429 }), /Too many/);
  assert.match(explainUploadError({ status: 419 }), /session/);
  assert.match(explainUploadError({ status: 500 }), /failed/);
  assert.match(explainUploadError(new Error('x')), /could not reach/);
});

test('upload is required', () => {
  assert.throws(() => createUploadQueue({}), /upload is required/);
});
