import test from 'node:test';
import assert from 'node:assert/strict';
import { reduce, initialState, jobOutcome, backoff, runExport, workingWord, workingMessage, POLL_STEPS, MAX_POLL_ERRORS, SLOW_AFTER_MS, GIVE_UP_AFTER_MS } from '../exportJob.js';

const job = (status, extra = {}) => ({ id: 7, format: 'pdf', status, error: null, download_url: null, expires_at: null, ...extra });
const apiErr = (status, message, extra = {}) => Object.assign(new Error(message), { status, api: true, ...extra });

test('backoff starts quick and settles at the last step', () => {
    assert.equal(backoff(0), POLL_STEPS[0]);
    assert.ok(backoff(1) > backoff(0));
    assert.equal(backoff(50), POLL_STEPS.at(-1));
    assert.equal(backoff(-3), POLL_STEPS[0]);
});

test('jobOutcome: success needs a download link', () => {
    assert.deepEqual(jobOutcome(job('done', { download_url: '/api/exports/7/download' })), { phase: 'done' });
    assert.equal(jobOutcome(job('done')).phase, 'failed');
    assert.match(jobOutcome(job('done')).message, /not available any more/);
    assert.equal(jobOutcome(job('queued')).phase, 'working');
    assert.equal(jobOutcome(job('running')).phase, 'working');
    assert.equal(jobOutcome(job('failed', { error: 'The deck has no slides to export.' })).message, 'The deck has no slides to export.');
    assert.match(jobOutcome(job('failed')).message, /Try again/);
    assert.match(jobOutcome(job('expired')).message, /expired/);
    assert.equal(jobOutcome(job('weird')).phase, 'failed');
    assert.equal(jobOutcome(null).phase, 'failed');
});

test('reducer: check, ready, stale', () => {
    let s = reduce(initialState(), { type: 'check' });
    assert.equal(s.phase, 'checking');
    s = reduce(s, { type: 'checked', preflight: { ok: 1 } });
    assert.equal(s.phase, 'ready');
    assert.deepEqual(s.preflight, { ok: 1 });
    s = reduce(s, { type: 'stale' });
    assert.equal(s.phase, 'idle');
    assert.equal(s.preflight, null);
    s = reduce(s, { type: 'checkFailed', error: 'nope' });
    assert.equal(s.error, 'nope');
    assert.equal(s.retryable, true);
});

test('stale is ignored while a job is running', () => {
    let s = reduce(initialState(), { type: 'start' });
    s = reduce(s, { type: 'started', job: job('queued'), at: 1000 });
    assert.equal(reduce(s, { type: 'stale' }).phase, 'working');
});

test('reducer: queued job becomes slow after the threshold but is still working', () => {
    let s = reduce(initialState(), { type: 'start' });
    s = reduce(s, { type: 'started', job: job('queued'), at: 1000 });
    assert.equal(s.phase, 'working');
    assert.equal(s.slow, false);
    s = reduce(s, { type: 'polled', job: job('queued'), at: 1000 + SLOW_AFTER_MS });
    assert.equal(s.phase, 'working');
    assert.equal(s.slow, true);
    assert.match(workingMessage(s, 'PDF'), /still waiting/);
    assert.equal(workingWord(s), 'still queued');
});

test('reducer: poll errors are tolerated a few times, fatal ones are not', () => {
    let s = reduce(initialState(), { type: 'start' });
    s = reduce(s, { type: 'started', job: job('running'), at: 0 });
    for (let i = 0; i < MAX_POLL_ERRORS; i++) {
        s = reduce(s, { type: 'pollError', error: 'net' });
        assert.equal(s.phase, 'working');
    }
    s = reduce(s, { type: 'pollError', error: 'net' });
    assert.equal(s.phase, 'failed');
    let t = reduce(initialState(), { type: 'start' });
    t = reduce(t, { type: 'started', job: job('running'), at: 0 });
    t = reduce(t, { type: 'pollError', error: 'gone', fatal: true });
    assert.equal(t.phase, 'failed');
    assert.equal(t.error, 'gone');
});

test('a good poll resets the error count', () => {
    let s = reduce(initialState(), { type: 'start' });
    s = reduce(s, { type: 'started', job: job('running'), at: 0 });
    s = reduce(s, { type: 'pollError', error: 'net' });
    assert.equal(s.pollErrors, 1);
    s = reduce(s, { type: 'polled', job: job('running'), at: 10 });
    assert.equal(s.pollErrors, 0);
});

// ---- driver --------------------------------------------------------------------------------------------------

function harness(script, { posted } = {}) {
    const calls = [];
    const sleeps = [];
    let clock = 0;
    const api = {
        post: async (url, body) => { calls.push(['POST', url, body]); const r = posted ? posted() : job('queued'); if (r instanceof Error) throw r; return r; },
        get: async (url) => { calls.push(['GET', url]); const r = script.shift(); if (r instanceof Error) throw r; return r; },
    };
    const sleep = async (ms) => { sleeps.push(ms); clock += ms; };
    const seen = [];
    return { api, sleep, calls, sleeps, seen, now: () => clock, onState: (s) => seen.push(s.phase) };
}

test('runExport follows a queued job to a downloadable file', async () => {
    const h = harness([job('running'), job('running'), job('done', { download_url: '/api/exports/7/download', size: 1200 })]);
    const end = await runExport({ api: h.api, deckId: 3, format: 'pdf', options: { include_hidden: true }, sleep: h.sleep, now: h.now, onState: h.onState });
    assert.equal(end.phase, 'done');
    assert.equal(end.job.download_url, '/api/exports/7/download');
    assert.deepEqual(h.calls[0], ['POST', '/api/decks/3/exports', { format: 'pdf', options: { include_hidden: true } }]);
    assert.deepEqual(h.calls.slice(1).map((c) => c[1]), ['/api/exports/7', '/api/exports/7', '/api/exports/7']);
    assert.deepEqual(h.sleeps, [POLL_STEPS[0], POLL_STEPS[1], POLL_STEPS[2]]);
    assert.deepEqual(h.seen.slice(0, 2), ['starting', 'working']);
    assert.equal(h.seen.at(-1), 'done');
});

test('a small deck answers done on the POST and is not polled', async () => {
    const h = harness([], { posted: () => job('done', { download_url: '/api/exports/7/download' }) });
    const end = await runExport({ api: h.api, deckId: 3, format: 'pptx', sleep: h.sleep, now: h.now });
    assert.equal(end.phase, 'done');
    assert.equal(h.calls.length, 1);
    assert.deepEqual(h.sleeps, []);
});

test('a done job without a file is a failure, never a success', async () => {
    const h = harness([], { posted: () => job('done') });
    const end = await runExport({ api: h.api, deckId: 3, format: 'pdf', sleep: h.sleep, now: h.now });
    assert.equal(end.phase, 'failed');
    assert.equal(end.job.download_url, null);
});

test('a failed job keeps the server message', async () => {
    const h = harness([job('running'), job('failed', { error: 'This deck has too many slides to export.' })]);
    const end = await runExport({ api: h.api, deckId: 3, format: 'pdf', sleep: h.sleep, now: h.now });
    assert.equal(end.phase, 'failed');
    assert.equal(end.error, 'This deck has too many slides to export.');
    assert.equal(end.retryable, true);
});

test('a start error (429) is described and ends the run', async () => {
    const h = harness([], { posted: () => apiErr(429, 'You already have exports in progress. Wait for one to finish.') });
    const end = await runExport({ api: h.api, deckId: 3, format: 'pdf', sleep: h.sleep, now: h.now, describe: (e) => `Told: ${e.message}` });
    assert.equal(end.phase, 'failed');
    assert.equal(end.error, 'Told: You already have exports in progress. Wait for one to finish.');
    assert.equal(h.calls.length, 1);
});

test('a network blip while polling is retried, then the job completes', async () => {
    const h = harness([Object.assign(new Error('offline'), { network: true }), job('done', { download_url: '/d' })]);
    const end = await runExport({ api: h.api, deckId: 3, format: 'pdf', sleep: h.sleep, now: h.now });
    assert.equal(end.phase, 'done');
    assert.equal(h.calls.length, 3);
});

test('repeated poll failures end in a failure, a 404 ends it at once', async () => {
    const many = harness(Array.from({ length: 10 }, () => apiErr(502, 'bad gateway')));
    const a = await runExport({ api: many.api, deckId: 3, format: 'pdf', sleep: many.sleep, now: many.now });
    assert.equal(a.phase, 'failed');
    assert.equal(many.calls.filter((c) => c[0] === 'GET').length, MAX_POLL_ERRORS + 1);

    const gone = harness([apiErr(404, 'not found')]);
    const b = await runExport({ api: gone.api, deckId: 3, format: 'pdf', sleep: gone.sleep, now: gone.now });
    assert.equal(b.phase, 'failed');
    assert.equal(gone.calls.filter((c) => c[0] === 'GET').length, 1);
});

test('a 429 on a poll waits at least as long as Retry-After', async () => {
    const h = harness([apiErr(429, 'slow down', { retryAfter: 30 }), job('done', { download_url: '/d' })]);
    await runExport({ api: h.api, deckId: 3, format: 'pdf', sleep: h.sleep, now: h.now });
    assert.ok(h.sleeps[1] >= 30000);
});

test('aborting stops the following without calling the server again', async () => {
    const ctl = new AbortController();
    const h = harness([job('running'), job('running'), job('running')]);
    const sleep = async (ms) => { h.sleeps.push(ms); ctl.abort(); };
    const end = await runExport({ api: h.api, deckId: 3, format: 'pdf', sleep, now: h.now, signal: ctl.signal });
    assert.equal(end.phase, 'working');
    assert.equal(h.calls.filter((c) => c[0] === 'GET').length, 0);
});

test('a job that never finishes is given up on, in plain words', async () => {
    const h = harness(Array.from({ length: 500 }, () => job('queued')));
    const end = await runExport({ api: h.api, deckId: 3, format: 'pdf', sleep: h.sleep, now: h.now });
    assert.equal(end.phase, 'failed');
    assert.match(end.error, /much longer than usual/);
    assert.ok(h.now() >= GIVE_UP_AFTER_MS);
    assert.ok(h.calls.length < 500);
});

test('working words', () => {
    let s = reduce(initialState(), { type: 'start' });
    assert.equal(workingWord(s), 'starting');
    s = reduce(s, { type: 'started', job: job('running'), at: 0 });
    assert.equal(workingWord(s), 'building');
    assert.match(workingMessage(s, 'PowerPoint file'), /Building your PowerPoint file/);
});
